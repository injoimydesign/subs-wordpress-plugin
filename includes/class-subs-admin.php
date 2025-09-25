<?php
/**
 * Admin Interface Controller
 *
 * Handles all admin-side functionality
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
     * Constructor
     */
    public function __construct() {
        $this->init();
    }

    /**
     * Initialize admin functionality
     */
    private function init() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_init', array($this, 'init_settings'));

        // Add subscription column to orders list
        add_filter('manage_edit-shop_order_columns', array($this, 'add_order_subscription_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'display_order_subscription_column'), 10, 2);

        // Add subscription metabox to orders
        add_action('add_meta_boxes', array($this, 'add_order_subscription_metabox'));

        // AJAX handlers
        add_action('wp_ajax_subs_admin_action', array($this, 'handle_admin_ajax'));

        // Notices
        add_action('admin_notices', array($this, 'admin_notices'));

        // Product settings integration
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_product_subscription_fields'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product_subscription_fields'));
    }

    /**
     * Add admin menu items
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
            25
        );

        // Submenu pages
        add_submenu_page(
            'subs-subscriptions',
            __('All Subscriptions', 'subs'),
            __('All Subscriptions', 'subs'),
            'manage_subs_subscriptions',
            'subs-subscriptions',
            array($this, 'subscriptions_page')
        );

        add_submenu_page(
            'subs-subscriptions',
            __('Settings', 'subs'),
            __('Settings', 'subs'),
            'manage_options',
            'subs-settings',
            array($this, 'settings_page')
        );

        add_submenu_page(
            'subs-subscriptions',
            __('Reports', 'subs'),
            __('Reports', 'subs'),
            'manage_subs_subscriptions',
            'subs-reports',
            array($this, 'reports_page')
        );

        // Hidden pages for individual subscription management
        add_submenu_page(
            null,
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
        if (strpos($hook, 'subs-') === false && !in_array($hook, array('post.php', 'post-new.php'))) {
            return;
        }

        wp_enqueue_style(
            'subs-admin',
            SUBS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            SUBS_VERSION
        );

        wp_enqueue_script(
            'subs-admin',
            SUBS_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
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
            )
        ));
    }

    /**
     * Initialize settings
     */
    public function init_settings() {
        // Register settings sections and fields
        register_setting('subs_settings', 'subs_stripe_test_mode');
        register_setting('subs_settings', 'subs_stripe_test_publishable_key');
        register_setting('subs_settings', 'subs_stripe_test_secret_key');
        register_setting('subs_settings', 'subs_stripe_live_publishable_key');
        register_setting('subs_settings', 'subs_stripe_live_secret_key');
        register_setting('subs_settings', 'subs_stripe_webhook_secret');
        register_setting('subs_settings', 'subs_pass_stripe_fees');
        register_setting('subs_settings', 'subs_stripe_fee_percentage');
        register_setting('subs_settings', 'subs_stripe_fee_fixed');
        register_setting('subs_settings', 'subs_subscription_display_location');
        register_setting('subs_settings', 'subs_enable_trials');
        register_setting('subs_settings', 'subs_default_trial_period');
        register_setting('subs_settings', 'subs_enable_customer_pause');
        register_setting('subs_settings', 'subs_enable_customer_cancel');
        register_setting('subs_settings', 'subs_enable_customer_modify');
        register_setting('subs_settings', 'subs_enable_payment_method_change');
    }

    /**
     * Subscriptions list page
     */
    public function subscriptions_page() {
        $list_table = new Subs_Admin_Subscriptions_List_Table();
        $list_table->prepare_items();

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=subs-edit-subscription&action=new')); ?>" class="page-title-action">
                    <?php _e('Add New', 'subs'); ?>
                </a>
            </h1>

            <?php $list_table->views(); ?>

            <form method="get">
                <input type="hidden" name="page" value="subs-subscriptions" />
                <?php $list_table->search_box(__('Search Subscriptions', 'subs'), 'subscription'); ?>
            </form>

            <form method="post">
                <?php $list_table->display(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Settings page
     */
    public function settings_page() {
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'stripe';

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=subs-settings&tab=stripe" class="nav-tab <?php echo $active_tab == 'stripe' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Stripe Settings', 'subs'); ?>
                </a>
                <a href="?page=subs-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('General Settings', 'subs'); ?>
                </a>
                <a href="?page=subs-settings&tab=display" class="nav-tab <?php echo $active_tab == 'display' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Display Settings', 'subs'); ?>
                </a>
                <a href="?page=subs-settings&tab=emails" class="nav-tab <?php echo $active_tab == 'emails' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Email Settings', 'subs'); ?>
                </a>
            </h2>

            <form method="post" action="options.php">
                <?php
                settings_fields('subs_settings');

                switch ($active_tab) {
                    case 'stripe':
                        $this->render_stripe_settings();
                        break;
                    case 'general':
                        $this->render_general_settings();
                        break;
                    case 'display':
                        $this->render_display_settings();
                        break;
                    case 'emails':
                        $this->render_email_settings();
                        break;
                }

                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Reports page
     */
    public function reports_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="subs-reports-dashboard">
                <div class="subs-stats-grid">
                    <?php $this->render_subscription_stats(); ?>
                </div>

                <div class="subs-charts-container">
                    <?php $this->render_subscription_charts(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Edit subscription page
     */
    public function edit_subscription_page() {
        $subscription_id = isset($_GET['subscription_id']) ? absint($_GET['subscription_id']) : 0;
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'edit';

        if ($action === 'new') {
            $subscription = new Subs_Subscription();
        } else {
            $subscription = new Subs_Subscription($subscription_id);

            if (!$subscription->get_id()) {
                wp_die(__('Subscription not found.', 'subs'));
            }
        }

        // Handle form submission
        if (isset($_POST['save_subscription'])) {
            $this->save_subscription($subscription);
        }

        ?>
        <div class="wrap">
            <h1>
                <?php echo $action === 'new' ? __('Add New Subscription', 'subs') : __('Edit Subscription', 'subs'); ?>
            </h1>

            <form method="post">
                <?php wp_nonce_field('subs_save_subscription', 'subs_subscription_nonce'); ?>

                <div id="poststuff">
                    <div id="post-body" class="metabox-holder columns-2">
                        <div id="post-body-content">
                            <?php $this->render_subscription_edit_form($subscription); ?>
                        </div>

                        <div id="postbox-container-1" class="postbox-container">
                            <?php $this->render_subscription_sidebar($subscription); ?>
                        </div>
                    </div>
                </div>

                <p class="submit">
                    <input type="submit" name="save_subscription" class="button-primary"
                           value="<?php echo $action === 'new' ? __('Create Subscription', 'subs') : __('Update Subscription', 'subs'); ?>">

                    <?php if ($action !== 'new'): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=subs-subscriptions')); ?>" class="button">
                        <?php _e('Back to Subscriptions', 'subs'); ?>
                    </a>
                    <?php endif; ?>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Render Stripe settings tab
     */
    private function render_stripe_settings() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Test Mode', 'subs'); ?></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox" name="subs_stripe_test_mode" value="yes"
                                   <?php checked('yes', get_option('subs_stripe_test_mode', 'yes')); ?>>
                            <?php _e('Enable test mode', 'subs'); ?>
                        </label>
                        <p class="description"><?php _e('Use Stripe test keys for transactions.', 'subs'); ?></p>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php _e('Test Publishable Key', 'subs'); ?></th>
                <td>
                    <input type="text" name="subs_stripe_test_publishable_key"
                           value="<?php echo esc_attr(get_option('subs_stripe_test_publishable_key', '')); ?>"
                           class="regular-text" placeholder="pk_test_...">
                    <p class="description"><?php _e('Your Stripe test publishable key.', 'subs'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php _e('Test Secret Key', 'subs'); ?></th>
                <td>
                    <input type="password" name="subs_stripe_test_secret_key"
                           value="<?php echo esc_attr(get_option('subs_stripe_test_secret_key', '')); ?>"
                           class="regular-text" placeholder="sk_test_...">
                    <p class="description"><?php _e('Your Stripe test secret key.', 'subs'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php _e('Live Publishable Key', 'subs'); ?></th>
                <td>
                    <input type="text" name="subs_stripe_live_publishable_key"
                           value="<?php echo esc_attr(get_option('subs_stripe_live_publishable_key', '')); ?>"
                           class="regular-text" placeholder="pk_live_...">
                    <p class="description"><?php _e('Your Stripe live publishable key.', 'subs'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php _e('Live Secret Key', 'subs'); ?></th>
                <td>
                    <input type="password" name="subs_stripe_live_secret_key"
                           value="<?php echo esc_attr(get_option('subs_stripe_live_secret_key', '')); ?>"
                           class="regular-text" placeholder="sk_live_...">
                    <p class="description"><?php _e('Your Stripe live secret key.', 'subs'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php _e('Webhook Secret', 'subs'); ?></th>
                <td>
                    <input type="text" name="subs_stripe_webhook_secret"
                           value="<?php echo esc_attr(get_option('subs_stripe_webhook_secret', '')); ?>"
                           class="regular-text" placeholder="whsec_...">
                    <p class="description">
                        <?php printf(__('Your Stripe webhook endpoint secret. Webhook URL: %s', 'subs'),
                            '<code>' . home_url('/wp-admin/admin-ajax.php?action=subs_stripe_webhook') . '</code>'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php _e('Pass Stripe Fees to Customer', 'subs'); ?></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox" name="subs_pass_stripe_fees" value="yes"
                                   <?php checked('yes', get_option('subs_pass_stripe_fees', 'no')); ?>>
                            <?php _e('Add Stripe processing fees to subscription cost', 'subs'); ?>
                        </label>
                        <p class="description"><?php _e('When enabled, Stripe fees will be added to the subscription amount.', 'subs'); ?></p>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php _e('Stripe Fee Percentage', 'subs'); ?></th>
                <td>
                    <input type="number" name="subs_stripe_fee_percentage"
                           value="<?php echo esc_attr(get_option('subs_stripe_fee_percentage', '2.9')); ?>"
                           step="0.01" min="0" max="10" class="small-text"> %
                    <p class="description"><?php _e('Stripe percentage fee (e.g., 2.9)', 'subs'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php _e('Stripe Fixed Fee', 'subs'); ?></th>
                <td>
                    <input type="number" name="subs_stripe_fee_fixed"
                           value="<?php echo esc_attr(get_option('subs_stripe_fee_fixed', '0.30')); ?>"
                           step="0.01" min="0" class="small-text"> <?php echo get_woocommerce_currency(); ?>
                    <p class="description"><?php _e('Stripe fixed fee per transaction (e.g., 0.30)', 'subs'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render general settings tab
     */
    private function render_general_settings() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Enable Trials', 'subs'); ?></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox" name="subs_enable_trials" value="yes"
                                   <?php checked('yes', get_option('subs_enable_trials', 'no')); ?>>
                            <?php _e('Enable trial periods for subscriptions', 'subs'); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php _e('Default Trial Period', 'subs'); ?></th>
                <td>
                    <input type="number" name="subs_default_trial_period"
                           value="<?php echo esc_attr(get_option('subs_default_trial_period', '7')); ?>"
                           min="1" class="small-text"> <?php _e('days', 'subs'); ?>
                    <p class="description"><?php _e('Default trial period length in days.', 'subs'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php _e('Customer Actions', 'subs'); ?></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox" name="subs_enable_customer_pause" value="yes"
                                   <?php checked('yes', get_option('subs_enable_customer_pause', 'yes')); ?>>
                            <?php _e('Allow customers to pause subscriptions', 'subs'); ?>
                        </label><br>

                        <label>
                            <input type="checkbox" name="subs_enable_customer_cancel" value="yes"
                                   <?php checked('yes', get_option('subs_enable_customer_cancel', 'yes')); ?>>
                            <?php _e('Allow customers to cancel subscriptions', 'subs'); ?>
                        </label><br>

                        <label>
                            <input type="checkbox" name="subs_enable_customer_modify" value="yes"
                                   <?php checked('yes', get_option('subs_enable_customer_modify', 'yes')); ?>>
                            <?php _e('Allow customers to modify subscriptions', 'subs'); ?>
                        </label><br>

                        <label>
                            <input type="checkbox" name="subs_enable_payment_method_change" value="yes"
                                   <?php checked('yes', get_option('subs_enable_payment_method_change', 'yes')); ?>>
                            <?php _e('Allow customers to change payment methods', 'subs'); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render display settings tab
     */
    private function render_display_settings() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Subscription Option Display', 'subs'); ?></th>
                <td>
                    <select name="subs_subscription_display_location">
                        <option value="before_add_to_cart" <?php selected('before_add_to_cart', get_option('subs_subscription_display_location', 'after_add_to_cart')); ?>>
                            <?php _e('Before Add to Cart button', 'subs'); ?>
                        </option>
                        <option value="after_add_to_cart" <?php selected('after_add_to_cart', get_option('subs_subscription_display_location', 'after_add_to_cart')); ?>>
                            <?php _e('After Add to Cart button', 'subs'); ?>
                        </option>
                        <option value="product_tabs" <?php selected('product_tabs', get_option('subs_subscription_display_location', 'after_add_to_cart')); ?>>
                            <?php _e('In Product Tabs', 'subs'); ?>
                        </option>
                        <option value="checkout_only" <?php selected('checkout_only', get_option('subs_subscription_display_location', 'after_add_to_cart')); ?>>
                            <?php _e('Checkout Page Only', 'subs'); ?>
                        </option>
                    </select>
                    <p class="description"><?php _e('Choose where the subscription option should appear on product pages.', 'subs'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render email settings tab
     */
    private function render_email_settings() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('From Name', 'subs'); ?></th>
                <td>
                    <input type="text" name="subs_from_name"
                           value="<?php echo esc_attr(get_option('subs_from_name', get_bloginfo('name'))); ?>"
                           class="regular-text">
                    <p class="description"><?php _e('Name to use for subscription emails.', 'subs'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php _e('From Email', 'subs'); ?></th>
                <td>
                    <input type="email" name="subs_from_email"
                           value="<?php echo esc_attr(get_option('subs_from_email', get_option('admin_email'))); ?>"
                           class="regular-text">
                    <p class="description"><?php _e('Email address to use for subscription emails.', 'subs'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Add subscription column to orders list
     */
    public function add_order_subscription_column($columns) {
        $new_columns = array();

        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;

            if ($key === 'order_status') {
                $new_columns['subscription'] = __('Subscription', 'subs');
            }
        }

        return $new_columns;
    }

    /**
     * Display subscription column content
     */
    public function display_order_subscription_column($column, $post_id) {
        if ($column === 'subscription') {
            $order = wc_get_order($post_id);

            if ($order && $order->get_meta('_is_subscription_order') === 'yes') {
                // Get subscription for this order
                global $wpdb;
                $subscription_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}subs_subscriptions WHERE order_id = %d",
                        $post_id
                    )
                );

                if ($subscription_id) {
                    $subscription = new Subs_Subscription($subscription_id);
                    printf(
                        '<a href="%s">#%d - %s</a>',
                        esc_url(admin_url('admin.php?page=subs-edit-subscription&subscription_id=' . $subscription_id)),
                        $subscription_id,
                        esc_html($subscription->get_status_label())
                    );
                } else {
                    echo '<span class="text-muted">' . __('Subscription Order', 'subs') . '</span>';
                }
            } else {
                echo '—';
            }
        }
    }

    /**
     * Add subscription metabox to order edit page
     */
    public function add_order_subscription_metabox() {
        global $post;

        if (!$post || $post->post_type !== 'shop_order') {
            return;
        }

        $order = wc_get_order($post->ID);
        if (!$order || $order->get_meta('_is_subscription_order') !== 'yes') {
            return;
        }

        add_meta_box(
            'subs_order_subscription',
            __('Subscription Details', 'subs'),
            array($this, 'render_order_subscription_metabox'),
            'shop_order',
            'normal',
            'high'
        );
    }

    /**
     * Render order subscription metabox
     */
    public function render_order_subscription_metabox($post) {
        $order = wc_get_order($post->ID);

        global $wpdb;
        $subscription_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}subs_subscriptions WHERE order_id = %d",
                $post->ID
            )
        );

        if (empty($subscription_ids)) {
            echo '<p>' . __('No subscriptions found for this order.', 'subs') . '</p>';
            return;
        }

        echo '<div class="subs-order-subscriptions">';

        foreach ($subscription_ids as $subscription_id) {
            $subscription = new Subs_Subscription($subscription_id);
            $product = $subscription->get_product();

            ?>
            <div class="subs-subscription-item">
                <h4>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=subs-edit-subscription&subscription_id=' . $subscription_id)); ?>">
                        <?php printf(__('Subscription #%d', 'subs'), $subscription_id); ?>
                    </a>
                </h4>

                <table class="wp-list-table widefat fixed striped">
                    <tbody>
                        <tr>
                            <th><?php _e('Product', 'subs'); ?></th>
                            <td><?php echo $product ? esc_html($product->get_name()) : __('Product not found', 'subs'); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Status', 'subs'); ?></th>
                            <td>
                                <span class="subs-status-badge subs-status-<?php echo esc_attr($subscription->get_status()); ?>">
                                    <?php echo esc_html($subscription->get_status_label()); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Billing', 'subs'); ?></th>
                            <td><?php echo esc_html($subscription->get_formatted_billing_period()); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Amount', 'subs'); ?></th>
                            <td><?php echo wp_kses_post($subscription->get_formatted_total()); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Next Payment', 'subs'); ?></th>
                            <td>
                                <?php
                                $next_payment = $subscription->get_next_payment_date();
                                echo $next_payment ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($next_payment))) : '—';
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php
        }

        echo '</div>';
    }

    /**
     * Add subscription fields to product data panel
     */
    public function add_product_subscription_fields() {
        global $post;

        echo '<div class="options_group">';

        woocommerce_wp_checkbox(array(
            'id' => '_subscription_enabled',
            'label' => __('Enable Subscription', 'subs'),
            'description' => __('Allow this product to be purchased as a subscription', 'subs'),
        ));

        woocommerce_wp_select(array(
            'id' => '_subscription_period',
            'label' => __('Subscription Period', 'subs'),
            'options' => array(
                'day' => __('Day(s)', 'subs'),
                'week' => __('Week(s)', 'subs'),
                'month' => __('Month(s)', 'subs'),
                'year' => __('Year(s)', 'subs'),
            ),
            'value' => get_post_meta($post->ID, '_subscription_period', true) ?: 'month'
        ));

        woocommerce_wp_text_input(array(
            'id' => '_subscription_period_interval',
            'label' => __('Subscription Interval', 'subs'),
            'description' => __('e.g. for every 2 months, enter 2', 'subs'),
            'type' => 'number',
            'value' => get_post_meta($post->ID, '_subscription_period_interval', true) ?: '1',
            'custom_attributes' => array(
                'min' => '1',
                'step' => '1'
            )
        ));

        woocommerce_wp_text_input(array(
            'id' => '_subscription_trial_period',
            'label' => __('Trial Period (days)', 'subs'),
            'description' => __('Number of days for free trial (optional)', 'subs'),
            'type' => 'number',
            'custom_attributes' => array(
                'min' => '0',
                'step' => '1'
            )
        ));

        echo '</div>';
    }

    /**
     * Save product subscription fields
     */
    public function save_product_subscription_fields($post_id) {
        $fields = array(
            '_subscription_enabled',
            '_subscription_period',
            '_subscription_period_interval',
            '_subscription_trial_period'
        );

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
            } else {
                delete_post_meta($post_id, $field);
            }
        }
    }

    /**
     * Handle admin AJAX requests
     */
    public function handle_admin_ajax() {
        check_ajax_referer('subs_admin_nonce', 'nonce');

        $action = isset($_POST['subs_action']) ? sanitize_text_field($_POST['subs_action']) : '';

        switch ($action) {
            case 'cancel_subscription':
                $this->ajax_cancel_subscription();
                break;

            case 'pause_subscription':
                $this->ajax_pause_subscription();
                break;

            case 'resume_subscription':
                $this->ajax_resume_subscription();
                break;

            case 'delete_subscription':
                $this->ajax_delete_subscription();
                break;

            default:
                wp_send_json_error(__('Invalid action', 'subs'));
        }
    }

    /**
     * AJAX cancel subscription
     */
    private function ajax_cancel_subscription() {
        $subscription_id = isset($_POST['subscription_id']) ? absint($_POST['subscription_id']) : 0;

        if (!$subscription_id) {
            wp_send_json_error(__('Invalid subscription ID', 'subs'));
        }

        $subscription = new Subs_Subscription($subscription_id);

        if (!$subscription->get_id()) {
            wp_send_json_error(__('Subscription not found', 'subs'));
        }

        $result = $subscription->cancel(__('Cancelled by administrator', 'subs'));

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(__('Subscription cancelled successfully', 'subs'));
    }

    /**
     * AJAX pause subscription
     */
    private function ajax_pause_subscription() {
        $subscription_id = isset($_POST['subscription_id']) ? absint($_POST['subscription_id']) : 0;

        if (!$subscription_id) {
            wp_send_json_error(__('Invalid subscription ID', 'subs'));
        }

        $subscription = new Subs_Subscription($subscription_id);

        if (!$subscription->get_id()) {
            wp_send_json_error(__('Subscription not found', 'subs'));
        }

        $result = $subscription->pause(__('Paused by administrator', 'subs'));

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(__('Subscription paused successfully', 'subs'));
    }

    /**
     * AJAX resume subscription
     */
    private function ajax_resume_subscription() {
        $subscription_id = isset($_POST['subscription_id']) ? absint($_POST['subscription_id']) : 0;

        if (!$subscription_id) {
            wp_send_json_error(__('Invalid subscription ID', 'subs'));
        }

        $subscription = new Subs_Subscription($subscription_id);

        if (!$subscription->get_id()) {
            wp_send_json_error(__('Subscription not found', 'subs'));
        }

        $result = $subscription->resume(__('Resumed by administrator', 'subs'));

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(__('Subscription resumed successfully', 'subs'));
    }

    /**
     * AJAX delete subscription
     */
    private function ajax_delete_subscription() {
        $subscription_id = isset($_POST['subscription_id']) ? absint($_POST['subscription_id']) : 0;

        if (!$subscription_id) {
            wp_send_json_error(__('Invalid subscription ID', 'subs'));
        }

        $subscription = new Subs_Subscription($subscription_id);

        if (!$subscription->get_id()) {
            wp_send_json_error(__('Subscription not found', 'subs'));
        }

        // Cancel in Stripe first
        if (!$subscription->is_cancelled()) {
            $subscription->cancel(__('Deleted by administrator', 'subs'));
        }

        // Delete local subscription
        $result = $subscription->delete();

        if (!$result) {
            wp_send_json_error(__('Failed to delete subscription', 'subs'));
        }

        wp_send_json_success(__('Subscription deleted successfully', 'subs'));
    }

    /**
     * Admin notices
     */
    public function admin_notices() {
        // Check if Stripe is configured
        $stripe = new Subs_Stripe();
        if (!$stripe->is_configured()) {
            echo '<div class="notice notice-warning"><p>';
            printf(
                __('Subs: Stripe is not configured. <a href="%s">Configure Stripe settings</a> to start accepting subscription payments.', 'subs'),
                admin_url('admin.php?page=subs-settings&tab=stripe')
            );
            echo '</p></div>';
        }
    }

    /**
     * Render subscription statistics
     */
    private function render_subscription_stats() {
        global $wpdb;

        // Get subscription counts by status
        $stats = $wpdb->get_results(
            "SELECT status, COUNT(*) as count
             FROM {$wpdb->prefix}subs_subscriptions
             GROUP BY status"
        );

        $stat_counts = array();
        $total_subscriptions = 0;

        foreach ($stats as $stat) {
            $stat_counts[$stat->status] = $stat->count;
            $total_subscriptions += $stat->count;
        }

        // Get monthly recurring revenue
        $mrr = $wpdb->get_var(
            "SELECT SUM(total_amount)
             FROM {$wpdb->prefix}subs_subscriptions
             WHERE status IN ('active', 'trialing')
             AND billing_period = 'month'"
        );

        $mrr = $mrr ?: 0;

        ?>
        <div class="subs-stat-box">
            <h3><?php _e('Total Subscriptions', 'subs'); ?></h3>
            <div class="subs-stat-number"><?php echo number_format($total_subscriptions); ?></div>
        </div>

        <div class="subs-stat-box">
            <h3><?php _e('Active Subscriptions', 'subs'); ?></h3>
            <div class="subs-stat-number"><?php echo number_format($stat_counts['active'] ?? 0); ?></div>
        </div>

        <div class="subs-stat-box">
            <h3><?php _e('Monthly Recurring Revenue', 'subs'); ?></h3>
            <div class="subs-stat-number"><?php echo wc_price($mrr); ?></div>
        </div>

        <div class="subs-stat-box">
            <h3><?php _e('Cancelled This Month', 'subs'); ?></h3>
            <div class="subs-stat-number"><?php echo number_format($stat_counts['cancelled'] ?? 0); ?></div>
        </div>
        <?php
    }

    /**
     * Render subscription charts
     */
    private function render_subscription_charts() {
        // This would contain chart rendering logic
        // For now, placeholder content
        ?>
        <div class="subs-chart-container">
            <h3><?php _e('Subscription Growth', 'subs'); ?></h3>
            <p><?php _e('Chart functionality would be implemented here with a charting library like Chart.js', 'subs'); ?></p>
        </div>
        <?php
    }
}
