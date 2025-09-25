<?php
/**
 * Frontend Controller
 *
 * Handles all frontend-facing functionality
 *
 * @package Subs
 * @version 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Subs Frontend Class
 *
 * @class Subs_Frontend
 * @version 1.0.0
 */
class Subs_Frontend {

    /**
     * Constructor
     */
    public function __construct() {
        $this->init();
    }

    /**
     * Initialize frontend functionality
     */
    private function init() {
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Product page integration
        $display_location = get_option('subs_subscription_display_location', 'after_add_to_cart');
        $this->setup_product_display_hooks($display_location);

        // Checkout integration
        add_action('woocommerce_checkout_fields', array($this, 'add_checkout_fields'));
        add_action('woocommerce_checkout_process', array($this, 'validate_checkout_fields'));
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_checkout_fields'));

        // Cart integration
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_add_to_cart'), 10, 5);
        add_filter('woocommerce_get_item_data', array($this, 'add_cart_item_data'), 10, 2);

        // Account page integration
        add_action('woocommerce_account_menu_items', array($this, 'add_account_menu_item'), 40);
        add_action('woocommerce_account_subscriptions_endpoint', array($this, 'subscriptions_endpoint_content'));
        add_filter('woocommerce_get_query_vars', array($this, 'add_query_vars'));

        // Shortcodes
        add_shortcode('subs_account', array($this, 'subscriptions_account_shortcode'));
        add_shortcode('subs_subscription', array($this, 'single_subscription_shortcode'));
        add_shortcode('subs_subscription_button', array($this, 'subscription_button_shortcode'));

        // Payment processing
        add_action('woocommerce_thankyou', array($this, 'process_subscription_after_payment'), 10, 1);
        add_action('woocommerce_order_status_completed', array($this, 'process_subscription_on_completion'), 10, 1);

        // Template overrides
        add_filter('wc_get_template', array($this, 'override_templates'), 10, 5);

        // Body classes for styling
        add_filter('body_class', array($this, 'add_body_classes'));

        // Single product modifications
        add_action('woocommerce_single_product_summary', array($this, 'maybe_modify_price_display'), 6);

        // Init rewrite rules
        add_action('init', array($this, 'add_rewrite_endpoints'));

        // AJAX handlers for logged out users
        add_action('wp_ajax_nopriv_subs_get_product_subscription_data', array($this, 'get_product_subscription_data'));
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_scripts() {
        // Only enqueue on relevant pages
        if (!$this->should_load_assets()) {
            return;
        }

        wp_enqueue_style(
            'subs-frontend',
            SUBS_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            SUBS_VERSION
        );

        wp_enqueue_script(
            'subs-frontend',
            SUBS_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            SUBS_VERSION,
            true
        );

        // Enqueue Stripe.js if needed
        if ($this->is_stripe_needed()) {
            wp_enqueue_script(
                'stripe-js',
                'https://js.stripe.com/v3/',
                array(),
                null,
                true
            );
        }

        // Localize frontend script
        $stripe = new Subs_Stripe();
        wp_localize_script('subs-frontend', 'subs_frontend', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('subs_frontend_nonce'),
            'stripe_publishable_key' => $stripe->get_publishable_key(),
            'currency' => get_woocommerce_currency(),
            'currency_symbol' => get_woocommerce_currency_symbol(),
            'strings' => array(
                'subscription_required' => __('Please select subscription options.', 'subs'),
                'processing' => __('Processing...', 'subs'),
                'error' => __('An error occurred. Please try again.', 'subs'),
                'confirm_cancel' => __('Are you sure you want to cancel this subscription?', 'subs'),
                'confirm_pause' => __('Are you sure you want to pause this subscription?', 'subs'),
                'select_subscription' => __('Please select a subscription option.', 'subs'),
                'invalid_address' => __('Please enter a valid delivery address.', 'subs'),
            )
        ));
    }

