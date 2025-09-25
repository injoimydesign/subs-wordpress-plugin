<?php
/**
 * Admin Settings
 *
 * Handles all subscription admin settings and configuration
 *
 * @package Subs
 * @version 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Subs Admin Settings Class
 *
 * @class Subs_Admin_Settings
 * @version 1.0.0
 */
class Subs_Admin_Settings {

    /**
     * Settings sections
     * @var array
     */
    private $sections = array();

    /**
     * Settings fields
     * @var array
     */
    private $fields = array();

    /**
     * Current tab
     * @var string
     */
    private $current_tab = '';

    /**
     * Constructor
     */
    public function __construct() {
        $this->init();
        $this->init_hooks();
    }

    /**
     * Initialize settings
     */
    private function init() {
        $this->current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
        $this->init_sections();
        $this->init_fields();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_subs_test_stripe_connection', array($this, 'ajax_test_stripe_connection'));
        add_action('wp_ajax_subs_reset_settings', array($this, 'ajax_reset_settings'));
    }

    /**
     * Initialize sections
     */
    private function init_sections() {
        $this->sections = array(
            'general' => array(
                'title' => __('General Settings', 'subs'),
                'description' => __('Configure general subscription settings.', 'subs'),
            ),
            'stripe' => array(
                'title' => __('Stripe Integration', 'subs'),
                'description' => __('Configure your Stripe payment gateway settings for subscriptions.', 'subs'),
            ),
            'emails' => array(
                'title' => __('Email Settings', 'subs'),
                'description' => __('Configure subscription email notifications.', 'subs'),
            ),
            'advanced' => array(
                'title' => __('Advanced Settings', 'subs'),
                'description' => __('Advanced configuration options for subscription management.', 'subs'),
            ),
        );
    }

