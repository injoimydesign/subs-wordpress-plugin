<?php
/**
 * Plugin Name: Subs - WooCommerce Subscription Management
 * Plugin URI: https://yoursite.com/subs
 * Description: A comprehensive WooCommerce subscription plugin with Stripe integration for recurring payments and subscription management.
 * Version: 1.0.0
 * Author: 1456 Media, LLC
 * Author URI: https://1456media.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: subs
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.3
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SUBS_PLUGIN_FILE', __FILE__);
define('SUBS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SUBS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SUBS_VERSION', '1.0.0');
define('SUBS_MINIMUM_WC_VERSION', '5.0');
define('SUBS_MINIMUM_PHP_VERSION', '7.4');

/**
 * Main Subs Plugin Class
 *
 * @class Subs
 * @version 1.0.0
 * @since 1.0.0
 */
final class Subs {

    /**
     * The single instance of the class
     * @var Subs
     */
    protected static $_instance = null;

    /**
     * Admin instance
     * @var Subs_Admin
     */
    public $admin;

    /**
     * Frontend instance
     * @var Subs_Frontend
     */
    public $frontend;

    /**
     * AJAX instance
     * @var Subs_Ajax
     */
    public $ajax;

    /**
     * Stripe instance
     * @var Subs_Stripe
     */
    public $stripe;

    /**
     * Customer instance
     * @var Subs_Customer
     */
    public $customer;

    /**
     * Emails instance
     * @var Subs_Emails
     */
    public $emails;

    /**
     * Main Subs Instance
     *
     * Ensures only one instance of Subs is loaded or can be loaded
     *
     * @static
     * @return Subs - Main instance
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Subs Constructor
     *
     * @access public
     * @return Subs
     */
    public function __construct() {
        $this->define_constants();
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Define additional plugin constants
     *
     * @access private
     */
    private function define_constants() {
        // Additional constants can be defined here
        if (!defined('SUBS_ABSPATH')) {
            define('SUBS_ABSPATH', dirname(SUBS_PLUGIN_FILE) . '/');
        }
    }

    /**
     * Include required core files
     *
     * @access public
     */
    public function includes() {
        // Core includes - with file existence checks to prevent fatal errors
        $core_files = array(
            'includes/class-subs-install.php',
            'includes/class-subs-subscription.php',
            'includes/class-subs-stripe.php',
            'includes/class-subs-admin.php',
            'includes/class-subs-frontend.php',
            'includes/class-subs-ajax.php',
            'includes/class-subs-customer.php',
            'includes/class-subs-emails.php',
        );

        foreach ($core_files as $file) {
            $file_path = SUBS_PLUGIN_PATH . $file;
            if (file_exists($file_path)) {
                include_once $file_path;
            } else {
                // Log missing files for debugging
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("Subs Plugin: Missing core file - {$file}");
                }
            }
        }

        // Admin includes
        if (is_admin()) {
            $admin_files = array(
                'includes/admin/class-subs-admin-settings.php',
                'includes/admin/class-subs-admin-subscriptions.php',
                'includes/admin/class-subs-admin-product-settings.php',
            );

            foreach ($admin_files as $file) {
                $file_path = SUBS_PLUGIN_PATH . $file;
                if (file_exists($file_path)) {
                    include_once $file_path;
                } else {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("Subs Plugin: Missing admin file - {$file}");
                    }
                }
            }
        }

