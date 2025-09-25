<?php
/**
 * AJAX Handler Class
 *
 * Handles all AJAX requests for frontend and admin
 *
 * @package Subs
 * @version 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Subs AJAX Class
 *
 * @class Subs_Ajax
 * @version 1.0.0
 */
class Subs_Ajax {

    /**
     * Constructor
     */
    public function __construct() {
        $this->init();
    }

    /**
     * Initialize AJAX handlers
     */
    private function init() {
        // Frontend AJAX actions
        $frontend_actions = array(
            'calculate_subscription_price',
            'pause_subscription',
            'resume_subscription',
            'cancel_subscription',
            'update_payment_method',
            'get_subscription_details',
            'update_flag_address',
        );

        foreach ($frontend_actions as $action) {
            add_action('wp_ajax_subs_' . $action, array($this, $action));
            add_action('wp_ajax_nopriv_subs_' . $action, array($this, 'ajax_login_required'));
        }

        // Admin AJAX actions (already handled in admin class, but we can add more here)
        $admin_actions = array(
            'get_subscription_history',
            'add_subscription_note',
            'bulk_subscription_action',
        );

        foreach ($admin_actions as $action) {
            add_action('wp_ajax_subs_admin_' . $action, array($this, 'admin_' . $action));
        }

        // Public AJAX actions (no login required)
        add_action('wp_ajax_subs_get_product_subscription_data', array($this, 'get_product_subscription_data'));
        add_action('wp_ajax_nopriv_subs_get_product_subscription_data', array($this, 'get_product_subscription_data'));
    }

    /**
     * Handle login required response
     */
    public function ajax_login_required() {
        wp_send_json_error(array(
            'message' => __('You must be logged in to perform this action.', 'subs'),
            'login_required' => true
        ));
    }

    /**
     * Calculate subscription price with fees
     */
    public function calculate_subscription_price() {
        check_ajax_referer('subs_frontend_nonce', 'nonce');

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $quantity = isset($_POST['quantity']) ? absint($_POST['quantity']) : 1;

        if (!$product_id) {
            wp_send_json_error(__('Invalid product ID.', 'subs'));
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(__('Product not found.', 'subs'));
        }

        // Check if subscription is enabled for this product
        if (get_post_meta($product_id, '_subscription_enabled', true) !== 'yes') {
            wp_send_json_error(__('Subscription is not available for this product.', 'subs'));
        }

        $base_price = $product->get_price() * $quantity;

        $stripe = new Subs_Stripe();
        $stripe_fee = 0;

        if ('yes' === get_option('subs_pass_stripe_fees', 'no')) {
            $stripe_fee = $stripe->calculate_stripe_fee($base_price);
        }

        $total_price = $base_price + $stripe_fee;

        // Get billing period info
        $period = get_post_meta($product_id, '_subscription_period', true) ?: 'month';
        $interval = get_post_meta($product_id, '_subscription_period_interval', true) ?: 1;

        $response = array(
            'base_price' => $base_price,
            'base_price_formatted' => wc_price($base_price),
            'stripe_fee' => $stripe_fee,
            'stripe_fee_formatted' => wc_price($stripe_fee),
            'total_price' => $total_price,
            'total_price_formatted' => wc_price($total_price),
            'billing_period' => $period,
            'billing_interval' => $interval,
            'billing_text' => $this->get_billing_period_text($period, $interval),
            'currency' => get_woocommerce_currency(),
        );

        wp_send_json_success($response);
    }

    /**
     * Pause user subscription
     */
    public function pause_subscription() {
        check_ajax_referer('subs_frontend_nonce', 'nonce');

        $subscription_id = isset($_POST['subscription_id']) ? absint($_POST['subscription_id']) : 0;
        $payment_method_id = isset($_POST['payment_method_id']) ? sanitize_text_field($_POST['payment_method_id']) : '';

        if (!$subscription_id) {
            wp_send_json_error(__('Invalid subscription ID.', 'subs'));
        }

        if (!$payment_method_id) {
            wp_send_json_error(__('Invalid payment method.', 'subs'));
        }

        $subscription = new Subs_Subscription($subscription_id);

        if (!$subscription->get_id()) {
            wp_send_json_error(__('Subscription not found.', 'subs'));
        }

        // Check if user owns this subscription
        if ($subscription->get_customer_id() !== get_current_user_id()) {
            wp_send_json_error(__('You do not have permission to modify this subscription.', 'subs'));
        }

        // Check if customer can change payment methods
        if ('no' === get_option('subs_enable_payment_method_change', 'yes')) {
            wp_send_json_error(__('Changing payment methods is not allowed.', 'subs'));
        }

        $stripe = new Subs_Stripe();
        $result = $stripe->update_subscription_payment_method(
            $subscription->get_stripe_subscription_id(),
            $payment_method_id
        );

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Update local subscription record
        $subscription->set_payment_method_id($payment_method_id);
        $subscription->save();

        $subscription->add_history('payment_method_changed', '', '',
            __('Payment method updated by customer', 'subs')
        );

        wp_send_json_success(array(
            'message' => __('Payment method updated successfully.', 'subs'),
        ));
    }

