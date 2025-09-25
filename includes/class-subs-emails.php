<?php
/**
 * Email System Class
 *
 * Handles all subscription-related emails
 *
 * @package Subs
 * @version 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Subs Emails Class
 *
 * @class Subs_Emails
 * @version 1.0.0
 */
class Subs_Emails {

    /**
     * Email templates
     * @var array
     */
    private $templates = array();

    /**
     * Constructor
     */
    public function __construct() {
        $this->init();
    }

    /**
     * Initialize email system
     */
    private function init() {
        // Load email templates
        $this->load_templates();

        // Hook into subscription events
        add_action('subs_subscription_created', array($this, 'send_subscription_created_email'));
        add_action('subs_subscription_status_changed', array($this, 'send_status_changed_email'), 10, 3);
        add_action('subs_stripe_payment_succeeded', array($this, 'send_payment_success_email'), 10, 2);
        add_action('subs_stripe_payment_failed', array($this, 'send_payment_failed_email'), 10, 2);
        add_action('subs_subscription_cancelled', array($this, 'send_cancellation_email'));
        add_action('subs_subscription_paused', array($this, 'send_pause_email'));
        add_action('subs_subscription_resumed', array($this, 'send_resume_email'));

        // Hook into WordPress email filters
        add_filter('wp_mail_content_type', array($this, 'set_email_content_type'));
    }

    /**
     * Load email templates
     */
    private function load_templates() {
        $this->templates = array(
            'subscription_created' => array(
                'subject' => __('Your subscription has been created', 'subs'),
                'template' => 'emails/subscription-created.php'
            ),
            'subscription_activated' => array(
                'subject' => __('Your subscription is now active', 'subs'),
                'template' => 'emails/subscription-activated.php'
            ),
            'payment_success' => array(
                'subject' => __('Payment successful for your subscription', 'subs'),
                'template' => 'emails/payment-success.php'
            ),
            'payment_failed' => array(
                'subject' => __('Payment failed for your subscription', 'subs'),
                'template' => 'emails/payment-failed.php'
            ),
            'subscription_paused' => array(
                'subject' => __('Your subscription has been paused', 'subs'),
                'template' => 'emails/subscription-paused.php'
            ),
            'subscription_resumed' => array(
                'subject' => __('Your subscription has been resumed', 'subs'),
                'template' => 'emails/subscription-resumed.php'
            ),
            'subscription_cancelled' => array(
                'subject' => __('Your subscription has been cancelled', 'subs'),
                'template' => 'emails/subscription-cancelled.php'
            ),
            'subscription_overdue' => array(
                'subject' => __('Your subscription payment is overdue', 'subs'),
                'template' => 'emails/subscription-overdue.php'
            ),
            'trial_ending' => array(
                'subject' => __('Your trial period is ending soon', 'subs'),
                'template' => 'emails/trial-ending.php'
            ),
            // Admin emails
            'admin_new_subscription' => array(
                'subject' => __('[%site_name%] New subscription created', 'subs'),
                'template' => 'emails/admin-new-subscription.php'
            ),
            'admin_subscription_cancelled' => array(
                'subject' => __('[%site_name%] Subscription cancelled', 'subs'),
                'template' => 'emails/admin-subscription-cancelled.php'
            ),
        );

        // Allow templates to be filtered
        $this->templates = apply_filters('subs_email_templates', $this->templates);
    }

    /**
     * Send customer email
     *
     * @param int $customer_id
     * @param string $template
     * @param array $data
     * @return bool
     */
    public function send_customer_email($customer_id, $template, $data = array()) {
        $customer = get_user_by('id', $customer_id);

        if (!$customer) {
            return false;
        }

        if (!isset($this->templates[$template])) {
            return false;
        }

        $template_data = $this->templates[$template];
        $subject = $this->parse_template_tags($template_data['subject'], $data);
        $content = $this->get_email_content($template_data['template'], $data);

        return $this->send_email(
            $customer->user_email,
            $subject,
            $content,
            $customer->display_name
        );
    }

    /**
     * Send admin email
     *
     * @param string $template
     * @param array $data
     * @return bool
     */
    public function send_admin_email($template, $data = array()) {
        if (!isset($this->templates[$template])) {
            return false;
        }

        $template_data = $this->templates[$template];
        $subject = $this->parse_template_tags($template_data['subject'], $data);
        $content = $this->get_email_content($template_data['template'], $data);

        $admin_email = get_option('admin_email');
        $admin_name = get_bloginfo('name');

        return $this->send_email($admin_email, $subject, $content, $admin_name);
    }