        // Frontend includes
        if (!is_admin() || defined('DOING_AJAX')) {
            $frontend_files = array(
                'includes/frontend/class-subs-frontend-product.php',
                'includes/frontend/class-subs-frontend-checkout.php',
                'includes/frontend/class-subs-frontend-account.php',
            );

            foreach ($frontend_files as $file) {
                $file_path = SUBS_PLUGIN_PATH . $file;
                if (file_exists($file_path)) {
                    include_once $file_path;
                } else {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("Subs Plugin: Missing frontend file - {$file}");
                    }
                }
            }
        }
    }

    /**
     * Hook into actions and filters
     *
     * @access private
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, array('Subs_Install', 'install'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('init', array($this, 'init'), 0);
        add_action('plugins_loaded', array($this, 'on_plugins_loaded'), -1);
    }

    /**
     * Initialize the plugin when WordPress initializes
     *
     * @access public
     */
    public function init() {
        // Before init action
        do_action('before_subs_init');

        // Set up localization
        $this->load_plugin_textdomain();

        // Initialize classes only if they exist to prevent fatal errors
        if (class_exists('Subs_Admin')) {
            $this->admin = new Subs_Admin();
        }

        if (class_exists('Subs_Frontend')) {
            $this->frontend = new Subs_Frontend();
        }

        if (class_exists('Subs_Ajax')) {
            $this->ajax = new Subs_Ajax();
        }

        if (class_exists('Subs_Stripe')) {
            $this->stripe = new Subs_Stripe();
        }

        if (class_exists('Subs_Customer')) {
            $this->customer = new Subs_Customer();
        }

        if (class_exists('Subs_Emails')) {
            $this->emails = new Subs_Emails();
        }

        // Init action
        do_action('subs_init');
    }

    /**
     * When WP has loaded all plugins, trigger the `subs_loaded` hook
     *
     * @access public
     */
    public function on_plugins_loaded() {
        // Check if WooCommerce is active
        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Check WooCommerce version
        if (!$this->is_woocommerce_version_supported()) {
            add_action('admin_notices', array($this, 'woocommerce_version_notice'));
            return;
        }

        do_action('subs_loaded');
    }

    /**
     * Load Localization files
     *
     * @access public
     */
    public function load_plugin_textdomain() {
        load_plugin_textdomain('subs', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    /**
     * Check if WooCommerce is active
     *
     * @access public
     * @return bool
     */
    public function is_woocommerce_active() {
        return class_exists('WooCommerce');
    }

    /**
     * Check if WooCommerce version is supported
     *
     * @access public
     * @return bool
     */
    public function is_woocommerce_version_supported() {
        return defined('WC_VERSION') && version_compare(WC_VERSION, SUBS_MINIMUM_WC_VERSION, '>=');
    }

    /**
     * WooCommerce missing notice
     *
     * @access public
     */
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p><strong>' . sprintf(esc_html__('Subs requires WooCommerce to be installed and active. You can download %s here.', 'subs'), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>') . '</strong></p></div>';
    }

    /**
     * WooCommerce version notice
     *
     * @access public
     */
    public function woocommerce_version_notice() {
        echo '<div class="error"><p>' . sprintf(__('Subs requires WooCommerce version %s or higher. Please update WooCommerce.', 'subs'), SUBS_MINIMUM_WC_VERSION) . '</p></div>';
    }

    /**
     * Plugin deactivation
     *
     * @access public
     */
    public function deactivate() {
        // Cleanup tasks on deactivation
        // Note: We don't delete data on deactivation, only on uninstall

        // Clear scheduled cron events
        wp_clear_scheduled_hook('subs_process_subscriptions');
        wp_clear_scheduled_hook('subs_retry_failed_payments');
        wp_clear_scheduled_hook('subs_send_renewal_notices');
        wp_clear_scheduled_hook('subs_trial_ending_notifications');

        // Clear any transients
        delete_transient('subs_stripe_connection_test');
        delete_transient('subs_subscription_stats');
    }

    /**
     * Get Ajax URL
     *
     * @return string
     */
    public function ajax_url() {
        return admin_url('admin-ajax.php', 'relative');
    }

    /**
     * Get the plugin url
     *
     * @return string
     */
    public function plugin_url() {
        return untrailingslashit(plugins_url('/', __FILE__));
    }

    /**
     * Get the plugin path
     *
     * @return string
     */
    public function plugin_path() {
        return untrailingslashit(plugin_dir_path(__FILE__));
    }

    /**
     * Get the template path
     *
     * @return string
     */
    public function template_path() {
        return apply_filters('subs_template_path', 'subs/');
    }

    /**
     * Get plugin version
     *
     * @return string
     */
    public function get_version() {
        return SUBS_VERSION;
    }

    /**
     * Magic getter to prevent deprecated notices
     *
     * @param string $key
     * @return mixed|null
     */
    public function __get($key) {
        // Handle legacy access to properties
        if (property_exists($this, $key)) {
            return $this->$key;
        }

        // Log deprecated access attempts
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Subs Plugin: Attempting to access undefined property: {$key}");
        }

        return null;
    }

    /**
     * Magic isset to prevent deprecated notices
     *
     * @param string $key
     * @return bool
     */
    public function __isset($key) {
        return property_exists($this, $key);
    }

    /**
     * Magic setter to prevent deprecated notices
     *
     * @param string $key
     * @param mixed $value
     */
    public function __set($key, $value) {
        // Prevent setting undefined properties
        if (property_exists($this, $key)) {
            $this->$key = $value;
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Subs Plugin: Attempting to set undefined property: {$key}");
            }
        }
    }
}

/**
 * Main instance of Subs
 *
 * Returns the main instance of Subs to prevent the need to use globals
 *
 * @since 1.0.0
 * @return Subs
 */
function SUBS() {
    return Subs::instance();
}

/**
 * Initialize the plugin
 */
SUBS();

// Global for backwards compatibility - deprecated but maintained for legacy code
$GLOBALS['subs'] = SUBS();
