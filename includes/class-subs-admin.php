<?php
/**
 * Admin Controller
 *
 * Handles all admin interface functionality for subscriptions
 *
 * @package Subs
 * @version 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Subs Admin Class
 *
 * @class Subs_Admin
 * @version 1.0.0
 */
class Subs_Admin {

    /**
     * Settings instance
     * @var Subs_Admin_Settings
     */
    public $settings;

    /**
     * Product Settings instance
     * @var Subs_Admin_Product_Settings
     */
    public $product_settings;

    /**
     * Constructor
     */
    public function __construct() {
        $this->init();
        $this->init_hooks();
    }

    /**
     * Initialize admin
     */
    private function init() {
        // Initialize settings if class exists
        if (class_exists('Subs_Admin_Settings')) {
            $this->settings = new Subs_Admin_Settings();
        }

        // Initialize product settings if class exists
        if (class_exists('Subs_Admin_Product_Settings')) {
            $this->product_settings = new Subs_Admin_Product_Settings();
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_notices', array($this, 'admin_notices'));
        add_filter('plugin_action_links_' . plugin_basename(SUBS_PLUGIN_FILE), array($this, 'plugin_action_links'));

        // Add admin bar menu
        add_action('admin_bar_menu', array($this, 'add_admin_bar_menu'), 100);

        // Dashboard widgets
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widgets'));
    }

    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        // Main menu page
        add_menu_page(
            __('Subscriptions', 'subs'),
            __('Subscriptions', 'subs'),
            'manage_subs_subscriptions',
            'subs-subscriptions',
            array($this, 'subscriptions_page'),
            'dashicons-update',
            56
        );

        // Subscriptions list (same as main page)
        add_submenu_page(
            'subs-subscriptions',
            __('All Subscriptions', 'subs'),
            __('All Subscriptions', 'subs'),
            'manage_subs_subscriptions',
            'subs-subscriptions',
            array($this, 'subscriptions_page')
        );

        // Add New Subscription
        add_submenu_page(
            'subs-subscriptions',
            __('Add Subscription', 'subs'),
            __('Add New', 'subs'),
            'manage_subs_subscriptions',
            'subs-add-subscription',
            array($this, 'add_subscription_page')
        );

        // Settings page
        add_submenu_page(
            'subs-subscriptions',
            __('Subscription Settings', 'subs'),
            __('Settings', 'subs'),
            'manage_options',
            'subs-settings',
            array($this, 'settings_page')
        );

        // Reports page
        add_submenu_page(
            'subs-subscriptions',
            __('Subscription Reports', 'subs'),
            __('Reports', 'subs'),
            'manage_subs_subscriptions',
            'subs-reports',
            array($this, 'reports_page')
        );

        // Tools page
        add_submenu_page(
            'subs-subscriptions',
            __('Subscription Tools', 'subs'),
            __('Tools', 'subs'),
            'manage_options',
            'subs-tools',
            array($this, 'tools_page')
        );

        // Hidden pages for individual subscription management
        add_submenu_page(
            null, // Hidden from menu
            __('Edit Subscription', 'subs'),
            __('Edit Subscription', 'subs'),
            'manage_subs_subscriptions',
            'subs-edit-subscription',
            array($this, 'edit_subscription_page')
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on Subs admin pages
        if (strpos($hook, 'subs-') === false && !in_array($hook, array('post.php', 'post-new.php', 'edit.php'))) {
            return;
        }

        // Global admin styles
        wp_enqueue_style(
            'subs-admin',
            SUBS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            SUBS_VERSION
        );

        // Global admin scripts
        wp_enqueue_script(
            'subs-admin',
            SUBS_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery', 'wp-util'),
            SUBS_VERSION,
            true
        );

        // Localize script
        wp_localize_script('subs-admin', 'subs_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('subs_admin_nonce'),
            'strings' => array(
                'confirm_cancel' => __('Are you sure you want to cancel this subscription?', 'subs'),
                'confirm_delete' => __('Are you sure you want to delete this subscription?', 'subs'),
                'processing' => __('Processing...', 'subs'),
                'error_occurred' => __('An error occurred. Please try again.', 'subs'),
            ),
            'settings' => array(
                'currency_symbol' => get_woocommerce_currency_symbol(),
                'date_format' => get_option('date_format'),
                'time_format' => get_option('time_format'),
            )
        ));

        // Load specific scripts for different pages
        if (strpos($hook, 'subs-settings') !== false) {
            wp_enqueue_script('wp-color-picker');
            wp_enqueue_style('wp-color-picker');
        }

        if (strpos($hook, 'subs-reports') !== false) {
            wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.9.1', true);
        }
    }

