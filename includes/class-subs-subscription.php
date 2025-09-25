<?php
/**
 * Core Subscription Class
 *
 * Handles subscription data and operations
 *
 * @package Subs
 * @version 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Subs Subscription Class
 *
 * @class Subs_Subscription
 * @version 1.0.0
 */
class Subs_Subscription {

    /**
     * The subscription (post) ID
     * @var int
     */
    public $id = 0;

    /**
     * Subscription data array
     * @var array
     */
    protected $data = array(
        'order_id'               => 0,
        'customer_id'            => 0,
        'product_id'             => 0,
        'stripe_subscription_id' => '',
        'status'                 => 'pending',
        'billing_period'         => 'month',
        'billing_interval'       => 1,
        'start_date'             => null,
        'next_payment_date'      => null,
        'last_payment_date'      => null,
        'end_date'               => null,
        'trial_end_date'         => null,
        'subscription_amount'    => 0,
        'stripe_fee_amount'      => 0,
        'total_amount'           => 0,
        'currency'               => 'USD',
        'payment_method_id'      => '',
        'flag_address'           => '',
        'notes'                  => '',
        'date_created'           => null,
        'date_modified'          => null,
    );

    /**
     * Subscription meta data
     * @var array
     */
    protected $meta_data = array();

    /**
     * Valid subscription statuses
     * @var array
     */
    protected $valid_statuses = array(
        'pending',
        'active',
        'trialing',
        'past_due',
        'cancelled',
        'unpaid',
        'paused'
    );

    /**
     * Constructor
     *
     * @param mixed $subscription
     */
    public function __construct($subscription = 0) {
        if (is_numeric($subscription) && $subscription > 0) {
            $this->set_id($subscription);
        } elseif (is_object($subscription)) {
            $this->set_id($subscription->id);
        } elseif (is_array($subscription) && !empty($subscription['id'])) {
            $this->set_id($subscription['id']);
        }

        $this->read();
    }

    /**
     * Set subscription ID
     *
     * @param int $id
     */
    public function set_id($id) {
        $this->id = absint($id);
    }