    /**
     * Initialize fields
     */
    private function init_fields() {
        $this->fields = array(
            'general' => array(
                'subs_enable_subscriptions' => array(
                    'title' => __('Enable Subscriptions', 'subs'),
                    'type' => 'checkbox',
                    'description' => __('Enable subscription functionality site-wide.', 'subs'),
                    'default' => 'yes',
                ),
                'subs_subscription_page' => array(
                    'title' => __('Subscription Page', 'subs'),
                    'type' => 'select',
                    'description' => __('Select the page where customers can manage their subscriptions.', 'subs'),
                    'options' => $this->get_pages_for_select(),
                    'default' => '',
                ),
                'subs_subscription_slug' => array(
                    'title' => __('Subscription URL Slug', 'subs'),
                    'type' => 'text',
                    'description' => __('URL slug for subscription pages. Default: subscription', 'subs'),
                    'default' => 'subscription',
                    'placeholder' => 'subscription',
                ),
                'subs_currency' => array(
                    'title' => __('Subscription Currency', 'subs'),
                    'type' => 'select',
                    'description' => __('Default currency for subscriptions (inherits from WooCommerce if not set).', 'subs'),
                    'options' => get_woocommerce_currencies(),
                    'default' => get_woocommerce_currency(),
                ),
                'subs_proration' => array(
                    'title' => __('Enable Proration', 'subs'),
                    'type' => 'checkbox',
                    'description' => __('Enable proration for subscription changes and upgrades.', 'subs'),
                    'default' => 'yes',
                ),
                'subs_pause_limit' => array(
                    'title' => __('Pause Limit', 'subs'),
                    'type' => 'number',
                    'description' => __('Maximum number of times a subscription can be paused (0 for unlimited).', 'subs'),
                    'default' => '3',
                    'min' => '0',
                ),
                'subs_max_pause_duration' => array(
                    'title' => __('Max Pause Duration (Days)', 'subs'),
                    'type' => 'number',
                    'description' => __('Maximum number of days a subscription can be paused (0 for unlimited).', 'subs'),
                    'default' => '90',
                    'min' => '0',
                ),
            ),
            'stripe' => array(
                'subs_stripe_test_mode' => array(
                    'title' => __('Test Mode', 'subs'),
                    'type' => 'checkbox',
                    'description' => __('Enable Stripe test mode for development and testing.', 'subs'),
                    'default' => 'yes',
                ),
                'subs_stripe_test_publishable_key' => array(
                    'title' => __('Test Publishable Key', 'subs'),
                    'type' => 'text',
                    'description' => __('Your Stripe test publishable key.', 'subs'),
                    'placeholder' => 'pk_test_...',
                ),
                'subs_stripe_test_secret_key' => array(
                    'title' => __('Test Secret Key', 'subs'),
                    'type' => 'password',
                    'description' => __('Your Stripe test secret key.', 'subs'),
                    'placeholder' => 'sk_test_...',
                ),
                'subs_stripe_live_publishable_key' => array(
                    'title' => __('Live Publishable Key', 'subs'),
                    'type' => 'text',
                    'description' => __('Your Stripe live publishable key.', 'subs'),
                    'placeholder' => 'pk_live_...',
                ),
                'subs_stripe_live_secret_key' => array(
                    'title' => __('Live Secret Key', 'subs'),
                    'type' => 'password',
                    'description' => __('Your Stripe live secret key.', 'subs'),
                    'placeholder' => 'sk_live_...',
                ),
                'subs_stripe_webhook_secret' => array(
                    'title' => __('Webhook Endpoint Secret', 'subs'),
                    'type' => 'password',
                    'description' => __('Your Stripe webhook endpoint secret for security validation.', 'subs'),
                    'placeholder' => 'whsec_...',
                ),
                'subs_stripe_pass_fees' => array(
                    'title' => __('Pass Processing Fees to Customer', 'subs'),
                    'type' => 'checkbox',
                    'description' => __('Add Stripe processing fees to the customer invoice.', 'subs'),
                    'default' => 'no',
                ),
                'subs_stripe_fee_percentage' => array(
                    'title' => __('Fee Percentage', 'subs'),
                    'type' => 'number',
                    'description' => __('Stripe processing fee percentage (default: 2.9%).', 'subs'),
                    'default' => '2.9',
                    'step' => '0.1',
                    'min' => '0',
                ),
                'subs_stripe_fee_fixed' => array(
                    'title' => __('Fixed Fee (Cents)', 'subs'),
                    'type' => 'number',
                    'description' => __('Stripe fixed fee in cents (default: 30 cents).', 'subs'),
                    'default' => '30',
                    'min' => '0',
                ),
            ),
            'emails' => array(
                'subs_email_enabled' => array(
                    'title' => __('Enable Email Notifications', 'subs'),
                    'type' => 'checkbox',
                    'description' => __('Enable subscription email notifications.', 'subs'),
                    'default' => 'yes',
                ),
                'subs_email_from_name' => array(
                    'title' => __('From Name', 'subs'),
                    'type' => 'text',
                    'description' => __('Email sender name (defaults to site name).', 'subs'),
                    'default' => get_bloginfo('name'),
                ),
                'subs_email_from_address' => array(
                    'title' => __('From Email', 'subs'),
                    'type' => 'email',
                    'description' => __('Email sender address (defaults to admin email).', 'subs'),
                    'default' => get_option('admin_email'),
                ),
                'subs_email_renewal_notice' => array(
                    'title' => __('Renewal Notice Days', 'subs'),
                    'type' => 'number',
                    'description' => __('Send renewal notice X days before renewal (0 to disable).', 'subs'),
                    'default' => '3',
                    'min' => '0',
                ),
                'subs_email_failed_payment_retry' => array(
                    'title' => __('Failed Payment Retry Days', 'subs'),
                    'type' => 'text',
                    'description' => __('Days to retry failed payments (comma-separated, e.g., 1,3,7).', 'subs'),
                    'default' => '1,3,7',
                    'placeholder' => '1,3,7',
                ),
                'subs_email_trial_ending_notice' => array(
                    'title' => __('Trial Ending Notice Days', 'subs'),
                    'type' => 'number',
                    'description' => __('Send trial ending notice X days before trial ends (0 to disable).', 'subs'),
                    'default' => '3',
                    'min' => '0',
                ),
            ),
            'advanced' => array(
                'subs_debug_mode' => array(
                    'title' => __('Debug Mode', 'subs'),
                    'type' => 'checkbox',
                    'description' => __('Enable detailed logging for debugging (check WordPress debug log).', 'subs'),
                    'default' => 'no',
                ),
                'subs_webhook_url' => array(
                    'title' => __('Webhook URL', 'subs'),
                    'type' => 'text',
                    'description' => __('Webhook URL to configure in your Stripe dashboard.', 'subs'),
                    'default' => home_url('/wp-json/subs/v1/stripe/webhook'),
                    'readonly' => true,
                ),
                'subs_cleanup_data' => array(
                    'title' => __('Cleanup on Uninstall', 'subs'),
                    'type' => 'checkbox',
                    'description' => __('Remove all subscription data when plugin is uninstalled.', 'subs'),
                    'default' => 'no',
                ),
                'subs_subscription_limit' => array(
                    'title' => __('Subscription Limit per Customer', 'subs'),
                    'type' => 'number',
                    'description' => __('Maximum active subscriptions per customer (0 for unlimited).', 'subs'),
                    'default' => '0',
                    'min' => '0',
                ),
                'subs_grace_period' => array(
                    'title' => __('Grace Period (Hours)', 'subs'),
                    'type' => 'number',
                    'description' => __('Hours to wait before canceling subscription after failed payment.', 'subs'),
                    'default' => '72',
                    'min' => '1',
                ),
            ),
        );
    }

