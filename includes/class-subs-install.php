<?php
/**
 * Installation related functions and actions
 *
 * @package Subs
 * @version 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Subs Install Class
 *
 * @class Subs_Install
 * @version 1.0.0
 */
class Subs_Install {

    /**
     * DB updates and callbacks that need to be run per version
     *
     * @var array
     */
    private static $db_updates = array(
        '1.0.0' => array(
            'subs_100_create_tables',
            'subs_100_create_options',
            'subs_100_create_pages',
            'subs_100_update_db_version',
        ),
    );

    /**
     * Install Subs
     */
    public static function install() {
        if (!is_blog_installed()) {
            return;
        }

        // Check if we are not already running this routine
        if ('yes' === get_transient('subs_installing')) {
            return;
        }

        // If we made it here, the install is running
        set_transient('subs_installing', 'yes', MINUTE_IN_SECONDS * 10);

        // Create database tables
        self::create_tables();

        // Create default options
        self::create_options();

        // Create default pages
        self::create_pages();

        // Create user roles and capabilities
        self::create_roles();

        // Set up cron jobs
        self::setup_cron_jobs();

        // Check version and run updates if necessary
        self::check_version();

        // Clear any cached data
        self::clear_cache();

        // Trigger action
        do_action('subs_installed');

        // Remove the install transient
        delete_transient('subs_installing');
    }

    /**
     * Check Subs version and run the updater if necessary
     */
    private static function check_version() {
        if (!defined('IFRAME_REQUEST') && version_compare(get_option('subs_version'), SUBS_VERSION, '<')) {
            self::update();
            do_action('subs_updated');
        }
    }

    /**
     * Update Subs version to current
     */
    private static function update() {
        $current_db_version = get_option('subs_db_version', null);
        $update_count       = 0;

        foreach (self::$db_updates as $version => $update_callbacks) {
            if (version_compare($current_db_version, $version, '<')) {
                foreach ($update_callbacks as $update_callback) {
                    if (method_exists(__CLASS__, $update_callback)) {
                        call_user_func(array(__CLASS__, $update_callback));
                        ++$update_count;
                    }
                }
            }
        }

        if ($update_count) {
            self::update_db_version();
        }

        self::update_version();
    }

    /**
     * Update DB version to current
     */
    public static function update_db_version($version = null) {
        update_option('subs_db_version', is_null($version) ? SUBS_VERSION : $version);
    }

    /**
     * Update Subs version to current
     */
    private static function update_version() {
        update_option('subs_version', SUBS_VERSION);
    }

    /**
     * Create tables for subscriptions
     */
    private static function create_tables() {
        global $wpdb;

        $wpdb->hide_errors();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(self::get_schema());

        // Trigger action after tables created
        do_action('subs_tables_created');
    }

