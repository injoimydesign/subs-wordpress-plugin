<?php
/**
 * Frontend Account Integration
 *
 * Handles subscription functionality in customer account area
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
 * Subs Frontend Account Class
 *
 * @class Subs_Frontend_Account
 * @version 1.0.0
 */
class Subs_Frontend_Account {

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
        // Add subscriptions tab to my account
        add_filter('woocommerce_account_menu_items', array($this, 'add_subscriptions_tab'), 10, 1);
        add_action('woocommerce_account_subscriptions_endpoint', array($this, 'subscriptions_content'));

        // Add subscription actions endpoints
        add_action('woocommerce_account_view-subscription_endpoint', array($this, 'view_subscription_content'));
        add_action('woocommerce_account_subscription-payment-method_endpoint', array($this, 'subscription_payment_method_content'));

        // Register custom endpoints
        add_action('init', array($this, 'add_account_endpoints'));
        add_filter('woocommerce_get_query_vars', array($this, 'add_query_vars'));

        // Subscription actions
        add_action('wp_loaded', array($this, 'process_subscription_actions'), 20);

        // Account dashboard modifications
        add_action('woocommerce_account_dashboard', array($this, 'display_subscription_summary'), 5);

        // Order details modifications
        add_action('woocommerce_view_order', array($this, 'display_order_subscription_info'), 5);
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_related_subscriptions'));

        // Scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_account_scripts'));

        // AJAX handlers for account actions
        add_action('wp_ajax_subs_pause_subscription', array($this, 'ajax_pause_subscription'));
        add_action('wp_ajax_subs_resume_subscription', array($this, 'ajax_resume_subscription'));
        add_action('wp_ajax_subs_cancel_subscription', array($this, 'ajax_cancel_subscription'));
        add_action('wp_ajax_subs_update_payment_method', array($this, 'ajax_update_payment_method'));