    /**
     * Get subscription ID
     *
     * @return int
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Read subscription data from database
     */
    protected function read() {
        global $wpdb;

        if (!$this->get_id()) {
            return;
        }

        $subscription = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}subs_subscriptions WHERE id = %d",
                $this->get_id()
            )
        );

        if ($subscription) {
            $this->set_props_from_data($subscription);
            $this->load_meta_data();
        }
    }

    /**
     * Set subscription properties from database data
     *
     * @param object $data
     */
    protected function set_props_from_data($data) {
        $props = get_object_vars($data);
        unset($props['id']); // Don't overwrite the ID

        foreach ($props as $key => $value) {
            if (array_key_exists($key, $this->data)) {
                $this->data[$key] = $value;
            }
        }
    }

    /**
     * Load subscription meta data
     */
    protected function load_meta_data() {
        global $wpdb;

        if (!$this->get_id()) {
            return;
        }

        $meta_data = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->prefix}subs_subscription_meta WHERE subscription_id = %d",
                $this->get_id()
            )
        );

        foreach ($meta_data as $meta) {
            $this->meta_data[$meta->meta_key] = maybe_unserialize($meta->meta_value);
        }
    }

    /**
     * Save subscription to database
     *
     * @return int|false Subscription ID on success, false on failure
     */
    public function save() {
        global $wpdb;

        $this->data['date_modified'] = current_time('mysql');

        if (!$this->get_id()) {
            // Insert new subscription
            $this->data['date_created'] = current_time('mysql');

            $result = $wpdb->insert(
                $wpdb->prefix . 'subs_subscriptions',
                $this->data
            );

            if ($result) {
                $this->set_id($wpdb->insert_id);
                $this->save_meta_data();

                // Add to subscription history
                $this->add_history('created', '', $this->get_status(), __('Subscription created', 'subs'));

                do_action('subs_subscription_created', $this);

                return $this->get_id();
            }
        } else {
            // Update existing subscription
            $result = $wpdb->update(
                $wpdb->prefix . 'subs_subscriptions',
                $this->data,
                array('id' => $this->get_id())
            );

            if ($result !== false) {
                $this->save_meta_data();

                do_action('subs_subscription_updated', $this);

                return $this->get_id();
            }
        }

        return false;
    }

    /**
     * Save meta data to database
     */
    protected function save_meta_data() {
        global $wpdb;

        if (!$this->get_id()) {
            return;
        }

        // Delete existing meta
        $wpdb->delete(
            $wpdb->prefix . 'subs_subscription_meta',
            array('subscription_id' => $this->get_id())
        );

        // Insert new meta
        foreach ($this->meta_data as $key => $value) {
            $wpdb->insert(
                $wpdb->prefix . 'subs_subscription_meta',
                array(
                    'subscription_id' => $this->get_id(),
                    'meta_key'        => $key,
                    'meta_value'      => maybe_serialize($value)
                )
            );
        }
    }

    /**
     * Delete subscription from database
     *
     * @return bool
     */
    public function delete() {
        global $wpdb;

        if (!$this->get_id()) {
            return false;
        }

        // Delete subscription meta
        $wpdb->delete(
            $wpdb->prefix . 'subs_subscription_meta',
            array('subscription_id' => $this->get_id())
        );

        // Delete subscription history
        $wpdb->delete(
            $wpdb->prefix . 'subs_subscription_history',
            array('subscription_id' => $this->get_id())
        );

        // Delete subscription
        $result = $wpdb->delete(
            $wpdb->prefix . 'subs_subscriptions',
            array('id' => $this->get_id())
        );

        if ($result) {
            do_action('subs_subscription_deleted', $this->get_id());
            return true;
        }

        return false;
    }

    /**
     * Get subscription property
     *
     * @param string $key
     * @return mixed
     */
    public function get_prop($key) {
        if (array_key_exists($key, $this->data)) {
            return $this->data[$key];
        }
        return null;
    }

    /**
     * Set subscription property
     *
     * @param string $key
     * @param mixed $value
     */
    public function set_prop($key, $value) {
        if (array_key_exists($key, $this->data)) {
            $this->data[$key] = $value;
        }
    }

    /**
     * Get all subscription properties
     *
     * @return array
     */
    public function get_data() {
        return array_merge(array('id' => $this->get_id()), $this->data);
    }

    // Getter methods for subscription properties
    public function get_order_id() { return absint($this->get_prop('order_id')); }
    public function get_customer_id() { return absint($this->get_prop('customer_id')); }
    public function get_product_id() { return absint($this->get_prop('product_id')); }
    public function get_stripe_subscription_id() { return $this->get_prop('stripe_subscription_id'); }
    public function get_status() { return $this->get_prop('status'); }
    public function get_billing_period() { return $this->get_prop('billing_period'); }
    public function get_billing_interval() { return absint($this->get_prop('billing_interval')); }
    public function get_start_date() { return $this->get_prop('start_date'); }
    public function get_next_payment_date() { return $this->get_prop('next_payment_date'); }
    public function get_last_payment_date() { return $this->get_prop('last_payment_date'); }
    public function get_end_date() { return $this->get_prop('end_date'); }
    public function get_trial_end_date() { return $this->get_prop('trial_end_date'); }
    public function get_subscription_amount() { return floatval($this->get_prop('subscription_amount')); }
    public function get_stripe_fee_amount() { return floatval($this->get_prop('stripe_fee_amount')); }
    public function get_total_amount() { return floatval($this->get_prop('total_amount')); }
    public function get_currency() { return $this->get_prop('currency'); }
    public function get_payment_method_id() { return $this->get_prop('payment_method_id'); }
    public function get_flag_address() { return $this->get_prop('flag_address'); }
    public function get_notes() { return $this->get_prop('notes'); }
    public function get_date_created() { return $this->get_prop('date_created'); }
    public function get_date_modified() { return $this->get_prop('date_modified'); }

    // Setter methods for subscription properties
    public function set_order_id($value) { $this->set_prop('order_id', absint($value)); }
    public function set_customer_id($value) { $this->set_prop('customer_id', absint($value)); }
    public function set_product_id($value) { $this->set_prop('product_id', absint($value)); }
    public function set_stripe_subscription_id($value) { $this->set_prop('stripe_subscription_id', sanitize_text_field($value)); }
    public function set_status($value) {
        if (in_array($value, $this->valid_statuses)) {
            $this->set_prop('status', $value);
        }
    }
    public function set_billing_period($value) { $this->set_prop('billing_period', sanitize_text_field($value)); }
    public function set_billing_interval($value) { $this->set_prop('billing_interval', absint($value)); }
    public function set_start_date($value) { $this->set_prop('start_date', $value); }
    public function set_next_payment_date($value) { $this->set_prop('next_payment_date', $value); }
    public function set_last_payment_date($value) { $this->set_prop('last_payment_date', $value); }
    public function set_end_date($value) { $this->set_prop('end_date', $value); }
    public function set_trial_end_date($value) { $this->set_prop('trial_end_date', $value); }
    public function set_subscription_amount($value) { $this->set_prop('subscription_amount', floatval($value)); }
    public function set_stripe_fee_amount($value) { $this->set_prop('stripe_fee_amount', floatval($value)); }
    public function set_total_amount($value) { $this->set_prop('total_amount', floatval($value)); }
    public function set_currency($value) { $this->set_prop('currency', sanitize_text_field($value)); }
    public function set_payment_method_id($value) { $this->set_prop('payment_method_id', sanitize_text_field($value)); }
    public function set_flag_address($value) { $this->set_prop('flag_address', sanitize_textarea_field($value)); }
    public function set_notes($value) { $this->set_prop('notes', sanitize_textarea_field($value)); }

    /**
     * Get subscription meta
     *
     * @param string $key
     * @param bool $single
     * @return mixed
     */
    public function get_meta($key, $single = true) {
        if (isset($this->meta_data[$key])) {
            return $single ? $this->meta_data[$key] : array($this->meta_data[$key]);
        }
        return $single ? '' : array();
    }

    /**
     * Update subscription meta
     *
     * @param string $key
     * @param mixed $value
     */
    public function update_meta($key, $value) {
        $this->meta_data[$key] = $value;
    }

    /**
     * Delete subscription meta
     *
     * @param string $key
     */
    public function delete_meta($key) {
        if (isset($this->meta_data[$key])) {
            unset($this->meta_data[$key]);
        }
    }

    /**
     * Get customer object
     *
     * @return WP_User|false
     */
    public function get_customer() {
        if ($this->get_customer_id()) {
            return get_user_by('id', $this->get_customer_id());
        }
        return false;
    }

    /**
     * Get order object
     *
     * @return WC_Order|false
     */
    public function get_order() {
        if ($this->get_order_id()) {
            return wc_get_order($this->get_order_id());
        }
        return false;
    }

    /**
     * Get product object
     *
     * @return WC_Product|false
     */
    public function get_product() {
        if ($this->get_product_id()) {
            return wc_get_product($this->get_product_id());
        }
        return false;
    }

    /**
     * Check if subscription is active
     *
     * @return bool
     */
    public function is_active() {
        return in_array($this->get_status(), array('active', 'trialing'));
    }

    /**
     * Check if subscription is cancelled
     *
     * @return bool
     */
    public function is_cancelled() {
        return $this->get_status() === 'cancelled';
    }

    /**
     * Check if subscription is paused
     *
     * @return bool
     */
    public function is_paused() {
        return $this->get_status() === 'paused';
    }

    /**
     * Check if subscription has trial
     *
     * @return bool
     */
    public function has_trial() {
        return !empty($this->get_trial_end_date());
    }

    /**
     * Get formatted billing period
     *
     * @return string
     */
    public function get_formatted_billing_period() {
        $interval = $this->get_billing_interval();
        $period = $this->get_billing_period();

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
     * Get formatted total amount
     *
     * @return string
     */
    public function get_formatted_total() {
        return wc_price($this->get_total_amount(), array('currency' => $this->get_currency()));
    }

    /**
     * Get subscription status label
     *
     * @return string
     */
    public function get_status_label() {
        $statuses = array(
            'pending'   => __('Pending', 'subs'),
            'active'    => __('Active', 'subs'),
            'trialing'  => __('Trialing', 'subs'),
            'past_due'  => __('Past Due', 'subs'),
            'cancelled' => __('Cancelled', 'subs'),
            'unpaid'    => __('Unpaid', 'subs'),
            'paused'    => __('Paused', 'subs'),
        );

        return isset($statuses[$this->get_status()]) ? $statuses[$this->get_status()] : $this->get_status();
    }

    /**
     * Add entry to subscription history
     *
     * @param string $action
     * @param string $status_from
     * @param string $status_to
     * @param string $note
     */
    public function add_history($action, $status_from = '', $status_to = '', $note = '') {
        global $wpdb;

        if (!$this->get_id()) {
            return;
        }

        $wpdb->insert(
            $wpdb->prefix . 'subs_subscription_history',
            array(
                'subscription_id' => $this->get_id(),
                'action'          => sanitize_text_field($action),
                'status_from'     => sanitize_text_field($status_from),
                'status_to'       => sanitize_text_field($status_to),
                'note'            => sanitize_textarea_field($note),
                'date_created'    => current_time('mysql')
            )
        );

        do_action('subs_subscription_history_added', $this->get_id(), $action, $status_from, $status_to, $note);
    }

    /**
     * Get subscription history
     *
     * @param int $limit
     * @return array
     */
    public function get_history($limit = 20) {
        global $wpdb;

        if (!$this->get_id()) {
            return array();
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}subs_subscription_history
                 WHERE subscription_id = %d
                 ORDER BY date_created DESC
                 LIMIT %d",
                $this->get_id(),
                $limit
            )
        );
    }

    /**
     * Update subscription status
     *
     * @param string $new_status
     * @param string $note
     * @return bool
     */
    public function update_status($new_status, $note = '') {
        if (!in_array($new_status, $this->valid_statuses)) {
            return false;
        }

        $old_status = $this->get_status();

        if ($old_status === $new_status) {
            return true;
        }

        $this->set_status($new_status);

        if ($this->save()) {
            $this->add_history('status_changed', $old_status, $new_status, $note);

            do_action('subs_subscription_status_changed', $this, $old_status, $new_status);

            return true;
        }

        return false;
    }

    /**
     * Calculate next payment date
     *
     * @param string $from_date
     * @return string
     */
    public function calculate_next_payment_date($from_date = '') {
        if (empty($from_date)) {
            $from_date = $this->get_next_payment_date() ?: current_time('mysql');
        }

        $period = $this->get_billing_period();
        $interval = $this->get_billing_interval();

        $date = new DateTime($from_date);

        switch ($period) {
            case 'day':
                $date->add(new DateInterval('P' . $interval . 'D'));
                break;
            case 'week':
                $date->add(new DateInterval('P' . ($interval * 7) . 'D'));
                break;
            case 'month':
                $date->add(new DateInterval('P' . $interval . 'M'));
                break;
            case 'year':
                $date->add(new DateInterval('P' . $interval . 'Y'));
                break;
        }

        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Process payment
     *
     * @return bool|WP_Error
     */
    public function process_payment() {
        if (!$this->is_active()) {
            return new WP_Error('invalid_status', __('Subscription is not active', 'subs'));
        }

        // Integrate with Stripe payment processing
        $stripe = new Subs_Stripe();
        $result = $stripe->process_subscription_payment($this);

        if (is_wp_error($result)) {
            $this->add_history('payment_failed', '', '', $result->get_error_message());
            return $result;
        }

        // Update payment dates
        $this->set_last_payment_date(current_time('mysql'));
        $this->set_next_payment_date($this->calculate_next_payment_date());

        $this->save();

        $this->add_history('payment_processed', '', '', __('Payment processed successfully', 'subs'));

        do_action('subs_subscription_payment_processed', $this);

        return true;
    }

    /**
     * Cancel subscription
     *
     * @param string $note
     * @return bool|WP_Error
     */
    public function cancel($note = '') {
        if ($this->is_cancelled()) {
            return new WP_Error('already_cancelled', __('Subscription is already cancelled', 'subs'));
        }

        // Cancel in Stripe
        $stripe = new Subs_Stripe();
        $result = $stripe->cancel_subscription($this->get_stripe_subscription_id());

        if (is_wp_error($result)) {
            return $result;
        }

        $this->update_status('cancelled', $note);
        $this->set_end_date(current_time('mysql'));
        $this->save();

        do_action('subs_subscription_cancelled', $this);

        return true;
    }

    /**
     * Pause subscription
     *
     * @param string $note
     * @return bool|WP_Error
     */
    public function pause($note = '') {
        if (!$this->is_active()) {
            return new WP_Error('not_active', __('Only active subscriptions can be paused', 'subs'));
        }

        // Pause in Stripe (if supported by your Stripe implementation)
        $stripe = new Subs_Stripe();
        $result = $stripe->pause_subscription($this->get_stripe_subscription_id());

        if (is_wp_error($result)) {
            return $result;
        }

        $this->update_status('paused', $note);

        do_action('subs_subscription_paused', $this);

        return true;
    }

    /**
     * Resume subscription
     *
     * @param string $note
     * @return bool|WP_Error
     */
    public function resume($note = '') {
        if (!$this->is_paused()) {
            return new WP_Error('not_paused', __('Only paused subscriptions can be resumed', 'subs'));
        }

        // Resume in Stripe
        $stripe = new Subs_Stripe();
        $result = $stripe->resume_subscription($this->get_stripe_subscription_id());

        if (is_wp_error($result)) {
            return $result;
        }

        $this->update_status('active', $note);

        do_action('subs_subscription_resumed', $this);

        return true;
    }
}

/**
 * Get subscription object
 *
 * @param mixed $subscription
 * @return Subs_Subscription|false
 */
function subs_get_subscription($subscription) {
    try {
        return new Subs_Subscription($subscription);
    } catch (Exception $e) {
        return false;
    }
}