    /**
     * Check if assets should be loaded on current page
     *
     * @return bool
     */
    private function should_load_assets() {
        // Load on product pages with subscription-enabled products
        if (is_product()) {
            global $post;
            return get_post_meta($post->ID, '_subscription_enabled', true) === 'yes';
        }

        // Load on checkout and account pages
        if (is_checkout() || is_account_page()) {
            return true;
        }

        // Load on subscription management page
        if (is_page(get_option('subs_subscription_page_id'))) {
            return true;
        }

        // Load on cart page if cart contains subscription products
        if (is_cart()) {
            return $this->cart_has_subscription_products();
        }

        return false;
    }

    /**
     * Check if Stripe.js is needed
     *
     * @return bool
     */
    private function is_stripe_needed() {
        return is_checkout() || is_account_page() || is_page(get_option('subs_subscription_page_id'));
    }

    /**
     * Setup product display hooks based on admin setting
     *
     * @param string $location
     */
    private function setup_product_display_hooks($location) {
        switch ($location) {
            case 'before_add_to_cart':
                add_action('woocommerce_single_product_summary', array($this, 'display_subscription_options'), 25);
                break;

            case 'after_add_to_cart':
                add_action('woocommerce_single_product_summary', array($this, 'display_subscription_options'), 35);
                break;

            case 'product_tabs':
                add_filter('woocommerce_product_tabs', array($this, 'add_subscription_tab'));
                break;

            case 'checkout_only':
                // Don't display on product page, only at checkout
                break;
        }
    }

    /**
     * Display subscription options on product page
     */
    public function display_subscription_options() {
        global $product;

        if (!$product || get_post_meta($product->get_id(), '_subscription_enabled', true) !== 'yes') {
            return;
        }

        $subscription_data = array(
            'period' => get_post_meta($product->get_id(), '_subscription_period', true) ?: 'month',
            'interval' => get_post_meta($product->get_id(), '_subscription_period_interval', true) ?: 1,
            'trial_period' => get_post_meta($product->get_id(), '_subscription_trial_period', true) ?: 0,
        );

        // Calculate subscription pricing
        $base_price = $product->get_price();
        $stripe = new Subs_Stripe();
        $stripe_fee = $stripe->calculate_stripe_fee($base_price);
        $total_price = $base_price + $stripe_fee;

        $this->load_template('single-product/subscription-options.php', array(
            'product' => $product,
            'subscription_data' => $subscription_data,
            'base_price' => $base_price,
            'stripe_fee' => $stripe_fee,
            'total_price' => $total_price,
            'stripe_fee_enabled' => 'yes' === get_option('subs_pass_stripe_fees', 'no'),
        ));
    }

    /**
     * Add subscription tab to product tabs
     *
     * @param array $tabs
     * @return array
     */
    public function add_subscription_tab($tabs) {
        global $product;

        if (!$product || get_post_meta($product->get_id(), '_subscription_enabled', true) !== 'yes') {
            return $tabs;
        }

        $tabs['subscription'] = array(
            'title'    => __('Subscription', 'subs'),
            'priority' => 50,
            'callback' => array($this, 'subscription_tab_content')
        );

        return $tabs;
    }

    /**
     * Subscription tab content
     */
    public function subscription_tab_content() {
        global $product;

        $subscription_data = array(
            'period' => get_post_meta($product->get_id(), '_subscription_period', true) ?: 'month',
            'interval' => get_post_meta($product->get_id(), '_subscription_period_interval', true) ?: 1,
            'trial_period' => get_post_meta($product->get_id(), '_subscription_trial_period', true) ?: 0,
        );

        $this->load_template('product/subscription-tab.php', array(
            'product' => $product,
            'subscription_data' => $subscription_data,
        ));
    }