    /**
     * Send email
     *
     * @param string $to_email
     * @param string $subject
     * @param string $content
     * @param string $to_name
     * @return bool
     */
    public function send_email($to_email, $subject, $content, $to_name = '') {
        $from_name = get_option('subs_from_name', get_bloginfo('name'));
        $from_email = get_option('subs_from_email', get_option('admin_email'));

        $headers = array();
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';

        if (!empty($to_name)) {
            $to = $to_name . ' <' . $to_email . '>';
        } else {
            $to = $to_email;
        }

        // Wrap content in email template
        $html_content = $this->wrap_in_template($content, $subject);

        return wp_mail($to, $subject, $html_content, $headers);
    }

    /**
     * Get email content from template
     *
     * @param string $template_file
     * @param array $data
     * @return string
     */
    public function get_email_content($template_file, $data = array()) {
        // Extract variables for use in template
        if (!empty($data)) {
            extract($data);
        }

        // Try to find template in theme first
        $template_path = locate_template('subs/emails/' . basename($template_file));

        // Fall back to plugin template
        if (!$template_path) {
            $template_path = SUBS_PLUGIN_PATH . 'templates/' . $template_file;
        }

        if (!file_exists($template_path)) {
            return __('Email template not found.', 'subs');
        }

        ob_start();
        include $template_path;
        $content = ob_get_clean();

        return $this->parse_template_tags($content, $data);
    }

    /**
     * Parse template tags
     *
     * @param string $content
     * @param array $data
     * @return string
     */
    public function parse_template_tags($content, $data = array()) {
        $tags = array(
            '%site_name%' => get_bloginfo('name'),
            '%site_url%' => home_url(),
            '%admin_email%' => get_option('admin_email'),
            '%current_date%' => date_i18n(get_option('date_format')),
            '%current_time%' => date_i18n(get_option('time_format')),
        );

        // Add subscription-specific tags if subscription is provided
        if (isset($data['subscription']) && $data['subscription'] instanceof Subs_Subscription) {
            $subscription = $data['subscription'];
            $product = $subscription->get_product();
            $customer = $subscription->get_customer();

            $tags['%subscription_id%'] = $subscription->get_id();
            $tags['%subscription_status%'] = $subscription->get_status_label();
            $tags['%product_name%'] = $product ? $product->get_name() : '';
            $tags['%subscription_amount%'] = $subscription->get_formatted_total();
            $tags['%billing_period%'] = $subscription->get_formatted_billing_period();
            $tags['%next_payment_date%'] = $subscription->get_next_payment_date() ?
                date_i18n(get_option('date_format'), strtotime($subscription->get_next_payment_date())) : '';
            $tags['%customer_name%'] = $customer ? $customer->display_name : '';
            $tags['%customer_email%'] = $customer ? $customer->user_email : '';
            $tags['%manage_url%'] = home_url('/my-account/subscriptions/');
        }

        // Allow custom tags to be added
        $tags = apply_filters('subs_email_template_tags', $tags, $data);

        return str_replace(array_keys($tags), array_values($tags), $content);
    }

    /**
     * Wrap content in HTML email template
     *
     * @param string $content
     * @param string $subject
     * @return string
     */
    public function wrap_in_template($content, $subject) {
        $template = $this->get_base_template();

        $template = str_replace('%EMAIL_SUBJECT%', $subject, $template);
        $template = str_replace('%EMAIL_CONTENT%', $content, $template);
        $template = str_replace('%SITE_NAME%', get_bloginfo('name'), $template);
        $template = str_replace('%SITE_URL%', home_url(), $template);

        return $template;
    }

