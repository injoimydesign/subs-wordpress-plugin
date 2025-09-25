<?php
/**
 * Product Settings Integration
 *
 * Handles subscription settings integration with WooCommerce products
 *
 * @package Subs
 * @version 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Subs Admin Product Settings Class
 *
 * @class Subs_Admin_Product_Settings
 * @version 1.0.0
 */
class Subs_Admin_Product_Settings {

    /**
     * Constructor
     */
    public function __construct() {
        $this->init();
    }

    /**
     * Initialize product settings integration
     */
    private function init() {
        // Add subscription fields to product data tabs
        add_filter('woocommerce_product_data_tabs', array($this, 'add_subscription_product_tab'));
        add_action('woocommerce_product_data_panels', array($this, 'add_subscription_product_fields'));

        // Save subscription product fields
        add_action('woocommerce_process_product_meta', array($this, 'save_subscription_product_fields'));

        // Add subscription fields to general tab (alternative display)
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_general_subscription_fields'));

        // Admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Product list table modifications
        add_filter('manage_edit-product_columns', array($this, 'add_product_list_columns'));
        add_action('manage_product_posts_custom_column', array($this, 'display_product_list_columns'), 10, 2);

        // Bulk edit support
        add_action('woocommerce_product_bulk_edit_start', array($this, 'bulk_edit_fields'));
        add_action('woocommerce_product_bulk_edit_save', array($this, 'bulk_edit_save'));

        // Quick edit support
        add_action('woocommerce_product_quick_edit_end', array($this, 'quick_edit_fields'));
        add_action('woocommerce_product_quick_edit_save', array($this, 'quick_edit_save'));

        // Product duplication
        add_action('woocommerce_product_duplicate_before_save', array($this, 'duplicate_subscription_meta'), 10, 2);

        // AJAX handlers
        add_action('wp_ajax_subs_preview_subscription_settings', array($this, 'preview_subscription_settings'));
    }

    /**
     * Add subscription tab to product data tabs
     *
     * @param array $tabs
     * @return array
     */
    public function add_subscription_product_tab($tabs) {
        $tabs['subscription'] = array(
            'label'    => __('Subscription', 'subs'),
            'target'   => 'subs_subscription_product_data',
            'class'    => array('show_if_simple', 'show_if_variable'),
            'priority' => 25,
        );

        return $tabs;
    }

