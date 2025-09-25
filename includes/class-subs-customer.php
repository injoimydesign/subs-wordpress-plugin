<?php
/**
 * Customer Management Class
 *
 * Handles customer-related subscription functionality
 *
 * @package Subs
 * @version 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Subs Customer Class
 *
 * @class Subs_Customer
 * @version 1.0.0
 */
class Subs_Customer {

    /**
     * Customer ID (WordPress user ID)
     * @var int
     */
    private $customer_id;

    /**
     * Customer data
     * @var WP_User
     */
    private $customer;

    /**
     * Constructor
     *
     * @param int $customer_id
     */
    public function __construct($customer_id = 0) {
        if (!$customer_id) {
            $customer_id = get_current_user_id();
        }

        $this->customer_id = $customer_id;
        $this->customer = get_user_by('id', $customer_id);

        if (!$this->customer) {
            return false;
        }

        $this->init();
    }

    /**
     * Initialize customer functionality
     */
    private function init() {
        // Customer-specific hooks can be added here
        add_action('wp_login', array($this, 'on_customer_login'), 10, 2);
        add_action('user_register', array($this, 'on_customer_register'));
    }

    /**
     * Get customer ID
     *
     * @return int
     */
    public function get_id() {
        return $this->customer_id;
    }

    /**
     * Get customer object
     *
     * @return WP_User|false
     */
    public function get_customer() {
        return $this->customer;
    }

    /**
     * Check if customer exists
     *
     * @return bool
     */
    public function exists() {
        return $this->customer instanceof WP_User;
    }

    /**
     * Get customer subscriptions
     *
     * @param string $status Filter by status
     * @param int $limit Number of subscriptions to retrieve
     * @return array
     */
    public function get_subscriptions($status = '', $limit = -1) {
        if (!$this->exists()) {
            return array();
        }

        global $wpdb;

        $query = "SELECT id FROM {$wpdb->prefix}subs_subscriptions WHERE customer_id = %d";
        $params = array($this->customer_id);

        if (!empty($status)) {
            $query .= " AND status = %s";
            $params[] = $status;
        }

        $query .= " ORDER BY date_created DESC";

        if ($limit > 0) {
            $query .= " LIMIT %d";
            $params[] = $limit;
        }

        $subscription_ids = $wpdb->get_col($wpdb->prepare($query, $params));

        $subscriptions = array();
        foreach ($subscription_ids as $id) {
            $subscription = new Subs_Subscription($id);
            if ($subscription->get_id()) {
                $subscriptions[] = $subscription;
            }
        }

        return $subscriptions;
    }

    /**
     * Get active subscriptions
     *
     * @return array
     */
    public function get_active_subscriptions() {
        return $this->get_subscriptions('active');
    }

    /**
     * Get subscription count by status
     *
     * @param string $status
     * @return int
     */
    public function get_subscription_count($status = '') {
        if (!$this->exists()) {
            return 0;
        }

        global $wpdb;

        $query = "SELECT COUNT(*) FROM {$wpdb->prefix}subs_subscriptions WHERE customer_id = %d";
        $params = array($this->customer_id);

        if (!empty($status)) {
            $query .= " AND status = %s";
            $params[] = $status;
        }

        return (int) $wpdb->get_var($wpdb->prepare($query, $params));
    }

    /**
     * Get total subscription value
     *
     * @param string $status
     * @return float
     */
    public function get_total_subscription_value($status = 'active') {
        if (!$this->exists()) {
            return 0;
        }

        global $wpdb;

        $query = "SELECT SUM(total_amount) FROM {$wpdb->prefix}subs_subscriptions WHERE customer_id = %d";
        $params = array($this->customer_id);

        if (!empty($status)) {
            $query .= " AND status = %s";
            $params[] = $status;
        }

        return (float) $wpdb->get_var($wpdb->prepare($query, $params));
    }