    /**
     * Display admin notices
     */
    public function admin_notices() {
        // Check for missing WooCommerce
        if (!class_exists('WooCommerce')) {
            ?>
            <div class="notice notice-error">
                <p><strong><?php _e('Subs requires WooCommerce to be installed and active.', 'subs'); ?></strong></p>
                <p>
                    <a href="<?php echo esc_url(admin_url('plugin-install.php?tab=plugin-information&plugin=woocommerce')); ?>" class="button button-primary">
                        <?php _e('Install WooCommerce', 'subs'); ?>
                    </a>
                </p>
            </div>
            <?php
            return;
        }

        // Check for missing Stripe configuration
        $screen = get_current_screen();
        if ($screen && strpos($screen->id, 'subs-') !== false) {
            $test_mode = get_option('subs_stripe_test_mode', 'yes') === 'yes';

            if ($test_mode) {
                $publishable_key = get_option('subs_stripe_test_publishable_key', '');
                $secret_key = get_option('subs_stripe_test_secret_key', '');
            } else {
                $publishable_key = get_option('subs_stripe_live_publishable_key', '');
                $secret_key = get_option('subs_stripe_live_secret_key', '');
            }

            if (empty($publishable_key) || empty($secret_key)) {
                ?>
                <div class="notice notice-warning">
                    <p><strong><?php _e('Stripe configuration incomplete', 'subs'); ?></strong></p>
                    <p>
                        <?php _e('Please configure your Stripe API keys to start processing subscription payments.', 'subs'); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=subs-settings&tab=stripe')); ?>" class="button button-secondary">
                            <?php _e('Configure Stripe', 'subs'); ?>
                        </a>
                    </p>
                </div>
                <?php
            }
        }

        // Check for pending failed payments
        global $wpdb;
        $failed_payments = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}subs_subscriptions
             WHERE status = 'past_due'
             AND next_payment_date < NOW()"
        );

        if ($failed_payments > 0) {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php printf(_n('%d subscription has failed payments', '%d subscriptions have failed payments', $failed_payments, 'subs'), $failed_payments); ?></strong>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=subs-subscriptions&status=past_due')); ?>" class="button button-secondary">
                        <?php _e('Review Failed Payments', 'subs'); ?>
                    </a>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Add plugin action links
     *
     * @param array $links
     * @return array
     */
    public function plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=subs-settings') . '">' . __('Settings', 'subs') . '</a>';
        $subscriptions_link = '<a href="' . admin_url('admin.php?page=subs-subscriptions') . '">' . __('Subscriptions', 'subs') . '</a>';

        array_unshift($links, $settings_link, $subscriptions_link);

        return $links;
    }

    /**
     * Add admin bar menu
     *
     * @param WP_Admin_Bar $wp_admin_bar
     */
    public function add_admin_bar_menu($wp_admin_bar) {
        if (!current_user_can('manage_subs_subscriptions')) {
            return;
        }

        // Get active subscriptions count
        global $wpdb;
        $active_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}subs_subscriptions
             WHERE status IN ('active', 'trialing')"
        );

        $wp_admin_bar->add_node(array(
            'id' => 'subs-admin-bar',
            'title' => sprintf(__('Subscriptions (%d)', 'subs'), $active_count),
            'href' => admin_url('admin.php?page=subs-subscriptions'),
        ));

        $wp_admin_bar->add_node(array(
            'parent' => 'subs-admin-bar',
            'id' => 'subs-all',
            'title' => __('All Subscriptions', 'subs'),
            'href' => admin_url('admin.php?page=subs-subscriptions'),
        ));

        $wp_admin_bar->add_node(array(
            'parent' => 'subs-admin-bar',
            'id' => 'subs-add-new',
            'title' => __('Add New', 'subs'),
            'href' => admin_url('admin.php?page=subs-add-subscription'),
        ));

        $wp_admin_bar->add_node(array(
            'parent' => 'subs-admin-bar',
            'id' => 'subs-settings',
            'title' => __('Settings', 'subs'),
            'href' => admin_url('admin.php?page=subs-settings'),
        ));
    }

    /**
     * Add dashboard widgets
     */
    public function add_dashboard_widgets() {
        if (!current_user_can('manage_subs_subscriptions')) {
            return;
        }

        wp_add_dashboard_widget(
            'subs_dashboard_widget',
            __('Subscription Overview', 'subs'),
            array($this, 'dashboard_widget_content')
        );
    }

    /**
     * Dashboard widget content
     */
    public function dashboard_widget_content() {
        global $wpdb;

        // Get subscription stats
        $stats = $wpdb->get_row(
            "SELECT
                COUNT(*) as total_subscriptions,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_subscriptions,
                SUM(CASE WHEN status = 'trialing' THEN 1 ELSE 0 END) as trialing_subscriptions,
                SUM(CASE WHEN status = 'past_due' THEN 1 ELSE 0 END) as past_due_subscriptions,
                SUM(CASE WHEN status = 'canceled' THEN 1 ELSE 0 END) as canceled_subscriptions
             FROM {$wpdb->prefix}subs_subscriptions"
        );

        // Get revenue stats
        $revenue = $wpdb->get_row(
            "SELECT
                SUM(CASE WHEN s.status IN ('active', 'trialing') THEN s.amount ELSE 0 END) as monthly_recurring_revenue,
                SUM(s.amount) as total_subscription_value
             FROM {$wpdb->prefix}subs_subscriptions s"
        );

        ?>
        <div class="subs-dashboard-widget">
            <div class="subs-stats-grid">
                <div class="subs-stat-box">
                    <h3><?php echo number_format($stats->total_subscriptions ?? 0); ?></h3>
                    <p><?php _e('Total Subscriptions', 'subs'); ?></p>
                </div>
                <div class="subs-stat-box active">
                    <h3><?php echo number_format($stats->active_subscriptions ?? 0); ?></h3>
                    <p><?php _e('Active', 'subs'); ?></p>
                </div>
                <div class="subs-stat-box trialing">
                    <h3><?php echo number_format($stats->trialing_subscriptions ?? 0); ?></h3>
                    <p><?php _e('Trialing', 'subs'); ?></p>
                </div>
                <div class="subs-stat-box past-due">
                    <h3><?php echo number_format($stats->past_due_subscriptions ?? 0); ?></h3>
                    <p><?php _e('Past Due', 'subs'); ?></p>
                </div>
            </div>

            <div class="subs-revenue-stats">
                <div class="subs-stat-box revenue">
                    <h3><?php echo wc_price($revenue->monthly_recurring_revenue ?? 0); ?></h3>
                    <p><?php _e('Monthly Recurring Revenue', 'subs'); ?></p>
                </div>
            </div>

            <div class="subs-quick-links">
                <a href="<?php echo admin_url('admin.php?page=subs-subscriptions'); ?>" class="button button-primary">
                    <?php _e('View All Subscriptions', 'subs'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=subs-add-subscription'); ?>" class="button button-secondary">
                    <?php _e('Add New Subscription', 'subs'); ?>
                </a>
            </div>
        </div>

        <style>
        .subs-dashboard-widget {
            font-size: 13px;
        }

        .subs-stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }

        .subs-stat-box {
            text-align: center;
            padding: 10px;
            background: #f9f9f9;
            border-radius: 4px;
            border-left: 4px solid #ddd;
        }

        .subs-stat-box.active {
            border-left-color: #46b450;
        }

        .subs-stat-box.trialing {
            border-left-color: #ffb900;
        }

        .subs-stat-box.past-due {
            border-left-color: #dc3232;
        }

        .subs-stat-box.revenue {
            border-left-color: #007cba;
        }

        .subs-stat-box h3 {
            margin: 0 0 5px 0;
            font-size: 18px;
            font-weight: bold;
            color: #333;
        }

        .subs-stat-box p {
            margin: 0;
            color: #666;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .subs-revenue-stats {
            margin-bottom: 15px;
        }

        .subs-quick-links {
            text-align: center;
        }

        .subs-quick-links .button {
            margin: 0 5px;
        }
        </style>
        <?php
    }

    /**
     * Subscriptions list page
     */
    public function subscriptions_page() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('Subscriptions', 'subs'); ?></h1>
            <a href="<?php echo admin_url('admin.php?page=subs-add-subscription'); ?>" class="page-title-action">
                <?php _e('Add New', 'subs'); ?>
            </a>
            <hr class="wp-header-end">

            <div id="subs-subscriptions-table">
                <!-- Subscription list table will be loaded here -->
                <p><?php _e('Loading subscriptions...', 'subs'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Add subscription page
     */
    public function add_subscription_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Add New Subscription', 'subs'); ?></h1>

            <div id="subs-add-subscription-form">
                <p><?php _e('Add subscription form will be implemented here.', 'subs'); ?></p>
                <p><em><?php _e('This feature requires the core subscription classes to be implemented.', 'subs'); ?></em></p>
            </div>
        </div>
        <?php
    }

    /**
     * Settings page
     */
    public function settings_page() {
        if ($this->settings && method_exists($this->settings, 'render_settings_page')) {
            $this->settings->render_settings_page();
        } else {
            ?>
            <div class="wrap">
                <h1><?php _e('Subscription Settings', 'subs'); ?></h1>
                <div class="notice notice-error">
                    <p><?php _e('Settings class not found. Please ensure all plugin files are properly installed.', 'subs'); ?></p>
                </div>
            </div>
            <?php
        }
    }

    /**
     * Reports page
     */
    public function reports_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Subscription Reports', 'subs'); ?></h1>

            <div id="subs-reports-content">
                <p><?php _e('Subscription reports and analytics will be displayed here.', 'subs'); ?></p>
                <p><em><?php _e('This feature is coming in a future update.', 'subs'); ?></em></p>
            </div>
        </div>
        <?php
    }

    /**
     * Tools page
     */
    public function tools_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Subscription Tools', 'subs'); ?></h1>

            <div class="subs-tools-grid">
                <div class="subs-tool-card">
                    <h3><?php _e('Import/Export', 'subs'); ?></h3>
                    <p><?php _e('Import and export subscription data.', 'subs'); ?></p>
                    <button class="button button-secondary" disabled>
                        <?php _e('Coming Soon', 'subs'); ?>
                    </button>
                </div>

                <div class="subs-tool-card">
                    <h3><?php _e('Data Cleanup', 'subs'); ?></h3>
                    <p><?php _e('Clean up orphaned subscription data.', 'subs'); ?></p>
                    <button class="button button-secondary" disabled>
                        <?php _e('Coming Soon', 'subs'); ?>
                    </button>
                </div>

                <div class="subs-tool-card">
                    <h3><?php _e('Migration Tools', 'subs'); ?></h3>
                    <p><?php _e('Migrate from other subscription plugins.', 'subs'); ?></p>
                    <button class="button button-secondary" disabled>
                        <?php _e('Coming Soon', 'subs'); ?>
                    </button>
                </div>

                <div class="subs-tool-card">
                    <h3><?php _e('System Status', 'subs'); ?></h3>
                    <p><?php _e('Check system status and requirements.', 'subs'); ?></p>
                    <a href="<?php echo admin_url('admin.php?page=wc-status'); ?>" class="button button-primary">
                        <?php _e('View System Status', 'subs'); ?>
                    </a>
                </div>
            </div>
        </div>

        <style>
        .subs-tools-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .subs-tool-card {
            padding: 20px;
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }

        .subs-tool-card h3 {
            margin-top: 0;
            color: #23282d;
        }

        .subs-tool-card p {
            color: #666;
            margin-bottom: 15px;
        }
        </style>
        <?php
    }

    /**
     * Edit subscription page
     */
    public function edit_subscription_page() {
        $subscription_id = isset($_GET['subscription_id']) ? intval($_GET['subscription_id']) : 0;

        ?>
        <div class="wrap">
            <h1><?php _e('Edit Subscription', 'subs'); ?></h1>

            <?php if ($subscription_id) : ?>
                <div id="subs-edit-subscription-form">
                    <p><?php printf(__('Editing subscription #%d', 'subs'), $subscription_id); ?></p>
                    <p><em><?php _e('Edit subscription form will be implemented here.', 'subs'); ?></em></p>
                </div>
            <?php else : ?>
                <div class="notice notice-error">
                    <p><?php _e('Invalid subscription ID.', 'subs'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get admin capability
     *
     * @return string
     */
    public function get_admin_capability() {
        return apply_filters('subs_admin_capability', 'manage_subs_subscriptions');
    }

    /**
     * Check if user can manage subscriptions
     *
     * @return bool
     */
    public function current_user_can_manage_subscriptions() {
        return current_user_can($this->get_admin_capability());
    }
}
