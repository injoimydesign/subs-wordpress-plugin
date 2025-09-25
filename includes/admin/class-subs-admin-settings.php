<?php
/**
 * Admin Settings Management
 *
 * Handles plugin settings and configuration
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
     * Constructor
     */
    public function __construct() {
        $this->init();
    }

    /**
     * Initialize settings
     */
    private function init() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        $this->define_sections();
        $this->define_fields();
    }

    /**
     * Define settings sections
     */
    private function define_sections() {
        $this->sections = array(
            'stripe' => array(
                'title' => __('Stripe Configuration', 'subs'),
                'description' => __('Configure your Stripe integration settings.', 'subs'),
            ),
            'general' => array(
                'title' => __('General Settings', 'subs'),
                'description' => __('General subscription settings and options.', 'subs'),
            ),
            'display' => array(
                'title' => __('Display Options', 'subs'),
                'description' => __('Control how subscription options are displayed to customers.', 'subs'),
            ),
            'emails' => array(
                'title' => __('Email Settings', 'subs'),
                'description' => __('Configure email notifications and templates.', 'subs'),
            ),
        );
    }

    /**
     * Define settings fields
     */
    private function define_fields() {
        // Stripe Settings
        $this->fields['stripe'] = array(
            'subs_stripe_test_mode' => array(
                'title' => __('Test Mode', 'subs'),
                'type' => 'checkbox',
                'description' => __('Enable test mode for Stripe payments.', 'subs'),
                'default' => 'yes',
            ),
            'subs_stripe_test_publishable_key' => array(
                'title' => __('Test Publishable Key', 'subs'),
                'type' => 'text',
                'description' => __('Your Stripe test publishable key (pk_test_...).', 'subs'),
                'placeholder' => 'pk_test_...',
                'class' => 'regular-text',
            ),
            'subs_stripe_test_secret_key' => array(
                'title' => __('Test Secret Key', 'subs'),
                'type' => 'password',
                'description' => __('Your Stripe test secret key (sk_test_...).', 'subs'),
                'placeholder' => 'sk_test_...',
                'class' => 'regular-text',
            ),
            'subs_stripe_live_publishable_key' => array(
                'title' => __('Live Publishable Key', 'subs'),
                'type' => 'text',
                'description' => __('Your Stripe live publishable key (pk_live_...).', 'subs'),
                'placeholder' => 'pk_live_...',
                'class' => 'regular-text',
            ),
            'subs_stripe_live_secret_key' => array(
                'title' => __('Live Secret Key', 'subs'),
                'type' => 'password',
                'description' => __('Your Stripe live secret key (sk_live_...).', 'subs'),
                'placeholder' => 'sk_live_...',
                'class' => 'regular-text',
            ),
            'subs_stripe_webhook_secret' => array(
                'title' => __('Webhook Secret', 'subs'),
                'type' => 'text',
                'description' => sprintf(
                    __('Your Stripe webhook endpoint secret. Webhook URL: %s', 'subs'),
                    '<code>' . home_url('/wp-admin/admin-ajax.php?action=subs_stripe_webhook') . '</code>'
                ),
                'placeholder' => 'whsec_...',
                'class' => 'regular-text',
            ),
            'subs_pass_stripe_fees' => array(
                'title' => __('Pass Stripe Fees to Customer', 'subs'),
                'type' => 'checkbox',
                'description' => __('Add Stripe processing fees to the subscription cost.', 'subs'),
                'default' => 'no',
            ),
            'subs_stripe_fee_percentage' => array(
                'title' => __('Stripe Fee Percentage', 'subs'),
                'type' => 'number',
                'description' => __('Stripe percentage fee (e.g., 2.9).', 'subs'),
                'default' => '2.9',
                'step' => '0.01',
                'min' => '0',
                'max' => '10',
                'class' => 'small-text',
                'suffix' => '%',
            ),
            'subs_stripe_fee_fixed' => array(
                'title' => __('Stripe Fixed Fee', 'subs'),
                'type' => 'number',
                'description' => __('Stripe fixed fee per transaction.', 'subs'),
                'default' => '0.30',
                'step' => '0.01',
                'min' => '0',
                'class' => 'small-text',
                'suffix' => get_woocommerce_currency(),
            ),
        );

        // General Settings
        $this->fields['general'] = array(
            'subs_enable_trials' => array(
                'title' => __('Enable Trials', 'subs'),
                'type' => 'checkbox',
                'description' => __('Enable trial periods for subscriptions.', 'subs'),
                'default' => 'no',
            ),
            'subs_default_trial_period' => array(
                'title' => __('Default Trial Period', 'subs'),
                'type' => 'number',
                'description' => __('Default trial period length in days.', 'subs'),
                'default' => '7',
                'min' => '1',
                'class' => 'small-text',
                'suffix' => __('days', 'subs'),
            ),
            'subs_enable_customer_pause' => array(
                'title' => __('Allow Customer Pause', 'subs'),
                'type' => 'checkbox',
                'description' => __('Allow customers to pause their subscriptions.', 'subs'),
                'default' => 'yes',
            ),
            'subs_enable_customer_cancel' => array(
                'title' => __('Allow Customer Cancel', 'subs'),
                'type' => 'checkbox',
                'description' => __('Allow customers to cancel their subscriptions.', 'subs'),
                'default' => 'yes',
            ),
            'subs_enable_customer_modify' => array(
                'title' => __('Allow Customer Modify', 'subs'),
                'type' => 'checkbox',
                'description' => __('Allow customers to modify their subscriptions.', 'subs'),
                'default' => 'yes',
            ),
            'subs_enable_payment_method_change' => array(
                'title' => __('Allow Payment Method Changes', 'subs'),
                'type' => 'checkbox',
                'description' => __('Allow customers to change payment methods.', 'subs'),
                'default' => 'yes',
            ),
            'subs_subscription_page_endpoint' => array(
                'title' => __('Account Page Endpoint', 'subs'),
                'type' => 'text',
                'description' => __('Endpoint for subscriptions in customer account area.', 'subs'),
                'default' => 'subscriptions',
                'class' => 'regular-text',
            ),
        );

        // Display Settings
        $this->fields['display'] = array(
            'subs_subscription_display_location' => array(
                'title' => __('Subscription Option Display', 'subs'),
                'type' => 'select',
                'description' => __('Choose where the subscription option appears on product pages.', 'subs'),
                'options' => array(
                    'before_add_to_cart' => __('Before Add to Cart button', 'subs'),
                    'after_add_to_cart' => __('After Add to Cart button', 'subs'),
                    'product_tabs' => __('In Product Tabs', 'subs'),
                    'checkout_only' => __('Checkout Page Only', 'subs'),
                ),
                'default' => 'after_add_to_cart',
            ),
        );

        // Email Settings
        $this->fields['emails'] = array(
            'subs_from_name' => array(
                'title' => __('From Name', 'subs'),
                'type' => 'text',
                'description' => __('Name to use for subscription emails.', 'subs'),
                'default' => get_bloginfo('name'),
                'class' => 'regular-text',
            ),
            'subs_from_email' => array(
                'title' => __('From Email', 'subs'),
                'type' => 'email',
                'description' => __('Email address to use for subscription emails.', 'subs'),
                'default' => get_option('admin_email'),
                'class' => 'regular-text',
            ),
            'subs_email_footer_text' => array(
                'title' => __('Email Footer Text', 'subs'),
                'type' => 'textarea',
                'description' => __('Text to include in email footers.', 'subs'),
                'default' => sprintf(__('This email was sent from %s', 'subs'), get_bloginfo('name')),
                'rows' => 3,
            ),
        );
    }

    /**
     * Register settings with WordPress
     */
    public function register_settings() {
        // Register each setting
        foreach ($this->fields as $section => $fields) {
            foreach ($fields as $field_id => $field) {
                register_setting('subs_settings', $field_id, array(
                    'sanitize_callback' => array($this, 'sanitize_field'),
                    'default' => isset($field['default']) ? $field['default'] : '',
                ));
            }
        }

        // Add settings sections
        foreach ($this->sections as $section_id => $section) {
            add_settings_section(
                'subs_' . $section_id,
                $section['title'],
                array($this, 'render_section_description'),
                'subs_settings'
            );
        }

        // Add settings fields
        foreach ($this->fields as $section => $fields) {
            foreach ($fields as $field_id => $field) {
                add_settings_field(
                    $field_id,
                    $field['title'],
                    array($this, 'render_field'),
                    'subs_settings',
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

        $class = isset($field['class']) ? $field['class'] : '';
        $placeholder = isset($field['placeholder']) ? $field['placeholder'] : '';

        switch ($field['type']) {
            case 'text':
            case 'email':
                printf(
                    '<input type="%s" id="%s" name="%s" value="%s" placeholder="%s" class="%s" />',
                    esc_attr($field['type']),
                    esc_attr($field_id),
                    esc_attr($field_id),
                    esc_attr($value),
                    esc_attr($placeholder),
                    esc_attr($class)
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
                    '<input type="number" id="%s" name="%s" value="%s" min="%s" max="%s" step="%s" class="%s" />',
                    esc_attr($field_id),
                    esc_attr($field_id),
                    esc_attr($value),
                    esc_attr($min),
                    esc_attr($max),
                    esc_attr($step),
                    esc_attr($class)
                );

                if (isset($field['suffix'])) {
                    echo ' <span class="subs-field-suffix">' . esc_html($field['suffix']) . '</span>';
                }
                break;

            case 'textarea':
                $rows = isset($field['rows']) ? $field['rows'] : '4';
                printf(
                    '<textarea id="%s" name="%s" rows="%s" class="%s">%s</textarea>',
                    esc_attr($field_id),
                    esc_attr($field_id),
                    esc_attr($rows),
                    esc_attr($class),
                    esc_textarea($value)
                );
                break;

            case 'checkbox':
                printf(
                    '<label><input type="checkbox" id="%s" name="%s" value="yes" %s /> %s</label>',
                    esc_attr($field_id),
                    esc_attr($field_id),
                    checked('yes', $value, false),
                    esc_html($field['description'])
                );
                $field['description'] = ''; // Don't show description twice
                break;

            case 'select':
                printf('<select id="%s" name="%s" class="%s">',
                    esc_attr($field_id), esc_attr($field_id), esc_attr($class));

                foreach ($field['options'] as $option_value => $option_label) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($option_value),
                        selected($option_value, $value, false),
                        esc_html($option_label)
                    );
                }

                echo '</select>';
                break;

            case 'radio':
                foreach ($field['options'] as $option_value => $option_label) {
                    printf(
                        '<label><input type="radio" name="%s" value="%s" %s /> %s</label><br>',
                        esc_attr($field_id),
                        esc_attr($option_value),
                        checked($option_value, $value, false),
                        esc_html($option_label)
                    );
                }
                break;
        }

        // Show description
        if (!empty($field['description'])) {
            printf('<p class="description">%s</p>', wp_kses_post($field['description']));
        }
    }

    /**
     * Sanitize field values
     *
     * @param mixed $value
     * @return mixed
     */
    public function sanitize_field($value) {
        // Get the field being sanitized
        $field_id = '';
        if (isset($_POST['option_page']) && $_POST['option_page'] === 'subs_settings') {
            // WordPress doesn't pass field info to sanitize callback,
            // so we need to determine field type from POST data
            foreach ($this->fields as $section => $fields) {
                foreach ($fields as $id => $field) {
                    if (isset($_POST[$id])) {
                        if ($_POST[$id] === $value) {
                            $field_id = $id;
                            break 2;
                        }
                    }
                }
            }
        }

        // Get field configuration
        $field_config = null;
        foreach ($this->fields as $section => $fields) {
            if (isset($fields[$field_id])) {
                $field_config = $fields[$field_id];
                break;
            }
        }

        if (!$field_config) {
            return sanitize_text_field($value);
        }

        // Sanitize based on field type
        switch ($field_config['type']) {
            case 'email':
                return sanitize_email($value);

            case 'number':
                return floatval($value);

            case 'textarea':
                return sanitize_textarea_field($value);

            case 'checkbox':
                return $value === 'yes' ? 'yes' : 'no';

            case 'password':
            case 'text':
            case 'select':
            case 'radio':
            default:
                return sanitize_text_field($value);
        }
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
     * Get all settings for a section
     *
     * @param string $section
     * @return array
     */
    public function get_section($section) {
        if (!isset($this->fields[$section])) {
            return array();
        }

        $settings = array();
        foreach ($this->fields[$section] as $field_id => $field) {
            $settings[$field_id] = $this->get($field_id);
        }

        return $settings;
    }

    /**
     * Export settings
     *
     * @return array
     */
    public function export_settings() {
        $settings = array();

        foreach ($this->fields as $section => $fields) {
            $settings[$section] = array();
            foreach ($fields as $field_id => $field) {
                $settings[$section][$field_id] = $this->get($field_id);
            }
        }

        return $settings;
    }

    /**
     * Import settings
     *
     * @param array $settings
     * @return bool
     */
    public function import_settings($settings) {
        if (!is_array($settings)) {
            return false;
        }

        $imported = 0;

        foreach ($settings as $section => $section_settings) {
            if (!isset($this->fields[$section]) || !is_array($section_settings)) {
                continue;
            }

            foreach ($section_settings as $field_id => $value) {
                if (isset($this->fields[$section][$field_id])) {
                    if ($this->update($field_id, $value)) {
                        $imported++;
                    }
                }
            }
        }

        return $imported > 0;
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
}