    /**
     * Validate add to cart for subscription products
     *
     * @param bool $passed
     * @param int $product_id
     * @param int $quantity
     * @param int $variation_id
     * @param array $variations
     * @return bool
     */
    public function validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0, $variations = array()) {
        $product = wc_get_product($product_id);

        if (!$product || get_post_meta($product_id, '_subscription_enabled', true) !== 'yes') {
            return $passed;
        }

        // Check if subscription data is provided when required
        $subscription_enabled = isset($_POST['subscription_enabled']) ? $_POST['subscription_enabled'] : '';

        if ($subscription_enabled === '1') {
            // Validate subscription-specific data if needed
            $flag_address = isset($_POST['subscription_flag_address']) ? sanitize_textarea_field($_POST['subscription_flag_address']) : '';

            if (empty($flag_address)) {
                wc_add_notice(__('Please provide a delivery address for your subscription.', 'subs'), 'error');
                return false;
            }
        }

        return $passed;
    }

    /**
     * Add subscription data to cart items
     *
     * @param array $item_data
     * @param array $cart_item_data
     * @return array
     */
    public function add_cart_item_data($item_data, $cart_item_data) {
        if (isset($cart_item_data['subscription_enabled']) && $cart_item_data['subscription_enabled'] === '1') {
            $item_data[] = array(
                'key'   => __('Subscription', 'subs'),
                'value' => __('Yes', 'subs'),
                'display' => '',
            );

            if (!empty($cart_item_data['subscription_billing_period'])) {
                $period = $cart_item_data['subscription_billing_period'];
                $interval = isset($cart_item_data['subscription_billing_interval']) ?
                    intval($cart_item_data['subscription_billing_interval']) : 1;

                $item_data[] = array(
                    'key'   => __('Billing', 'subs'),
                    'value' => $this->get_billing_period_text($period, $interval),
                    'display' => '',
                );
            }

            if (!empty($cart_item_data['subscription_flag_address'])) {
                $item_data[] = array(
                    'key'   => __('Delivery Address', 'subs'),
                    'value' => wp_kses_post(nl2br($cart_item_data['subscription_flag_address'])),
                    'display' => '',
                );
            }
        }

        return $item_data;
    }

    /**
     * Add checkout fields for subscription
     *
     * @param array $fields
     * @return array
     */
    public function add_checkout_fields($fields) {
        // Only add if cart contains subscription products
        if (!$this->cart_has_subscription_products()) {
            return $fields;
        }

        $fields['billing']['flag_delivery_address'] = array(
            'type'        => 'textarea',
            'label'       => __('Flag Delivery Address', 'subs'),
            'placeholder' => __('Enter the address where flags should be delivered...', 'subs'),
            'required'    => true,
            'class'       => array('form-row-wide'),
            'priority'    => 110,
        );

        return $fields;
    }

    /**
     * Validate checkout fields
     */
    public function validate_checkout_fields() {
        if ($this->cart_has_subscription_products()) {
            if (empty($_POST['flag_delivery_address'])) {
                wc_add_notice(__('Flag delivery address is required for subscription orders.', 'subs'), 'error');
            }
        }
    }

    /**
     * Save checkout fields to order
     *
     * @param int $order_id
     */
    public function save_checkout_fields($order_id) {
        if (!$this->cart_has_subscription_products()) {
            return;
        }

        if (!empty($_POST['flag_delivery_address'])) {
            update_post_meta($order_id, '_flag_delivery_address', sanitize_textarea_field($_POST['flag_delivery_address']));
        }

        // Mark as subscription order
        update_post_meta($order_id, '_is_subscription_order', 'yes');

        // Save subscription preferences from cart items
        $cart = WC()->cart->get_cart();
        foreach ($cart as $cart_item_key => $cart_item) {
            if (isset($cart_item['subscription_enabled']) && $cart_item['subscription_enabled'] === '1') {
                update_post_meta($order_id, '_subscription_enabled', 'yes');

                if (isset($cart_item['subscription_billing_period'])) {
                    update_post_meta($order_id, '_subscription_billing_period', $cart_item['subscription_billing_period']);
                }

                if (isset($cart_item['subscription_billing_interval'])) {
                    update_post_meta($order_id, '_subscription_billing_interval', $cart_item['subscription_billing_interval']);
                }

                if (isset($cart_item['subscription_trial_days'])) {
                    update_post_meta($order_id, '_subscription_trial_days', $cart_item['subscription_trial_days']);
                }

                break; // Only need to save once per order
            }
        }
    }

    /**
     * Check if cart contains subscription products
     *
     * @return bool
     */
    private function cart_has_subscription_products() {
        if (!WC()->cart) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            if (get_post_meta($product_id, '_subscription_enabled', true) === 'yes') {
                return true;
            }

            // Also check if cart item has subscription data
            if (isset($cart_item['subscription_enabled']) && $cart_item['subscription_enabled'] === '1') {
                return true;
            }
        }

        return false;
    }

    /**
     * Add account menu item
     *
     * @param array $items
     * @return array
     */
    public function add_account_menu_item($items) {
        $logout = $items['customer-logout'];
        unset($items['customer-logout']);

        $items['subscriptions'] = __('Subscriptions', 'subs');
        $items['customer-logout'] = $logout;

        return $items;
    }

    /**
     * Add query vars for account endpoint
     *
     * @param array $vars
     * @return array
     */
    public function add_query_vars($vars) {
        $vars['subscriptions'] = get_option('subs_subscription_page_endpoint', 'subscriptions');
        return $vars;
    }

    /**
     * Add rewrite endpoints
     */
    public function add_rewrite_endpoints() {
        $endpoint = get_option('subs_subscription_page_endpoint', 'subscriptions');
        add_rewrite_endpoint($endpoint, EP_ROOT | EP_PAGES);
    }

    /**
     * Subscriptions endpoint content
     */
    public function subscriptions_endpoint_content() {
        $this->subscriptions_account_shortcode();
    }

    /**
     * Subscriptions account shortcode
     *
     * @param array $atts
     * @return string
     */
    public function subscriptions_account_shortcode($atts = array()) {
        if (!is_user_logged_in()) {
            return '<p>' . sprintf(
                __('You must be <a href="%s">logged in</a> to view subscriptions.', 'subs'),
                esc_url(wc_get_page_permalink('myaccount'))
            ) . '</p>';
        }

        $user_id = get_current_user_id();
        $subscriptions = $this->get_user_subscriptions($user_id);

        ob_start();
        $this->load_template('account/subscriptions.php', array(
            'subscriptions' => $subscriptions,
            'user_id' => $user_id,
        ));
        return ob_get_clean();
    }

    /**
     * Single subscription shortcode
     *
     * @param array $atts
     * @return string
     */
    public function single_subscription_shortcode($atts = array()) {
        $atts = shortcode_atts(array(
            'id' => 0,
        ), $atts);

        if (!is_user_logged_in()) {
            return '<p>' . __('You must be logged in to view subscription details.', 'subs') . '</p>';
        }

        $subscription_id = absint($atts['id']);
        if (!$subscription_id) {
            return '<p>' . __('Invalid subscription ID.', 'subs') . '</p>';
        }

        $subscription = new Subs_Subscription($subscription_id);

        if (!$subscription->get_id() || $subscription->get_customer_id() !== get_current_user_id()) {
            return '<p>' . __('Subscription not found.', 'subs') . '</p>';
        }

        ob_start();
        $this->load_template('account/single-subscription.php', array(
            'subscription' => $subscription,
        ));
        return ob_get_clean();
    }

    /**
     * Subscription button shortcode
     *
     * @param array $atts
     * @return string
     */
    public function subscription_button_shortcode($atts = array()) {
        $atts = shortcode_atts(array(
            'product_id' => 0,
            'text' => __('Subscribe Now', 'subs'),
            'class' => 'subs-subscription-button',
        ), $atts);

        $product_id = absint($atts['product_id']);
        if (!$product_id) {
            global $product;
            if ($product && $product->get_id()) {
                $product_id = $product->get_id();
            }
        }

        if (!$product_id || get_post_meta($product_id, '_subscription_enabled', true) !== 'yes') {
            return '';
        }

        $product_url = get_permalink($product_id);

        return sprintf(
            '<a href="%s" class="%s">%s</a>',
            esc_url($product_url),
            esc_attr($atts['class']),
            esc_html($atts['text'])
        );
    }

    /**
     * Get user subscriptions
     *
     * @param int $user_id
     * @return array
     */
    private function get_user_subscriptions($user_id) {
        global $wpdb;

        $subscription_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}subs_subscriptions
                 WHERE customer_id = %d
                 ORDER BY date_created DESC",
                $user_id
            )
        );

        $subscriptions = array();
        foreach ($subscription_ids as $id) {
            $subscriptions[] = new Subs_Subscription($id);
        }

        return $subscriptions;
    }

    /**
     * Process subscription after successful payment
     *
     * @param int $order_id
     */
    public function process_subscription_after_payment($order_id) {
        $order = wc_get_order($order_id);

        if (!$order || $order->get_meta('_is_subscription_order') !== 'yes') {
            return;
        }

        // Only process if payment was successful
        if (!$order->is_paid()) {
            return;
        }

        $this->create_subscription_from_order($order);
    }

    /**
     * Process subscription when order is completed
     *
     * @param int $order_id
     */
    public function process_subscription_on_completion($order_id) {
        $this->process_subscription_after_payment($order_id);
    }

    /**
     * Create subscription from order
     *
     * @param WC_Order $order
     * @return bool|WP_Error
     */
    private function create_subscription_from_order($order) {
        // Check if already processed
        if ($order->get_meta('_subscription_processed') === 'yes') {
            return false;
        }

        $stripe = new Subs_Stripe();
        $result = $stripe->process_subscription_order($order->get_id(), $order);

        if (is_wp_error($result)) {
            // Log error but don't show to customer on thank you page
            error_log('Subs: Failed to process subscription for order ' . $order->get_id() . ': ' . $result->get_error_message());
            return $result;
        }

        // Mark as processed
        $order->update_meta_data('_subscription_processed', 'yes');
        $order->save();

        return true;
    }

    /**
     * Override WooCommerce templates
     *
     * @param string $template
     * @param string $template_name
     * @param array $args
     * @param string $template_path
     * @param string $default_path
     * @return string
     */
    public function override_templates($template, $template_name, $args, $template_path, $default_path) {
        // Check for subscription-related templates in our plugin
        $plugin_template_path = SUBS_PLUGIN_PATH . 'templates/';

        if (file_exists($plugin_template_path . $template_name)) {
            return $plugin_template_path . $template_name;
        }

        return $template;
    }

    /**
     * Add body classes for subscription pages
     *
     * @param array $classes
     * @return array
     */
    public function add_body_classes($classes) {
        if (is_product()) {
            global $post;
            if (get_post_meta($post->ID, '_subscription_enabled', true) === 'yes') {
                $classes[] = 'subs-product-page';
            }
        }

        if (is_account_page() && isset($GLOBALS['wp_query']->query_vars['subscriptions'])) {
            $classes[] = 'subs-account-page';
        }

        if ($this->cart_has_subscription_products()) {
            $classes[] = 'subs-has-subscription';
        }

        return $classes;
    }

    /**
     * Modify price display for subscription products
     */
    public function maybe_modify_price_display() {
        global $product;

        if (!$product || get_post_meta($product->get_id(), '_subscription_enabled', true) !== 'yes') {
            return;
        }

        // Add subscription pricing info after the price
        add_action('woocommerce_single_product_summary', array($this, 'add_subscription_price_info'), 11);
    }

    /**
     * Add subscription price information
     */
    public function add_subscription_price_info() {
        global $product;

        $period = get_post_meta($product->get_id(), '_subscription_period', true) ?: 'month';
        $interval = get_post_meta($product->get_id(), '_subscription_period_interval', true) ?: 1;
        $trial_period = get_post_meta($product->get_id(), '_subscription_trial_period', true) ?: 0;

        $billing_text = $this->get_billing_period_text($period, $interval);

        echo '<div class="subs-price-suffix">';
        echo '<span class="subs-billing-period">' . esc_html($billing_text) . '</span>';

        if ($trial_period > 0) {
            echo '<span class="subs-trial-info">' .
                 sprintf(_n('with %d day free trial', 'with %d days free trial', $trial_period, 'subs'), $trial_period) .
                 '</span>';
        }
        echo '</div>';
    }

    /**
     * Load template file
     *
     * @param string $template_name
     * @param array $args
     * @param string $template_path
     * @return void
     */
    public function load_template($template_name, $args = array(), $template_path = '') {
        if ($args && is_array($args)) {
            extract($args);
        }

        $located = $this->locate_template($template_name, $template_path);

        if (!file_exists($located)) {
            _doing_it_wrong(__FUNCTION__, sprintf(__('%s does not exist.', 'subs'), '<code>' . $located . '</code>'), '1.0.0');
            return;
        }

        do_action('subs_before_template_part', $template_name, $template_path, $located, $args);

        include $located;

        do_action('subs_after_template_part', $template_name, $template_path, $located, $args);
    }

    /**
     * Locate a template file
     *
     * @param string $template_name
     * @param string $template_path
     * @return string
     */
    public function locate_template($template_name, $template_path = '') {
        if (!$template_path) {
            $template_path = 'subs/';
        }

        // Look in theme first
        $template = locate_template(array(
            trailingslashit($template_path) . $template_name,
            $template_name
        ));

        // Get default template
        if (!$template) {
            $template = SUBS_PLUGIN_PATH . 'templates/' . $template_name;
        }

        return apply_filters('subs_locate_template', $template, $template_name, $template_path);
    }

    /**
     * Get template HTML
     *
     * @param string $template_name
     * @param array $args
     * @return string
     */
    public function get_template_html($template_name, $args = array()) {
        ob_start();
        $this->load_template($template_name, $args);
        return ob_get_clean();
    }

    /**
     * Get billing period text
     *
     * @param string $period
     * @param int $interval
     * @return string
     */
    private function get_billing_period_text($period, $interval) {
        $periods = array(
            'day'   => _n('day', 'days', $interval, 'subs'),
            'week'  => _n('week', 'weeks', $interval, 'subs'),
            'month' => _n('month', 'months', $interval, 'subs'),
            'year'  => _n('year', 'years', $interval, 'subs'),
        );

        $period_text = isset($periods[$period]) ? $periods[$period] : $period;

        if ($interval == 1) {
            return sprintf(__('every %s', 'subs'), $period_text);
        } else {
            return sprintf(__('every %d %s', 'subs'), $interval, $period_text);
        }
    }

    /**
     * Get product subscription data (AJAX)
     */
    public function get_product_subscription_data() {
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

        if (!$product_id) {
            wp_send_json_error(__('Invalid product ID.', 'subs'));
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(__('Product not found.', 'subs'));
        }

        // Check if subscription is enabled
        if (get_post_meta($product_id, '_subscription_enabled', true) !== 'yes') {
            wp_send_json_error(__('Subscription is not available for this product.', 'subs'));
        }

        $subscription_data = array(
            'enabled' => true,
            'period' => get_post_meta($product_id, '_subscription_period', true) ?: 'month',
            'interval' => get_post_meta($product_id, '_subscription_period_interval', true) ?: 1,
            'trial_period' => get_post_meta($product_id, '_subscription_trial_period', true) ?: 0,
            'base_price' => $product->get_price(),
        );

        $stripe = new Subs_Stripe();
        $subscription_data['stripe_fee'] = $stripe->calculate_stripe_fee($subscription_data['base_price']);
        $subscription_data['total_price'] = $subscription_data['base_price'] + $subscription_data['stripe_fee'];
        $subscription_data['billing_text'] = $this->get_billing_period_text(
            $subscription_data['period'],
            $subscription_data['interval']
        );

        // Format prices
        $subscription_data['base_price_formatted'] = wc_price($subscription_data['base_price']);
        $subscription_data['stripe_fee_formatted'] = wc_price($subscription_data['stripe_fee']);
        $subscription_data['total_price_formatted'] = wc_price($subscription_data['total_price']);

        wp_send_json_success($subscription_data);
    }
}