        // Account navigation modifications
        add_filter('woocommerce_account_orders_query', array($this, 'exclude_subscription_orders_from_orders_list'), 10, 1);
    }

    /**
     * Add subscriptions tab to my account menu
     *
     * @param array $items
     * @return array
     * @since 1.0.0
     */
    public function add_subscriptions_tab($items) {
        // Insert subscriptions tab after orders
        $new_items = array();

        foreach ($items as $key => $item) {
            $new_items[$key] = $item;

            if ($key === 'orders') {
                $new_items['subscriptions'] = __('Subscriptions', 'subs');
            }
        }

        return $new_items;
    }

    /**
     * Add custom account endpoints
     *
     * @since 1.0.0
     */
    public function add_account_endpoints() {
        add_rewrite_endpoint('subscriptions', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('view-subscription', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('subscription-payment-method', EP_ROOT | EP_PAGES);

        // Flush rewrite rules on activation
        if (get_option('subs_flush_rewrite_rules') === 'yes') {
            flush_rewrite_rules();
            delete_option('subs_flush_rewrite_rules');
        }
    }

    /**
     * Add query vars
     *
     * @param array $vars
     * @return array
     * @since 1.0.0
     */
    public function add_query_vars($vars) {
        $vars[] = 'subscriptions';
        $vars[] = 'view-subscription';
        $vars[] = 'subscription-payment-method';

        return $vars;
    }

    /**
     * Display subscriptions content
     *
     * @since 1.0.0
     */
    public function subscriptions_content() {
        $current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $subscriptions_per_page = apply_filters('subs_account_subscriptions_per_page', 10);

        $customer_id = get_current_user_id();
        $subscriptions = $this->get_customer_subscriptions($customer_id, $current_page, $subscriptions_per_page);
        $total_subscriptions = $this->get_customer_subscriptions_count($customer_id);
        $total_pages = ceil($total_subscriptions / $subscriptions_per_page);

        wc_get_template(
            'myaccount/subscriptions.php',
            array(
                'subscriptions' => $subscriptions,
                'current_page' => $current_page,
                'total_pages' => $total_pages,
                'has_subscriptions' => !empty($subscriptions)
            ),
            '',
            SUBS_PLUGIN_PATH . 'templates/'
        );
    }

    /**
     * Display view subscription content
     *
     * @since 1.0.0
     */
    public function view_subscription_content() {
        global $wp;

        $subscription_id = isset($wp->query_vars['view-subscription']) ? intval($wp->query_vars['view-subscription']) : 0;

        if (!$subscription_id) {
            wc_add_notice(__('Invalid subscription.', 'subs'), 'error');
            return;
        }

        $subscription = new Subs_Subscription($subscription_id);

        if (!$subscription->exists()) {
            wc_add_notice(__('Subscription not found.', 'subs'), 'error');
            return;
        }

        // Check if user can view this subscription
        if (!$this->user_can_view_subscription($subscription)) {
            wc_add_notice(__('You do not have permission to view this subscription.', 'subs'), 'error');
            return;
        }

        $payment_history = $this->get_subscription_payment_history($subscription_id);
        $related_orders = $this->get_subscription_related_orders($subscription_id);

        wc_get_template(
            'myaccount/view-subscription.php',
            array(
                'subscription' => $subscription,
                'payment_history' => $payment_history,
                'related_orders' => $related_orders,
                'actions' => $this->get_subscription_actions($subscription)
            ),
            '',
            SUBS_PLUGIN_PATH . 'templates/'
        );
    }

    /**
     * Display subscription payment method content
     *
     * @since 1.0.0
     */
    public function subscription_payment_method_content() {
        global $wp;

        $subscription_id = isset($wp->query_vars['subscription-payment-method']) ? intval($wp->query_vars['subscription-payment-method']) : 0;

        if (!$subscription_id) {
            wc_add_notice(__('Invalid subscription.', 'subs'), 'error');
            return;
        }

        $subscription = new Subs_Subscription($subscription_id);

        if (!$subscription->exists() || !$this->user_can_manage_subscription($subscription)) {
            wc_add_notice(__('You do not have permission to manage this subscription.', 'subs'), 'error');
            return;
        }

        $available_gateways = $this->get_available_payment_methods();
        $current_payment_method = $subscription->get_payment_method();

        wc_get_template(
            'myaccount/subscription-payment-method.php',
            array(
                'subscription' => $subscription,
                'available_gateways' => $available_gateways,
                'current_payment_method' => $current_payment_method
            ),
            '',
            SUBS_PLUGIN_PATH . 'templates/'
        );
    }

    /**
     * Process subscription actions from forms
     *
     * @since 1.0.0
     */
    public function process_subscription_actions() {
        if (!isset($_POST['subs_action']) || !is_account_page()) {
            return;
        }

        // Verify nonce
        if (!wp_verify_nonce($_POST['subs_nonce'], 'subs_account_action')) {
            wc_add_notice(__('Security verification failed.', 'subs'), 'error');
            return;
        }

        $action = sanitize_text_field($_POST['subs_action']);
        $subscription_id = isset($_POST['subscription_id']) ? intval($_POST['subscription_id']) : 0;

        if (!$subscription_id) {
            wc_add_notice(__('Invalid subscription.', 'subs'), 'error');
            return;
        }

        $subscription = new Subs_Subscription($subscription_id);

        if (!$subscription->exists() || !$this->user_can_manage_subscription($subscription)) {
            wc_add_notice(__('You do not have permission to manage this subscription.', 'subs'), 'error');
            return;
        }

        switch ($action) {
            case 'pause':
                $this->process_pause_subscription($subscription);
                break;
            case 'resume':
                $this->process_resume_subscription($subscription);
                break;
            case 'cancel':
                $this->process_cancel_subscription($subscription);
                break;
            case 'update_payment_method':
                $this->process_update_payment_method($subscription);
                break;
            case 'update_shipping':
                $this->process_update_shipping_address($subscription);
                break;
            default:
                do_action('subs_process_account_action_' . $action, $subscription);
                break;
        }

        // Redirect to avoid form resubmission
        wp_safe_redirect(wc_get_account_endpoint_url('view-subscription/' . $subscription_id));
        exit;
    }

    /**
     * Display subscription summary on account dashboard
     *
     * @since 1.0.0
     */
    public function display_subscription_summary() {
        $customer_id = get_current_user_id();
        $active_subscriptions = $this->get_customer_active_subscriptions($customer_id);

        if (empty($active_subscriptions)) {
            return;
        }

        wc_get_template(
            'myaccount/dashboard-subscriptions.php',
            array(
                'subscriptions' => $active_subscriptions,
                'subscription_count' => count($active_subscriptions)
            ),
            '',
            SUBS_PLUGIN_PATH . 'templates/'
        );
    }

    /**
     * Display order subscription information
     *
     * @param int $order_id
     * @since 1.0.0
     */
    public function display_order_subscription_info($order_id) {
        $order = wc_get_order($order_id);

        if (!$order || $order->get_meta('_contains_subscription') !== 'yes') {
            return;
        }

        $related_subscriptions = $this->get_order_related_subscriptions($order_id);

        if (empty($related_subscriptions)) {
            return;
        }

        wc_get_template(
            'myaccount/order-subscription-info.php',
            array(
                'order' => $order,
                'subscriptions' => $related_subscriptions
            ),
            '',
            SUBS_PLUGIN_PATH . 'templates/'
        );
    }

    /**
     * Display related subscriptions after order table
     *
     * @param WC_Order $order
     * @since 1.0.0
     */
    public function display_related_subscriptions($order) {
        $related_subscriptions = $this->get_order_related_subscriptions($order->get_id());

        if (empty($related_subscriptions)) {
            return;
        }

        wc_get_template(
            'myaccount/order-related-subscriptions.php',
            array(
                'order' => $order,
                'subscriptions' => $related_subscriptions
            ),
            '',
            SUBS_PLUGIN_PATH . 'templates/'
        );
    }

    /**
     * Enqueue account scripts and styles
     *
     * @since 1.0.0
     */
    public function enqueue_account_scripts() {
        if (!is_account_page()) {
            return;
        }

        wp_enqueue_style(
            'subs-frontend-account',
            SUBS_PLUGIN_URL . 'assets/css/frontend-account.css',
            array(),
            SUBS_VERSION
        );

        wp_enqueue_script(
            'subs-frontend-account',
            SUBS_PLUGIN_URL . 'assets/js/frontend-account.js',
            array('jquery'),
            SUBS_VERSION,
            true
        );

        // Localize script
        wp_localize_script('subs-frontend-account', 'subs_account', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('subs_account_nonce'),
            'strings' => array(
                'confirm_pause' => __('Are you sure you want to pause this subscription?', 'subs'),
                'confirm_resume' => __('Are you sure you want to resume this subscription?', 'subs'),
                'confirm_cancel' => __('Are you sure you want to cancel this subscription? This action cannot be undone.', 'subs'),
                'processing' => __('Processing...', 'subs'),
                'error_occurred' => __('An error occurred. Please try again.', 'subs'),
            )
        ));
    }

    /**
     * AJAX handler for pausing subscription
     *
     * @since 1.0.0
     */
    public function ajax_pause_subscription() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'subs_account_nonce')) {
            wp_send_json_error(__('Security verification failed.', 'subs'));
        }

        $subscription_id = intval($_POST['subscription_id']);
        $subscription = new Subs_Subscription($subscription_id);

        if (!$subscription->exists() || !$this->user_can_manage_subscription($subscription)) {
            wp_send_json_error(__('Invalid subscription or insufficient permissions.', 'subs'));
        }

        $result = $this->process_pause_subscription($subscription, false);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'message' => __('Subscription paused successfully.', 'subs'),
            'new_status' => $subscription->get_status()
        ));
    }

    /**
     * AJAX handler for resuming subscription
     *
     * @since 1.0.0
     */
    public function ajax_resume_subscription() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'subs_account_nonce')) {
            wp_send_json_error(__('Security verification failed.', 'subs'));
        }

        $subscription_id = intval($_POST['subscription_id']);
        $subscription = new Subs_Subscription($subscription_id);

        if (!$subscription->exists() || !$this->user_can_manage_subscription($subscription)) {
            wp_send_json_error(__('Invalid subscription or insufficient permissions.', 'subs'));
        }

        $result = $this->process_resume_subscription($subscription, false);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'message' => __('Subscription resumed successfully.', 'subs'),
            'new_status' => $subscription->get_status()
        ));
    }

    /**
     * AJAX handler for canceling subscription
     *
     * @since 1.0.0
     */
    public function ajax_cancel_subscription() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'subs_account_nonce')) {
            wp_send_json_error(__('Security verification failed.', 'subs'));
        }

        $subscription_id = intval($_POST['subscription_id']);
        $subscription = new Subs_Subscription($subscription_id);

        if (!$subscription->exists() || !$this->user_can_manage_subscription($subscription)) {
            wp_send_json_error(__('Invalid subscription or insufficient permissions.', 'subs'));
        }

        $result = $this->process_cancel_subscription($subscription, false);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'message' => __('Subscription canceled successfully.', 'subs'),
            'new_status' => $subscription->get_status()
        ));
    }

    /**
     * AJAX handler for updating payment method
     *
     * @since 1.0.0
     */
    public function ajax_update_payment_method() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'subs_account_nonce')) {
            wp_send_json_error(__('Security verification failed.', 'subs'));
        }

        $subscription_id = intval($_POST['subscription_id']);
        $new_payment_method = sanitize_text_field($_POST['payment_method']);

        $subscription = new Subs_Subscription($subscription_id);

        if (!$subscription->exists() || !$this->user_can_manage_subscription($subscription)) {
            wp_send_json_error(__('Invalid subscription or insufficient permissions.', 'subs'));
        }

        $result = $this->process_update_payment_method($subscription, $new_payment_method, false);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'message' => __('Payment method updated successfully.', 'subs')
        ));
    }

    /**
     * Exclude subscription renewal orders from orders list
     *
     * @param array $args
     * @return array
     * @since 1.0.0
     */
    public function exclude_subscription_orders_from_orders_list($args) {
        // Only show parent orders, not subscription renewal orders
        $args['meta_query'] = isset($args['meta_query']) ? $args['meta_query'] : array();

        $args['meta_query'][] = array(
            'relation' => 'OR',
            array(
                'key' => '_subscription_renewal',
                'compare' => 'NOT EXISTS'
            ),
            array(
                'key' => '_subscription_renewal',
                'value' => 'yes',
                'compare' => '!='
            )
        );

        return $args;
    }

    /**
     * Get customer subscriptions with pagination
     *
     * @param int $customer_id
     * @param int $page
     * @param int $per_page
     * @return array
     * @since 1.0.0
     */
    private function get_customer_subscriptions($customer_id, $page = 1, $per_page = 10) {
        global $wpdb;

        $offset = ($page - 1) * $per_page;

        $subscription_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}subs_subscriptions
                 WHERE customer_id = %d
                 ORDER BY date_created DESC
                 LIMIT %d OFFSET %d",
                $customer_id,
                $per_page,
                $offset
            )
        );

        $subscriptions = array();
        foreach ($subscription_ids as $id) {
            $subscription = new Subs_Subscription($id);
            if ($subscription->exists()) {
                $subscriptions[] = $subscription;
            }
        }

        return $subscriptions;
    }

    /**
     * Get customer subscriptions count
     *
     * @param int $customer_id
     * @return int
     * @since 1.0.0
     */
    private function get_customer_subscriptions_count($customer_id) {
        global $wpdb;

        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(id) FROM {$wpdb->prefix}subs_subscriptions
                 WHERE customer_id = %d",
                $customer_id
            )
        );
    }

    /**
     * Get customer active subscriptions
     *
     * @param int $customer_id
     * @return array
     * @since 1.0.0
     */
    private function get_customer_active_subscriptions($customer_id) {
        global $wpdb;

        $subscription_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}subs_subscriptions
                 WHERE customer_id = %d
                 AND status IN ('active', 'trialing')
                 ORDER BY date_created DESC
                 LIMIT 5",
                $customer_id
            )
        );

        $subscriptions = array();
        foreach ($subscription_ids as $id) {
            $subscription = new Subs_Subscription($id);
            if ($subscription->exists()) {
                $subscriptions[] = $subscription;
            }
        }

        return $subscriptions;
    }

    /**
     * Get subscription payment history
     *
     * @param int $subscription_id
     * @return array
     * @since 1.0.0
     */
    private function get_subscription_payment_history($subscription_id) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}subs_payment_logs
                 WHERE subscription_id = %d
                 ORDER BY date_created DESC",
                $subscription_id
            )
        );
    }

    /**
     * Get subscription related orders
     *
     * @param int $subscription_id
     * @return array
     * @since 1.0.0
     */
    private function get_subscription_related_orders($subscription_id) {
        global $wpdb;

        $order_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT pm.post_id
                 FROM {$wpdb->postmeta} pm
                 WHERE pm.meta_key = '_subscription_id'
                 AND pm.meta_value = %d",
                $subscription_id
            )
        );

        $orders = array();
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $orders[] = $order;
            }
        }

        return $orders;
    }

    /**
     * Get order related subscriptions
     *
     * @param int $order_id
     * @return array
     * @since 1.0.0
     */
    private function get_order_related_subscriptions($order_id) {
        global $wpdb;

        $subscription_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}subs_subscriptions
                 WHERE parent_order_id = %d
                 OR id IN (
                     SELECT CAST(pm.meta_value AS UNSIGNED)
                     FROM {$wpdb->postmeta} pm
                     WHERE pm.post_id = %d
                     AND pm.meta_key = '_subscription_id'
                 )",
                $order_id,
                $order_id
            )
        );

        $subscriptions = array();
        foreach ($subscription_ids as $id) {
            $subscription = new Subs_Subscription($id);
            if ($subscription->exists()) {
                $subscriptions[] = $subscription;
            }
        }

        return $subscriptions;
    }

    /**
     * Get available subscription actions for user
     *
     * @param Subs_Subscription $subscription
     * @return array
     * @since 1.0.0
     */
    private function get_subscription_actions($subscription) {
        $actions = array();
        $status = $subscription->get_status();

        // Pause action
        if (in_array($status, array('active', 'trialing')) && $subscription->can_be_paused()) {
            $actions['pause'] = array(
                'url' => $this->get_subscription_action_url($subscription->get_id(), 'pause'),
                'name' => __('Pause', 'subs'),
                'class' => 'button subs-pause-subscription',
                'confirm' => __('Are you sure you want to pause this subscription?', 'subs')
            );
        }

        // Resume action
        if ($status === 'on-hold' && $subscription->can_be_resumed()) {
            $actions['resume'] = array(
                'url' => $this->get_subscription_action_url($subscription->get_id(), 'resume'),
                'name' => __('Resume', 'subs'),
                'class' => 'button subs-resume-subscription'
            );
        }

        // Cancel action
        if (in_array($status, array('active', 'trialing', 'on-hold')) && $subscription->can_be_cancelled()) {
            $actions['cancel'] = array(
                'url' => $this->get_subscription_action_url($subscription->get_id(), 'cancel'),
                'name' => __('Cancel', 'subs'),
                'class' => 'button subs-cancel-subscription',
                'confirm' => __('Are you sure you want to cancel this subscription? This action cannot be undone.', 'subs')
            );
        }

        // Update payment method action
        if (in_array($status, array('active', 'trialing', 'on-hold'))) {
            $actions['update_payment'] = array(
                'url' => wc_get_account_endpoint_url('subscription-payment-method/' . $subscription->get_id()),
                'name' => __('Update Payment Method', 'subs'),
                'class' => 'button'
            );
        }

        // Allow filtering of actions
        return apply_filters('subs_account_subscription_actions', $actions, $subscription);
    }

    /**
     * Get subscription action URL
     *
     * @param int $subscription_id
     * @param string $action
     * @return string
     * @since 1.0.0
     */
    private function get_subscription_action_url($subscription_id, $action) {
        return wp_nonce_url(
            add_query_arg(
                array(
                    'subs_action' => $action,
                    'subscription_id' => $subscription_id
                ),
                wc_get_account_endpoint_url('view-subscription/' . $subscription_id)
            ),
            'subs_account_action_' . $action,
            'subs_nonce'
        );
    }

    /**
     * Get available payment methods for subscriptions
     *
     * @return array
     * @since 1.0.0
     */
    private function get_available_payment_methods() {
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        $supported_gateways = apply_filters('subs_supported_payment_gateways', array('stripe'));

        $available = array();
        foreach ($gateways as $gateway_id => $gateway) {
            if (in_array($gateway_id, $supported_gateways)) {
                $available[$gateway_id] = $gateway;
            }
        }

        return $available;
    }

    /**
     * Check if user can view subscription
     *
     * @param Subs_Subscription $subscription
     * @return bool
     * @since 1.0.0
     */
    private function user_can_view_subscription($subscription) {
        $current_user_id = get_current_user_id();

        // Check if user owns the subscription
        if ($subscription->get_customer_id() === $current_user_id) {
            return true;
        }

        // Check if user has admin capabilities
        if (current_user_can('manage_subs_subscriptions')) {
            return true;
        }

        return apply_filters('subs_user_can_view_subscription', false, $subscription, $current_user_id);
    }

    /**
     * Check if user can manage subscription
     *
     * @param Subs_Subscription $subscription
     * @return bool
     * @since 1.0.0
     */
    private function user_can_manage_subscription($subscription) {
        $current_user_id = get_current_user_id();

        // Check if user owns the subscription
        if ($subscription->get_customer_id() === $current_user_id) {
            return true;
        }

        // Check if user has admin capabilities
        if (current_user_can('manage_subs_subscriptions')) {
            return true;
        }

        return apply_filters('subs_user_can_manage_subscription', false, $subscription, $current_user_id);
    }

    /**
     * Process pause subscription
     *
     * @param Subs_Subscription $subscription
     * @param bool $redirect
     * @return bool|WP_Error
     * @since 1.0.0
     */
    private function process_pause_subscription($subscription, $redirect = true) {
        if (!$subscription->can_be_paused()) {
            $error = new WP_Error('cannot_pause', __('This subscription cannot be paused.', 'subs'));

            if ($redirect) {
                wc_add_notice($error->get_error_message(), 'error');
                return false;
            }

            return $error;
        }

        $result = $subscription->pause();

        if (is_wp_error($result)) {
            if ($redirect) {
                wc_add_notice($result->get_error_message(), 'error');
                return false;
            }

            return $result;
        }

        if ($redirect) {
            wc_add_notice(__('Subscription paused successfully.', 'subs'), 'success');
        }

        return true;
    }

    /**
     * Process resume subscription
     *
     * @param Subs_Subscription $subscription
     * @param bool $redirect
     * @return bool|WP_Error
     * @since 1.0.0
     */
    private function process_resume_subscription($subscription, $redirect = true) {
        if (!$subscription->can_be_resumed()) {
            $error = new WP_Error('cannot_resume', __('This subscription cannot be resumed.', 'subs'));

            if ($redirect) {
                wc_add_notice($error->get_error_message(), 'error');
                return false;
            }

            return $error;
        }

        $result = $subscription->resume();

        if (is_wp_error($result)) {
            if ($redirect) {
                wc_add_notice($result->get_error_message(), 'error');
                return false;
            }

            return $result;
        }

        if ($redirect) {
            wc_add_notice(__('Subscription resumed successfully.', 'subs'), 'success');
        }

        return true;
    }

    /**
     * Process cancel subscription
     *
     * @param Subs_Subscription $subscription
     * @param bool $redirect
     * @return bool|WP_Error
     * @since 1.0.0
     */
    private function process_cancel_subscription($subscription, $redirect = true) {
        if (!$subscription->can_be_cancelled()) {
            $error = new WP_Error('cannot_cancel', __('This subscription cannot be canceled.', 'subs'));

            if ($redirect) {
                wc_add_notice($error->get_error_message(), 'error');
                return false;
            }

            return $error;
        }

        $result = $subscription->cancel(__('Canceled by customer from account page.', 'subs'));

        if (is_wp_error($result)) {
            if ($redirect) {
                wc_add_notice($result->get_error_message(), 'error');
                return false;
            }

            return $result;
        }

        if ($redirect) {
            wc_add_notice(__('Subscription canceled successfully.', 'subs'), 'success');
        }

        return true;
    }

    /**
     * Process update payment method
     *
     * @param Subs_Subscription $subscription
     * @param string $new_payment_method
     * @param bool $redirect
     * @return bool|WP_Error
     * @since 1.0.0
     */
    private function process_update_payment_method($subscription, $new_payment_method = null, $redirect = true) {
        if (!$new_payment_method && isset($_POST['payment_method'])) {
            $new_payment_method = sanitize_text_field($_POST['payment_method']);
        }

        if (empty($new_payment_method)) {
            $error = new WP_Error('no_payment_method', __('Please select a payment method.', 'subs'));

            if ($redirect) {
                wc_add_notice($error->get_error_message(), 'error');
                return false;
            }

            return $error;
        }

        // Validate payment method
        $available_methods = $this->get_available_payment_methods();

        if (!isset($available_methods[$new_payment_method])) {
            $error = new WP_Error('invalid_payment_method', __('Invalid payment method selected.', 'subs'));

            if ($redirect) {
                wc_add_notice($error->get_error_message(), 'error');
                return false;
            }

            return $error;
        }

        // TODO: Implement actual payment method update logic
        // This would involve:
        // 1. Validating the new payment method with the gateway
        // 2. Updating the subscription's payment method
        // 3. Updating any stored payment tokens
        // 4. Notifying the payment gateway of the change

        $result = $subscription->update_payment_method($new_payment_method);

        if (is_wp_error($result)) {
            if ($redirect) {
                wc_add_notice($result->get_error_message(), 'error');
                return false;
            }

            return $result;
        }

        if ($redirect) {
            wc_add_notice(__('Payment method updated successfully.', 'subs'), 'success');
        }

        return true;
    }

    /**
     * Process update shipping address
     *
     * @param Subs_Subscription $subscription
     * @return bool|WP_Error
     * @since 1.0.0
     */
    private function process_update_shipping_address($subscription) {
        // TODO: Implement shipping address update logic
        // This would involve:
        // 1. Validating the shipping address fields
        // 2. Updating the subscription's shipping address
        // 3. Recalculating shipping costs if necessary
        // 4. Updating future renewal orders

        wc_add_notice(__('Shipping address update functionality coming soon.', 'subs'), 'notice');

        return true;
    }
}