    /**
     * Add subscription fields to product data panels
     */
    public function add_subscription_product_fields() {
        global $post;

        $product = wc_get_product($post->ID);
        $subscription_enabled = get_post_meta($post->ID, '_subscription_enabled', true);
        $subscription_period = get_post_meta($post->ID, '_subscription_period', true) ?: 'month';
        $subscription_interval = get_post_meta($post->ID, '_subscription_period_interval', true) ?: '1';
        $trial_period = get_post_meta($post->ID, '_subscription_trial_period', true) ?: '';
        $signup_fee = get_post_meta($post->ID, '_subscription_signup_fee', true) ?: '';

        ?>
        <div id='subs_subscription_product_data' class='panel woocommerce_options_panel'>
            <div class="options_group">
                <h3><?php _e('Subscription Settings', 'subs'); ?></h3>

                <?php
                woocommerce_wp_checkbox(array(
                    'id'          => '_subscription_enabled',
                    'value'       => $subscription_enabled,
                    'label'       => __('Enable Subscription', 'subs'),
                    'description' => __('Allow this product to be purchased as a subscription.', 'subs'),
                ));
                ?>
            </div>

            <div class="options_group subs-conditional-fields" style="<?php echo $subscription_enabled === 'yes' ? '' : 'display:none;'; ?>">
                <h4><?php _e('Billing Schedule', 'subs'); ?></h4>

                <div class="subs-billing-schedule-wrapper">
                    <p class="form-field">
                        <label><?php _e('Subscription Billing', 'subs'); ?></label>

                        <span class="subs-billing-inputs">
                            <?php _e('Every', 'subs'); ?>

                            <input type="number"
                                   name="_subscription_period_interval"
                                   id="_subscription_period_interval"
                                   value="<?php echo esc_attr($subscription_interval); ?>"
                                   min="1"
                                   step="1"
                                   class="short"
                                   style="width: 80px;" />

                            <select name="_subscription_period" id="_subscription_period" class="short">
                                <option value="day" <?php selected($subscription_period, 'day'); ?>><?php _e('Day(s)', 'subs'); ?></option>
                                <option value="week" <?php selected($subscription_period, 'week'); ?>><?php _e('Week(s)', 'subs'); ?></option>
                                <option value="month" <?php selected($subscription_period, 'month'); ?>><?php _e('Month(s)', 'subs'); ?></option>
                                <option value="year" <?php selected($subscription_period, 'year'); ?>><?php _e('Year(s)', 'subs'); ?></option>
                            </select>
                        </span>

                        <span class="description" id="subs-billing-description">
                            <?php echo $this->get_billing_description($subscription_period, $subscription_interval); ?>
                        </span>
                    </p>
                </div>

                <?php
                // Trial period
                if ('yes' === get_option('subs_enable_trials', 'no')) {
                    woocommerce_wp_text_input(array(
                        'id'                => '_subscription_trial_period',
                        'value'             => $trial_period,
                        'label'             => __('Free Trial', 'subs'),
                        'description'       => __('Number of days for free trial period (leave empty for no trial).', 'subs'),
                        'type'              => 'number',
                        'custom_attributes' => array(
                            'min'  => '0',
                            'step' => '1',
                        ),
                        'desc_tip'          => true,
                    ));
                }

                // Signup fee
                woocommerce_wp_text_input(array(
                    'id'          => '_subscription_signup_fee',
                    'value'       => $signup_fee,
                    'label'       => __('Sign-up Fee', 'subs') . ' (' . get_woocommerce_currency_symbol() . ')',
                    'description' => __('Optional one-time fee charged at signup (in addition to the recurring subscription price).', 'subs'),
                    'type'        => 'number',
                    'custom_attributes' => array(
                        'min'  => '0',
                        'step' => '0.01',
                    ),
                    'desc_tip'    => true,
                ));
                ?>
            </div>

            <div class="options_group subs-conditional-fields" style="<?php echo $subscription_enabled === 'yes' ? '' : 'display:none;'; ?>">
                <h4><?php _e('Subscription Limits', 'subs'); ?></h4>

                <?php
                woocommerce_wp_text_input(array(
                    'id'                => '_subscription_limit',
                    'value'             => get_post_meta($post->ID, '_subscription_limit', true),
                    'label'             => __('Subscription Limit', 'subs'),
                    'description'       => __('Maximum number of times this subscription can be purchased (leave empty for unlimited).', 'subs'),
                    'type'              => 'number',
                    'custom_attributes' => array(
                        'min'  => '1',
                        'step' => '1',
                    ),
                    'desc_tip'          => true,
                ));

                woocommerce_wp_text_input(array(
                    'id'                => '_subscription_length',
                    'value'             => get_post_meta($post->ID, '_subscription_length', true),
                    'label'             => __('Subscription Length', 'subs'),
                    'description'       => __('Number of billing cycles before subscription expires (leave empty for never-ending).', 'subs'),
                    'type'              => 'number',
                    'custom_attributes' => array(
                        'min'  => '1',
                        'step' => '1',
                    ),
                    'desc_tip'          => true,
                ));
                ?>
            </div>

            <div class="options_group subs-conditional-fields" style="<?php echo $subscription_enabled === 'yes' ? '' : 'display:none;'; ?>">
                <h4><?php _e('Subscription Options', 'subs'); ?></h4>

                <?php
                woocommerce_wp_checkbox(array(
                    'id'          => '_subscription_one_time_shipping',
                    'value'       => get_post_meta($post->ID, '_subscription_one_time_shipping', true),
                    'label'       => __('One Time Shipping', 'subs'),
                    'description' => __('Charge shipping only on the first order (not on recurring payments).', 'subs'),
                ));

                woocommerce_wp_checkbox(array(
                    'id'          => '_subscription_prorate_renewal',
                    'value'       => get_post_meta($post->ID, '_subscription_prorate_renewal', true),
                    'label'       => __('Prorate Renewals', 'subs'),
                    'description' => __('Prorate the first renewal payment if subscription starts mid-cycle.', 'subs'),
                ));

                woocommerce_wp_checkbox(array(
                    'id'          => '_subscription_virtual_required',
                    'value'       => get_post_meta($post->ID, '_subscription_virtual_required', true),
                    'label'       => __('Force Virtual Product', 'subs'),
                    'description' => __('Always treat this subscription as a virtual product (no shipping required).', 'subs'),
                ));
                ?>
            </div>

            <?php if ('yes' === get_option('subs_pass_stripe_fees', 'no')): ?>
            <div class="options_group subs-conditional-fields" style="<?php echo $subscription_enabled === 'yes' ? '' : 'display:none;'; ?>">
                <h4><?php _e('Fee Settings', 'subs'); ?></h4>

                <?php
                $stripe = new Subs_Stripe();
                $sample_fee = $stripe->calculate_stripe_fee($product ? $product->get_price() : 10);

                woocommerce_wp_checkbox(array(
                    'id'          => '_subscription_include_stripe_fees',
                    'value'       => get_post_meta($post->ID, '_subscription_include_stripe_fees', true) ?: 'yes',
                    'label'       => __('Include Stripe Fees', 'subs'),
                    'description' => sprintf(
                        __('Add Stripe processing fees to subscription price. (Example: %s fee on %s)', 'subs'),
                        wc_price($sample_fee),
                        wc_price($product ? $product->get_price() : 10)
                    ),
                ));
                ?>
            </div>
            <?php endif; ?>

            <div class="options_group subs-conditional-fields" style="<?php echo $subscription_enabled === 'yes' ? '' : 'display:none;'; ?>">
                <h4><?php _e('Preview', 'subs'); ?></h4>
                <div id="subs-subscription-preview">
                    <?php $this->render_subscription_preview($post->ID); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Add subscription fields to general tab (alternative display)
     */
    public function add_general_subscription_fields() {
        global $post;

        // Only show if the subscription tab is not being used
        $display_location = get_option('subs_subscription_display_location', 'tab');
        if ($display_location === 'tab') {
            return;
        }

        echo '<div class="options_group subs-general-fields">';
        echo '<h3>' . __('Subscription Options', 'subs') . '</h3>';

        woocommerce_wp_checkbox(array(
            'id'          => '_subscription_enabled',
            'label'       => __('Enable Subscription', 'subs'),
            'description' => __('Allow this product to be purchased as a subscription', 'subs'),
        ));

        echo '</div>';
    }

    /**
     * Save subscription product fields
     *
     * @param int $post_id
     */
    public function save_subscription_product_fields($post_id) {
        // Verify nonce
        if (!isset($_POST['woocommerce_meta_nonce']) || !wp_verify_nonce($_POST['woocommerce_meta_nonce'], 'woocommerce_save_data')) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_product', $post_id)) {
            return;
        }

        $fields = array(
            '_subscription_enabled'              => 'string',
            '_subscription_period'               => 'string',
            '_subscription_period_interval'      => 'int',
            '_subscription_trial_period'         => 'int',
            '_subscription_signup_fee'           => 'float',
            '_subscription_limit'                => 'int',
            '_subscription_length'               => 'int',
            '_subscription_one_time_shipping'    => 'string',
            '_subscription_prorate_renewal'      => 'string',
            '_subscription_virtual_required'     => 'string',
            '_subscription_include_stripe_fees'  => 'string',
        );

        foreach ($fields as $field => $type) {
            $value = isset($_POST[$field]) ? $_POST[$field] : '';

            switch ($type) {
                case 'int':
                    $value = $value !== '' ? absint($value) : '';
                    break;
                case 'float':
                    $value = $value !== '' ? floatval($value) : '';
                    break;
                case 'string':
                default:
                    $value = sanitize_text_field($value);
                    break;
            }

            if ($value !== '') {
                update_post_meta($post_id, $field, $value);
            } else {
                delete_post_meta($post_id, $field);
            }
        }

        // Handle subscription enabled logic
        $subscription_enabled = isset($_POST['_subscription_enabled']) ? 'yes' : 'no';
        update_post_meta($post_id, '_subscription_enabled', $subscription_enabled);

        // If subscription is enabled, ensure required fields have defaults
        if ($subscription_enabled === 'yes') {
            if (!get_post_meta($post_id, '_subscription_period', true)) {
                update_post_meta($post_id, '_subscription_period', 'month');
            }
            if (!get_post_meta($post_id, '_subscription_period_interval', true)) {
                update_post_meta($post_id, '_subscription_period_interval', '1');
            }
        }

        // Clear any cached subscription data
        delete_transient('subs_product_' . $post_id . '_subscription_data');

        do_action('subs_product_subscription_settings_saved', $post_id);
    }

