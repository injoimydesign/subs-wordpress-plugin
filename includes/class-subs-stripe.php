<?php
/**
 * Stripe Integration Class
 *
 * Handles all Stripe API interactions for subscriptions
 *
 * @package Subs
 * @version 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Subs Stripe Class
 *
 * @class Subs_Stripe
 * @version 1.0.0
 */
class Subs_Stripe {

    /**
     * Stripe API instance
     * @var object
     */
    private $stripe;

    /**
     * Test mode
     * @var bool
     */
    private $test_mode;

    /**
     * API keys
     * @var array
     */
    private $keys;

    /**
     * Constructor
     */
    public function __construct() {
        $this->init();
        $this->init_hooks();
    }

    /**
     * Initialize Stripe settings
     */
    private function init() {
        $this->test_mode = 'yes' === get_option('subs_stripe_test_mode', 'yes');

        $this->keys = array(
            'publishable' => $this->test_mode
                ? get_option('subs_stripe_test_publishable_key', '')
                : get_option('subs_stripe_live_publishable_key', ''),
            'secret' => $this->test_mode
                ? get_option('subs_stripe_test_secret_key', '')
                : get_option('subs_stripe_live_secret_key', '')
        );

        // Initialize Stripe (assuming Stripe PHP library is loaded)
        if (class_exists('Stripe\Stripe')) {
            \Stripe\Stripe::setApiKey($this->keys['secret']);
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Webhook handler
        add_action('wp_ajax_subs_stripe_webhook', array($this, 'handle_webhook'));
        add_action('wp_ajax_nopriv_subs_stripe_webhook', array($this, 'handle_webhook'));

        // Payment processing hooks
        add_action('woocommerce_checkout_process', array($this, 'validate_checkout_subscription'));
        add_action('woocommerce_checkout_create_order', array($this, 'add_subscription_to_order'));
    }

    /**
     * Check if Stripe is configured
     *
     * @return bool
     */
    public function is_configured() {
        return !empty($this->keys['publishable']) && !empty($this->keys['secret']);
    }

    /**
     * Get publishable key
     *
     * @return string
     */
    public function get_publishable_key() {
        return $this->keys['publishable'];
    }

    /**
     * Calculate Stripe fee
     *
     * @param float $amount
     * @return float
     */
    public function calculate_stripe_fee($amount) {
        if ('no' === get_option('subs_pass_stripe_fees', 'no')) {
            return 0;
        }

        $percentage = floatval(get_option('subs_stripe_fee_percentage', '2.9'));
        $fixed_fee = floatval(get_option('subs_stripe_fee_fixed', '0.30'));

        return ($amount * ($percentage / 100)) + $fixed_fee;
    }

    /**
     * Create Stripe customer
     *
     * @param WP_User $user
     * @param array $payment_method_data
     * @return string|WP_Error Customer ID or error
     */
    public function create_customer($user, $payment_method_data = array()) {
        if (!$this->is_configured()) {
            return new WP_Error('stripe_not_configured', __('Stripe is not properly configured', 'subs'));
        }

        try {
            $customer_data = array(
                'email' => $user->user_email,
                'name'  => $user->display_name,
                'metadata' => array(
                    'wp_user_id' => $user->ID
                )
            );

            if (!empty($payment_method_data)) {
                $customer_data['payment_method'] = $payment_method_data['id'];
                $customer_data['invoice_settings'] = array(
                    'default_payment_method' => $payment_method_data['id']
                );
            }

            $customer = \Stripe\Customer::create($customer_data);

            // Store customer ID in user meta
            update_user_meta($user->ID, '_subs_stripe_customer_id', $customer->id);

            return $customer->id;

        } catch (Exception $e) {
            return new WP_Error('stripe_customer_error', $e->getMessage());
        }
    }

    /**
     * Get or create Stripe customer
     *
     * @param int $user_id
     * @return string|WP_Error
     */
    public function get_or_create_customer($user_id) {
        $customer_id = get_user_meta($user_id, '_subs_stripe_customer_id', true);

        if (!empty($customer_id)) {
            // Verify customer exists in Stripe
            try {
                \Stripe\Customer::retrieve($customer_id);
                return $customer_id;
            } catch (Exception $e) {
                // Customer doesn't exist, create new one
                delete_user_meta($user_id, '_subs_stripe_customer_id');
            }
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error('user_not_found', __('User not found', 'subs'));
        }

        return $this->create_customer($user);
    }

    /**
     * Create Stripe price for product
     *
     * @param WC_Product $product
     * @param array $subscription_settings
     * @return string|WP_Error Price ID or error
     */
    public function create_price($product, $subscription_settings) {
        if (!$this->is_configured()) {
            return new WP_Error('stripe_not_configured', __('Stripe is not properly configured', 'subs'));
        }

        try {
            $amount = $product->get_price();

            // Add Stripe fee if enabled
            $stripe_fee = $this->calculate_stripe_fee($amount);
            $total_amount = $amount + $stripe_fee;

            // Convert to cents
            $unit_amount = intval($total_amount * 100);

            $price_data = array(
                'unit_amount' => $unit_amount,
                'currency' => strtolower(get_woocommerce_currency()),
                'recurring' => array(
                    'interval' => $subscription_settings['billing_period'],
                    'interval_count' => intval($subscription_settings['billing_interval'])
                ),
                'product_data' => array(
                    'name' => $product->get_name(),
                    'metadata' => array(
                        'wc_product_id' => $product->get_id()
                    )
                )
            );

            $price = \Stripe\Price::create($price_data);

            // Store price ID in product meta
            update_post_meta($product->get_id(), '_subs_stripe_price_id', $price->id);
            update_post_meta($product->get_id(), '_subs_stripe_fee_amount', $stripe_fee);

            return $price->id;

        } catch (Exception $e) {
            return new WP_Error('stripe_price_error', $e->getMessage());
        }
    }

    /**
     * Create Stripe subscription
     *
     * @param array $subscription_data
     * @return array|WP_Error Subscription data or error
     */
    public function create_subscription($subscription_data) {
        if (!$this->is_configured()) {
            return new WP_Error('stripe_not_configured', __('Stripe is not properly configured', 'subs'));
        }

        try {
            $stripe_data = array(
                'customer' => $subscription_data['customer_id'],
                'items' => array(
                    array(
                        'price' => $subscription_data['price_id'],
                        'quantity' => intval($subscription_data['quantity'])
                    )
                ),
                'metadata' => array(
                    'wc_order_id' => $subscription_data['order_id'],
                    'wc_product_id' => $subscription_data['product_id'],
                    'wc_customer_id' => $subscription_data['wp_user_id']
                ),
                'expand' => array('latest_invoice.payment_intent')
            );

            // Add trial period if specified
            if (!empty($subscription_data['trial_period_days'])) {
                $stripe_data['trial_period_days'] = intval($subscription_data['trial_period_days']);
            }

            // Set payment behavior
            $stripe_data['payment_behavior'] = 'default_incomplete';

            $subscription = \Stripe\Subscription::create($stripe_data);

            return array(
                'subscription_id' => $subscription->id,
                'client_secret' => $subscription->latest_invoice->payment_intent->client_secret,
                'subscription' => $subscription
            );

        } catch (Exception $e) {
            return new WP_Error('stripe_subscription_error', $e->getMessage());
        }
    }

    /**
     * Update Stripe subscription
     *
     * @param string $subscription_id
     * @param array $update_data
     * @return bool|WP_Error
     */
    public function update_subscription($subscription_id, $update_data) {
        if (!$this->is_configured()) {
            return new WP_Error('stripe_not_configured', __('Stripe is not properly configured', 'subs'));
        }

        try {
            \Stripe\Subscription::update($subscription_id, $update_data);
            return true;

        } catch (Exception $e) {
            return new WP_Error('stripe_update_error', $e->getMessage());
        }
    }

    /**
     * Cancel Stripe subscription
     *
     * @param string $subscription_id
     * @return bool|WP_Error
     */
    public function cancel_subscription($subscription_id) {
        if (!$this->is_configured()) {
            return new WP_Error('stripe_not_configured', __('Stripe is not properly configured', 'subs'));
        }

        try {
            $subscription = \Stripe\Subscription::retrieve($subscription_id);
            $subscription->cancel();

            return true;

        } catch (Exception $e) {
            return new WP_Error('stripe_cancel_error', $e->getMessage());
        }
    }

    /**
     * Pause Stripe subscription
     *
     * @param string $subscription_id
     * @return bool|WP_Error
     */
    public function pause_subscription($subscription_id) {
        if (!$this->is_configured()) {
            return new WP_Error('stripe_not_configured', __('Stripe is not properly configured', 'subs'));
        }

        try {
            \Stripe\Subscription::update($subscription_id, array(
                'pause_collection' => array(
                    'behavior' => 'void'
                )
            ));

            return true;

        } catch (Exception $e) {
            return new WP_Error('stripe_pause_error', $e->getMessage());
        }
    }

    /**
     * Resume Stripe subscription
     *
     * @param string $subscription_id
     * @return bool|WP_Error
     */
    public function resume_subscription($subscription_id) {
        if (!$this->is_configured()) {
            return new WP_Error('stripe_not_configured', __('Stripe is not properly configured', 'subs'));
        }

        try {
            \Stripe\Subscription::update($subscription_id, array(
                'pause_collection' => null
            ));

            return true;

        } catch (Exception $e) {
            return new WP_Error('stripe_resume_error', $e->getMessage());
        }
    }

    /**
     * Process subscription payment
     *
     * @param Subs_Subscription $subscription
     * @return bool|WP_Error
     */
    public function process_subscription_payment($subscription) {
        if (!$this->is_configured()) {
            return new WP_Error('stripe_not_configured', __('Stripe is not properly configured', 'subs'));
        }

        try {
            $stripe_subscription = \Stripe\Subscription::retrieve($subscription->get_stripe_subscription_id());

            // Create invoice and attempt payment
            $invoice = \Stripe\Invoice::create(array(
                'customer' => $stripe_subscription->customer,
                'subscription' => $stripe_subscription->id
            ));

            $invoice->pay();

            return true;

        } catch (Exception $e) {
            return new WP_Error('stripe_payment_error', $e->getMessage());
        }
    }

    /**
     * Handle Stripe webhooks
     */
    public function handle_webhook() {
        $payload = @file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $endpoint_secret = get_option('subs_stripe_webhook_secret', '');

        if (empty($endpoint_secret)) {
            http_response_code(400);
            exit('Webhook secret not configured');
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sig_header,
                $endpoint_secret
            );

            // Handle the event
            switch ($event['type']) {
                case 'customer.subscription.created':
                    $this->handle_subscription_created($event['data']['object']);
                    break;

                case 'customer.subscription.updated':
                    $this->handle_subscription_updated($event['data']['object']);
                    break;

                case 'customer.subscription.deleted':
                    $this->handle_subscription_deleted($event['data']['object']);
                    break;

                case 'invoice.payment_succeeded':
                    $this->handle_payment_succeeded($event['data']['object']);
                    break;

                case 'invoice.payment_failed':
                    $this->handle_payment_failed($event['data']['object']);
                    break;

                default:
                    // Unhandled event type
                    break;
            }

            http_response_code(200);
            exit('Webhook handled');

        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            http_response_code(400);
            exit('Invalid payload');
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            http_response_code(400);
            exit('Invalid signature');
        }
    }

