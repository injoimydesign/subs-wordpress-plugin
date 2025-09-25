<?php
/**
 * Product Settings Integration
 *
 * Handles subscription settings integration with WooCommerce products
 * Allows any WooCommerce product to be converted into a subscription product
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

        // Add subscription fields to variations
        add_action('woocommerce_product_after_variable_attributes', array($this, 'add_variation_subscription_fields'), 10, 3);
        add_action('woocommerce_save_product_variation', array($this, 'save_variation_subscription_fields'), 10, 2);

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
        add_action('wp_ajax_subs_get_subscription_plans', array($this, 'get_subscription_plans'));

        // Add subscription indicator to product title
        add_filter('the_title', array($this, 'add_subscription_indicator_to_title'), 10, 2);

        // Price display modifications for subscription products
        add_filter('woocommerce_get_price_html', array($this, 'modify_subscription_price_display'), 10, 2);
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
        $subscription_limit = get_post_meta($post->ID, '_subscription_limit', true) ?: '';
        $subscription_length = get_post_meta($post->ID, '_subscription_length', true) ?: '';

        ?>
        <div id='subs_subscription_product_data' class='panel woocommerce_options_panel'>
            <div class="options_group">
                <h3><?php _e('Subscription Settings', 'subs'); ?></h3>

                <?php
                woocommerce_wp_checkbox(array(
                    'id'          => '_subscription_enabled',
                    'value'       => $subscription_enabled,
                    'label'       => __('Enable Subscription', 'subs'),
                    'description' => __('Convert this product into a subscription product that charges customers on a recurring basis.', 'subs'),
                ));
                ?>
            </div>

            <div class="options_group subs-conditional-fields" style="<?php echo $subscription_enabled === 'yes' ? '' : 'display:none;'; ?>">
                <h4><?php _e('Billing Schedule', 'subs'); ?></h4>

                <?php
                woocommerce_wp_select(array(
                    'id'          => '_subscription_period_interval',
                    'label'       => __('Billing Interval', 'subs'),
                    'description' => __('Charge every X period(s)', 'subs'),
                    'value'       => $subscription_interval,
                    'options'     => array(
                        '1'  => __('1', 'subs'),
                        '2'  => __('2', 'subs'),
                        '3'  => __('3', 'subs'),
                        '4'  => __('4', 'subs'),
                        '5'  => __('5', 'subs'),
                        '6'  => __('6', 'subs'),
                        '12' => __('12', 'subs'),
                    ),
                ));

                woocommerce_wp_select(array(
                    'id'          => '_subscription_period',
                    'label'       => __('Billing Period', 'subs'),
                    'description' => __('The billing period for the subscription', 'subs'),
                    'value'       => $subscription_period,
                    'options'     => array(
                        'day'   => __('Day(s)', 'subs'),
                        'week'  => __('Week(s)', 'subs'),
                        'month' => __('Month(s)', 'subs'),
                        'year'  => __('Year(s)', 'subs'),
                    ),
                ));
                ?>
            </div>

            <div class="options_group subs-conditional-fields" style="<?php echo $subscription_enabled === 'yes' ? '' : 'display:none;'; ?>">
                <h4><?php _e('Trial Period & Fees', 'subs'); ?></h4>

                <?php
                woocommerce_wp_text_input(array(
                    'id'                => '_subscription_trial_period',
                    'label'             => __('Trial Period (Days)', 'subs'),
                    'description'       => __('Number of days for the free trial period (0 for no trial)', 'subs'),
                    'value'             => $trial_period,
                    'type'              => 'number',
                    'custom_attributes' => array(
                        'min'  => '0',
                        'step' => '1',
                    ),
                ));

                woocommerce_wp_text_input(array(
                    'id'                => '_subscription_signup_fee',
                    'label'             => __('Sign-up Fee (' . get_woocommerce_currency_symbol() . ')', 'subs'),
                    'description'       => __('One-time fee charged when the subscription starts', 'subs'),
                    'value'             => $signup_fee,
                    'type'              => 'number',
                    'custom_attributes' => array(
                        'min'  => '0',
                        'step' => '0.01',
                    ),
                ));
                ?>
            </div>

            <div class="options_group subs-conditional-fields" style="<?php echo $subscription_enabled === 'yes' ? '' : 'display:none;'; ?>">
                <h4><?php _e('Subscription Limits', 'subs'); ?></h4>

                <?php
                woocommerce_wp_text_input(array(
                    'id'                => '_subscription_length',
                    'label'             => __('Subscription Length', 'subs'),
                    'description'       => __('Number of billing cycles (0 for unlimited)', 'subs'),
                    'value'             => $subscription_length,
                    'type'              => 'number',
                    'custom_attributes' => array(
                        'min'  => '0',
                        'step' => '1',
                    ),
                ));

                woocommerce_wp_text_input(array(
                    'id'                => '_subscription_limit',
                    'label'             => __('Limit per Customer', 'subs'),
                    'description'       => __('Maximum active subscriptions per customer (0 for unlimited)', 'subs'),
                    'value'             => $subscription_limit,
                    'type'              => 'number',
                    'custom_attributes' => array(
                        'min'  => '0',
                        'step' => '1',
                    ),
                ));
                ?>
            </div>

            <div class="options_group subs-conditional-fields" style="<?php echo $subscription_enabled === 'yes' ? '' : 'display:none;'; ?>">
                <h4><?php _e('Advanced Options', 'subs'); ?></h4>

                <?php
                woocommerce_wp_checkbox(array(
                    'id'          => '_subscription_one_time_shipping',
                    'label'       => __('One-time Shipping', 'subs'),
                    'description' => __('Charge shipping only on the first order', 'subs'),
                ));

                woocommerce_wp_checkbox(array(
                    'id'          => '_subscription_prorate_renewal',
                    'label'       => __('Prorate Renewals', 'subs'),
                    'description' => __('Prorate charges when subscription is changed mid-cycle', 'subs'),
                ));

                woocommerce_wp_checkbox(array(
                    'id'          => '_subscription_virtual_required',
                    'label'       => __('Force Virtual Product', 'subs'),
                    'description' => __('Automatically mark subscription orders as virtual', 'subs'),
                ));

                // Only show Stripe fee option if Stripe is configured
                if ($this->is_stripe_configured()) :
                    $sample_fee = $this->calculate_sample_stripe_fee($product ? $product->get_price() : 10);

                    woocommerce_wp_checkbox(array(
                        'id'          => '_subscription_include_stripe_fees',
                        'value'       => get_post_meta($post->ID, '_subscription_include_stripe_fees', true) ?: 'no',
                        'label'       => __('Pass Stripe Fees to Customer', 'subs'),
                        'description' => sprintf(
                            __('Add Stripe processing fees to subscription price. (Example: %s fee on %s)', 'subs'),
                            wc_price($sample_fee),
                            wc_price($product ? $product->get_price() : 10)
                        ),
                    ));
                endif;
                ?>
            </div>

            <div class="options_group subs-conditional-fields" style="<?php echo $subscription_enabled === 'yes' ? '' : 'display:none;'; ?>">
                <h4><?php _e('Subscription Preview', 'subs'); ?></h4>
                <div id="subs-subscription-preview" class="subs-preview-container">
                    <?php $this->render_subscription_preview($post->ID); ?>
                </div>
            </div>

            <div class="options_group subs-conditional-fields" style="<?php echo $subscription_enabled === 'yes' ? '' : 'display:none;'; ?>">
                <h4><?php _e('Customer Display Options', 'subs'); ?></h4>

                <?php
                woocommerce_wp_textarea_input(array(
                    'id'          => '_subscription_description',
                    'label'       => __('Subscription Description', 'subs'),
                    'description' => __('Description shown to customers about this subscription', 'subs'),
                    'value'       => get_post_meta($post->ID, '_subscription_description', true),
                    'rows'        => 3,
                ));

                woocommerce_wp_textarea_input(array(
                    'id'          => '_subscription_benefits',
                    'label'       => __('Subscription Benefits', 'subs'),
                    'description' => __('Benefits shown to customers (one per line)', 'subs'),
                    'value'       => get_post_meta($post->ID, '_subscription_benefits', true),
                    'rows'        => 4,
                ));
                ?>
            </div>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Toggle subscription fields
            $('#_subscription_enabled').change(function() {
                if ($(this).is(':checked')) {
                    $('.subs-conditional-fields').slideDown();
                } else {
                    $('.subs-conditional-fields').slideUp();
                }
            });

            // Update preview when fields change
            $('.subs-conditional-fields input, .subs-conditional-fields select, .subs-conditional-fields textarea').on('change keyup', function() {
                updateSubscriptionPreview();
            });

            function updateSubscriptionPreview() {
                var data = {
                    action: 'subs_preview_subscription_settings',
                    nonce: '<?php echo wp_create_nonce('subs_product_admin'); ?>',
                    product_id: <?php echo $post->ID; ?>,
                    _subscription_period: $('#_subscription_period').val(),
                    _subscription_period_interval: $('#_subscription_period_interval').val(),
                    _subscription_trial_period: $('#_subscription_trial_period').val(),
                    _subscription_signup_fee: $('#_subscription_signup_fee').val(),
                    _subscription_length: $('#_subscription_length').val(),
                    _subscription_include_stripe_fees: $('#_subscription_include_stripe_fees').is(':checked') ? 'yes' : 'no'
                };

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: data,
                    success: function(response) {
                        if (response.success) {
                            $('#subs-subscription-preview').html(response.data.preview);
                        }
                    }
                });
            }
        });
        </script>

        <style>
        .subs-preview-container {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            margin: 10px 0;
        }

        .subs-preview-item {
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
        }

        .subs-preview-label {
            font-weight: bold;
            color: #333;
        }

        .subs-preview-value {
            color: #666;
        }

        .subs-preview-highlight {
            background: #fff;
            padding: 8px;
            border-left: 3px solid #007cba;
            margin-top: 10px;
            font-weight: bold;
        }

        .subs-conditional-fields h4 {
            margin-top: 20px;
            margin-bottom: 10px;
            color: #333;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
        }
        </style>
        <?php
    }

    /**
     * Add subscription fields to product variations
     *
     * @param int $loop
     * @param array $variation_data
     * @param WP_Post $variation
     */
    public function add_variation_subscription_fields($loop, $variation_data, $variation) {
        $variation_subscription_enabled = get_post_meta($variation->ID, '_subscription_enabled', true);
        ?>
        <div class="subs-variation-fields">
            <p class="form-row form-row-full">
                <label>
                    <input type="checkbox" name="_subscription_enabled[<?php echo $loop; ?>]" value="yes" <?php checked($variation_subscription_enabled, 'yes'); ?> />
                    <?php _e('Enable subscription for this variation', 'subs'); ?>
                </label>
            </p>
        </div>
        <?php
    }

    /**
     * Save variation subscription fields
     *
     * @param int $variation_id
     * @param int $i
     */
    public function save_variation_subscription_fields($variation_id, $i) {
        $subscription_enabled = isset($_POST['_subscription_enabled'][$i]) ? 'yes' : 'no';
        update_post_meta($variation_id, '_subscription_enabled', $subscription_enabled);
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
            '_subscription_description'          => 'textarea',
            '_subscription_benefits'             => 'textarea',
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
                case 'textarea':
                    $value = sanitize_textarea_field($value);
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

        // If subscription is enabled, ensure we have some required fields
        if ($subscription_enabled === 'yes') {
            $period = get_post_meta($post_id, '_subscription_period', true);
            $interval = get_post_meta($post_id, '_subscription_period_interval', true);

            if (!$period) {
                update_post_meta($post_id, '_subscription_period', 'month');
            }
            if (!$interval) {
                update_post_meta($post_id, '_subscription_period_interval', '1');
            }
        }

        // Hook for additional processing
        do_action('subs_product_subscription_settings_saved', $post_id, $_POST);
    }

    /**
     * Enqueue admin scripts
     *
     * @param string $hook
     */
    public function enqueue_admin_scripts($hook) {
        if (!in_array($hook, array('post.php', 'post-new.php', 'edit.php'))) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'product') {
            return;
        }

        wp_enqueue_style(
            'subs-product-admin',
            SUBS_PLUGIN_URL . 'assets/css/admin-product.css',
            array(),
            SUBS_VERSION
        );

        wp_enqueue_script(
            'subs-product-admin',
            SUBS_PLUGIN_URL . 'assets/js/admin-product.js',
            array('jquery', 'wc-admin-meta-boxes'),
            SUBS_VERSION,
            true
        );

        wp_localize_script('subs-product-admin', 'subs_product_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('subs_product_admin'),
            'strings' => array(
                'preview_loading' => __('Updating preview...', 'subs'),
                'preview_error' => __('Error updating preview', 'subs'),
            )
        ));
    }

    /**
     * Add subscription column to product list
     *
     * @param array $columns
     * @return array
     */
    public function add_product_list_columns($columns) {
        // Add subscription column after the product type column
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
        if ($column !== 'subscription') {
            return;
        }

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

                if ($inline_data.length) {
                    var subscription_enabled = $inline_data.find('.subscription_enabled').text();
                    $('input[name="_subscription_enabled"]', '.inline-edit-row').prop('checked', subscription_enabled === 'yes');
                }
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
            '_subscription_description',
            '_subscription_benefits',
        );

        foreach ($subscription_meta_keys as $meta_key) {
            $meta_value = $original->get_meta($meta_key, true);
            if ($meta_value) {
                $duplicate->update_meta_data($meta_key, $meta_value);
            }
        }
    }

    /**
     * Add subscription indicator to product title in admin
     *
     * @param string $title
     * @param int $id
     * @return string
     */
    public function add_subscription_indicator_to_title($title, $id) {
        if (!is_admin() || !$id) {
            return $title;
        }

        $post = get_post($id);
        if (!$post || $post->post_type !== 'product') {
            return $title;
        }

        $subscription_enabled = get_post_meta($id, '_subscription_enabled', true);
        if ($subscription_enabled === 'yes') {
            $title .= ' <span class="subs-title-indicator">[' . __('Subscription', 'subs') . ']</span>';
        }

        return $title;
    }

    /**
     * Modify price display for subscription products
     *
     * @param string $price_html
     * @param WC_Product $product
     * @return string
     */
    public function modify_subscription_price_display($price_html, $product) {
        if (!is_admin()) {
            return $price_html;
        }

        $subscription_enabled = $product->get_meta('_subscription_enabled', true);
        if ($subscription_enabled !== 'yes') {
            return $price_html;
        }

        $period = $product->get_meta('_subscription_period', true) ?: 'month';
        $interval = $product->get_meta('_subscription_period_interval', true) ?: 1;

        $billing_description = $this->get_billing_description($period, $interval);

        return $price_html . ' <span class="subs-billing-period">/ ' . $billing_description . '</span>';
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
     * AJAX get subscription plans
     */
    public function get_subscription_plans() {
        check_ajax_referer('subs_product_admin', 'nonce');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(__('Permission denied', 'subs'));
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

        if (!$product_id) {
            wp_send_json_error(__('Invalid product ID', 'subs'));
        }

        $plans = $this->get_product_subscription_plans($product_id);

        wp_send_json_success(array(
            'plans' => $plans,
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

        $length = isset($override_data['_subscription_length']) ?
            intval($override_data['_subscription_length']) :
            intval(get_post_meta($product_id, '_subscription_length', true) ?: 0);

        $include_stripe_fees = isset($override_data['_subscription_include_stripe_fees']) ?
            $override_data['_subscription_include_stripe_fees'] :
            get_post_meta($product_id, '_subscription_include_stripe_fees', true);

        // Calculate pricing
        $base_price = $product->get_price();
        $stripe_fee = 0;

        if ($include_stripe_fees === 'yes' && $this->is_stripe_configured()) {
            $stripe_fee = $this->calculate_sample_stripe_fee($base_price);
        }

        $total_recurring_price = $base_price + $stripe_fee;

        // Build preview HTML
        $preview = '<div class="subs-preview-content">';

        // Billing schedule
        $billing_description = $this->get_billing_description($period, $interval);
        $preview .= '<div class="subs-preview-item">';
        $preview .= '<span class="subs-preview-label">' . __('Billing Schedule:', 'subs') . '</span>';
        $preview .= '<span class="subs-preview-value">Every ' . $billing_description . '</span>';
        $preview .= '</div>';

        // Recurring price
        $preview .= '<div class="subs-preview-item">';
        $preview .= '<span class="subs-preview-label">' . __('Recurring Price:', 'subs') . '</span>';
        $preview .= '<span class="subs-preview-value">' . wc_price($total_recurring_price) . '</span>';
        $preview .= '</div>';

        // Stripe fee breakdown
        if ($stripe_fee > 0) {
            $preview .= '<div class="subs-preview-item">';
            $preview .= '<span class="subs-preview-label">' . __('Processing Fee:', 'subs') . '</span>';
            $preview .= '<span class="subs-preview-value">' . wc_price($stripe_fee) . '</span>';
            $preview .= '</div>';
        }

        // Trial period
        if ($trial_period > 0) {
            $preview .= '<div class="subs-preview-item">';
            $preview .= '<span class="subs-preview-label">' . __('Trial Period:', 'subs') . '</span>';
            $preview .= '<span class="subs-preview-value">' . sprintf(_n('%d day', '%d days', $trial_period, 'subs'), $trial_period) . '</span>';
            $preview .= '</div>';
        }

        // Sign-up fee
        if ($signup_fee > 0) {
            $preview .= '<div class="subs-preview-item">';
            $preview .= '<span class="subs-preview-label">' . __('Sign-up Fee:', 'subs') . '</span>';
            $preview .= '<span class="subs-preview-value">' . wc_price($signup_fee) . '</span>';
            $preview .= '</div>';
        }

        // Subscription length
        if ($length > 0) {
            $preview .= '<div class="subs-preview-item">';
            $preview .= '<span class="subs-preview-label">' . __('Subscription Length:', 'subs') . '</span>';
            $preview .= '<span class="subs-preview-value">' . sprintf(_n('%d billing cycle', '%d billing cycles', $length, 'subs'), $length) . '</span>';
            $preview .= '</div>';
        } else {
            $preview .= '<div class="subs-preview-item">';
            $preview .= '<span class="subs-preview-label">' . __('Subscription Length:', 'subs') . '</span>';
            $preview .= '<span class="subs-preview-value">' . __('Unlimited', 'subs') . '</span>';
            $preview .= '</div>';
        }

        // Total first payment calculation
        $first_payment = $trial_period > 0 ? $signup_fee : ($total_recurring_price + $signup_fee);
        if ($first_payment > 0) {
            $preview .= '<div class="subs-preview-highlight">';
            $preview .= __('First Payment:', 'subs') . ' <strong>' . wc_price($first_payment) . '</strong>';
            if ($trial_period > 0 && $signup_fee == 0) {
                $preview .= ' <em>(' . __('Free Trial', 'subs') . ')</em>';
            }
            $preview .= '</div>';
        }

        $preview .= '</div>';

        return $preview;
    }

    /**
     * Get product subscription plans (for multiple plan support)
     *
     * @param int $product_id
     * @return array
     */
    private function get_product_subscription_plans($product_id) {
        $plans = array();

        // For now, create a single plan based on the product settings
        $subscription_enabled = get_post_meta($product_id, '_subscription_enabled', true);

        if ($subscription_enabled === 'yes') {
            $product = wc_get_product($product_id);

            if ($product) {
                $period = get_post_meta($product_id, '_subscription_period', true) ?: 'month';
                $interval = get_post_meta($product_id, '_subscription_period_interval', true) ?: 1;
                $trial_period = get_post_meta($product_id, '_subscription_trial_period', true) ?: 0;
                $signup_fee = get_post_meta($product_id, '_subscription_signup_fee', true) ?: 0;

                $plans['default'] = array(
                    'id' => 'default',
                    'name' => sprintf(__('%s Subscription', 'subs'), $product->get_name()),
                    'price' => $product->get_price(),
                    'interval' => $period,
                    'interval_count' => intval($interval),
                    'trial_days' => intval($trial_period),
                    'sign_up_fee' => floatval($signup_fee),
                    'description' => get_post_meta($product_id, '_subscription_description', true),
                );
            }
        }

        return apply_filters('subs_product_subscription_plans', $plans, $product_id);
    }

    /**
     * Get billing description
     *
     * @param string $period
     * @param int $interval
     * @return string
     */
    private function get_billing_description($period, $interval = 1) {
        $interval = max(1, intval($interval));

        switch ($period) {
            case 'day':
                return $interval === 1 ? __('day', 'subs') : sprintf(__('%d days', 'subs'), $interval);
            case 'week':
                return $interval === 1 ? __('week', 'subs') : sprintf(__('%d weeks', 'subs'), $interval);
            case 'month':
                return $interval === 1 ? __('month', 'subs') : sprintf(__('%d months', 'subs'), $interval);
            case 'year':
                return $interval === 1 ? __('year', 'subs') : sprintf(__('%d years', 'subs'), $interval);
            default:
                return $period;
        }
    }

    /**
     * Calculate sample Stripe fee
     *
     * @param float $amount
     * @return float
     */
    private function calculate_sample_stripe_fee($amount) {
        $percentage = floatval(get_option('subs_stripe_fee_percentage', 2.9)) / 100;
        $fixed = floatval(get_option('subs_stripe_fee_fixed', 30)) / 100; // Convert cents to dollars

        return ($amount * $percentage) + $fixed;
    }

    /**
     * Check if Stripe is configured
     *
     * @return bool
     */
    private function is_stripe_configured() {
        $test_mode = get_option('subs_stripe_test_mode', 'yes') === 'yes';

        if ($test_mode) {
            $publishable_key = get_option('subs_stripe_test_publishable_key', '');
            $secret_key = get_option('subs_stripe_test_secret_key', '');
        } else {
            $publishable_key = get_option('subs_stripe_live_publishable_key', '');
            $secret_key = get_option('subs_stripe_live_secret_key', '');
        }

        return !empty($publishable_key) && !empty($secret_key);
    }

    /**
     * Check if product is subscription enabled
     *
     * @param int|WC_Product $product
     * @return bool
     */
    public static function is_subscription_product($product) {
        if (is_numeric($product)) {
            $product_id = $product;
        } elseif (is_a($product, 'WC_Product')) {
            $product_id = $product->get_id();
        } else {
            return false;
        }

        return get_post_meta($product_id, '_subscription_enabled', true) === 'yes';
    }

    /**
     * Get subscription settings for a product
     *
     * @param int $product_id
     * @return array
     */
    public static function get_subscription_settings($product_id) {
        if (!self::is_subscription_product($product_id)) {
            return array();
        }

        return array(
            'enabled' => true,
            'period' => get_post_meta($product_id, '_subscription_period', true) ?: 'month',
            'interval' => intval(get_post_meta($product_id, '_subscription_period_interval', true) ?: 1),
            'trial_period' => intval(get_post_meta($product_id, '_subscription_trial_period', true) ?: 0),
            'signup_fee' => floatval(get_post_meta($product_id, '_subscription_signup_fee', true) ?: 0),
            'limit' => intval(get_post_meta($product_id, '_subscription_limit', true) ?: 0),
            'length' => intval(get_post_meta($product_id, '_subscription_length', true) ?: 0),
            'one_time_shipping' => get_post_meta($product_id, '_subscription_one_time_shipping', true) === 'yes',
            'prorate_renewal' => get_post_meta($product_id, '_subscription_prorate_renewal', true) === 'yes',
            'virtual_required' => get_post_meta($product_id, '_subscription_virtual_required', true) === 'yes',
            'include_stripe_fees' => get_post_meta($product_id, '_subscription_include_stripe_fees', true) === 'yes',
            'description' => get_post_meta($product_id, '_subscription_description', true),
            'benefits' => get_post_meta($product_id, '_subscription_benefits', true),
        );
    }

    /**
     * Export subscription product settings
     *
     * @param int $product_id
     * @return array
     */
    public static function export_subscription_settings($product_id) {
        $product = wc_get_product($product_id);

        if (!$product) {
            return array();
        }

        $subscription_data = self::get_subscription_settings($product_id);

        return array(
            'product_id'              => $product_id,
            'product_name'            => $product->get_name(),
            'product_sku'             => $product->get_sku(),
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
            'description'            => '_subscription_description',
            'benefits'               => '_subscription_benefits',
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

        do_action('subs_product_subscription_settings_imported', $product_id, $settings);

        return true;
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
                    'key'   => '_subscription_enabled',
                    'value' => 'yes',
                ),
            ),
        );

        $args = wp_parse_args($args, $defaults);
        $posts = get_posts($args);

        $products = array();
        foreach ($posts as $post) {
            $product = wc_get_product($post->ID);
            if ($product) {
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * Convert regular product to subscription product
     *
     * @param int $product_id
     * @param array $subscription_settings
     * @return bool|WP_Error
     */
    public static function convert_to_subscription_product($product_id, $subscription_settings = array()) {
        $product = wc_get_product($product_id);

        if (!$product) {
            return new WP_Error('product_not_found', __('Product not found.', 'subs'));
        }

        // Set default subscription settings
        $defaults = array(
            'period' => 'month',
            'interval' => 1,
            'trial_period' => 0,
            'signup_fee' => 0,
        );

        $settings = wp_parse_args($subscription_settings, $defaults);

        // Enable subscription
        update_post_meta($product_id, '_subscription_enabled', 'yes');

        // Apply settings
        foreach ($settings as $key => $value) {
            $meta_key = '_subscription_' . $key;
            update_post_meta($product_id, $meta_key, $value);
        }

        do_action('subs_product_converted_to_subscription', $product_id, $settings);

        return true;
    }

    /**
     * Convert subscription product to regular product
     *
     * @param int $product_id
     * @return bool|WP_Error
     */
    public static function convert_to_regular_product($product_id) {
        $product = wc_get_product($product_id);

        if (!$product) {
            return new WP_Error('product_not_found', __('Product not found.', 'subs'));
        }

        // Disable subscription
        update_post_meta($product_id, '_subscription_enabled', 'no');

        do_action('subs_product_converted_to_regular', $product_id);

        return true;
    }
}