    /**
     * Get subscription details
     */
    public function get_subscription_details() {
        check_ajax_referer('subs_frontend_nonce', 'nonce');

        $subscription_id = isset($_POST['subscription_id']) ? absint($_POST['subscription_id']) : 0;

        if (!$subscription_id) {
            wp_send_json_error(__('Invalid subscription ID.', 'subs'));
        }

        $subscription = new Subs_Subscription($subscription_id);

        if (!$subscription->get_id()) {
            wp_send_json_error(__('Subscription not found.', 'subs'));
        }

        // Check if user owns this subscription
        if ($subscription->get_customer_id() !== get_current_user_id()) {
            wp_send_json_error(__('You do not have permission to view this subscription.', 'subs'));
        }

        $product = $subscription->get_product();
        $history = $subscription->get_history(10);

        $response = array(
            'id' => $subscription->get_id(),
            'status' => $subscription->get_status(),
            'status_label' => $subscription->get_status_label(),
            'product_name' => $product ? $product->get_name() : __('Product not found', 'subs'),
            'total_amount' => $subscription->get_formatted_total(),
            'billing_period' => $subscription->get_formatted_billing_period(),
            'start_date' => $subscription->get_start_date(),
            'next_payment_date' => $subscription->get_next_payment_date(),
            'last_payment_date' => $subscription->get_last_payment_date(),
            'flag_address' => $subscription->get_flag_address(),
            'history' => array(),
        );

        // Format history
        foreach ($history as $entry) {
            $response['history'][] = array(
                'action' => $entry->action,
                'note' => $entry->note,
                'date' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->date_created)),
            );
        }

        wp_send_json_success($response);
    }

    /**
     * Update flag delivery address
     */
    public function update_flag_address() {
        check_ajax_referer('subs_frontend_nonce', 'nonce');

        $subscription_id = isset($_POST['subscription_id']) ? absint($_POST['subscription_id']) : 0;
        $flag_address = isset($_POST['flag_address']) ? sanitize_textarea_field($_POST['flag_address']) : '';

        if (!$subscription_id) {
            wp_send_json_error(__('Invalid subscription ID.', 'subs'));
        }

        if (empty($flag_address)) {
            wp_send_json_error(__('Flag delivery address cannot be empty.', 'subs'));
        }

        $subscription = new Subs_Subscription($subscription_id);

        if (!$subscription->get_id()) {
            wp_send_json_error(__('Subscription not found.', 'subs'));
        }

        // Check if user owns this subscription
        if ($subscription->get_customer_id() !== get_current_user_id()) {
            wp_send_json_error(__('You do not have permission to modify this subscription.', 'subs'));
        }

        $subscription->set_flag_address($flag_address);
        $result = $subscription->save();

        if (!$result) {
            wp_send_json_error(__('Failed to update flag address.', 'subs'));
        }

        $subscription->add_history('address_changed', '', '',
            __('Flag delivery address updated by customer', 'subs')
        );

        wp_send_json_success(array(
            'message' => __('Flag delivery address updated successfully.', 'subs'),
        ));
    }

    /**
     * Get product subscription data
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

        wp_send_json_success($subscription_data);
    }

    /**
     * Admin: Get subscription history
     */
    public function admin_get_subscription_history() {
        check_ajax_referer('subs_admin_nonce', 'nonce');

        if (!current_user_can('manage_subs_subscriptions')) {
            wp_send_json_error(__('Insufficient permissions.', 'subs'));
        }

        $subscription_id = isset($_POST['subscription_id']) ? absint($_POST['subscription_id']) : 0;

        if (!$subscription_id) {
            wp_send_json_error(__('Invalid subscription ID.', 'subs'));
        }

        $subscription = new Subs_Subscription($subscription_id);

        if (!$subscription->get_id()) {
            wp_send_json_error(__('Subscription not found.', 'subs'));
        }

        $history = $subscription->get_history(50);
        $formatted_history = array();

        foreach ($history as $entry) {
            $formatted_history[] = array(
                'id' => $entry->id,
                'action' => $entry->action,
                'status_from' => $entry->status_from,
                'status_to' => $entry->status_to,
                'note' => $entry->note,
                'date_created' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->date_created)),
                'raw_date' => $entry->date_created,
            );
        }

        wp_send_json_success(array(
            'history' => $formatted_history,
        ));
    }

    /**
     * Admin: Add subscription note
     */
    public function admin_add_subscription_note() {
        check_ajax_referer('subs_admin_nonce', 'nonce');

        if (!current_user_can('manage_subs_subscriptions')) {
            wp_send_json_error(__('Insufficient permissions.', 'subs'));
        }

        $subscription_id = isset($_POST['subscription_id']) ? absint($_POST['subscription_id']) : 0;
        $note = isset($_POST['note']) ? sanitize_textarea_field($_POST['note']) : '';

        if (!$subscription_id) {
            wp_send_json_error(__('Invalid subscription ID.', 'subs'));
        }

        if (empty($note)) {
            wp_send_json_error(__('Note cannot be empty.', 'subs'));
        }

        $subscription = new Subs_Subscription($subscription_id);

        if (!$subscription->get_id()) {
            wp_send_json_error(__('Subscription not found.', 'subs'));
        }

        $user = wp_get_current_user();
        $note_with_author = sprintf(__('Note by %s: %s', 'subs'), $user->display_name, $note);

        $subscription->add_history('note_added', '', '', $note_with_author);

        wp_send_json_success(array(
            'message' => __('Note added successfully.', 'subs'),
        ));
    }

    /**
     * Admin: Bulk subscription actions
     */
    public function admin_bulk_subscription_action() {
        check_ajax_referer('subs_admin_nonce', 'nonce');

        if (!current_user_can('manage_subs_subscriptions')) {
            wp_send_json_error(__('Insufficient permissions.', 'subs'));
        }

        $action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';
        $subscription_ids = isset($_POST['subscription_ids']) ? array_map('absint', $_POST['subscription_ids']) : array();

        if (empty($action) || empty($subscription_ids)) {
            wp_send_json_error(__('Invalid action or no subscriptions selected.', 'subs'));
        }

        $processed = 0;
        $errors = array();

        foreach ($subscription_ids as $subscription_id) {
            $subscription = new Subs_Subscription($subscription_id);

            if (!$subscription->get_id()) {
                $errors[] = sprintf(__('Subscription #%d not found.', 'subs'), $subscription_id);
                continue;
            }

            switch ($action) {
                case 'pause':
                    $result = $subscription->pause(__('Bulk action by administrator', 'subs'));
                    break;

                case 'resume':
                    $result = $subscription->resume(__('Bulk action by administrator', 'subs'));
                    break;

                case 'cancel':
                    $result = $subscription->cancel(__('Bulk action by administrator', 'subs'));
                    break;

                default:
                    $result = new WP_Error('invalid_action', __('Invalid action.', 'subs'));
            }

            if (is_wp_error($result)) {
                $errors[] = sprintf(__('Subscription #%d: %s', 'subs'), $subscription_id, $result->get_error_message());
            } else {
                $processed++;
            }
        }

        $message = sprintf(_n('%d subscription processed.', '%d subscriptions processed.', $processed, 'subs'), $processed);

        if (!empty($errors)) {
            $message .= ' ' . sprintf(_n('%d error occurred:', '%d errors occurred:', count($errors), 'subs'), count($errors));
            $message .= ' ' . implode(', ', $errors);
        }

        wp_send_json_success(array(
            'message' => $message,
            'processed' => $processed,
            'errors' => $errors,
        ));
    }

    /**
     * Get formatted billing period text
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
            return sprintf(__('Every %s', 'subs'), $period_text);
        } else {
            return sprintf(__('Every %d %s', 'subs'), $interval, $period_text);
        }
    }

    /**
     * Save customer preference
     */
    public function save_preference() {
        check_ajax_referer('subs_frontend_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(__('You must be logged in to save preferences.', 'subs'));
        }

        $preference = isset($_POST['preference']) ? sanitize_text_field($_POST['preference']) : '';
        $value = isset($_POST['value']) ? sanitize_text_field($_POST['value']) : '';

        if (empty($preference)) {
            wp_send_json_error(__('Invalid preference key.', 'subs'));
        }

        $customer = subs_get_customer();
        $result = $customer->set_preference($preference, $value);

        if ($result) {
            wp_send_json_success(__('Preference saved successfully.', 'subs'));
        } else {
            wp_send_json_error(__('Failed to save preference.', 'subs'));
        }
    }

    /**
     * Test Stripe connection (admin only)
     */
    public function test_stripe_connection() {
        check_ajax_referer('subs_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'subs'));
        }

        $test_mode = isset($_POST['test_mode']) && $_POST['test_mode'] === 'yes';
        $publishable_key = sanitize_text_field($_POST['publishable_key'] ?? '');
        $secret_key = sanitize_text_field($_POST['secret_key'] ?? '');

        if (empty($publishable_key) || empty($secret_key)) {
            wp_send_json_error(__('Please enter both publishable and secret keys.', 'subs'));
        }

        // Validate key formats
        $pub_prefix = $test_mode ? 'pk_test_' : 'pk_live_';
        $sec_prefix = $test_mode ? 'sk_test_' : 'sk_live_';

        if (strpos($publishable_key, $pub_prefix) !== 0) {
            wp_send_json_error(sprintf(__('Publishable key should start with %s', 'subs'), $pub_prefix));
        }

        if (strpos($secret_key, $sec_prefix) !== 0) {
            wp_send_json_error(sprintf(__('Secret key should start with %s', 'subs'), $sec_prefix));
        }

        // Test the connection
        try {
            // Temporarily set the API key
            if (class_exists('Stripe\Stripe')) {
                \Stripe\Stripe::setApiKey($secret_key);

                // Test with a simple API call
                $account = \Stripe\Account::retrieve();

                if ($account && $account->id) {
                    wp_send_json_success(array(
                        'message' => sprintf(
                            __('Connection successful! Account: %s (%s)', 'subs'),
                            $account->display_name ?: $account->id,
                            $account->country
                        )
                    ));
                } else {
                    wp_send_json_error(__('Connection failed: Unable to retrieve account information.', 'subs'));
                }
            } else {
                wp_send_json_error(__('Stripe PHP library is not installed.', 'subs'));
            }
        } catch (Exception $e) {
            wp_send_json_error(sprintf(__('Connection failed: %s', 'subs'), $e->getMessage()));
        }
    }

    /**
     * Export settings (admin only)
     */
    public function export_settings() {
        check_ajax_referer('subs_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'subs'));
        }

        $settings = new Subs_Admin_Settings();
        $exported_settings = $settings->export_settings();

        // Add metadata
        $export_data = array(
            'version' => SUBS_VERSION,
            'exported_at' => current_time('mysql'),
            'site_url' => home_url(),
            'settings' => $exported_settings
        );

        wp_send_json_success($export_data);
    }

    /**
     * Import settings (admin only)
     */
    public function import_settings() {
        check_ajax_referer('subs_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'subs'));
        }

        $settings_json = isset($_POST['settings']) ? stripslashes($_POST['settings']) : '';

        if (empty($settings_json)) {
            wp_send_json_error(__('No settings data provided.', 'subs'));
        }

        $import_data = json_decode($settings_json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(__('Invalid JSON format.', 'subs'));
        }

        if (!isset($import_data['settings'])) {
            wp_send_json_error(__('Invalid settings file format.', 'subs'));
        }

        $settings = new Subs_Admin_Settings();
        $result = $settings->import_settings($import_data['settings']);

        if ($result) {
            wp_send_json_success(__('Settings imported successfully.', 'subs'));
        } else {
            wp_send_json_error(__('Failed to import settings.', 'subs'));
        }
    }

    /**
     * Reset settings (admin only)
     */
    public function reset_settings() {
        check_ajax_referer('subs_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'subs'));
        }

        $section = isset($_POST['section']) ? sanitize_text_field($_POST['section']) : '';

        $settings = new Subs_Admin_Settings();
        $result = $settings->reset_settings($section);

        if ($result) {
            $message = $section ?
                sprintf(__('%s settings reset successfully.', 'subs'), ucfirst($section)) :
                __('All settings reset successfully.', 'subs');
            wp_send_json_success($message);
        } else {
            wp_send_json_error(__('Failed to reset settings.', 'subs'));
        }
    }

    /**
     * Load reports data (admin only)
     */
    public function load_reports() {
        check_ajax_referer('subs_admin_nonce', 'nonce');

        if (!current_user_can('manage_subs_subscriptions')) {
            wp_send_json_error(__('Insufficient permissions.', 'subs'));
        }

        $date_range = isset($_POST['date_range']) ? sanitize_text_field($_POST['date_range']) : '30days';
        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';

        // Calculate date range
        switch ($date_range) {
            case '7days':
                $start = date('Y-m-d', strtotime('-7 days'));
                $end = date('Y-m-d');
                break;
            case '30days':
                $start = date('Y-m-d', strtotime('-30 days'));
                $end = date('Y-m-d');
                break;
            case '90days':
                $start = date('Y-m-d', strtotime('-90 days'));
                $end = date('Y-m-d');
                break;
            case 'custom':
                $start = $start_date ?: date('Y-m-d', strtotime('-30 days'));
                $end = $end_date ?: date('Y-m-d');
                break;
            default:
                $start = date('Y-m-d', strtotime('-30 days'));
                $end = date('Y-m-d');
        }

        global $wpdb;

        // Get basic statistics
        $stats = array();

        // Total subscriptions in date range
        $stats['total_subscriptions'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}subs_subscriptions
                 WHERE DATE(date_created) BETWEEN %s AND %s",
                $start, $end
            )
        );

        // Active subscriptions
        $stats['active_subscriptions'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}subs_subscriptions
             WHERE status IN ('active', 'trialing')"
        );

        // Monthly Recurring Revenue
        $mrr = $wpdb->get_var(
            "SELECT SUM(total_amount) FROM {$wpdb->prefix}subs_subscriptions
             WHERE status IN ('active', 'trialing') AND billing_period = 'month'"
        );

        // Convert other billing periods to monthly equivalent
        $yearly_revenue = $wpdb->get_var(
            "SELECT SUM(total_amount) FROM {$wpdb->prefix}subs_subscriptions
             WHERE status IN ('active', 'trialing') AND billing_period = 'year'"
        );

        $weekly_revenue = $wpdb->get_var(
            "SELECT SUM(total_amount) FROM {$wpdb->prefix}subs_subscriptions
             WHERE status IN ('active', 'trialing') AND billing_period = 'week'"
        );

        $daily_revenue = $wpdb->get_var(
            "SELECT SUM(total_amount) FROM {$wpdb->prefix}subs_subscriptions
             WHERE status IN ('active', 'trialing') AND billing_period = 'day'"
        );

        // Calculate MRR
        $total_mrr = floatval($mrr) +
                    (floatval($yearly_revenue) / 12) +
                    (floatval($weekly_revenue) * 4.33) +
                    (floatval($daily_revenue) * 30);

        $stats['mrr'] = $total_mrr;
        $stats['mrr_formatted'] = wc_price($total_mrr);

        // Churn rate (cancelled subscriptions in period)
        $cancelled_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}subs_subscriptions
                 WHERE status = 'cancelled' AND DATE(date_modified) BETWEEN %s AND %s",
                $start, $end
            )
        );

        $total_active_start = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}subs_subscriptions
                 WHERE status IN ('active', 'trialing') AND DATE(date_created) <= %s",
                $start
            )
        );

        $stats['churn_rate'] = $total_active_start > 0 ?
            round(($cancelled_count / $total_active_start) * 100, 2) : 0;

        // Growth chart data
        $growth_data = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(date_created) as date, COUNT(*) as count
                 FROM {$wpdb->prefix}subs_subscriptions
                 WHERE DATE(date_created) BETWEEN %s AND %s
                 GROUP BY DATE(date_created)
                 ORDER BY date",
                $start, $end
            )
        );

        // Status distribution
        $status_distribution = $wpdb->get_results(
            "SELECT status, COUNT(*) as count
             FROM {$wpdb->prefix}subs_subscriptions
             GROUP BY status"
        );

        $chart_data = array(
            'growth' => array(
                'labels' => wp_list_pluck($growth_data, 'date'),
                'data' => wp_list_pluck($growth_data, 'count')
            ),
            'status_distribution' => array(
                'labels' => wp_list_pluck($status_distribution, 'status'),
                'data' => wp_list_pluck($status_distribution, 'count')
            )
        );

        wp_send_json_success(array(
            'stats' => $stats,
            'chart_data' => $chart_data,
            'date_range' => array(
                'start' => $start,
                'end' => $end
            )
        ));
    }

    /**
     * Export report (admin only)
     */
    public function export_report() {
        check_ajax_referer('subs_admin_nonce', 'nonce');

        if (!current_user_can('manage_subs_subscriptions')) {
            wp_die(__('Insufficient permissions.', 'subs'));
        }

        $format = isset($_GET['format']) ? sanitize_text_field($_GET['format']) : 'csv';
        $date_range = isset($_GET['date_range']) ? sanitize_text_field($_GET['date_range']) : '30days';

        global $wpdb;

        // Get subscriptions data
        $query = "SELECT s.*, u.display_name, u.user_email, p.post_title as product_name
                  FROM {$wpdb->prefix}subs_subscriptions s
                  LEFT JOIN {$wpdb->users} u ON s.customer_id = u.ID
                  LEFT JOIN {$wpdb->posts} p ON s.product_id = p.ID
                  ORDER BY s.date_created DESC";

        $subscriptions = $wpdb->get_results($query);

        if ($format === 'csv') {
            $this->export_csv($subscriptions);
        } else {
            $this->export_json($subscriptions);
        }
    }

    /**
     * Export data as CSV
     *
     * @param array $data
     */
    private function export_csv($data) {
        $filename = 'subs-subscriptions-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $output = fopen('php://output', 'w');

        // CSV headers
        fputcsv($output, array(
            'ID',
            'Customer Name',
            'Customer Email',
            'Product',
            'Status',
            'Amount',
            'Currency',
            'Billing Period',
            'Start Date',
            'Next Payment',
            'Created Date'
        ));

        // CSV data
        foreach ($data as $subscription) {
            fputcsv($output, array(
                $subscription->id,
                $subscription->display_name,
                $subscription->user_email,
                $subscription->product_name,
                $subscription->status,
                $subscription->total_amount,
                $subscription->currency,
                $subscription->billing_period,
                $subscription->start_date,
                $subscription->next_payment_date,
                $subscription->date_created
            ));
        }

        fclose($output);
        exit;
    }

    /**
     * Export data as JSON
     *
     * @param array $data
     */
    private function export_json($data) {
        $filename = 'subs-subscriptions-' . date('Y-m-d') . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $export_data = array(
            'exported_at' => current_time('mysql'),
            'site_url' => home_url(),
            'total_subscriptions' => count($data),
            'subscriptions' => $data
        );

        echo json_encode($export_data, JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Resume user subscription
     */
    public function resume_subscription() {
        check_ajax_referer('subs_frontend_nonce', 'nonce');

        $subscription_id = isset($_POST['subscription_id']) ? absint($_POST['subscription_id']) : 0;

        if (!$subscription_id) {
            wp_send_json_error(__('Invalid subscription ID.', 'subs'));
        }

        $subscription = new Subs_Subscription($subscription_id);

        if (!$subscription->get_id()) {
            wp_send_json_error(__('Subscription not found.', 'subs'));
        }

        // Check if user owns this subscription
        if ($subscription->get_customer_id() !== get_current_user_id()) {
            wp_send_json_error(__('You do not have permission to modify this subscription.', 'subs'));
        }

        $result = $subscription->resume(__('Resumed by customer', 'subs'));

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'message' => __('Subscription resumed successfully.', 'subs'),
            'new_status' => $subscription->get_status(),
            'status_label' => $subscription->get_status_label(),
        ));
    }


    /**
     * Cancel user subscription
     */
    public function cancel_subscription() {
        check_ajax_referer('subs_frontend_nonce', 'nonce');

        $subscription_id = isset($_POST['subscription_id']) ? absint($_POST['subscription_id']) : 0;
        $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';

        if (!$subscription_id) {
            wp_send_json_error(__('Invalid subscription ID.', 'subs'));
        }

        $subscription = new Subs_Subscription($subscription_id);

        if (!$subscription->get_id()) {
            wp_send_json_error(__('Subscription not found.', 'subs'));
        }

        // Check if user owns this subscription
        if ($subscription->get_customer_id() !== get_current_user_id()) {
            wp_send_json_error(__('You do not have permission to modify this subscription.', 'subs'));
        }

        // Check if customer can cancel subscriptions
        if ('no' === get_option('subs_enable_customer_cancel', 'yes')) {
            wp_send_json_error(__('Cancelling subscriptions is not allowed.', 'subs'));
        }

        $note = __('Cancelled by customer', 'subs');
        if (!empty($reason)) {
            $note .= '. ' . sprintf(__('Reason: %s', 'subs'), $reason);
        }

        $result = $subscription->cancel($note);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'message' => __('Subscription cancelled successfully.', 'subs'),
            'new_status' => $subscription->get_status(),
            'status_label' => $subscription->get_status_label(),
        ));
    }
} : 0;