    /**
     * Handle subscription created webhook
     *
     * @param object $stripe_subscription
     */
    private function handle_subscription_created($stripe_subscription) {
        // Find local subscription by Stripe ID
        $subscription = $this->get_subscription_by_stripe_id($stripe_subscription->id);

        if ($subscription) {
            $subscription->update_status('active', __('Subscription activated via webhook', 'subs'));

            // Update subscription details from Stripe
            $subscription->set_start_date(date('Y-m-d H:i:s', $stripe_subscription->current_period_start));
            $subscription->set_next_payment_date(date('Y-m-d H:i:s', $stripe_subscription->current_period_end));

            if ($stripe_subscription->trial_end) {
                $subscription->set_trial_end_date(date('Y-m-d H:i:s', $stripe_subscription->trial_end));
                $subscription->update_status('trialing', __('Trial period started', 'subs'));
            }

            $subscription->save();

            do_action('subs_stripe_subscription_created', $subscription, $stripe_subscription);
        }
    }

    /**
     * Handle subscription updated webhook
     *
     * @param object $stripe_subscription
     */
    private function handle_subscription_updated($stripe_subscription) {
        $subscription = $this->get_subscription_by_stripe_id($stripe_subscription->id);

        if ($subscription) {
            // Update status based on Stripe status
            $status_map = array(
                'active' => 'active',
                'past_due' => 'past_due',
                'unpaid' => 'unpaid',
                'canceled' => 'cancelled',
                'incomplete' => 'pending',
                'incomplete_expired' => 'cancelled',
                'trialing' => 'trialing',
                'paused' => 'paused'
            );

            $new_status = isset($status_map[$stripe_subscription->status])
                ? $status_map[$stripe_subscription->status]
                : $stripe_subscription->status;

            if ($subscription->get_status() !== $new_status) {
                $subscription->update_status($new_status,
                    sprintf(__('Status updated via webhook: %s', 'subs'), $stripe_subscription->status)
                );
            }

            // Update payment dates
            $subscription->set_next_payment_date(date('Y-m-d H:i:s', $stripe_subscription->current_period_end));
            $subscription->save();

            do_action('subs_stripe_subscription_updated', $subscription, $stripe_subscription);
        }
    }