    /**
     * Enqueue admin scripts for product editing
     *
     * @param string $hook
     */
    public function enqueue_admin_scripts($hook) {
        if (!in_array($hook, array('post.php', 'post-new.php'))) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'product') {
            return;
        }

        wp_enqueue_script(
            'subs-product-admin',
            SUBS_PLUGIN_URL . 'assets/js/product-admin.js',
            array('jquery', 'wc-enhanced-select'),
            SUBS_VERSION,
            true
        );

        wp_localize_script('subs-product-admin', 'subs_product_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('subs_product_admin'),
            'strings'  => array(
                'preview_loading' => __('Loading preview...', 'subs'),
                'preview_error'   => __('Error loading preview', 'subs'),
            ),
        ));

        wp_enqueue_style(
            'subs-product-admin',
            SUBS_PLUGIN_URL . 'assets/css/product-admin.css',
            array(),
            SUBS_VERSION
        );
    }

    /**
     * Add columns to product list table
     *
     * @param array $columns
     * @return array
     */
    public function add_product_list_columns($columns) {
        $new_columns = array();

        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;

            if ($key === 'product_type') {
                $new_columns['subscription'] = __('Subscription', 'subs');
            }
        }

        return $new_columns;
    }

    /**
     * Display subscription column content
     *
     * @param string $column
     * @param int $post_id
     */
    public function display_product_list_columns($column, $post_id) {
        if ($column === 'subscription') {
            $subscription_enabled = get_post_meta($post_id, '_subscription_enabled', true);

            if ($subscription_enabled === 'yes') {
                $period = get_post_meta($post_id, '_subscription_period', true) ?: 'month';
                $interval = get_post_meta($post_id, '_subscription_period_interval', true) ?: 1;

                echo '<span class="subs-enabled-indicator" title="' . esc_attr($this->get_billing_description($period, $interval)) . '">';
                echo '<span class="dashicons dashicons-update"></span>';
                echo '</span>';
            } else {
                echo '<span class="subs-disabled-indicator">—</span>';
            }
        }
    }

    /**
     * Add bulk edit fields
     */
    public function bulk_edit_fields() {
        ?>
        <div class="inline-edit-group">
            <label class="alignleft">
                <span class="title"><?php _e('Subscription', 'subs'); ?></span>
                <select name="_subscription_enabled">
                    <option value=""><?php _e('— No change —', 'subs'); ?></option>
                    <option value="yes"><?php _e('Enable', 'subs'); ?></option>
                    <option value="no"><?php _e('Disable', 'subs'); ?></option>
                </select>
            </label>
        </div>
        <?php
    }

    /**
     * Save bulk edit fields
     *
     * @param WC_Product $product
     */
    public function bulk_edit_save($product) {
        if (!isset($_REQUEST['_subscription_enabled']) || $_REQUEST['_subscription_enabled'] === '') {
            return;
        }

        $subscription_enabled = sanitize_text_field($_REQUEST['_subscription_enabled']);

        if (in_array($subscription_enabled, array('yes', 'no'))) {
            $product->update_meta_data('_subscription_enabled', $subscription_enabled);
            $product->save();
        }
    }

    /**
     * Add quick edit fields
     */
    public function quick_edit_fields() {
        ?>
        <br class="clear" />
        <div class="inline-edit-group">
            <label>
                <span class="title"><?php _e('Subscription', 'subs'); ?></span>
                <input type="checkbox" name="_subscription_enabled" value="yes" />
                <span class="checkbox-title"><?php _e('Enable subscription', 'subs'); ?></span>
            </label>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#the-list').on('click', '.editinline', function() {
                var post_id = $(this).closest('tr').attr('id').replace('post-', '');
                var $inline_data = $('#woocommerce_inline_' + post_id);
                var subscription_enabled = $inline_data.find('.subscription_enabled').text();

                $('input[name="_subscription_enabled"]', '.inline-edit-row').prop('checked', subscription_enabled === 'yes');
            });
        });
        </script>
        <?php
    }

    /**
     * Save quick edit fields
     *
     * @param WC_Product $product
     */
    public function quick_edit_save($product) {
        if (!isset($_REQUEST['_subscription_enabled'])) {
            $subscription_enabled = 'no';
        } else {
            $subscription_enabled = $_REQUEST['_subscription_enabled'] === 'yes' ? 'yes' : 'no';
        }

        $product->update_meta_data('_subscription_enabled', $subscription_enabled);
        $product->save();
    }

    /**
     * Handle product duplication
     *
     * @param WC_Product $duplicate
     * @param WC_Product $original
     */
    public function duplicate_subscription_meta($duplicate, $original) {
        $subscription_meta_keys = array(
            '_subscription_enabled',
            '_subscription_period',
            '_subscription_period_interval',
            '_subscription_trial_period',
            '_subscription_signup_fee',
            '_subscription_limit',
            '_subscription_length',
            '_subscription_one_time_shipping',
            '_subscription_prorate_renewal',
            '_subscription_virtual_required',
            '_subscription_include_stripe_fees',
        );

        foreach ($subscription_meta_keys as $meta_key) {
            $meta_value = $original->get_meta($meta_key, true);
            if ($meta_value) {
                $duplicate->update_meta_data($meta_key, $meta_value);
            }
        }
    }

    /**
     * AJAX preview subscription settings
     */
    public function preview_subscription_settings() {
        check_ajax_referer('subs_product_admin', 'nonce');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(__('Permission denied', 'subs'));
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

        if (!$product_id) {
            wp_send_json_error(__('Invalid product ID', 'subs'));
        }

        $preview_html = $this->render_subscription_preview($product_id, $_POST);

        wp_send_json_success(array(
            'preview' => $preview_html,
        ));
    }

    /**
     * Render subscription preview
     *
     * @param int $product_id
     * @param array $override_data
     * @return string
     */
    private function render_subscription_preview($product_id, $override_data = array()) {
        $product = wc_get_product($product_id);

        if (!$product) {
            return '<p>' . __('Product not found', 'subs') . '</p>';
        }

        // Get current or override values
        $period = isset($override_data['_subscription_period']) ?
            $override_data['_subscription_period'] :
            (get_post_meta($product_id, '_subscription_period', true) ?: 'month');

        $interval = isset($override_data['_subscription_period_interval']) ?
            intval($override_data['_subscription_period_interval']) :
            intval(get_post_meta($product_id, '_subscription_period_interval', true) ?: 1);

        $trial_period = isset($override_data['_subscription_trial_period']) ?
            intval($override_data['_subscription_trial_period']) :
            intval(get_post_meta($product_id, '_subscription_trial_period', true) ?: 0);

        $signup_fee = isset($override_data['_subscription_signup_fee']) ?
            floatval($override_data['_subscription_signup_fee']) :
            floatval(get_post_meta($product_id, '_subscription_signup_fee', true) ?: 0);

        $base_price = $product->get_price();
        $stripe = new Subs_Stripe();
        $stripe_fee = $stripe->calculate_stripe_fee($base_price);
        $total_price = $base_price + $stripe_fee;

        ob_start();
        ?>
        <div class="subs-preview-content">
            <h5><?php _e('Customer will see:', 'subs'); ?></h5>

            <div class="subs-preview-pricing">
                <div class="price"><?php echo wc_price($total_price); ?></div>
                <div class="billing-period"><?php echo esc_html($this->get_billing_description($period, $interval)); ?></div>

                <?php if ($trial_period > 0): ?>
                <div class="trial-info">
                    <?php printf(_n('with %d day free trial', 'with %d days free trial', $trial_period, 'subs'), $trial_period); ?>
                </div>
                <?php endif; ?>

                <?php if ($signup_fee > 0): ?>
                <div class="signup-fee">
                    <?php printf(__('+ %s signup fee', 'subs'), wc_price($signup_fee)); ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="subs-preview-details">
                <h6><?php _e('Subscription Details:', 'subs'); ?></h6>
                <ul>
                    <li><strong><?php _e('Product Price:', 'subs'); ?></strong> <?php echo wc_price($base_price); ?></li>
                    <?php if ($stripe_fee > 0): ?>
                    <li><strong><?php _e('Processing Fee:', 'subs'); ?></strong> <?php echo wc_price($stripe_fee); ?></li>
                    <?php endif; ?>
                    <li><strong><?php _e('Total per billing cycle:', 'subs'); ?></strong> <?php echo wc_price($total_price); ?></li>
                    <li><strong><?php _e('Billing frequency:', 'subs'); ?></strong> <?php echo esc_html($this->get_billing_description($period, $interval)); ?></li>
                    <?php if ($trial_period > 0): ?>
                    <li><strong><?php _e('Trial period:', 'subs'); ?></strong> <?php printf(_n('%d day', '%d days', $trial_period, 'subs'), $trial_period); ?></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Get billing period description
     *
     * @param string $period
     * @param int $interval
     * @return string
     */
    private function get_billing_description($period, $interval) {
        $intervals = intval($interval);

        $periods = array(
            'day'   => _n('day', 'days', $intervals, 'subs'),
            'week'  => _n('week', 'weeks', $intervals, 'subs'),
            'month' => _n('month', 'months', $intervals, 'subs'),
            'year'  => _n('year', 'years', $intervals, 'subs'),
        );

        $period_text = isset($periods[$period]) ? $periods[$period] : $period;

        if ($intervals == 1) {
            return sprintf(__('Every %s', 'subs'), $period_text);
        } else {
            return sprintf(__('Every %d %s', 'subs'), $intervals, $period_text);
        }
    }

    /**
     * Get subscription product data for external use
     *
     * @param int $product_id
     * @return array|false
     */
    public static function get_subscription_data($product_id) {
        if (get_post_meta($product_id, '_subscription_enabled', true) !== 'yes') {
            return false;
        }

        return array(
            'enabled'                => true,
            'period'                 => get_post_meta($product_id, '_subscription_period', true) ?: 'month',
            'interval'               => intval(get_post_meta($product_id, '_subscription_period_interval', true) ?: 1),
            'trial_period'           => intval(get_post_meta($product_id, '_subscription_trial_period', true) ?: 0),
            'signup_fee'             => floatval(get_post_meta($product_id, '_subscription_signup_fee', true) ?: 0),
            'limit'                  => intval(get_post_meta($product_id, '_subscription_limit', true) ?: 0),
            'length'                 => intval(get_post_meta($product_id, '_subscription_length', true) ?: 0),
            'one_time_shipping'      => get_post_meta($product_id, '_subscription_one_time_shipping', true) === 'yes',
            'prorate_renewal'        => get_post_meta($product_id, '_subscription_prorate_renewal', true) === 'yes',
            'virtual_required'       => get_post_meta($product_id, '_subscription_virtual_required', true) === 'yes',
            'include_stripe_fees'    => get_post_meta($product_id, '_subscription_include_stripe_fees', true) !== 'no',
        );
    }

    /**
     * Check if product has subscription enabled
     *
     * @param int $product_id
     * @return bool
     */
    public static function is_subscription_product($product_id) {
        return get_post_meta($product_id, '_subscription_enabled', true) === 'yes';
    }

    /**
     * Get subscription products
     *
     * @param array $args
     * @return array
     */
    public static function get_subscription_products($args = array()) {
        $defaults = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => '_subscription_enabled',
                    'value'   => 'yes',
                    'compare' => '='
                )
            )
        );

        $args = wp_parse_args($args, $defaults);

        return get_posts($args);
    }

    /**
     * Validate subscription product settings
     *
     * @param int $product_id
     * @return array Array of validation errors (empty if valid)
     */
    public static function validate_subscription_product($product_id) {
        $errors = array();
        $product = wc_get_product($product_id);

        if (!$product) {
            $errors[] = __('Product not found.', 'subs');
            return $errors;
        }

        if (get_post_meta($product_id, '_subscription_enabled', true) !== 'yes') {
            return $errors; // Not a subscription product, no validation needed
        }

        // Check required fields
        $period = get_post_meta($product_id, '_subscription_period', true);
        $interval = get_post_meta($product_id, '_subscription_period_interval', true);

        if (empty($period)) {
            $errors[] = __('Subscription period is required.', 'subs');
        } elseif (!in_array($period, array('day', 'week', 'month', 'year'))) {
            $errors[] = __('Invalid subscription period.', 'subs');
        }

        if (empty($interval) || intval($interval) < 1) {
            $errors[] = __('Subscription interval must be at least 1.', 'subs');
        }

        // Validate trial period
        $trial_period = get_post_meta($product_id, '_subscription_trial_period', true);
        if ($trial_period !== '' && intval($trial_period) < 0) {
            $errors[] = __('Trial period cannot be negative.', 'subs');
        }

        // Validate signup fee
        $signup_fee = get_post_meta($product_id, '_subscription_signup_fee', true);
        if ($signup_fee !== '' && floatval($signup_fee) < 0) {
            $errors[] = __('Signup fee cannot be negative.', 'subs');
        }

        // Validate subscription limit
        $limit = get_post_meta($product_id, '_subscription_limit', true);
        if ($limit !== '' && intval($limit) < 1) {
            $errors[] = __('Subscription limit must be at least 1.', 'subs');
        }

        // Validate subscription length
        $length = get_post_meta($product_id, '_subscription_length', true);
        if ($length !== '' && intval($length) < 1) {
            $errors[] = __('Subscription length must be at least 1.', 'subs');
        }

        // Check if product has a price
        if (!$product->get_price() || floatval($product->get_price()) <= 0) {
            $errors[] = __('Subscription products must have a price greater than 0.', 'subs');
        }

        // Check for conflicting settings
        if ($product->is_virtual() && get_post_meta($product_id, '_subscription_one_time_shipping', true) === 'yes') {
            $errors[] = __('Virtual products cannot have one-time shipping enabled.', 'subs');
        }

        return $errors;
    }

    /**
     * Export subscription product settings
     *
     * @param int $product_id
     * @return array
     */
    public static function export_subscription_settings($product_id) {
        $subscription_data = self::get_subscription_data($product_id);

        if (!$subscription_data) {
            return array();
        }

        $product = wc_get_product($product_id);

        return array(
            'product_id'              => $product_id,
            'product_name'            => $product ? $product->get_name() : '',
            'product_sku'             => $product ? $product->get_sku() : '',
            'subscription_settings'   => $subscription_data,
            'export_date'             => current_time('mysql'),
        );
    }

    /**
     * Import subscription product settings
     *
     * @param int $product_id
     * @param array $settings
     * @return bool|WP_Error
     */
    public static function import_subscription_settings($product_id, $settings) {
        if (!$product_id || !is_array($settings)) {
            return new WP_Error('invalid_data', __('Invalid product ID or settings data.', 'subs'));
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('product_not_found', __('Product not found.', 'subs'));
        }

        // Map settings to meta keys
        $meta_mapping = array(
            'enabled'                => '_subscription_enabled',
            'period'                 => '_subscription_period',
            'interval'               => '_subscription_period_interval',
            'trial_period'           => '_subscription_trial_period',
            'signup_fee'             => '_subscription_signup_fee',
            'limit'                  => '_subscription_limit',
            'length'                 => '_subscription_length',
            'one_time_shipping'      => '_subscription_one_time_shipping',
            'prorate_renewal'        => '_subscription_prorate_renewal',
            'virtual_required'       => '_subscription_virtual_required',
            'include_stripe_fees'    => '_subscription_include_stripe_fees',
        );

        foreach ($meta_mapping as $setting_key => $meta_key) {
            if (isset($settings[$setting_key])) {
                $value = $settings[$setting_key];

                // Convert boolean values to yes/no
                if (is_bool($value)) {
                    $value = $value ? 'yes' : 'no';
                }

                update_post_meta($product_id, $meta_key, $value);
            }
        }

        // Validate the imported settings
        $errors = self::validate_subscription_product($product_id);
        if (!empty($errors)) {
            return new WP_Error('validation_failed', implode(' ', $errors));
        }

        return true;
    }

    /**
     * Get subscription product statistics
     *
     * @return array
     */
    public static function get_subscription_product_stats() {
        global $wpdb;

        // Total products with subscriptions enabled
        $total_subscription_products = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_subscription_enabled'
             AND pm.meta_value = 'yes'
             AND p.post_status = 'publish'
             AND p.post_type = 'product'"
        );

        // Products by billing period
        $period_stats = $wpdb->get_results(
            "SELECT pm2.meta_value as period, COUNT(*) as count
             FROM {$wpdb->postmeta} pm1
             INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
             INNER JOIN {$wpdb->posts} p ON pm1.post_id = p.ID
             WHERE pm1.meta_key = '_subscription_enabled'
             AND pm1.meta_value = 'yes'
             AND pm2.meta_key = '_subscription_period'
             AND p.post_status = 'publish'
             AND p.post_type = 'product'
             GROUP BY pm2.meta_value"
        );

        // Products with trials
        $trial_products = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm1
             INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
             INNER JOIN {$wpdb->posts} p ON pm1.post_id = p.ID
             WHERE pm1.meta_key = '_subscription_enabled'
             AND pm1.meta_value = 'yes'
             AND pm2.meta_key = '_subscription_trial_period'
             AND pm2.meta_value > 0
             AND p.post_status = 'publish'
             AND p.post_type = 'product'"
        );

        return array(
            'total_subscription_products' => intval($total_subscription_products),
            'trial_products'              => intval($trial_products),
            'period_distribution'         => $period_stats,
        );
    }

    /**
     * Cleanup orphaned subscription product data
     *
     * @return int Number of cleaned up records
     */
    public static function cleanup_orphaned_data() {
        global $wpdb;

        $subscription_meta_keys = array(
            '_subscription_enabled',
            '_subscription_period',
            '_subscription_period_interval',
            '_subscription_trial_period',
            '_subscription_signup_fee',
            '_subscription_limit',
            '_subscription_length',
            '_subscription_one_time_shipping',
            '_subscription_prorate_renewal',
            '_subscription_virtual_required',
            '_subscription_include_stripe_fees',
        );

        $placeholders = implode(',', array_fill(0, count($subscription_meta_keys), '%s'));

        $cleaned_up = $wpdb->query($wpdb->prepare(
            "DELETE pm FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key IN ($placeholders)
             AND (p.ID IS NULL OR p.post_type != 'product')",
            $subscription_meta_keys
        ));

        return intval($cleaned_up);
    }
}