    /**
     * Register settings with WordPress
     */
    public function register_settings() {
        // Register settings for each section
        foreach ($this->fields as $section => $fields) {
            foreach ($fields as $field_id => $field) {
                register_setting(
                    'subs_' . $section . '_settings',
                    $field_id,
                    array(
                        'sanitize_callback' => array($this, 'sanitize_field'),
                        'default' => isset($field['default']) ? $field['default'] : '',
                    )
                );
            }
        }

        // Add settings sections
        foreach ($this->sections as $section_id => $section) {
            add_settings_section(
                'subs_' . $section_id,
                $section['title'],
                array($this, 'render_section_description'),
                'subs_' . $section_id . '_settings'
            );
        }

        // Add settings fields
        foreach ($this->fields as $section => $fields) {
            foreach ($fields as $field_id => $field) {
                add_settings_field(
                    $field_id,
                    $field['title'],
                    array($this, 'render_field'),
                    'subs_' . $section . '_settings',
                    'subs_' . $section,
                    array(
                        'id' => $field_id,
                        'field' => $field,
                        'section' => $section,
                    )
                );
            }
        }
    }

    /**
     * Render section description
     *
     * @param array $args
     */
    public function render_section_description($args) {
        $section_id = str_replace('subs_', '', $args['id']);

        if (isset($this->sections[$section_id]['description'])) {
            echo '<p>' . esc_html($this->sections[$section_id]['description']) . '</p>';
        }

        // Add special content for certain sections
        if ($section_id === 'stripe') {
            echo '<div class="subs-stripe-connection-test">';
            echo '<button type="button" class="button button-secondary" id="test-stripe-connection">';
            echo __('Test Stripe Connection', 'subs');
            echo '</button>';
            echo '<span class="subs-connection-result"></span>';
            echo '</div>';
        }
    }