    /**
     * Handle subscription deleted webhook
     *
     * @param object $stripe_subscription
     */
    private function handle_subscription_deleted($stripe_subscription) {
        $subscription = $this->get_subscription_by_stripe_id($stripe_subscription->id);

        if ($subscription) {
            $subscription->update_status('cancelled', __('Subscription cancelled via webhook', 'subs'));
            $subscription->set_end_date(current_time('mysql'));
            $subscription->save();

            do_action('subs_stripe_subscription_deleted', $subscription, $stripe_subscription);
        }
    }

    /**
     * Handle payment succeeded webhook
     *
     * @param object $invoice
     */
    private function handle_payment_succeeded($invoice) {
        if (!$invoice->subscription) {
            return;
        }

        $subscription = $this->get_subscription_by_stripe_id($invoice->subscription);

        if ($subscription) {
            $subscription->set_last_payment_date(current_time('mysql'));
            $subscription->set_next_payment_date(date('Y-m-d H:i:s', $invoice->period_end));

            // If subscription was past due or unpaid, reactivate it
            if (in_array($subscription->get_status(), array('past_due', 'unpaid'))) {
                $subscription->update_status('active', __('Payment received, subscription reactivated', 'subs'));
            }

            $subscription->save();

            $subscription->add_history('payment_received', '', '',
                sprintf(__('Payment received: %s', 'subs'),
                    wc_price($invoice->amount_paid / 100, array('currency' => strtoupper($invoice->currency)))
                )
            );

            do_action('subs_stripe_payment_succeeded', $subscription, $invoice);
        }
    }