    /**
     * Get base HTML email template
     *
     * @return string
     */
    public function get_base_template() {
        $template = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>%EMAIL_SUBJECT%</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .email-container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .email-header { background: #f8f9fa; padding: 20px; text-align: center; border-bottom: 2px solid #dee2e6; }
                .email-content { padding: 30px 20px; }
                .email-footer { background: #f8f9fa; padding: 15px 20px; text-align: center; font-size: 12px; color: #666; }
                .button { display: inline-block; background: #007cba; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; margin: 10px 0; }
                .subscription-details { background: #f8f9fa; padding: 15px; border-radius: 4px; margin: 15px 0; }
                .subscription-details h4 { margin: 0 0 10px 0; color: #333; }
            </style>
        </head>
        <body>
            <div class="email-container">
                <div class="email-header">
                    <h2>%SITE_NAME%</h2>
                </div>
                <div class="email-content">
                    %EMAIL_CONTENT%
                </div>
                <div class="email-footer">
                    <p>This email was sent from <a href="%SITE_URL%">%SITE_NAME%</a></p>
                    <p>If you have any questions, please contact us.</p>
                </div>
            </div>
        </body>
        </html>';

        return apply_filters('subs_base_email_template', $template);
    }

    /**
     * Set email content type to HTML
     *
     * @return string
     */
    public function set_email_content_type() {
        return 'text/html';
    }

    /**
     * Send subscription created email
     *
     * @param Subs_Subscription $subscription
     */
    public function send_subscription_created_email($subscription) {
        $this->send_customer_email(
            $subscription->get_customer_id(),
            'subscription_created',
            array('subscription' => $subscription)
        );

        // Send admin notification
        $this->send_admin_email(
            'admin_new_subscription',
            array('subscription' => $subscription)
        );
    }

    /**
     * Send status changed email
     *
     * @param Subs_Subscription $subscription
     * @param string $old_status
     * @param string $new_status
     */
    public function send_status_changed_email($subscription, $old_status, $new_status) {
        $data = array('subscription' => $subscription);

        switch ($new_status) {
            case 'active':
                if ($old_status === 'pending') {
                    $this->send_customer_email($subscription->get_customer_id(), 'subscription_activated', $data);
                }
                break;

            case 'cancelled':
                $this->send_customer_email($subscription->get_customer_id(), 'subscription_cancelled', $data);
                $this->send_admin_email('admin_subscription_cancelled', $data);
                break;

            case 'paused':
                $this->send_customer_email($subscription->get_customer_id(), 'subscription_paused', $data);
                break;
        }
    }

    /**
     * Send payment success email
     *
     * @param Subs_Subscription $subscription
     * @param object $invoice
     */
    public function send_payment_success_email($subscription, $invoice) {
        $data = array(
            'subscription' => $subscription,
            'invoice' => $invoice,
            'amount' => wc_price($invoice->amount_paid / 100, array('currency' => strtoupper($invoice->currency)))
        );

        $this->send_customer_email(
            $subscription->get_customer_id(),
            'payment_success',
            $data
        );
    }

    /**
     * Send payment failed email
     *
     * @param Subs_Subscription $subscription
     * @param object $invoice
     */
    public function send_payment_failed_email($subscription, $invoice) {
        $data = array(
            'subscription' => $subscription,
            'invoice' => $invoice,
            'amount' => wc_price($invoice->amount_due / 100, array('currency' => strtoupper($invoice->currency)))
        );

        $this->send_customer_email(
            $subscription->get_customer_id(),
            'payment_failed',
            $data
        );
    }

    /**
     * Send cancellation email
     *
     * @param Subs_Subscription $subscription
     */
    public function send_cancellation_email($subscription) {
        // Email is sent by status changed handler
    }

    /**
     * Send pause email
     *
     * @param Subs_Subscription $subscription
     */
    public function send_pause_email($subscription) {
        // Email is sent by status changed handler
    }

    /**
     * Send resume email
     *
     * @param Subs_Subscription $subscription
     */
    public function send_resume_email($subscription) {
        $this->send_customer_email(
            $subscription->get_customer_id(),
            'subscription_resumed',
            array('subscription' => $subscription)
        );
    }

    /**
     * Send trial ending reminder
     *
     * @param Subs_Subscription $subscription
     * @param int $days_remaining
     */
    public function send_trial_ending_email($subscription, $days_remaining = 3) {
        $data = array(
            'subscription' => $subscription,
            'days_remaining' => $days_remaining
        );

        $this->send_customer_email(
            $subscription->get_customer_id(),
            'trial_ending',
            $data
        );
    }

    /**
     * Send subscription overdue email
     *
     * @param Subs_Subscription $subscription
     */
    public function send_overdue_email($subscription) {
        $this->send_customer_email(
            $subscription->get_customer_id(),
            'subscription_overdue',
            array('subscription' => $subscription)
        );
    }

    /**
     * Get available email templates
     *
     * @return array
     */
    public function get_templates() {
        return $this->templates;
    }

    /**
     * Preview email template
     *
     * @param string $template
     * @param array $sample_data
     * @return string
     */
    public function preview_email($template, $sample_data = array()) {
        if (!isset($this->templates[$template])) {
            return false;
        }

        // Create sample subscription if not provided
        if (!isset($sample_data['subscription'])) {
            $sample_data['subscription'] = $this->get_sample_subscription();
        }

        $template_data = $this->templates[$template];
        $subject = $this->parse_template_tags($template_data['subject'], $sample_data);
        $content = $this->get_email_content($template_data['template'], $sample_data);

        return $this->wrap_in_template($content, $subject);
    }

    /**
     * Get sample subscription for email previews
     *
     * @return object
     */
    private function get_sample_subscription() {
        return (object) array(
            'get_id' => function() { return 123; },
            'get_status_label' => function() { return __('Active', 'subs'); },
            'get_formatted_total' => function() { return wc_price(29.99); },
            'get_formatted_billing_period' => function() { return __('Every month', 'subs'); },
            'get_next_payment_date' => function() { return date('Y-m-d H:i:s', strtotime('+1 month')); },
            'get_customer' => function() {
                return (object) array(
                    'display_name' => 'John Doe',
                    'user_email' => 'john@example.com'
                );
            },
            'get_product' => function() {
                return (object) array(
                    'get_name' => function() { return 'Sample Product'; }
                );
            }
        );
    }
}