    /**
     * Render settings field
     *
     * @param array $args
     */
    public function render_field($args) {
        $field_id = $args['id'];
        $field = $args['field'];
        $value = get_option($field_id, isset($field['default']) ? $field['default'] : '');

        $class = isset($field['class']) ? $field['class'] : 'regular-text';
        $placeholder = isset($field['placeholder']) ? $field['placeholder'] : '';
        $readonly = isset($field['readonly']) && $field['readonly'] ? 'readonly' : '';

        echo '<div class="subs-field-wrapper">';

        switch ($field['type']) {
            case 'text':
            case 'email':
                printf(
                    '<input type="%s" id="%s" name="%s" value="%s" placeholder="%s" class="%s" %s />',
                    esc_attr($field['type']),
                    esc_attr($field_id),
                    esc_attr($field_id),
                    esc_attr($value),
                    esc_attr($placeholder),
                    esc_attr($class),
                    $readonly
                );
                break;

            case 'password':
                printf(
                    '<input type="password" id="%s" name="%s" value="%s" placeholder="%s" class="%s" />',
                    esc_attr($field_id),
                    esc_attr($field_id),
                    esc_attr($value),
                    esc_attr($placeholder),
                    esc_attr($class)
                );
                break;

            case 'number':
                $min = isset($field['min']) ? $field['min'] : '';
                $max = isset($field['max']) ? $field['max'] : '';
                $step = isset($field['step']) ? $field['step'] : '1';

                printf(
                    '<input type="number" id="%s" name="%s" value="%s" placeholder="%s" class="%s" min="%s" max="%s" step="%s" />',
                    esc_attr($field_id),
                    esc_attr($field_id),
                    esc_attr($value),
                    esc_attr($placeholder),
                    esc_attr($class),
                    esc_attr($min),
                    esc_attr($max),
                    esc_attr($step)
                );
                break;

            case 'textarea':
                $rows = isset($field['rows']) ? $field['rows'] : '5';
                printf(
                    '<textarea id="%s" name="%s" placeholder="%s" class="%s" rows="%s">%s</textarea>',
                    esc_attr($field_id),
                    esc_attr($field_id),
                    esc_attr($placeholder),
                    esc_attr($class),
                    esc_attr($rows),
                    esc_textarea($value)
                );
                break;

            case 'checkbox':
                printf(
                    '<label for="%s"><input type="checkbox" id="%s" name="%s" value="yes" %s /> %s</label>',
                    esc_attr($field_id),
                    esc_attr($field_id),
                    esc_attr($field_id),
                    checked($value, 'yes', false),
                    esc_html($field['description'] ?? '')
                );
                break;

            case 'select':
                printf('<select id="%s" name="%s" class="%s">', esc_attr($field_id), esc_attr($field_id), esc_attr($class));

                if (isset($field['options']) && is_array($field['options'])) {
                    foreach ($field['options'] as $option_value => $option_label) {
                        printf(
                            '<option value="%s" %s>%s</option>',
                            esc_attr($option_value),
                            selected($value, $option_value, false),
                            esc_html($option_label)
                        );
                    }
                }

                echo '</select>';
                break;

            case 'radio':
                if (isset($field['options']) && is_array($field['options'])) {
                    foreach ($field['options'] as $option_value => $option_label) {
                        printf(
                            '<label><input type="radio" name="%s" value="%s" %s /> %s</label><br />',
                            esc_attr($field_id),
                            esc_attr($option_value),
                            checked($value, $option_value, false),
                            esc_html($option_label)
                        );
                    }
                }
                break;
        }

        if (isset($field['description']) && $field['type'] !== 'checkbox') {
            printf('<p class="description">%s</p>', wp_kses_post($field['description']));
        }

        echo '</div>';
    }

    /**
     * Sanitize field value
     *
     * @param mixed $value
     * @return mixed
     */
    public function sanitize_field($value) {
        if (is_array($value)) {
            return array_map('sanitize_text_field', $value);
        }

        // Handle different field types
        if (is_email($value)) {
            return sanitize_email($value);
        }

        if (is_numeric($value)) {
            return floatval($value);
        }

        if ($value === 'yes' || $value === 'no') {
            return $value;
        }

        return sanitize_text_field($value);
    }

