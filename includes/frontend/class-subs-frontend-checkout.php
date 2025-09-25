<?php
/**
 * Frontend Checkout Integration
 *
 * Handles subscription functionality during checkout process
 *
 * @package Subs
 * @subpackage Frontend
 * @version 1.0.0
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Subs Frontend Checkout Class
 *
 * @class Subs_Frontend_Checkout
 * @version 1.0.0
 */
class Subs_Frontend_Checkout {

    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     *
     * @since 1.0.0
     */
    private function init_hooks() {
        // Checkout process modifications
        add_action('woocommerce_checkout_order_review', array($this, 'display_subscription_summary'));
        add_action('woocommerce_review_order_after_order_total', array($this, 'display_subscription_totals'));

        // Order creation and processing
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'add_subscription_order_item_meta'), 10, 4);
        add_action('woocommerce_checkout_order_processed', array($this, 'process_subscription_checkout'), 10, 3);

        // Payment method validation
        add_action('woocommerce_checkout_process', array($this, 'validate_subscription_payment_method'));
        add_filter('woocommerce_available_payment_gateways', array($this, 'filter_payment_gateways_for_subscriptions'));

        // Order totals calculation
        add_action('woocommerce_cart_calculate_fees', array($this, 'add_subscription_fees'));
        add_filter('woocommerce_calculated_total', array($this, 'calculate_subscription_total'), 10, 2);

        // Checkout field modifications
        add_filter('woocommerce_checkout_fields', array($this, 'modify_checkout_fields_for_subscriptions'));

        // Thank you page modifications
        add_action('woocommerce_thankyou', array($this, 'display_subscription_thank_you_message'), 5);

        // Email modifications
        add_action('woocommerce_email_before_order_table', array($this, 'add_subscription_info_to_emails'), 10, 4);

        // Scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_scripts'));

        // AJAX handlers
        add_action('wp_ajax_subs_update_checkout_totals', array($this, 'ajax_update_checkout_totals'));
        add_action('wp_ajax_nopriv_subs_update_checkout_totals', array($this, 'ajax_update_checkout_totals'));
    }

    /**
     * Display subscription summary in checkout
     *
     * @since 1.0.0
     */
    public function display_subscription_summary() {
        if (!$this->cart_contains_subscription()) {
            return;
        }

        $subscription_items = $this->get_cart_subscription_items();

        if (empty($subscription_items)) {
            return;
        }

        wc_get_template(
            'checkout/subscription-summary.php',
            array(
                'subscription_items' => $subscription_items,
                'recurring_total' => $this->calculate_recurring_total()
            ),
            '',
            SUBS_PLUGIN_PATH . 'templates/'
        );
    }

    /**
     * Display subscription totals after order total
     *
     * @since 1.0.0
     */
    public function display_subscription_totals() {
        if (!$this->cart_contains_subscription()) {
            return;
        }

        $recurring_total = $this->calculate_recurring_total();
        $sign_up_fee = $this->calculate_sign_up_fee();
        $trial_info = $this->get_trial_information();

        wc_get_template(
            'checkout/subscription-totals.php',
            array(
                'recurring_total' => $recurring_total,
                'sign_up_fee' => $sign_up_fee,
                'trial_info' => $trial_info,
                'next_payment_date' => $this->calculate_next_payment_date()
            ),
            '',
            SUBS_PLUGIN_PATH . 'templates/'
        );
    }

    /**
     * Add subscription metadata to order line items
     *
     * @param WC_Order_Item_Product $item
     * @param string $cart_item_key
     * @param array $values
     * @param WC_Order $order
     * @since 1.0.0
     */
    public function add_subscription_order_item_meta($item, $cart_item_key, $values, $order) {
        if (!isset($values['is_subscription']) || !$values['is_subscription']) {
            return;
        }

        // Add subscription flag
        $item->add_meta_data('_is_subscription', 'yes');

        // Add subscription plan data
        if (isset($values['subscription_plan'])) {
            $item->add_meta_data('_subscription_plan_id', $values['subscription_plan']);
        }

        if (isset($values['subscription_data'])) {
            $subscription_data = $values['subscription_data'];

            // Store individual subscription parameters
            $item->add_meta_data('_subscription_price', $subscription_data['price']);
            $item->add_meta_data('_subscription_interval', $subscription_data['interval']);
            $item->add_meta_data('_subscription_interval_count', $subscription_data['interval_count']);

            if (isset($subscription_data['trial_days']) && $subscription_data['trial_days'] > 0) {
                $item->add_meta_data('_subscription_trial_days', $subscription_data['trial_days']);
            }

            if (isset($subscription_data['sign_up_fee']) && $subscription_data['sign_up_fee'] > 0) {
                $item->add_meta_data('_subscription_sign_up_fee', $subscription_data['sign_up_fee']);
            }
        }

        // Mark order as containing subscriptions
        $order->update_meta_data('_contains_subscription', 'yes');
    }

    /**
     * Process subscription checkout after order is created
     *
     * @param int $order_id
     * @param array $posted_data
     * @param WC_Order $order
     * @since 1.0.0
     */
    public function process_subscription_checkout($order_id, $posted_data, $order) {
        if ($order->get_meta('_contains_subscription') !== 'yes') {
            return;
        }

        // Add order flag for subscription processing
        $order->update_meta_data('_is_subscription_order', 'yes');
        $order->update_meta_data('_subscription_processed', 'no');

        // Store checkout data for subscription creation
        $subscription_checkout_data = array(
            'customer_id' => $order->get_customer_id(),
            'billing_details' => array(
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
                'address_1' => $order->get_billing_address_1(),
                'address_2' => $order->get_billing_address_2(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'postcode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
            ),
            'shipping_details' => array(
                'first_name' => $order->get_shipping_first_name(),
                'last_name' => $order->get_shipping_last_name(),
                'address_1' => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2(),
                'city' => $order->get_shipping_city(),
                'state' => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'country' => $order->get_shipping_country(),
            ),
            'payment_method' => $order->get_payment_method(),
            'currency' => $order->get_currency(),
        );

        $order->update_meta_data('_subscription_checkout_data', $subscription_checkout_data);
        $order->save();

        // TODO: Process subscription creation after successful payment
        // This will be handled in the payment gateway or order completion hooks
    }

    /**
     * Validate payment method for subscriptions
     *
     * @since 1.0.0
     */
    public function validate_subscription_payment_method() {
        if (!$this->cart_contains_subscription()) {
            return;
        }

        $chosen_payment_method = WC()->session->get('chosen_payment_method');

        if (empty($chosen_payment_method)) {
            wc_add_notice(__('Please select a payment method for your subscription.', 'subs'), 'error');
            return;
        }

        // Check if payment method supports subscriptions
        $supported_gateways = $this->get_subscription_supported_gateways();

        if (!in_array($chosen_payment_method, $supported_gateways)) {
            wc_add_notice(
                __('The selected payment method does not support subscriptions. Please choose a different payment method.', 'subs'),
                'error'
            );
        }

        // TODO: Add additional validation for subscription-specific payment requirements
        // - Validate payment method tokens for recurring payments
        // - Check gateway-specific subscription requirements
    }

    /**
     * Filter available payment gateways for subscriptions
     *
     * @param array $gateways
     * @return array
     * @since 1.0.0
     */
    public function filter_payment_gateways_for_subscriptions($gateways) {
        if (!$this->cart_contains_subscription()) {
            return $gateways;
        }

        $supported_gateways = $this->get_subscription_supported_gateways();

        foreach ($gateways as $gateway_id => $gateway) {
            if (!in_array($gateway_id, $supported_gateways)) {
                unset($gateways[$gateway_id]);
            }
        }

        return $gateways;
    }

    /**
     * Add subscription fees to cart
     *
     * @since 1.0.0
     */
    public function add_subscription_fees() {
        if (!$this->cart_contains_subscription()) {
            return;
        }

        $sign_up_fee = $this->calculate_sign_up_fee();

        if ($sign_up_fee > 0) {
            WC()->cart->add_fee(__('Sign-up Fee', 'subs'), $sign_up_fee);
        }

        // Allow other plugins to add subscription-related fees
        do_action('subs_add_subscription_fees');
    }

    /**
     * Calculate subscription total for checkout
     *
     * @param float $total
     * @param WC_Cart $cart
     * @return float
     * @since 1.0.0
     */
    public function calculate_subscription_total($total, $cart) {
        if (!$this->cart_contains_subscription()) {
            return $total;
        }

        // For trial subscriptions, adjust the initial total
        $trial_discount = $this->calculate_trial_discount();

        if ($trial_discount > 0) {
            $total = max(0, $total - $trial_discount);
        }

        return $total;
    }

    /**
     * Modify checkout fields for subscriptions
     *
     * @param array $fields
     * @return array
     * @since 1.0.0
     */
    public function modify_checkout_fields_for_subscriptions($fields) {
        if (!$this->cart_contains_subscription()) {
            return $fields;
        }

        // Add subscription agreement field
        $fields['order']['subscription_agreement'] = array(
            'type'     => 'checkbox',
            'label'    => __('I agree to the subscription terms and conditions', 'subs'),
            'required' => true,
            'class'    => array('form-row-wide'),
            'priority' => 999,
        );

        // TODO: Add additional subscription-specific fields as needed
        // - Payment method save option
        // - Subscription preferences
        // - Delivery instructions for recurring orders

        return apply_filters('subs_checkout_fields', $fields);
    }

    /**
     * Display subscription thank you message
     *
     * @param int $order_id
     * @since 1.0.0
     */
    public function display_subscription_thank_you_message($order_id) {
        $order = wc_get_order($order_id);

        if (!$order || $order->get_meta('_contains_subscription') !== 'yes') {
            return;
        }

        $subscription_items = $this->get_order_subscription_items($order);

        if (empty($subscription_items)) {
            return;
        }

        wc_get_template(
            'checkout/subscription-thank-you.php',
            array(
                'order' => $order,
                'subscription_items' => $subscription_items
            ),
            '',
            SUBS_PLUGIN_PATH . 'templates/'
        );
    }

    /**
     * Add subscription information to order emails
     *
     * @param WC_Order $order
     * @param bool $sent_to_admin
     * @param bool $plain_text
     * @param WC_Email $email
     * @since 1.0.0
     */
    public function add_subscription_info_to_emails($order, $sent_to_admin, $plain_text, $email) {
        if ($order->get_meta('_contains_subscription') !== 'yes') {
            return;
        }

        $subscription_items = $this->get_order_subscription_items($order);

        if (empty($subscription_items)) {
            return;
        }

        if ($plain_text) {
            wc_get_template(
                'emails/plain/subscription-details.php',
                array(
                    'order' => $order,
                    'subscription_items' => $subscription_items,
                    'sent_to_admin' => $sent_to_admin
                ),
                '',
                SUBS_PLUGIN_PATH . 'templates/'
            );
        } else {
            wc_get_template(
                'emails/subscription-details.php',
                array(
                    'order' => $order,
                    'subscription_items' => $subscription_items,
                    'sent_to_admin' => $sent_to_admin
                ),
                '',
                SUBS_PLUGIN_PATH . 'templates/'
            );
        }
    }

    /**
     * Enqueue checkout scripts and styles
     *
     * @since 1.0.0
     */
    public function enqueue_checkout_scripts() {
        if (!is_checkout() && !is_cart()) {
            return;
        }

        if (!$this->cart_contains_subscription()) {
            return;
        }

        wp_enqueue_style(
            'subs-frontend-checkout',
            SUBS_PLUGIN_URL . 'assets/css/frontend-checkout.css',
            array(),
            SUBS_VERSION
        );

        wp_enqueue_script(
            'subs-frontend-checkout',
            SUBS_PLUGIN_URL . 'assets/js/frontend-checkout.js',
            array('jquery', 'wc-checkout'),
            SUBS_VERSION,
            true
        );

        // Localize script
        wp_localize_script('subs-frontend-checkout', 'subs_checkout', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('subs_checkout_nonce'),
            'strings' => array(
                'agreement_required' => __('Please agree to the subscription terms and conditions.', 'subs'),
                'processing' => __('Processing subscription...', 'subs'),
            )
        ));
    }

    /**
     * AJAX handler for updating checkout totals
     *
     * @since 1.0.0
     */
    public function ajax_update_checkout_totals() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'subs_checkout_nonce')) {
            wp_die(__('Security check failed', 'subs'));
        }

        // Recalculate cart totals
        WC()->cart->calculate_totals();

        $response = array(
            'total' => WC()->cart->get_total(),
            'recurring_total' => $this->calculate_recurring_total(),
            'next_payment_date' => $this->calculate_next_payment_date()
        );

        wp_send_json_success($response);
    }

    /**
     * Check if cart contains subscription items
     *
     * @return bool
     * @since 1.0.0
     */
    private function cart_contains_subscription() {
        if (empty(WC()->cart->get_cart())) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            if (isset($cart_item['is_subscription']) && $cart_item['is_subscription']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get subscription items from cart
     *
     * @return array
     * @since 1.0.0
     */
    private function get_cart_subscription_items() {
        $subscription_items = array();

        if (empty(WC()->cart->get_cart())) {
            return $subscription_items;
        }

        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['is_subscription']) && $cart_item['is_subscription']) {
                $subscription_items[$cart_item_key] = $cart_item;
            }
        }

        return $subscription_items;
    }

    /**
     * Get subscription items from order
     *
     * @param WC_Order $order
     * @return array
     * @since 1.0.0
     */
    private function get_order_subscription_items($order) {
        $subscription_items = array();

        foreach ($order->get_items() as $item_id => $item) {
            if ($item->get_meta('_is_subscription') === 'yes') {
                $subscription_items[$item_id] = $item;
            }
        }

        return $subscription_items;
    }

    /**
     * Calculate recurring total for subscriptions
     *
     * @return float
     * @since 1.0.0
     */
    private function calculate_recurring_total() {
        $recurring_total = 0;

        $subscription_items = $this->get_cart_subscription_items();

        foreach ($subscription_items as $cart_item) {
            if (isset($cart_item['subscription_data']['price'])) {
                $item_total = $cart_item['subscription_data']['price'] * $cart_item['quantity'];
                $recurring_total += $item_total;
            }
        }

        return apply_filters('subs_recurring_total', $recurring_total);
    }

    /**
     * Calculate sign-up fee for subscriptions
     *
     * @return float
     * @since 1.0.0
     */
    private function calculate_sign_up_fee() {
        $sign_up_fee = 0;

        $subscription_items = $this->get_cart_subscription_items();

        foreach ($subscription_items as $cart_item) {
            if (isset($cart_item['subscription_data']['sign_up_fee'])) {
                $sign_up_fee += $cart_item['subscription_data']['sign_up_fee'];
            }
        }

        return apply_filters('subs_sign_up_fee', $sign_up_fee);
    }

    /**
     * Calculate trial discount
     *
     * @return float
     * @since 1.0.0
     */
    private function calculate_trial_discount() {
        $trial_discount = 0;

        $subscription_items = $this->get_cart_subscription_items();

        foreach ($subscription_items as $cart_item) {
            if (isset($cart_item['subscription_data']['trial_days']) &&
                $cart_item['subscription_data']['trial_days'] > 0) {
                // For trial subscriptions, the recurring amount is not charged initially
                $item_total = $cart_item['subscription_data']['price'] * $cart_item['quantity'];
                $trial_discount += $item_total;
            }
        }

        return apply_filters('subs_trial_discount', $trial_discount);
    }

    /**
     * Get trial information for display
     *
     * @return array
     * @since 1.0.0
     */
    private function get_trial_information() {
        $trial_info = array();

        $subscription_items = $this->get_cart_subscription_items();

        foreach ($subscription_items as $cart_item_key => $cart_item) {
            if (isset($cart_item['subscription_data']['trial_days']) &&
                $cart_item['subscription_data']['trial_days'] > 0) {

                $trial_info[$cart_item_key] = array(
                    'trial_days' => $cart_item['subscription_data']['trial_days'],
                    'trial_text' => sprintf(
                        _n('%d day free trial', '%d days free trial', $cart_item['subscription_data']['trial_days'], 'subs'),
                        $cart_item['subscription_data']['trial_days']
                    )
                );
            }
        }

        return $trial_info;
    }

    /**
     * Calculate next payment date for subscriptions
     *
     * @return string
     * @since 1.0.0
     */
    private function calculate_next_payment_date() {
        $subscription_items = $this->get_cart_subscription_items();

        if (empty($subscription_items)) {
            return '';
        }

        // Get the first subscription item to determine next payment date
        $first_item = reset($subscription_items);

        if (!isset($first_item['subscription_data'])) {
            return '';
        }

        $subscription_data = $first_item['subscription_data'];
        $start_date = current_time('timestamp');

        // If there's a trial period, add trial days
        if (isset($subscription_data['trial_days']) && $subscription_data['trial_days'] > 0) {
            $start_date += ($subscription_data['trial_days'] * DAY_IN_SECONDS);
        } else {
            // Otherwise, next payment is one interval from now
            $interval_seconds = $this->get_interval_seconds(
                $subscription_data['interval'],
                $subscription_data['interval_count']
            );
            $start_date += $interval_seconds;
        }

        return date_i18n(wc_date_format(), $start_date);
    }

    /**
     * Get interval in seconds
     *
     * @param string $interval
     * @param int $interval_count
     * @return int
     * @since 1.0.0
     */
    private function get_interval_seconds($interval, $interval_count = 1) {
        $interval_count = max(1, intval($interval_count));

        switch ($interval) {
            case 'day':
                return DAY_IN_SECONDS * $interval_count;
            case 'week':
                return WEEK_IN_SECONDS * $interval_count;
            case 'month':
                return MONTH_IN_SECONDS * $interval_count;
            case 'year':
                return YEAR_IN_SECONDS * $interval_count;
            default:
                return DAY_IN_SECONDS * $interval_count;
        }
    }

    /**
     * Get subscription supported payment gateways
     *
     * @return array
     * @since 1.0.0
     */
    private function get_subscription_supported_gateways() {
        $supported_gateways = array(
            'stripe', // Stripe gateway
            'stripe_cc', // Stripe Credit Card
        );

        // Allow filtering of supported gateways
        return apply_filters('subs_supported_payment_gateways', $supported_gateways);
    }
}