    /**
     * Get customer's next payment date
     *
     * @return string|false
     */
    public function get_next_payment_date() {
        if (!$this->exists()) {
            return false;
        }

        global $wpdb;

        $next_payment = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(next_payment_date)
             FROM {$wpdb->prefix}subs_subscriptions
             WHERE customer_id = %d AND status IN ('active', 'trialing')
             AND next_payment_date IS NOT NULL AND next_payment_date > NOW()",
            $this->customer_id
        ));

        return $next_payment ?: false;
    }

    /**
     * Check if customer has any active subscriptions
     *
     * @return bool
     */
    public function has_active_subscriptions() {
        return $this->get_subscription_count('active') > 0;
    }

    /**
     * Check if customer can manage subscriptions
     *
     * @return bool
     */
    public function can_manage_subscriptions() {
        return $this->exists() && $this->has_subscriptions();
    }

    /**
     * Check if customer has any subscriptions
     *
     * @return bool
     */
    public function has_subscriptions() {
        return $this->get_subscription_count() > 0;
    }

    /**
     * Get customer's Stripe customer ID
     *
     * @return string
     */
    public function get_stripe_customer_id() {
        if (!$this->exists()) {
            return '';
        }

        return get_user_meta($this->customer_id, '_subs_stripe_customer_id', true);
    }

    /**
     * Set customer's Stripe customer ID
     *
     * @param string $stripe_customer_id
     * @return bool
     */
    public function set_stripe_customer_id($stripe_customer_id) {
        if (!$this->exists()) {
            return false;
        }

        return update_user_meta($this->customer_id, '_subs_stripe_customer_id', $stripe_customer_id);
    }

    /**
     * Get customer's payment methods
     *
     * @return array
     */
    public function get_payment_methods() {
        $stripe_customer_id = $this->get_stripe_customer_id();

        if (empty($stripe_customer_id)) {
            return array();
        }

        $stripe = new Subs_Stripe();
        $payment_methods = $stripe->get_customer_payment_methods($stripe_customer_id);

        if (is_wp_error($payment_methods)) {
            return array();
        }

        return $payment_methods;
    }

    /**
     * Get customer subscription statistics
     *
     * @return array
     */
    public function get_subscription_stats() {
        if (!$this->exists()) {
            return array();
        }

        global $wpdb;

        $stats = $wpdb->get_results($wpdb->prepare(
            "SELECT
                status,
                COUNT(*) as count,
                SUM(total_amount) as total_value
             FROM {$wpdb->prefix}subs_subscriptions
             WHERE customer_id = %d
             GROUP BY status",
            $this->customer_id
        ));

        $formatted_stats = array(
            'total_subscriptions' => 0,
            'active_subscriptions' => 0,
            'paused_subscriptions' => 0,
            'cancelled_subscriptions' => 0,
            'total_monthly_value' => 0,
            'by_status' => array()
        );

        foreach ($stats as $stat) {
            $formatted_stats['by_status'][$stat->status] = array(
                'count' => (int) $stat->count,
                'total_value' => (float) $stat->total_value
            );

            $formatted_stats['total_subscriptions'] += (int) $stat->count;

            switch ($stat->status) {
                case 'active':
                case 'trialing':
                    $formatted_stats['active_subscriptions'] += (int) $stat->count;
                    $formatted_stats['total_monthly_value'] += (float) $stat->total_value;
                    break;
                case 'paused':
                    $formatted_stats['paused_subscriptions'] += (int) $stat->count;
                    break;
                case 'cancelled':
                    $formatted_stats['cancelled_subscriptions'] += (int) $stat->count;
                    break;
            }
        }

        return $formatted_stats;
    }

    /**
     * Get subscription history for customer
     *
     * @param int $limit
     * @return array
     */
    public function get_subscription_history($limit = 20) {
        if (!$this->exists()) {
            return array();
        }

        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT h.*, s.id as subscription_id
             FROM {$wpdb->prefix}subs_subscription_history h
             INNER JOIN {$wpdb->prefix}subs_subscriptions s ON h.subscription_id = s.id
             WHERE s.customer_id = %d
             ORDER BY h.date_created DESC
             LIMIT %d",
            $this->customer_id,
            $limit
        ));
    }

    /**
     * Get customer preferences
     *
     * @param string $key Specific preference key
     * @return mixed
     */
    public function get_preference($key = '') {
        if (!$this->exists()) {
            return false;
        }

        $preferences = get_user_meta($this->customer_id, '_subs_preferences', true);

        if (!is_array($preferences)) {
            $preferences = array();
        }

        if (empty($key)) {
            return $preferences;
        }

        return isset($preferences[$key]) ? $preferences[$key] : null;
    }

    /**
     * Set customer preference
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function set_preference($key, $value) {
        if (!$this->exists()) {
            return false;
        }

        $preferences = $this->get_preference();
        $preferences[$key] = $value;

        return update_user_meta($this->customer_id, '_subs_preferences', $preferences);
    }

    /**
     * Get default flag delivery address
     *
     * @return string
     */
    public function get_default_flag_address() {
        $address = $this->get_preference('default_flag_address');

        if (empty($address)) {
            // Fall back to billing address if available
            $address = $this->get_formatted_billing_address();
        }

        return $address ?: '';
    }

    /**
     * Set default flag delivery address
     *
     * @param string $address
     * @return bool
     */
    public function set_default_flag_address($address) {
        return $this->set_preference('default_flag_address', sanitize_textarea_field($address));
    }

    /**
     * Get formatted billing address
     *
     * @return string
     */
    public function get_formatted_billing_address() {
        if (!$this->exists()) {
            return '';
        }

        $address_parts = array();

        $first_name = get_user_meta($this->customer_id, 'billing_first_name', true);
        $last_name = get_user_meta($this->customer_id, 'billing_last_name', true);

        if ($first_name || $last_name) {
            $address_parts[] = trim($first_name . ' ' . $last_name);
        }

        $company = get_user_meta($this->customer_id, 'billing_company', true);
        if ($company) {
            $address_parts[] = $company;
        }

        $address_1 = get_user_meta($this->customer_id, 'billing_address_1', true);
        if ($address_1) {
            $address_parts[] = $address_1;
        }

        $address_2 = get_user_meta($this->customer_id, 'billing_address_2', true);
        if ($address_2) {
            $address_parts[] = $address_2;
        }

        $city = get_user_meta($this->customer_id, 'billing_city', true);
        $state = get_user_meta($this->customer_id, 'billing_state', true);
        $postcode = get_user_meta($this->customer_id, 'billing_postcode', true);

        $city_line = array();
        if ($city) $city_line[] = $city;
        if ($state) $city_line[] = $state;
        if ($postcode) $city_line[] = $postcode;

        if (!empty($city_line)) {
            $address_parts[] = implode(', ', $city_line);
        }

        $country = get_user_meta($this->customer_id, 'billing_country', true);
        if ($country) {
            $countries = WC()->countries->get_countries();
            $address_parts[] = isset($countries[$country]) ? $countries[$country] : $country;
        }

        return implode("\n", array_filter($address_parts));
    }

    /**
     * Send customer notification email
     *
     * @param string $template
     * @param array $data
     * @return bool
     */
    public function send_notification($template, $data = array()) {
        if (!$this->exists()) {
            return false;
        }

        $emails = new Subs_Emails();
        return $emails->send_customer_email($this->customer_id, $template, $data);
    }

    /**
     * Handle customer login
     *
     * @param string $user_login
     * @param WP_User $user
     */
    public function on_customer_login($user_login, $user) {
        // Check for any overdue subscriptions and update status
        $this->check_overdue_subscriptions();

        // Update last login time
        $this->set_preference('last_login', current_time('mysql'));
    }

    /**
     * Handle customer registration
     *
     * @param int $user_id
     */
    public function on_customer_register($user_id) {
        // Initialize customer preferences
        update_user_meta($user_id, '_subs_preferences', array(
            'email_notifications' => 'yes',
            'created' => current_time('mysql'),
        ));
    }

    /**
     * Check for overdue subscriptions
     */
    private function check_overdue_subscriptions() {
        if (!$this->exists()) {
            return;
        }

        global $wpdb;

        // Get subscriptions that are past due
        $overdue_subscription_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}subs_subscriptions
             WHERE customer_id = %d
             AND status = 'active'
             AND next_payment_date < NOW()
             AND next_payment_date IS NOT NULL",
            $this->customer_id
        ));

        foreach ($overdue_subscription_ids as $subscription_id) {
            $subscription = new Subs_Subscription($subscription_id);

            if ($subscription->get_id()) {
                $subscription->update_status('past_due', __('Subscription is past due', 'subs'));

                // Send overdue notification
                $this->send_notification('subscription_overdue', array(
                    'subscription' => $subscription
                ));
            }
        }
    }

    /**
     * Check if customer can perform action on subscription
     *
     * @param Subs_Subscription $subscription
     * @param string $action
     * @return bool
     */
    public function can_perform_action($subscription, $action) {
        if (!$this->exists() || !$subscription->get_id()) {
            return false;
        }

        // Check ownership
        if ($subscription->get_customer_id() !== $this->customer_id) {
            return false;
        }

        // Check if action is enabled in settings
        switch ($action) {
            case 'pause':
                return 'yes' === get_option('subs_enable_customer_pause', 'yes');

            case 'cancel':
                return 'yes' === get_option('subs_enable_customer_cancel', 'yes');

            case 'modify':
                return 'yes' === get_option('subs_enable_customer_modify', 'yes');

            case 'change_payment_method':
                return 'yes' === get_option('subs_enable_payment_method_change', 'yes');

            default:
                return false;
        }
    }

    /**
     * Get subscription renewal dates for calendar
     *
     * @param int $months Number of months to look ahead
     * @return array
     */
    public function get_upcoming_renewals($months = 3) {
        if (!$this->exists()) {
            return array();
        }

        $subscriptions = $this->get_subscriptions('active');
        $renewals = array();

        $end_date = new DateTime();
        $end_date->add(new DateInterval('P' . $months . 'M'));

        foreach ($subscriptions as $subscription) {
            $next_payment = $subscription->get_next_payment_date();

            if (!$next_payment) {
                continue;
            }

            $payment_date = new DateTime($next_payment);

            // Generate renewal dates for the specified period
            while ($payment_date <= $end_date) {
                $renewals[] = array(
                    'subscription_id' => $subscription->get_id(),
                    'date' => $payment_date->format('Y-m-d'),
                    'amount' => $subscription->get_total_amount(),
                    'product_name' => $subscription->get_product() ? $subscription->get_product()->get_name() : '',
                    'billing_period' => $subscription->get_formatted_billing_period(),
                );

                // Calculate next renewal date
                $payment_date = new DateTime($subscription->calculate_next_payment_date($payment_date->format('Y-m-d H:i:s')));
            }
        }

        // Sort by date
        usort($renewals, function($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        return $renewals;
    }

    /**
     * Get customer lifetime value from subscriptions
     *
     * @return float
     */
    public function get_lifetime_value() {
        if (!$this->exists()) {
            return 0;
        }

        global $wpdb;

        // Calculate total payments made
        $total_paid = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(total_amount)
             FROM {$wpdb->prefix}subs_subscriptions
             WHERE customer_id = %d
             AND last_payment_date IS NOT NULL",
            $this->customer_id
        ));

        return (float) ($total_paid ?: 0);
    }

    /**
     * Export customer subscription data
     *
     * @return array
     */
    public function export_data() {
        if (!$this->exists()) {
            return array();
        }

        $subscriptions = $this->get_subscriptions();
        $export_data = array(
            'customer_info' => array(
                'id' => $this->customer_id,
                'email' => $this->customer->user_email,
                'name' => $this->customer->display_name,
                'registered' => $this->customer->user_registered,
            ),
            'subscription_stats' => $this->get_subscription_stats(),
            'subscriptions' => array(),
            'preferences' => $this->get_preference(),
        );

        foreach ($subscriptions as $subscription) {
            $product = $subscription->get_product();

            $export_data['subscriptions'][] = array(
                'id' => $subscription->get_id(),
                'status' => $subscription->get_status(),
                'product_name' => $product ? $product->get_name() : '',
                'total_amount' => $subscription->get_total_amount(),
                'billing_period' => $subscription->get_formatted_billing_period(),
                'start_date' => $subscription->get_start_date(),
                'next_payment_date' => $subscription->get_next_payment_date(),
                'flag_address' => $subscription->get_flag_address(),
            );
        }

        return $export_data;
    }

    /**
     * Delete customer subscription data
     *
     * @param bool $anonymize Whether to anonymize instead of delete
     * @return bool
     */
    public function delete_subscription_data($anonymize = true) {
        if (!$this->exists()) {
            return false;
        }

        $subscriptions = $this->get_subscriptions();

        foreach ($subscriptions as $subscription) {
            if ($anonymize) {
                // Anonymize subscription data
                $subscription->set_flag_address(__('[Anonymized Address]', 'subs'));
                $subscription->set_notes(__('[Customer data anonymized]', 'subs'));
                $subscription->save();

                // Add anonymization note to history
                $subscription->add_history('anonymized', '', '',
                    __('Customer data anonymized per privacy request', 'subs')
                );
            } else {
                // Completely delete subscription
                $subscription->delete();
            }
        }

        if (!$anonymize) {
            // Delete customer meta data
            delete_user_meta($this->customer_id, '_subs_stripe_customer_id');
            delete_user_meta($this->customer_id, '_subs_preferences');
        }

        return true;
    }

    /**
     * Get customer dashboard data
     *
     * @return array
     */
    public function get_dashboard_data() {
        if (!$this->exists()) {
            return array();
        }

        $stats = $this->get_subscription_stats();
        $next_payment = $this->get_next_payment_date();
        $recent_history = $this->get_subscription_history(5);
        $upcoming_renewals = $this->get_upcoming_renewals(1);

        return array(
            'stats' => $stats,
            'next_payment_date' => $next_payment,
            'next_payment_formatted' => $next_payment ?
                date_i18n(get_option('date_format'), strtotime($next_payment)) : null,
            'recent_activity' => $recent_history,
            'upcoming_renewals' => $upcoming_renewals,
            'can_manage' => $this->can_manage_subscriptions(),
            'lifetime_value' => $this->get_lifetime_value(),
        );
    }
}

/**
 * Get customer instance
 *
 * @param int $customer_id
 * @return Subs_Customer
 */
function subs_get_customer($customer_id = 0) {
    return new Subs_Customer($customer_id);
}