    /**
     * Handle payment failed webhook
     *
     * @param object $invoice
     */
    private function handle_payment_failed($invoice) {
        if (!$invoice->subscription) {
            return;
        }

        $subscription = $this->get_subscription_by_stripe_id($invoice->subscription);

        if ($subscription) {
            $subscription->update_status('past_due', __('Payment failed', 'subs'));
            $subscription->save();

            $subscription->add_history('payment_failed', '', '',
                sprintf(__('Payment failed: %s', 'subs'),
                    wc_price($invoice->amount_due / 100, array('currency' => strtoupper($invoice->currency)))
                )
            );

            do_action('subs_stripe_payment_failed', $subscription, $invoice);
        }
    }

    /**
     * Get subscription by Stripe ID
     *
     * @param string $stripe_subscription_id
     * @return Subs_Subscription|false
     */
    private function get_subscription_by_stripe_id($stripe_subscription_id) {
        global $wpdb;

        $subscription_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}subs_subscriptions WHERE stripe_subscription_id = %s",
                $stripe_subscription_id
            )
        );

        return $subscription_id ? new Subs_Subscription($subscription_id) : false;
    }

    /**
     * Validate checkout subscription fields
     */
    public function validate_checkout_subscription() {
        if (!isset($_POST['subscription_enabled']) || $_POST['subscription_enabled'] !== '1') {
            return;
        }

        // Validate flag address
        if (empty($_POST['flag_delivery_address'])) {
            wc_add_notice(__('Flag delivery address is required for subscriptions.', 'subs'), 'error');
        }

        // Validate payment method
        if (empty($_POST['payment_method']) || $_POST['payment_method'] !== 'stripe') {
            wc_add_notice(__('Stripe payment method is required for subscriptions.', 'subs'), 'error');
        }
    }

    /**
     * Add subscription data to order
     *
     * @param WC_Order $order
     */
    public function add_subscription_to_order($order) {
        if (!isset($_POST['subscription_enabled']) || $_POST['subscription_enabled'] !== '1') {
            return;
        }

        // Add subscription meta to order
        $order->update_meta_data('_is_subscription_order', 'yes');
        $order->update_meta_data('_subscription_billing_period', sanitize_text_field($_POST['subscription_billing_period']));
        $order->update_meta_data('_subscription_billing_interval', absint($_POST['subscription_billing_interval']));
        $order->update_meta_data('_flag_delivery_address', sanitize_textarea_field($_POST['flag_delivery_address']));

        if (!empty($_POST['subscription_trial_days'])) {
            $order->update_meta_data('_subscription_trial_days', absint($_POST['subscription_trial_days']));
        }
    }

    /**
     * Process subscription order after payment
     *
     * @param int $order_id
     * @param WC_Order $order
     * @return bool|WP_Error
     */
    public function process_subscription_order($order_id, $order) {
        if ($order->get_meta('_is_subscription_order') !== 'yes') {
            return false;
        }

        $customer_id = $order->get_customer_id();
        if (!$customer_id) {
            return new WP_Error('no_customer', __('Customer ID not found', 'subs'));
        }

        // Get or create Stripe customer
        $stripe_customer_id = $this->get_or_create_customer($customer_id);
        if (is_wp_error($stripe_customer_id)) {
            return $stripe_customer_id;
        }

        // Process each item in the order
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            // Get subscription settings
            $billing_period = $order->get_meta('_subscription_billing_period') ?: 'month';
            $billing_interval = $order->get_meta('_subscription_billing_interval') ?: 1;
            $trial_days = $order->get_meta('_subscription_trial_days') ?: 0;

            // Create or get Stripe price
            $subscription_settings = array(
                'billing_period' => $billing_period,
                'billing_interval' => $billing_interval
            );

            $price_id = $this->create_price($product, $subscription_settings);
            if (is_wp_error($price_id)) {
                return $price_id;
            }

            // Create Stripe subscription
            $subscription_data = array(
                'customer_id' => $stripe_customer_id,
                'price_id' => $price_id,
                'quantity' => $item->get_quantity(),
                'order_id' => $order_id,
                'product_id' => $product->get_id(),
                'wp_user_id' => $customer_id
            );

            if ($trial_days > 0) {
                $subscription_data['trial_period_days'] = $trial_days;
            }

            $stripe_result = $this->create_subscription($subscription_data);
            if (is_wp_error($stripe_result)) {
                return $stripe_result;
            }

            // Create local subscription record
            $subscription = new Subs_Subscription();
            $subscription->set_order_id($order_id);
            $subscription->set_customer_id($customer_id);
            $subscription->set_product_id($product->get_id());
            $subscription->set_stripe_subscription_id($stripe_result['subscription_id']);
            $subscription->set_status('pending');
            $subscription->set_billing_period($billing_period);
            $subscription->set_billing_interval($billing_interval);
            $subscription->set_subscription_amount($product->get_price());
            $subscription->set_stripe_fee_amount($this->calculate_stripe_fee($product->get_price()));
            $subscription->set_total_amount($product->get_price() + $subscription->get_stripe_fee_amount());
            $subscription->set_currency(get_woocommerce_currency());
            $subscription->set_flag_address($order->get_meta('_flag_delivery_address'));

            if ($trial_days > 0) {
                $trial_end = new DateTime();
                $trial_end->add(new DateInterval('P' . $trial_days . 'D'));
                $subscription->set_trial_end_date($trial_end->format('Y-m-d H:i:s'));
            }

            $subscription->save();

            // Store client secret for frontend confirmation
            $order->update_meta_data('_stripe_client_secret', $stripe_result['client_secret']);
            $order->save();
        }

        return true;
    }

    /**
     * Get subscription payment methods
     *
     * @param string $customer_id Stripe customer ID
     * @return array|WP_Error
     */
    public function get_customer_payment_methods($customer_id) {
        if (!$this->is_configured()) {
            return new WP_Error('stripe_not_configured', __('Stripe is not properly configured', 'subs'));
        }

        try {
            $payment_methods = \Stripe\PaymentMethod::all(array(
                'customer' => $customer_id,
                'type' => 'card',
            ));

            return $payment_methods->data;

        } catch (Exception $e) {
            return new WP_Error('stripe_payment_methods_error', $e->getMessage());
        }
    }

    /**
     * Update subscription payment method
     *
     * @param string $subscription_id
     * @param string $payment_method_id
     * @return bool|WP_Error
     */
    public function update_subscription_payment_method($subscription_id, $payment_method_id) {
        if (!$this->is_configured()) {
            return new WP_Error('stripe_not_configured', __('Stripe is not properly configured', 'subs'));
        }

        try {
            \Stripe\Subscription::update($subscription_id, array(
                'default_payment_method' => $payment_method_id
            ));

            return true;

        } catch (Exception $e) {
            return new WP_Error('stripe_payment_method_error', $e->getMessage());
        }
    }

    /**
     * Create setup intent for adding payment method
     *
     * @param string $customer_id
     * @return array|WP_Error
     */
    public function create_setup_intent($customer_id) {
        if (!$this->is_configured()) {
            return new WP_Error('stripe_not_configured', __('Stripe is not properly configured', 'subs'));
        }

        try {
            $intent = \Stripe\SetupIntent::create(array(
                'customer' => $customer_id,
                'payment_method_types' => array('card'),
                'usage' => 'off_session'
            ));

            return array(
                'client_secret' => $intent->client_secret,
                'setup_intent' => $intent
            );

        } catch (Exception $e) {
            return new WP_Error('stripe_setup_intent_error', $e->getMessage());
        }
    }
}
