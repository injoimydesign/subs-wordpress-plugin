<?php
/**
 * Admin Subscriptions List Table
 *
 * Displays subscriptions in WordPress admin list table format
 *
 * @package Subs
 * @version 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Include WordPress List Table class
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Subs Admin Subscriptions List Table Class
 *
 * @class Subs_Admin_Subscriptions_List_Table
 * @extends WP_List_Table
 * @version 1.0.0
 */
class Subs_Admin_Subscriptions_List_Table extends WP_List_Table {

    /**
     * Total items count
     * @var int
     */
    private $total_items = 0;

    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct(array(
            'singular' => 'subscription',
            'plural' => 'subscriptions',
            'ajax' => true,
            'screen' => 'subs_subscriptions',
        ));
    }

    /**
     * Get columns
     *
     * @return array
     */
    public function get_columns() {
        return array(
            'cb' => '<input type="checkbox" />',
            'subscription_id' => __('ID', 'subs'),
            'customer' => __('Customer', 'subs'),
            'product' => __('Product', 'subs'),
            'status' => __('Status', 'subs'),
            'amount' => __('Amount', 'subs'),
            'billing_period' => __('Billing', 'subs'),
            'next_payment' => __('Next Payment', 'subs'),
            'start_date' => __('Start Date', 'subs'),
            'actions' => __('Actions', 'subs'),
        );
    }

    /**
     * Get sortable columns
     *
     * @return array
     */
    public function get_sortable_columns() {
        return array(
            'subscription_id' => array('id', false),
            'customer' => array('customer_id', false),
            'status' => array('status', false),
            'amount' => array('total_amount', false),
            'next_payment' => array('next_payment_date', false),
            'start_date' => array('date_created', true), // Default sort
        );
    }

    /**
     * Get bulk actions
     *
     * @return array
     */
    public function get_bulk_actions() {
        return array(
            'pause' => __('Pause', 'subs'),
            'resume' => __('Resume', 'subs'),
            'cancel' => __('Cancel', 'subs'),
            'delete' => __('Delete', 'subs'),
        );
    }

    /**
     * Get views (status filters)
     *
     * @return array
     */
    public function get_views() {
        global $wpdb;

        $status_counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as count
             FROM {$wpdb->prefix}subs_subscriptions
             GROUP BY status",
            OBJECT_K
        );

        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}subs_subscriptions");
        $current_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

        $views = array(
            'all' => sprintf(
                '<a href="%s" %s>%s <span class="count">(%d)</span></a>',
                remove_query_arg('status'),
                $current_status === '' ? 'class="current"' : '',
                __('All', 'subs'),
                $total
            )
        );

        $status_labels = array(
            'active' => __('Active', 'subs'),
            'paused' => __('Paused', 'subs'),
            'cancelled' => __('Cancelled', 'subs'),
            'past_due' => __('Past Due', 'subs'),
            'trialing' => __('Trialing', 'subs'),
            'pending' => __('Pending', 'subs'),
        );

        foreach ($status_labels as $status => $label) {
            if (isset($status_counts[$status])) {
                $count = $status_counts[$status]->count;
                $views[$status] = sprintf(
                    '<a href="%s" %s>%s <span class="count">(%d)</span></a>',
                    add_query_arg('status', $status),
                    $current_status === $status ? 'class="current"' : '',
                    $label,
                    $count
                );
            }
        }

        return $views;
    }

    /**
     * Prepare items for display
     */
    public function prepare_items() {
        global $wpdb;

        // Set up columns
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array($columns, $hidden, $sortable);

        // Handle bulk actions
        $this->process_bulk_action();

        // Pagination
        $per_page = $this->get_items_per_page('subs_subscriptions_per_page', 20);
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        // Build query
        $where = '1=1';
        $where_params = array();

        // Status filter
        if (isset($_GET['status']) && !empty($_GET['status'])) {
            $status = sanitize_text_field($_GET['status']);
            $where .= ' AND status = %s';
            $where_params[] = $status;
        }

        // Search
        if (isset($_GET['s']) && !empty($_GET['s'])) {
            $search = '%' . $wpdb->esc_like(sanitize_text_field($_GET['s'])) . '%';
            $where .= ' AND (
                id LIKE %s OR
                stripe_subscription_id LIKE %s OR
                customer_id IN (
                    SELECT ID FROM ' . $wpdb->users . '
                    WHERE display_name LIKE %s OR user_email LIKE %s
                ) OR
                product_id IN (
                    SELECT ID FROM ' . $wpdb->posts . '
                    WHERE post_title LIKE %s
                )
            )';
            $where_params = array_merge($where_params, array($search, $search, $search, $search, $search));
        }

        // Sorting
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'date_created';
        $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'DESC';

        // Validate orderby
        $allowed_orderby = array('id', 'customer_id', 'status', 'total_amount', 'next_payment_date', 'date_created');
        if (!in_array($orderby, $allowed_orderby)) {
            $orderby = 'date_created';
        }

        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}subs_subscriptions WHERE $where";
        $this->total_items = $wpdb->get_var($wpdb->prepare($count_query, $where_params));

        // Get items
        $query = "SELECT * FROM {$wpdb->prefix}subs_subscriptions
                  WHERE $where
                  ORDER BY $orderby $order
                  LIMIT %d OFFSET %d";

        $query_params = array_merge($where_params, array($per_page, $offset));
        $results = $wpdb->get_results($wpdb->prepare($query, $query_params));

        $this->items = $results;

        // Set pagination
        $this->set_pagination_args(array(
            'total_items' => $this->total_items,
            'per_page' => $per_page,
            'total_pages' => ceil($this->total_items / $per_page)
        ));
    }

    /**
     * Process bulk actions
     */
    public function process_bulk_action() {
        if (!current_user_can('manage_subs_subscriptions')) {
            return;
        }

        $action = $this->current_action();

        if (!$action) {
            return;
        }

        $subscription_ids = isset($_POST['subscription']) ? array_map('absint', $_POST['subscription']) : array();

        if (empty($subscription_ids)) {
            return;
        }

        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'], 'bulk-subscriptions')) {
            wp_die(__('Security check failed.', 'subs'));
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
                    $result = $subscription->pause(__('Bulk action: Paused by administrator', 'subs'));
                    break;

                case 'resume':
                    $result = $subscription->resume(__('Bulk action: Resumed by administrator', 'subs'));
                    break;

                case 'cancel':
                    $result = $subscription->cancel(__('Bulk action: Cancelled by administrator', 'subs'));
                    break;

                case 'delete':
                    // Cancel first if not already cancelled
                    if (!$subscription->is_cancelled()) {
                        $subscription->cancel(__('Bulk action: Cancelled before deletion', 'subs'));
                    }
                    $result = $subscription->delete();
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

        // Show admin notices
        if ($processed > 0) {
            $message = sprintf(_n('%d subscription processed.', '%d subscriptions processed.', $processed, 'subs'), $processed);
            add_action('admin_notices', function() use ($message) {
                echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
            });
        }

        if (!empty($errors)) {
            $error_message = implode('<br>', $errors);
            add_action('admin_notices', function() use ($error_message) {
                echo '<div class="notice notice-error"><p>' . wp_kses_post($error_message) . '</p></div>';
            });
        }

        // Redirect to remove query args
        wp_redirect(remove_query_arg(array('action', 'action2', 'subscription', '_wpnonce')));
        exit;
    }

    /**
     * Checkbox column
     *
     * @param object $item
     * @return string
     */
    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="subscription[]" value="%s" />', $item->id);
    }

    /**
     * Subscription ID column
     *
     * @param object $item
     * @return string
     */
    public function column_subscription_id($item) {
        $edit_url = add_query_arg(array(
            'page' => 'subs-edit-subscription',
            'subscription_id' => $item->id
        ), admin_url('admin.php'));

        return sprintf('<strong><a href="%s">#%d</a></strong>', esc_url($edit_url), $item->id);
    }

    /**
     * Customer column
     *
     * @param object $item
     * @return string
     */
    public function column_customer($item) {
        $customer = get_user_by('id', $item->customer_id);

        if (!$customer) {
            return '<em>' . __('Unknown Customer', 'subs') . '</em>';
        }

        $edit_url = add_query_arg('user_id', $customer->ID, admin_url('user-edit.php'));

        return sprintf(
            '<div class="subs-customer-info">
                <div class="subs-customer-name"><a href="%s">%s</a></div>
                <div class="subs-customer-email">%s</div>
            </div>',
            esc_url($edit_url),
            esc_html($customer->display_name),
            esc_html($customer->user_email)
        );
    }

    /**
     * Product column
     *
     * @param object $item
     * @return string
     */
    public function column_product($item) {
        $product = wc_get_product($item->product_id);

        if (!$product) {
            return '<em>' . __('Product not found', 'subs') . '</em>';
        }

        $edit_url = add_query_arg('post', $product->get_id(), admin_url('post.php?action=edit'));
        $billing_text = $this->get_billing_period_text($item->billing_period, $item->billing_interval);

        return sprintf(
            '<div class="subs-product-info">
                <div class="subs-product-name"><a href="%s">%s</a></div>
                <div class="subs-product-billing">%s</div>
            </div>',
            esc_url($edit_url),
            esc_html($product->get_name()),
            esc_html($billing_text)
        );
    }

    /**
     * Status column
     *
     * @param object $item
     * @return string
     */
    public function column_status($item) {
        $status_labels = array(
            'active' => __('Active', 'subs'),
            'paused' => __('Paused', 'subs'),
            'cancelled' => __('Cancelled', 'subs'),
            'past_due' => __('Past Due', 'subs'),
            'trialing' => __('Trialing', 'subs'),
            'pending' => __('Pending', 'subs'),
        );

        $status_label = isset($status_labels[$item->status]) ? $status_labels[$item->status] : ucfirst($item->status);

        return sprintf(
            '<span class="subs-status-badge subs-status-%s">%s</span>',
            esc_attr($item->status),
            esc_html($status_label)
        );
    }

    /**
     * Amount column
     *
     * @param object $item
     * @return string
     */
    public function column_amount($item) {
        return wc_price($item->total_amount, array('currency' => $item->currency));
    }

    /**
     * Billing period column
     *
     * @param object $item
     * @return string
     */
    public function column_billing_period($item) {
        return $this->get_billing_period_text($item->billing_period, $item->billing_interval);
    }

    /**
     * Next payment column
     *
     * @param object $item
     * @return string
     */
    public function column_next_payment($item) {
        if (!$item->next_payment_date || $item->status === 'cancelled') {
            return '—';
        }

        $date = date_i18n(get_option('date_format'), strtotime($item->next_payment_date));

        // Show overdue status
        if (strtotime($item->next_payment_date) < time()) {
            return '<span style="color: #d63638;">' . esc_html($date) . ' <em>(' . __('Overdue', 'subs') . ')</em></span>';
        }

        return esc_html($date);
    }

    /**
     * Start date column
     *
     * @param object $item
     * @return string
     */
    public function column_start_date($item) {
        return date_i18n(get_option('date_format'), strtotime($item->date_created));
    }

    /**
     * Actions column
     *
     * @param object $item
     * @return string
     */
    public function column_actions($item) {
        $actions = array();

        // Edit link
        $edit_url = add_query_arg(array(
            'page' => 'subs-edit-subscription',
            'subscription_id' => $item->id
        ), admin_url('admin.php'));

        $actions['edit'] = sprintf('<a href="%s">%s</a>', esc_url($edit_url), __('Edit', 'subs'));

        // Status-specific actions
        switch ($item->status) {
            case 'active':
            case 'trialing':
                $actions['pause'] = sprintf(
                    '<a href="#" class="subs-action-pause" data-subscription-id="%d">%s</a>',
                    $item->id,
                    __('Pause', 'subs')
                );
                $actions['cancel'] = sprintf(
                    '<a href="#" class="subs-action-cancel" data-subscription-id="%d" style="color: #d63638;">%s</a>',
                    $item->id,
                    __('Cancel', 'subs')
                );
                break;

            case 'paused':
                $actions['resume'] = sprintf(
                    '<a href="#" class="subs-action-resume" data-subscription-id="%d">%s</a>',
                    $item->id,
                    __('Resume', 'subs')
                );
                $actions['cancel'] = sprintf(
                    '<a href="#" class="subs-action-cancel" data-subscription-id="%d" style="color: #d63638;">%s</a>',
                    $item->id,
                    __('Cancel', 'subs')
                );
                break;

            case 'past_due':
                $actions['reactivate'] = sprintf(
                    '<a href="#" class="subs-action-reactivate" data-subscription-id="%d">%s</a>',
                    $item->id,
                    __('Reactivate', 'subs')
                );
                break;
        }

        // Delete action (always available)
        $actions['delete'] = sprintf(
            '<a href="#" class="subs-action-delete" data-subscription-id="%d" style="color: #d63638;">%s</a>',
            $item->id,
            __('Delete', 'subs')
        );

        return implode(' | ', $actions);
    }

    /**
     * Default column handler
     *
     * @param object $item
     * @param string $column_name
     * @return string
     */
    public function column_default($item, $column_name) {
        switch ($column_name) {
            default:
                return isset($item->$column_name) ? esc_html($item->$column_name) : '';
        }
    }

    /**
     * Display when no items found
     */
    public function no_items() {
        _e('No subscriptions found.', 'subs');
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
            'day' => _n('day', 'days', $interval, 'subs'),
            'week' => _n('week', 'weeks', $interval, 'subs'),
            'month' => _n('month', 'months', $interval, 'subs'),
            'year' => _n('year', 'years', $interval, 'subs'),
        );

        $period_text = isset($periods[$period]) ? $periods[$period] : $period;

        if ($interval == 1) {
            return sprintf(__('Every %s', 'subs'), $period_text);
        } else {
            return sprintf(__('Every %d %s', 'subs'), $interval, $period_text);
        }
    }

    /**
     * Extra tablenav
     *
     * @param string $which
     */
    public function extra_tablenav($which) {
        if ($which === 'top') {
            echo '<div class="alignleft actions">';

            // Date range filter
            $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
            $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';

            echo '<input type="date" name="start_date" value="' . esc_attr($start_date) . '" placeholder="' . esc_attr__('Start date', 'subs') . '">';
            echo '<input type="date" name="end_date" value="' . esc_attr($end_date) . '" placeholder="' . esc_attr__('End date', 'subs') . '">';

            submit_button(__('Filter', 'subs'), 'secondary', 'filter_action', false);

            if ($start_date || $end_date) {
                $clear_url = remove_query_arg(array('start_date', 'end_date'));
                echo ' <a href="' . esc_url($clear_url) . '" class="button">' . __('Clear', 'subs') . '</a>';
            }

            echo '</div>';
        }
    }
}
