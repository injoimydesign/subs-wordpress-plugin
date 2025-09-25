<?php
/**
 * Product Subscription Options Template
 *
 * Displays subscription options on single product pages
 *
 * This template can be overridden by copying it to yourtheme/subs/single-product/subscription-options.php.
 *
 * @package Subs
 * @subpackage Templates
 * @version 1.0.0
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

global $product;
?>

<div class="subs-subscription-options" id="subs-subscription-options">
    <div class="subs-options-header">
        <h3 class="subs-options-title">
            <?php _e('Subscription Options', 'subs'); ?>
        </h3>
        <p class="subs-options-description">
            <?php _e('Choose how often you\'d like to receive this product.', 'subs'); ?>
        </p>
    </div>

    <?php if (!empty($subscription_options)) : ?>
        <div class="subs-options-content">

            <?php if (isset($subscription_options['allow_one_time']) && $subscription_options['allow_one_time']) : ?>
                <div class="subs-purchase-options">
                    <h4 class="subs-section-title">
                        <?php _e('Purchase Options', 'subs'); ?>
                    </h4>

                    <div class="subs-purchase-choice">
                        <label class="subs-option-label">
                            <input type="radio" name="subs_purchase_type" value="one_time" class="subs-purchase-type-radio" checked>
                            <span class="subs-label-text">
                                <?php _e('One-time purchase', 'subs'); ?>
                                <span class="subs-one-time-price">
                                    <?php echo $product->get_price_html(); ?>
                                </span>
                            </span>
                        </label>
                    </div>

                    <div class="subs-purchase-choice">
                        <label class="subs-option-label">
                            <input type="radio" name="subs_purchase_type" value="subscription" class="subs-purchase-type-radio">
                            <span class="subs-label-text">
                                <?php _e('Subscribe and save', 'subs'); ?>
                                <span class="subs-subscription-benefits">
                                    <?php if (isset($subscription_options['savings_text'])) : ?>
                                        <small class="subs-savings-text"><?php echo esc_html($subscription_options['savings_text']); ?></small>
                                    <?php endif; ?>
                                </span>
                            </span>
                        </label>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($subscription_options['benefits']) && !empty($subscription_options['benefits'])) : ?>
                <div class="subs-benefits-section">
                    <h4 class="subs-section-title">
                        <?php _e('Subscription Benefits', 'subs'); ?>
                    </h4>

                    <ul class="subs-benefits-list">
                        <?php foreach ($subscription_options['benefits'] as $benefit) : ?>
                            <li class="subs-benefit-item">
                                <span class="subs-benefit-icon">✓</span>
                                <?php echo esc_html($benefit); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (isset($subscription_options['delivery_info']) && !empty($subscription_options['delivery_info'])) : ?>
                <div class="subs-delivery-info">
                    <h4 class="subs-section-title">
                        <?php _e('Delivery Information', 'subs'); ?>
                    </h4>

                    <div class="subs-delivery-content">
                        <?php echo wp_kses_post($subscription_options['delivery_info']); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($subscription_options['cancellation_policy'])) : ?>
                <div class="subs-policy-info">
                    <details class="subs-policy-details">
                        <summary class="subs-policy-summary">
                            <?php _e('Cancellation Policy', 'subs'); ?>
                        </summary>
                        <div class="subs-policy-content">
                            <?php echo wp_kses_post($subscription_options['cancellation_policy']); ?>
                        </div>
                    </details>
                </div>
            <?php endif; ?>

        </div>
    <?php else : ?>
        <p class="subs-no-options">
            <?php _e('No subscription options available for this product.', 'subs'); ?>
        </p>
    <?php endif; ?>

    <?php
    /**
     * Hook for additional subscription option content
     *
     * @since 1.0.0
     * @param WC_Product $product Current product object
     * @param array $subscription_options Subscription options data
     */
    do_action('subs_after_subscription_options', $product, $subscription_options);
    ?>
</div>

<style>
/* Basic styling - can be overridden by theme */
.subs-subscription-options {
    margin: 20px 0;
    padding: 20px;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    background: #f9f9f9;
}

.subs-options-title {
    margin: 0 0 10px 0;
    font-size: 1.2em;
    color: #333;
}

.subs-options-description {
    margin: 0 0 20px 0;
    color: #666;
    font-size: 0.9em;
}

.subs-section-title {
    margin: 15px 0 10px 0;
    font-size: 1em;
    color: #333;
}

.subs-purchase-choice {
    margin: 10px 0;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    transition: background-color 0.3s;
}

.subs-purchase-choice:hover {
    background-color: #fff;
}

.subs-option-label {
    display: flex;
    align-items: center;
    cursor: pointer;
    font-weight: normal;
}

.subs-purchase-type-radio {
    margin-right: 10px;
}

.subs-label-text {
    flex: 1;
}

.subs-one-time-price,
.subs-subscription-benefits {
    display: block;
    margin-top: 5px;
}

.subs-savings-text {
    color: #28a745;
    font-weight: bold;
}

.subs-benefits-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.subs-benefit-item {
    display: flex;
    align-items: center;
    margin: 8px 0;
    padding: 5px 0;
}

.subs-benefit-icon {
    color: #28a745;
    font-weight: bold;
    margin-right: 10px;
    font-size: 1.1em;
}

.subs-delivery-info,
.subs-policy-info {
    margin: 15px 0;
    padding: 10px;
    background: #fff;
    border-radius: 4px;
    border-left: 4px solid #007cba;
}

.subs-policy-details {
    margin: 0;
}

.subs-policy-summary {
    cursor: pointer;
    font-weight: bold;
    color: #007cba;
    padding: 5px 0;
}

.subs-policy-content {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #eee;
}

.subs-no-options {
    text-align: center;
    color: #999;
    font-style: italic;
}
</style>