    /**
     * Get Table schema for subscriptions
     *
     * @return string
     */
    private static function get_schema() {
        global $wpdb;

        $collate = '';

        if ($wpdb->has_cap('collation')) {
            $collate = $wpdb->get_charset_collate();
        }

        $tables = "
        CREATE TABLE {$wpdb->prefix}subs_subscriptions (
            id bigint(20) unsigned NOT NULL auto_increment,
            order_id bigint(20) unsigned NOT NULL,
            customer_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            stripe_subscription_id varchar(255) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            billing_period varchar(20) NOT NULL DEFAULT 'month',
            billing_interval int(11) NOT NULL DEFAULT 1,
            start_date datetime NOT NULL,
            next_payment_date datetime NOT NULL,
            last_payment_date datetime NULL,
            end_date datetime NULL,
            trial_end_date datetime NULL,
            subscription_amount decimal(10,2) NOT NULL,
            stripe_fee_amount decimal(10,2) NOT NULL DEFAULT 0,
            total_amount decimal(10,2) NOT NULL,
            currency varchar(3) NOT NULL DEFAULT 'USD',
            payment_method_id varchar(255) NULL,
            flag_address text NULL,
            notes text NULL,
            date_created datetime NOT NULL,
            date_modified datetime NOT NULL,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY customer_id (customer_id),
            KEY product_id (product_id),
            KEY stripe_subscription_id (stripe_subscription_id(191)),
            KEY status (status),
            KEY next_payment_date (next_payment_date),
            KEY billing_period (billing_period),
            KEY date_created (date_created)
        ) $collate;

        CREATE TABLE {$wpdb->prefix}subs_subscription_meta (
            meta_id bigint(20) unsigned NOT NULL auto_increment,
            subscription_id bigint(20) unsigned NOT NULL,
            meta_key varchar(255) NOT NULL,
            meta_value longtext NULL,
            PRIMARY KEY (meta_id),
            KEY subscription_id (subscription_id),
            KEY meta_key (meta_key(191)),
            KEY subscription_meta (subscription_id, meta_key(191))
        ) $collate;

        CREATE TABLE {$wpdb->prefix}subs_subscription_history (
            id bigint(20) unsigned NOT NULL auto_increment,
            subscription_id bigint(20) unsigned NOT NULL,
            action varchar(50) NOT NULL,
            status_from varchar(20) NULL,
            status_to varchar(20) NULL,
            note text NULL,
            user_id bigint(20) unsigned NULL,
            date_created datetime NOT NULL,
            PRIMARY KEY (id),
            KEY subscription_id (subscription_id),
            KEY action (action),
            KEY date_created (date_created),
            KEY status_from (status_from),
            KEY status_to (status_to)
        ) $collate;

        CREATE TABLE {$wpdb->prefix}subs_payment_logs (
            id bigint(20) unsigned NOT NULL auto_increment,
            subscription_id bigint(20) unsigned NOT NULL,
            stripe_invoice_id varchar(255) NULL,
            stripe_payment_intent_id varchar(255) NULL,
            amount decimal(10,2) NOT NULL,
            currency varchar(3) NOT NULL DEFAULT 'USD',
            status varchar(50) NOT NULL,
            failure_reason text NULL,
            date_created datetime NOT NULL,
            PRIMARY KEY (id),
            KEY subscription_id (subscription_id),
            KEY stripe_invoice_id (stripe_invoice_id(191)),
            KEY stripe_payment_intent_id (stripe_payment_intent_id(191)),
            KEY status (status),
            KEY date_created (date_created)
        ) $collate;

        CREATE TABLE {$wpdb->prefix}subs_email_queue (
            id bigint(20) unsigned NOT NULL auto_increment,
            subscription_id bigint(20) unsigned NULL,
            customer_id bigint(20) unsigned NOT NULL,
            email_type varchar(50) NOT NULL,
            to_email varchar(100) NOT NULL,
            subject varchar(255) NOT NULL,
            message longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts int(11) NOT NULL DEFAULT 0,
            last_attempt datetime NULL,
            date_created datetime NOT NULL,
            date_sent datetime NULL,
            PRIMARY KEY (id),
            KEY subscription_id (subscription_id),
            KEY customer_id (customer_id),
            KEY email_type (email_type),
            KEY status (status),
            KEY date_created (date_created)
        ) $collate;
        ";

        return $tables;
    }

    /**
     * Create default plugin options
     */
    private static function create_options() {
        $default_options = array(
            // Stripe Settings
            'subs_stripe_test_mode' => 'yes',
            'subs_stripe_test_publishable_key' => '',
            'subs_stripe_test_secret_key' => '',
            'subs_stripe_live_publishable_key' => '',
            'subs_stripe_live_secret_key' => '',
            'subs_stripe_webhook_secret' => '',
            'subs_pass_stripe_fees' => 'no',
            'subs_stripe_fee_percentage' => '2.9',
            'subs_stripe_fee_fixed' => '0.30',

            // General Settings
            'subs_enable_trials' => 'no',
            'subs_default_trial_period' => '7',
            'subs_enable_customer_pause' => 'yes',
            'subs_enable_customer_cancel' => 'yes',
            'subs_enable_customer_modify' => 'yes',
            'subs_enable_payment_method_change' => 'yes',
            'subs_subscription_page_endpoint' => 'subscriptions',

            // Display Settings
            'subs_subscription_display_location' => 'after_add_to_cart',

            // Email Settings
            'subs_from_name' => get_bloginfo('name'),
            'subs_from_email' => get_option('admin_email'),
            'subs_email_footer_text' => sprintf(__('This email was sent from %s', 'subs'), get_bloginfo('name')),

            // Advanced Settings
            'subs_retry_failed_payments' => 'yes',
            'subs_max_retry_attempts' => '3',
            'subs_retry_delay_hours' => '24',
            'subs_delete_data_on_uninstall' => 'no',

            // Version tracking
            'subs_version' => SUBS_VERSION,
            'subs_db_version' => SUBS_VERSION,
            'subs_installed_date' => current_time('mysql'),
        );

        foreach ($default_options as $option => $value) {
            if (get_option($option) === false) {
                add_option($option, $value);
            }
        }
    }

