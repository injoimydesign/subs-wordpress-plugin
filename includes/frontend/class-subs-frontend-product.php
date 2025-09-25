<?php
/**
 * Frontend Product Integration
 *
 * Handles subscription functionality on product pages
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
 * Subs Frontend Product Class
 *
 * @class Subs_Frontend_Product
 * @version 1.0.0
 */
class Subs_Frontend_Product {

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
        // Product page modifications
        add_action('woocommerce_single_product_summary', array($this, 'display_subscription_options'), 25);
        add_action('woocommerce_before_add_to_cart_button', array($this, 'display_subscription_fields'));

        // Price display modifications
        add_filter('woocommerce_get_price_html', array($this, 'modify_price_display'), 10, 2);
        add_filter('woocommerce_product_get_price', array($this, 'get_subscription_price'), 10, 2);

        // Add to cart modifications
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_add_to_cart'), 10, 3);
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_subscription_cart_data'), 10, 3);

        // Cart item display
        add_filter('woocommerce_cart_item_name', array($this, 'display_cart_subscription_info'), 10, 3);
        add_action('woocommerce_after_cart_item_name', array($this, 'display_cart_subscription_details'), 10, 2);

        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // AJAX handlers for subscription options
        add_action('wp_ajax_subs_get_subscription_price', array($this, 'ajax_get_subscription_price'));
        add_action('wp_ajax_nopriv_subs_get_subscription_price', array($this, 'ajax_get_subscription_price'));
    }

    /**
     * Display subscription options on product page
     *
     * @since 1.0.0
     */
    public function display_subscription_options() {
        global $product;

        if (!$this->is_subscription_product($product)) {
            return;
        }

        $subscription_options = $this->get_product_subscription_options($product->get_id());

        if (empty($subscription_options)) {
            return;
        }

        wc_get_template(
            'single-product/subscription-options.php',
            array(
                'product' => $product,
                'subscription_options' => $subscription_options
            ),
            '',
            SUBS_PLUGIN_PATH . 'templates/'
        );
    }

    /**
     * Display subscription fields before add to cart button
     *
     * @since 1.0.0
     */
    public function display_subscription_fields() {
        global $product;

        if (!$this->is_subscription_product($product)) {
            return;
        }

        $subscription_plans = $this->get_product_subscription_plans($product->get_id());
        $default_plan = $this->get_default_subscription_plan($product->get_id());

        if (empty($subscription_plans)) {
            return;
        }

        wc_get_template(
            'single-product/subscription-fields.php',
            array(
                'product' => $product,
                'subscription_plans' => $subscription_plans,
                'default_plan' => $default_plan
            ),
            '',
            SUBS_PLUGIN_PATH . 'templates/'
        );
    }

    /**
     * Modify price display for subscription products
     *
     * @param string $price_html
     * @param WC_Product $product
     * @return string
     * @since 1.0.0
     */
    public function modify_price_display($price_html, $product) {
        if (!$this->is_subscription_product($product)) {
            return $price_html;
        }

        $subscription_plans = $this->get_product_subscription_plans($product->get_id());

        if (empty($subscription_plans)) {
            return $price_html;
        }

        // Get the default or first plan for display
        $default_plan = $this->get_default_subscription_plan($product->get_id());
        $display_plan = $default_plan ? $default_plan : reset($subscription_plans);

        $subscription_price_html = $this->format_subscription_price_html($product, $display_plan);

        // Allow filtering of subscription price HTML
        return apply_filters('subs_product_price_html', $subscription_price_html, $product, $display_plan);
    }

    /**
     * Get subscription price for product
     *
     * @param string $price
     * @param WC_Product $product
     * @return string
     * @since 1.0.0
     */
    public function get_subscription_price($price, $product) {
        if (!$this->is_subscription_product($product)) {
            return $price;
        }

        $default_plan = $this->get_default_subscription_plan($product->get_id());

        if ($default_plan && isset($default_plan['price'])) {
            return $default_plan['price'];
        }

        return $price;
    }

    /**
     * Validate add to cart for subscription products
     *
     * @param bool $passed
     * @param int $product_id
     * @param int $quantity
     * @return bool
     * @since 1.0.0
     */
    public function validate_add_to_cart($passed, $product_id, $quantity) {
        $product = wc_get_product($product_id);

        if (!$this->is_subscription_product($product)) {
            return $passed;
        }

        // Validate subscription plan selection
        if (!isset($_POST['subscription_plan']) || empty($_POST['subscription_plan'])) {
            wc_add_notice(__('Please select a subscription plan.', 'subs'), 'error');
            return false;
        }

        // Validate subscription plan exists
        $subscription_plans = $this->get_product_subscription_plans($product_id);
        $selected_plan = sanitize_text_field($_POST['subscription_plan']);

        if (!isset($subscription_plans[$selected_plan])) {
            wc_add_notice(__('Invalid subscription plan selected.', 'subs'), 'error');
            return false;
        }

        // Check if customer already has active subscription for this product
        if ($this->customer_has_active_subscription($product_id)) {
            wc_add_notice(__('You already have an active subscription for this product.', 'subs'), 'error');
            return false;
        }

        // TODO: Add additional validation for subscription-specific rules
        // - Check inventory for subscription products
        // - Validate subscription quantities
        // - Check customer subscription limits

        return $passed;
    }

    /**
     * Add subscription data to cart item
     *
     * @param array $cart_item_data
     * @param int $product_id
     * @param int $variation_id
     * @return array
     * @since 1.0.0
     */
    public function add_subscription_cart_data($cart_item_data, $product_id, $variation_id) {
        $product = wc_get_product($product_id);

        if (!$this->is_subscription_product($product)) {
            return $cart_item_data;
        }

        if (isset($_POST['subscription_plan']) && !empty($_POST['subscription_plan'])) {
            $selected_plan = sanitize_text_field($_POST['subscription_plan']);
            $subscription_plans = $this->get_product_subscription_plans($product_id);

            if (isset($subscription_plans[$selected_plan])) {
                $cart_item_data['subscription_plan'] = $selected_plan;
                $cart_item_data['subscription_data'] = $subscription_plans[$selected_plan];
                $cart_item_data['is_subscription'] = true;

                // Set unique cart item key to prevent consolidation
                $cart_item_data['unique_key'] = md5(microtime() . rand());
            }
        }

        return $cart_item_data;
    }

    /**
     * Display subscription info in cart item name
     *
     * @param string $item_name
     * @param array $cart_item
     * @param string $cart_item_key
     * @return string
     * @since 1.0.0
     */
    public function display_cart_subscription_info($item_name, $cart_item, $cart_item_key) {
        if (!isset($cart_item['is_subscription']) || !$cart_item['is_subscription']) {
            return $item_name;
        }

        $subscription_label = sprintf(
            '<span class="subs-cart-label">%s</span>',
            __('Subscription', 'subs')
        );

        return $item_name . ' ' . $subscription_label;
    }

    /**
     * Display subscription details after cart item name
     *
     * @param array $cart_item
     * @param string $cart_item_key
     * @since 1.0.0
     */
    public function display_cart_subscription_details($cart_item, $cart_item_key) {
        if (!isset($cart_item['is_subscription']) || !$cart_item['is_subscription']) {
            return;
        }

        if (!isset($cart_item['subscription_data'])) {
            return;
        }

        $subscription_data = $cart_item['subscription_data'];

        echo '<div class="subs-cart-details">';

        if (isset($subscription_data['interval']) && isset($subscription_data['interval_count'])) {
            $interval_text = $this->format_billing_period(
                $subscription_data['interval'],
                $subscription_data['interval_count']
            );

            printf(
                '<small class="subs-billing-period">%s: %s</small>',
                __('Billing', 'subs'),
                esc_html($interval_text)
            );
        }

        if (isset($subscription_data['trial_days']) && $subscription_data['trial_days'] > 0) {
            printf(
                '<br><small class="subs-trial-period">%s: %d %s</small>',
                __('Trial Period', 'subs'),
                intval($subscription_data['trial_days']),
                _n('day', 'days', $subscription_data['trial_days'], 'subs')
            );
        }

        echo '</div>';
    }

    /**
     * Enqueue frontend scripts and styles
     *
     * @since 1.0.0
     */
    public function enqueue_scripts() {
        if (!is_product()) {
            return;
        }

        global $product;

        if (!$this->is_subscription_product($product)) {
            return;
        }

        wp_enqueue_style(
            'subs-frontend-product',
            SUBS_PLUGIN_URL . 'assets/css/frontend-product.css',
            array(),
            SUBS_VERSION
        );

        wp_enqueue_script(
            'subs-frontend-product',
            SUBS_PLUGIN_URL . 'assets/js/frontend-product.js',
            array('jquery'),
            SUBS_VERSION,
            true
        );

        // Localize script
        wp_localize_script('subs-frontend-product', 'subs_product', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('subs_product_nonce'),
            'strings' => array(
                'select_plan' => __('Please select a subscription plan', 'subs'),
                'loading' => __('Loading...', 'subs'),
            )
        ));
    }

    /**
     * AJAX handler for getting subscription price
     *
     * @since 1.0.0
     */
    public function ajax_get_subscription_price() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'subs_product_nonce')) {
            wp_die(__('Security check failed', 'subs'));
        }

        $product_id = intval($_POST['product_id']);
        $plan_id = sanitize_text_field($_POST['plan_id']);

        $product = wc_get_product($product_id);

        if (!$product || !$this->is_subscription_product($product)) {
            wp_send_json_error(__('Invalid product', 'subs'));
        }

        $subscription_plans = $this->get_product_subscription_plans($product_id);

        if (!isset($subscription_plans[$plan_id])) {
            wp_send_json_error(__('Invalid subscription plan', 'subs'));
        }

        $plan = $subscription_plans[$plan_id];
        $price_html = $this->format_subscription_price_html($product, $plan);

        wp_send_json_success(array(
            'price_html' => $price_html,
            'price' => $plan['price']
        ));
    }

    /**
     * Check if product is a subscription product
     *
     * @param WC_Product $product
     * @return bool
     * @since 1.0.0
     */
    private function is_subscription_product($product) {
        if (!$product || !is_a($product, 'WC_Product')) {
            return false;
        }

        $is_subscription = $product->get_meta('_subs_is_subscription');

        return $is_subscription === 'yes';
    }

    /**
     * Get product subscription plans
     *
     * @param int $product_id
     * @return array
     * @since 1.0.0
     */
    private function get_product_subscription_plans($product_id) {
        $plans = get_post_meta($product_id, '_subs_subscription_plans', true);

        if (!is_array($plans)) {
            return array();
        }

        // Apply filters to allow modification of plans
        return apply_filters('subs_product_subscription_plans', $plans, $product_id);
    }

    /**
     * Get product subscription options for display
     *
     * @param int $product_id
     * @return array
     * @since 1.0.0
     */
    private function get_product_subscription_options($product_id) {
        $options = get_post_meta($product_id, '_subs_subscription_options', true);

        if (!is_array($options)) {
            return array();
        }

        return apply_filters('subs_product_subscription_options', $options, $product_id);
    }

    /**
     * Get default subscription plan for product
     *
     * @param int $product_id
     * @return array|null
     * @since 1.0.0
     */
    private function get_default_subscription_plan($product_id) {
        $plans = $this->get_product_subscription_plans($product_id);
        $default_plan_id = get_post_meta($product_id, '_subs_default_plan', true);

        if ($default_plan_id && isset($plans[$default_plan_id])) {
            return $plans[$default_plan_id];
        }

        // Return first plan if no default set
        return !empty($plans) ? reset($plans) : null;
    }

    /**
     * Format subscription price HTML
     *
     * @param WC_Product $product
     * @param array $plan
     * @return string
     * @since 1.0.0
     */
    private function format_subscription_price_html($product, $plan) {
        $price = wc_price($plan['price']);

        $billing_period = $this->format_billing_period(
            $plan['interval'],
            $plan['interval_count']
        );

        $price_html = sprintf(
            '%s <span class="subs-billing-period">/ %s</span>',
            $price,
            $billing_period
        );

        // Add trial information if available
        if (isset($plan['trial_days']) && $plan['trial_days'] > 0) {
            $trial_text = sprintf(
                _n('%d day free trial', '%d days free trial', $plan['trial_days'], 'subs'),
                $plan['trial_days']
            );

            $price_html .= sprintf(
                ' <span class="subs-trial-info">(%s)</span>',
                $trial_text
            );
        }

        return $price_html;
    }

    /**
     * Format billing period text
     *
     * @param string $interval
     * @param int $interval_count
     * @return string
     * @since 1.0.0
     */
    private function format_billing_period($interval, $interval_count = 1) {
        $interval_count = max(1, intval($interval_count));

        $intervals = array(
            'day' => _n('day', 'days', $interval_count, 'subs'),
            'week' => _n('week', 'weeks', $interval_count, 'subs'),
            'month' => _n('month', 'months', $interval_count, 'subs'),
            'year' => _n('year', 'years', $interval_count, 'subs'),
        );

        if (!isset($intervals[$interval])) {
            return $interval;
        }

        if ($interval_count === 1) {
            return $intervals[$interval];
        }

        return sprintf('%d %s', $interval_count, $intervals[$interval]);
    }

    /**
     * Check if customer has active subscription for product
     *
     * @param int $product_id
     * @return bool
     * @since 1.0.0
     */
    private function customer_has_active_subscription($product_id) {
        if (!is_user_logged_in()) {
            return false;
        }

        global $wpdb;

        $customer_id = get_current_user_id();

        $active_subscription = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}subs_subscriptions
                 WHERE customer_id = %d
                 AND product_id = %d
                 AND status IN ('active', 'trialing')
                 LIMIT 1",
                $customer_id,
                $product_id
            )
        );

        return !empty($active_subscription);
    }
}
