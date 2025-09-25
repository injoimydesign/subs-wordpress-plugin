<?php
/**
 * Account Subscriptions Template
 *
 * Displays customer subscriptions in their account area
 *
 * @package Subs
 * @version 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

$customer = subs_get_customer($user_id);
$dashboard_data = $customer->get_dashboard_data();
?>

<div class="subs-subscriptions-container">

    <?php if ($customer->has_subscriptions()): ?>

        <!-- Dashboard Widget -->
        <div class="subs-dashboard-widget">
            <h3><?php _e('Subscription Overview', 'subs'); ?></h3>

            <div class="subs-dashboard-stats">
                <div class="subs-stat-item">
                    <span class="subs-stat-number"><?php echo esc_html($dashboard_data['stats']['active_subscriptions']); ?></span>
                    <span class="subs-stat-label"><?php _e('Active Subscriptions', 'subs'); ?></span>
                </div>

                <div class="subs-stat-item">
                    <span class="subs-stat-number"><?php echo wc_price($dashboard_data['stats']['total_monthly_value']); ?></span>
                    <span class="subs-stat-label"><?php _e('Monthly Value', 'subs'); ?></span>
                </div>

                <?php if ($dashboard_data['next_payment_date']): ?>
                <div class="subs-stat-item">
                    <span class="subs-stat-number"><?php echo esc_html($dashboard_data['next_payment_formatted']); ?></span>
                    <span class="subs-stat-label"><?php _e('Next Payment', 'subs'); ?></span>
                </div>
                <?php endif; ?>

                <div class="subs-stat-item">
                    <span class="subs-stat-number"><?php echo wc_price($dashboard_data['lifetime_value']); ?></span>
                    <span class="subs-stat-label"><?php _e('Lifetime Value', 'subs'); ?></span>
                </div>
            </div>

            <?php if (!empty($dashboard_data['upcoming_renewals'])): ?>
            <div class="subs-upcoming-renewals">
                <h4><?php _e('Upcoming Renewals', 'subs'); ?></h4>
                <?php foreach (array_slice($dashboard_data['upcoming_renewals'], 0, 3) as $renewal): ?>
                    <div class="subs-renewal-item">
                        <div>
                            <div class="subs-renewal-date"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($renewal['date']))); ?></div>
                            <div class="subs-renewal-product"><?php echo esc_html($renewal['product_name']); ?></div>
                        </div>
                        <div class="subs-renewal-amount"><?php echo wc_price($renewal['amount']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Subscriptions List -->
        <div class="subs-subscriptions-list">
            <?php foreach ($subscriptions as $subscription):
                $product = $subscription->get_product();
                $can_pause = $customer->can_perform_action($subscription, 'pause');
                $can_cancel = $customer->can_perform_action($subscription, 'cancel');
                $can_change_payment = $customer->can_perform_action($subscription, 'change_payment_method');
            ?>

            <div class="subs-subscription-item" data-subscription-id="<?php echo esc_attr($subscription->get_id()); ?>">

                <div class="subs-subscription-header">
                    <div>
                        <h3 class="subs-subscription-title">
                            <a href="<?php echo esc_url(add_query_arg('view_subscription', $subscription->get_id())); ?>">
                                <?php
                                if ($product) {
                                    echo esc_html($product->get_name());
                                } else {
                                    printf(__('Subscription #%d', 'subs'), $subscription->get_id());
                                }
                                ?>
                            </a>
                        </h3>
                        <p class="subs-subscription-meta">
                            <?php printf(__('Started %s', 'subs'),
                                esc_html(date_i18n(get_option('date_format'), strtotime($subscription->get_start_date())))
                            ); ?>
                        </p>
                    </div>

                    <span class="subs-status-badge subs-status-<?php echo esc_attr($subscription->get_status()); ?>">
                        <?php echo esc_html($subscription->get_status_label()); ?>
                    </span>
                </div>

                <div class="subs-subscription-content">

                    <div class="subs-subscription-grid">
                        <div class="subs-subscription-detail">
                            <h4><?php _e('Amount', 'subs'); ?></h4>
                            <p><?php echo wp_kses_post($subscription->get_formatted_total()); ?></p>
                        </div>

                        <div class="subs-subscription-detail">
                            <h4><?php _e('Billing', 'subs'); ?></h4>
                            <p><?php echo esc_html($subscription->get_formatted_billing_period()); ?></p>
                        </div>

                        <?php if ($subscription->get_next_payment_date()): ?>
                        <div class="subs-subscription-detail">
                            <h4><?php _e('Next Payment', 'subs'); ?></h4>
                            <p><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($subscription->get_next_payment_date()))); ?></p>
                        </div>
                        <?php endif; ?>

                        <?php if ($subscription->get_flag_address()): ?>
                        <div class="subs-subscription-detail">
                            <h4><?php _e('Delivery Address', 'subs'); ?></h4>
                            <p><?php echo nl2br(esc_html($subscription->get_flag_address())); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Action Buttons -->
                    <div class="subs-subscription-actions">

                        <?php if ($subscription->is_active() && $can_pause): ?>
                        <button class="subs-btn subs-btn-warning subs-pause-btn"
                                data-subscription-id="<?php echo esc_attr($subscription->get_id()); ?>">
                            <?php _e('Pause', 'subs'); ?>
                        </button>
                        <?php endif; ?>

                        <?php if ($subscription->is_paused()): ?>
                        <button class="subs-btn subs-btn-success subs-resume-btn"
                                data-subscription-id="<?php echo esc_attr($subscription->get_id()); ?>">
                            <?php _e('Resume', 'subs'); ?>
                        </button>
                        <?php endif; ?>

                        <?php if (!$subscription->is_cancelled() && $can_cancel): ?>
                        <button class="subs-btn subs-btn-danger subs-cancel-btn"
                                data-subscription-id="<?php echo esc_attr($subscription->get_id()); ?>">
                            <?php _e('Cancel', 'subs'); ?>
                        </button>
                        <?php endif; ?>

                        <?php if ($subscription->is_active() || $subscription->is_paused()): ?>
                        <button class="subs-btn subs-btn-secondary subs-update-address-btn"
                                data-subscription-id="<?php echo esc_attr($subscription->get_id()); ?>"
                                data-current-address="<?php echo esc_attr($subscription->get_flag_address()); ?>">
                            <?php _e('Update Address', 'subs'); ?>
                        </button>
                        <?php endif; ?>

                        <?php if (($subscription->is_active() || $subscription->is_paused()) && $can_change_payment): ?>
                        <button class="subs-btn subs-btn-secondary subs-change-payment-btn"
                                data-subscription-id="<?php echo esc_attr($subscription->get_id()); ?>">
                            <?php _e('Change Payment Method', 'subs'); ?>
                        </button>
                        <?php endif; ?>

                        <button class="subs-btn subs-btn-secondary subs-view-details"
                                data-subscription-id="<?php echo esc_attr($subscription->get_id()); ?>">
                            <?php _e('View Details', 'subs'); ?>
                            <span class="subs-toggle-icon">▼</span>
                        </button>
                    </div>

                    <!-- Subscription Details (Hidden by default) -->
                    <div class="subs-subscription-details" style="display: none;">
                        <div class="subs-subscription-grid">

                            <?php if ($subscription->get_stripe_subscription_id()): ?>
                            <div class="subs-subscription-detail">
                                <h4><?php _e('Subscription ID', 'subs'); ?></h4>
                                <p><code><?php echo esc_html($subscription->get_stripe_subscription_id()); ?></code></p>
                            </div>
                            <?php endif; ?>

                            <?php if ($subscription->has_trial()): ?>
                            <div class="subs-subscription-detail">
                                <h4><?php _e('Trial End Date', 'subs'); ?></h4>
                                <p><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($subscription->get_trial_end_date()))); ?></p>
                            </div>
                            <?php endif; ?>

                            <?php if ($subscription->get_last_payment_date()): ?>
                            <div class="subs-subscription-detail">
                                <h4><?php _e('Last Payment', 'subs'); ?></h4>
                                <p><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($subscription->get_last_payment_date()))); ?></p>
                            </div>
                            <?php endif; ?>

                            <?php if ($subscription->is_cancelled() && $subscription->get_end_date()): ?>
                            <div class="subs-subscription-detail">
                                <h4><?php _e('Cancelled Date', 'subs'); ?></h4>
                                <p><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($subscription->get_end_date()))); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Subscription History -->
                        <div class="subs-subscription-history">
                            <button class="subs-load-history" data-subscription-id="<?php echo esc_attr($subscription->get_id()); ?>">
                                <?php _e('View History', 'subs'); ?>
                            </button>
                            <div class="subs-history-container"></div>
                        </div>
                    </div>

                </div>
            </div>

            <?php endforeach; ?>
        </div>

    <?php else: ?>

        <!-- No Subscriptions -->
        <div class="subs-no-subscriptions">
            <h3><?php _e('No Subscriptions Found', 'subs'); ?></h3>
            <p><?php _e('You don\'t have any subscriptions yet. Browse our products to get started!', 'subs'); ?></p>
            <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>" class="subs-btn subs-btn-primary">
                <?php _e('Browse Products', 'subs'); ?>
            </a>
        </div>

    <?php endif; ?>

</div>

<?php
/**
 * Action hook for adding custom content after subscriptions
 */
do_action('subs_after_account_subscriptions', $user_id, $subscriptions);
?>