    /**
     * Create default pages
     */
    private static function create_pages() {
        // Create subscription management page if it doesn't exist
        $existing_page_id = get_option('subs_subscription_page_id');

        if (!$existing_page_id || !get_post($existing_page_id)) {
            $subscription_page_id = wp_insert_post(array(
                'post_title'     => __('Manage Subscriptions', 'subs'),
                'post_content'   => '[subs_account]',
                'post_status'    => 'publish',
                'post_type'      => 'page',
                'comment_status' => 'closed',
                'ping_status'    => 'closed',
                'post_author'    => 1,
                'menu_order'     => 0,
            ));

            if ($subscription_page_id && !is_wp_error($subscription_page_id)) {
                update_option('subs_subscription_page_id', $subscription_page_id);
            }
        }

        // Flush rewrite rules after creating pages
        flush_rewrite_rules();
    }

    /**
     * Create roles and capabilities
     */
    private static function create_roles() {
        global $wp_roles;

        if (!class_exists('WP_Roles')) {
            return;
        }

        if (!isset($wp_roles)) {
            $wp_roles = new WP_Roles();
        }

        // Subscription management capabilities
        $capabilities = array(
            'manage_subs_subscriptions'    => __('Manage Subscriptions', 'subs'),
            'edit_subs_subscriptions'      => __('Edit Subscriptions', 'subs'),
            'delete_subs_subscriptions'    => __('Delete Subscriptions', 'subs'),
            'view_subs_subscriptions'      => __('View Subscriptions', 'subs'),
            'read_subs_subscriptions'      => __('Read Subscriptions', 'subs'),
            'edit_subs_settings'           => __('Edit Subscription Settings', 'subs'),
            'view_subs_reports'            => __('View Subscription Reports', 'subs'),
            'export_subs_data'             => __('Export Subscription Data', 'subs'),
        );

        // Add capabilities to administrator
        foreach ($capabilities as $cap => $description) {
            $wp_roles->add_cap('administrator', $cap);
        }

        // Add capabilities to shop manager (if exists)
        if ($wp_roles->get_role('shop_manager')) {
            foreach ($capabilities as $cap => $description) {
                $wp_roles->add_cap('shop_manager', $cap);
            }
        }

        // Create subscription manager role
        $wp_roles->add_role('subscription_manager', __('Subscription Manager', 'subs'), array(
            'read'                         => true,
            'manage_subs_subscriptions'    => true,
            'edit_subs_subscriptions'      => true,
            'view_subs_subscriptions'      => true,
            'read_subs_subscriptions'      => true,
            'view_subs_reports'            => true,
        ));
    }

    /**
     * Setup cron jobs
     */
    private static function setup_cron_jobs() {
        // Daily cleanup and maintenance
        if (!wp_next_scheduled('subs_daily_maintenance')) {
            wp_schedule_event(time(), 'daily', 'subs_daily_maintenance');
        }

        // Hourly payment processing
        if (!wp_next_scheduled('subs_process_payments')) {
            wp_schedule_event(time(), 'hourly', 'subs_process_payments');
        }

        // Email queue processing (every 5 minutes)
        if (!wp_next_scheduled('subs_process_email_queue')) {
            wp_schedule_event(time(), 'subs_five_minutes', 'subs_process_email_queue');
        }

        // Trial ending notifications (daily)
        if (!wp_next_scheduled('subs_trial_ending_notifications')) {
            wp_schedule_event(time(), 'daily', 'subs_trial_ending_notifications');
        }

        // Add custom cron schedule for 5 minutes
        add_filter('cron_schedules', array(__CLASS__, 'add_cron_schedules'));
    }

    /**
     * Add custom cron schedules
     *
     * @param array $schedules
     * @return array
     */
    public static function add_cron_schedules($schedules) {
        $schedules['subs_five_minutes'] = array(
            'interval' => 300,
            'display'  => __('Every 5 Minutes', 'subs'),
        );

        $schedules['subs_fifteen_minutes'] = array(
            'interval' => 900,
            'display'  => __('Every 15 Minutes', 'subs'),
        );

        return $schedules;
    }