    /**
     * Enqueue admin scripts
     *
     * @param string $hook
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'subs-settings') === false) {
            return;
        }

        wp_enqueue_script(
            'subs-admin-settings',
            SUBS_PLUGIN_URL . 'assets/js/admin-settings.js',
            array('jquery'),
            SUBS_VERSION,
            true
        );

        wp_localize_script('subs-admin-settings', 'subs_settings', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('subs_settings_nonce'),
            'strings' => array(
                'test_connection' => __('Testing connection...', 'subs'),
                'connection_success' => __('Connection successful!', 'subs'),
                'connection_failed' => __('Connection failed. Please check your credentials.', 'subs'),
            )
        ));
    }

    /**
     * AJAX handler for testing Stripe connection
     */
    public function ajax_test_stripe_connection() {
        check_ajax_referer('subs_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'subs'));
        }

        $test_mode = get_option('subs_stripe_test_mode', 'yes') === 'yes';

        if ($test_mode) {
            $secret_key = get_option('subs_stripe_test_secret_key', '');
        } else {
            $secret_key = get_option('subs_stripe_live_secret_key', '');
        }

        if (empty($secret_key)) {
            wp_send_json_error(__('No secret key configured.', 'subs'));
        }

        // Simple test API call to Stripe
        $response = wp_remote_get('https://api.stripe.com/v1/balance', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
            ),
        ));

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 200) {
            wp_send_json_success(__('Connection successful!', 'subs'));
        } else {
            $body = wp_remote_retrieve_body($response);
            $error_data = json_decode($body, true);
            $error_message = isset($error_data['error']['message']) ? $error_data['error']['message'] : __('Unknown error occurred.', 'subs');
            wp_send_json_error($error_message);
        }
    }

    /**
     * AJAX handler for resetting settings
     */
    public function ajax_reset_settings() {
        check_ajax_referer('subs_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'subs'));
        }

        $section = isset($_POST['section']) ? sanitize_text_field($_POST['section']) : '';

        if ($this->reset_settings($section)) {
            wp_send_json_success(__('Settings reset successfully.', 'subs'));
        } else {
            wp_send_json_error(__('Failed to reset settings.', 'subs'));
        }
    }

    /**
     * Get setting value
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get($key, $default = null) {
        // Check if field exists and get its default
        foreach ($this->fields as $section => $fields) {
            if (isset($fields[$key])) {
                $field_default = isset($fields[$key]['default']) ? $fields[$key]['default'] : '';
                $default = $default !== null ? $default : $field_default;
                break;
            }
        }

        return get_option($key, $default);
    }

    /**
     * Update setting value
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function update($key, $value) {
        $sanitized_value = $this->sanitize_field($value);
        return update_option($key, $sanitized_value);
    }

    /**
     * Reset settings to defaults
     *
     * @param string $section Optional section to reset
     * @return bool
     */
    public function reset_settings($section = '') {
        $fields_to_reset = array();

        if ($section && isset($this->fields[$section])) {
            $fields_to_reset = $this->fields[$section];
        } else {
            foreach ($this->fields as $section_fields) {
                $fields_to_reset = array_merge($fields_to_reset, $section_fields);
            }
        }

        $reset = 0;
        foreach ($fields_to_reset as $field_id => $field) {
            $default = isset($field['default']) ? $field['default'] : '';
            if (update_option($field_id, $default)) {
                $reset++;
            }
        }

        return $reset > 0;
    }

    /**
     * Get pages for select dropdown
     *
     * @return array
     */
    private function get_pages_for_select() {
        $pages = get_pages();
        $options = array('' => __('— Select —', 'subs'));

        foreach ($pages as $page) {
            $options[$page->ID] = $page->post_title;
        }

        return $options;
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (isset($_POST['submit']) && check_admin_referer('subs_settings_nonce', 'subs_settings_nonce')) {
            $this->save_settings();
        }

        ?>
        <div class="wrap">
            <h1><?php _e('Subscription Settings', 'subs'); ?></h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ($this->sections as $section_id => $section) : ?>
                    <a href="?page=subs-settings&tab=<?php echo esc_attr($section_id); ?>"
                       class="nav-tab <?php echo $this->current_tab === $section_id ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($section['title']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <form method="post" action="">
                <?php
                wp_nonce_field('subs_settings_nonce', 'subs_settings_nonce');
                settings_fields('subs_' . $this->current_tab . '_settings');
                do_settings_sections('subs_' . $this->current_tab . '_settings');
                submit_button();
                ?>
            </form>
        </div>

        <style>
        .subs-field-wrapper {
            max-width: 400px;
        }

        .subs-stripe-connection-test {
            margin-top: 15px;
            padding: 10px;
            background: #f9f9f9;
            border-left: 4px solid #007cba;
        }

        .subs-connection-result {
            margin-left: 10px;
            font-weight: bold;
        }

        .subs-connection-result.success {
            color: #46b450;
        }

        .subs-connection-result.error {
            color: #dc3232;
        }
        </style>
        <?php
    }

    /**
     * Save settings
     */
    private function save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'subs'));
        }

        $current_section_fields = isset($this->fields[$this->current_tab]) ? $this->fields[$this->current_tab] : array();

        $updated = 0;
        foreach ($current_section_fields as $field_id => $field) {
            if (isset($_POST[$field_id])) {
                $value = $_POST[$field_id];
                $sanitized_value = $this->sanitize_field($value);

                if (update_option($field_id, $sanitized_value)) {
                    $updated++;
                }
            } else {
                // Handle checkboxes that aren't checked
                if ($field['type'] === 'checkbox') {
                    update_option($field_id, 'no');
                    $updated++;
                }
            }
        }

        if ($updated > 0) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p>' . __('Settings saved successfully.', 'subs') . '</p>';
                echo '</div>';
            });
        }

        // Hook for additional processing
        do_action('subs_settings_saved', $this->current_tab, $_POST);
    }
}