    /**
     * Clear any cached data
     */
    private static function clear_cache() {
        // Clear WordPress cache
        wp_cache_flush();

        // Clear object cache if available
        if (function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();
        }

        // Clear any plugin-specific transients
        delete_transient('subs_stripe_connection_test');
        delete_transient('subs_subscription_stats');
    }

    /**
     * Database updates for version 1.0.0
     */
    public static function subs_100_create_tables() {
        self::create_tables();
    }

    /**
     * Create options for version 1.0.0
     */
    public static function subs_100_create_options() {
        self::create_options();
    }

    /**
     * Create pages for version 1.0.0
     */
    public static function subs_100_create_pages() {
        self::create_pages();
    }

    /**
     * Update database version for 1.0.0
     */
    public static function subs_100_update_db_version() {
        self::update_db_version('1.0.0');
    }

    /**
     * Check if plugin tables exist
     *
     * @return bool
     */
    public static function tables_exist() {
        global $wpdb;

        $tables = array(
            $wpdb->prefix . 'subs_subscriptions',
            $wpdb->prefix . 'subs_subscription_meta',
            $wpdb->prefix . 'subs_subscription_history',
            $wpdb->prefix . 'subs_payment_logs',
            $wpdb->prefix . 'subs_email_queue',
        );

        foreach ($tables as $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get database info
     *
     * @return array
     */
    public static function get_db_info() {
        global $wpdb;

        $info = array(
            'version' => get_option('subs_version'),
            'db_version' => get_option('subs_db_version'),
            'installed_date' => get_option('subs_installed_date'),
            'tables_exist' => self::tables_exist(),
            'tables' => array(),
        );

        // Get table info
        $tables = array(
            'subscriptions' => $wpdb->prefix . 'subs_subscriptions',
            'subscription_meta' => $wpdb->prefix . 'subs_subscription_meta',
            'subscription_history' => $wpdb->prefix . 'subs_subscription_history',
            'payment_logs' => $wpdb->prefix . 'subs_payment_logs',
            'email_queue' => $wpdb->prefix . 'subs_email_queue',
        );

        foreach ($tables as $key => $table_name) {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
            $info['tables'][$key] = array(
                'name' => $table_name,
                'exists' => ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name),
                'count' => $count !== null ? intval($count) : 0,
            );
        }

        return $info;
    }

    /**
     * Repair database tables
     *
     * @return bool
     */
    public static function repair_database() {
        global $wpdb;

        try {
            // Recreate tables
            self::create_tables();

            // Verify tables were created
            if (!self::tables_exist()) {
                return false;
            }

            // Update version numbers
            self::update_db_version();
            self::update_version();

            return true;

        } catch (Exception $e) {
            error_log('Subs: Database repair failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Run database maintenance
     */
    public static function run_maintenance() {
        global $wpdb;

        // Clean up old history entries (keep last 1000 per subscription)
        $wpdb->query("
            DELETE h1 FROM {$wpdb->prefix}subs_subscription_history h1
            INNER JOIN (
                SELECT subscription_id, MIN(id) as min_id
                FROM {$wpdb->prefix}subs_subscription_history
                GROUP BY subscription_id
                HAVING COUNT(*) > 1000
            ) h2 ON h1.subscription_id = h2.subscription_id
            WHERE h1.id < h2.min_id + 1000
        ");

        // Clean up old payment logs (keep last 6 months)
        $six_months_ago = date('Y-m-d H:i:s', strtotime('-6 months'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}subs_payment_logs WHERE date_created < %s",
            $six_months_ago
        ));

        // Clean up processed email queue (older than 30 days)
        $thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}subs_email_queue
             WHERE status = 'sent' AND date_sent < %s",
            $thirty_days_ago
        ));

        // Clean up failed email queue (older than 7 days, more than 5 attempts)
        $seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}subs_email_queue
             WHERE status = 'failed' AND attempts > 5 AND last_attempt < %s",
            $seven_days_ago
        ));

        // Optimize tables
        $tables = array(
            $wpdb->prefix . 'subs_subscriptions',
            $wpdb->prefix . 'subs_subscription_meta',
            $wpdb->prefix . 'subs_subscription_history',
            $wpdb->prefix . 'subs_payment_logs',
            $wpdb->prefix . 'subs_email_queue',
        );

        foreach ($tables as $table) {
            $wpdb->query("OPTIMIZE TABLE {$table}");
        }

        do_action('subs_maintenance_completed');
    }
}
