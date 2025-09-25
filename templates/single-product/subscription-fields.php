<?php
/**
 * Product Subscription Fields Template
 *
 * Displays subscription plan selection fields before add to cart button
 *
 * This template can be overridden by copying it to yourtheme/subs/single-product/subscription-fields.php.
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

// Helper function to format billing period
function subs_format_billing_period($interval, $interval_count = 1) {
    $interval_count = max(1, intval($interval_count));

    $intervals = array(
        'day' => _n('day', 'days', $interval_count, 'subs'),
        'week' => _n('week', 'weeks', $interval_count, 'subs'),
        'month' => _n('month', 'months', $interval_count, 'subs'),
        'year' => _n('year', 'years', $interval_count, 'subs'),
    );

    if (!isset($intervals[$interval])) {
        return $interval;
    }

    if ($interval_count === 1) {
        return $intervals[$interval];
    }

    return sprintf('%d %s', $interval_count, $intervals[$interval]);
}
?>

<div class="subs-subscription-fields" id="subs-subscription-fields" style="display: none;">

    <?php if (!empty($subscription_plans)) : ?>

        <div class="subs-plan-selection">
            <label for="subs-plan-select" class="subs-field-label">
                <?php _e('Choose your subscription plan:', 'subs'); ?>
                <span class="required">*</span>
            </label>

            <div class="subs-plans-container">

                <?php if (count($subscription_plans) === 1) : ?>
                    <!-- Single plan - show as hidden field with plan details -->
                    <?php
                    $plan_key = key($subscription_plans);
                    $plan = current($subscription_plans);
                    ?>

                    <input type="hidden" name="subscription_plan" value="<?php echo esc_attr($plan_key); ?>" />

                    <div class="subs-single-plan-display">
                        <div class="subs-plan-card subs-plan-selected">
                            <div class="subs-plan-header">
                                <h4 class="subs-plan-name">
                                    <?php echo esc_html($plan['name'] ?? __('Subscription Plan', 'subs')); ?>
                                </h4>
                                <div class="subs-plan-price">
                                    <span class="subs-price-amount">
                                        <?php echo wc_price($plan['price']); ?>
                                    </span>
                                    <span class="subs-price-period">
                                        / <?php echo esc_html(subs_format_billing_period($plan['interval'], $plan['interval_count'] ?? 1)); ?>
                                    </span>
                                </div>
                            </div>

                            <?php if (!empty($plan['description'])) : ?>
                                <div class="subs-plan-description">
                                    <?php echo wp_kses_post($plan['description']); ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($plan['trial_days']) && $plan['trial_days'] > 0) : ?>
                                <div class="subs-plan-trial">
                                    <span class="subs-trial-badge">
                                        <?php
                                        printf(
                                            _n('%d day free trial', '%d days free trial', $plan['trial_days'], 'subs'),
                                            $plan['trial_days']
                                        );
                                        ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php else : ?>
                    <!-- Multiple plans - show as selectable options -->
                    <div class="subs-plans-grid">

                        <?php foreach ($subscription_plans as $plan_key => $plan) : ?>
                            <div class="subs-plan-option">
                                <input
                                    type="radio"
                                    id="subs_plan_<?php echo esc_attr($plan_key); ?>"
                                    name="subscription_plan"
                                    value="<?php echo esc_attr($plan_key); ?>"
                                    class="subs-plan-radio"
                                    data-price="<?php echo esc_attr($plan['price']); ?>"
                                    data-interval="<?php echo esc_attr($plan['interval']); ?>"
                                    data-interval-count="<?php echo esc_attr($plan['interval_count'] ?? 1); ?>"
                                    <?php checked($plan_key, $default_plan['id'] ?? ''); ?>
                                />

                                <label for="subs_plan_<?php echo esc_attr($plan_key); ?>" class="subs-plan-card">
                                    <div class="subs-plan-header">
                                        <h4 class="subs-plan-name">
                                            <?php echo esc_html($plan['name'] ?? __('Subscription Plan', 'subs')); ?>
                                        </h4>

                                        <div class="subs-plan-badges">
                                            <?php if (!empty($plan['popular'])) : ?>
                                                <span class="subs-plan-badge subs-popular-badge">
                                                    <?php _e('Most Popular', 'subs'); ?>
                                                </span>
                                            <?php endif; ?>

                                            <?php if (!empty($plan['savings_percent']) && $plan['savings_percent'] > 0) : ?>
                                                <span class="subs-plan-badge subs-savings-badge">
                                                    <?php printf(__('Save %d%%', 'subs'), $plan['savings_percent']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="subs-plan-pricing">
                                        <div class="subs-plan-price">
                                            <span class="subs-price-amount">
                                                <?php echo wc_price($plan['price']); ?>
                                            </span>
                                            <span class="subs-price-period">
                                                / <?php echo esc_html(subs_format_billing_period($plan['interval'], $plan['interval_count'] ?? 1)); ?>
                                            </span>
                                        </div>

                                        <?php if (!empty($plan['original_price']) && $plan['original_price'] > $plan['price']) : ?>
                                            <div class="subs-plan-original-price">
                                                <span class="subs-original-price">
                                                    <?php echo wc_price($plan['original_price']); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($plan['description'])) : ?>
                                        <div class="subs-plan-description">
                                            <?php echo wp_kses_post($plan['description']); ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($plan['features'])) : ?>
                                        <div class="subs-plan-features">
                                            <ul class="subs-features-list">
                                                <?php foreach ($plan['features'] as $feature) : ?>
                                                    <li class="subs-feature-item">
                                                        <span class="subs-feature-icon">✓</span>
                                                        <?php echo esc_html($feature); ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($plan['trial_days']) && $plan['trial_days'] > 0) : ?>
                                        <div class="subs-plan-trial">
                                            <span class="subs-trial-badge">
                                                <?php
                                                printf(
                                                    _n('%d day free trial', '%d days free trial', $plan['trial_days'], 'subs'),
                                                    $plan['trial_days']
                                                );
                                                ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($plan['sign_up_fee']) && $plan['sign_up_fee'] > 0) : ?>
                                        <div class="subs-plan-signup-fee">
                                            <small class="subs-signup-fee-text">
                                                <?php
                                                printf(
                                                    __('One-time setup fee: %s', 'subs'),
                                                    wc_price($plan['sign_up_fee'])
                                                );
                                                ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </label>
                            </div>
                        <?php endforeach; ?>

                    </div>
                <?php endif; ?>

            </div>
        </div>

        <?php
        /**
         * Hook for additional subscription fields
         *
         * @since 1.0.0
         * @param WC_Product $product Current product object
         * @param array $subscription_plans Available subscription plans
         * @param array $default_plan Default plan data
         */
        do_action('subs_after_subscription_plan_fields', $product, $subscription_plans, $default_plan);
        ?>

        <div class="subs-field-notices">
            <!-- AJAX notices will be inserted here -->
        </div>

    <?php else : ?>

        <div class="subs-no-plans-message">
            <p><?php _e('No subscription plans are currently available for this product.', 'subs'); ?></p>
        </div>

    <?php endif; ?>

</div>

<style>
/* Subscription Fields Styling */
.subs-subscription-fields {
    margin: 20px 0;
    padding: 20px;
    border: 1px solid #ddd;
    border-radius: 8px;
    background: #f8f9fa;
}

.subs-field-label {
    display: block;
    margin-bottom: 15px;
    font-weight: 600;
    color: #333;
    font-size: 1.1em;
}

.subs-field-label .required {
    color: #dc3232;
    font-weight: bold;
}

/* Single Plan Display */
.subs-single-plan-display .subs-plan-card {
    border: 2px solid #007cba;
    background: #fff;
    box-shadow: 0 4px 12px rgba(0, 124, 186, 0.1);
}

/* Plans Grid */
.subs-plans-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin: 15px 0;
}

.subs-plan-option {
    position: relative;
}

.subs-plan-radio {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.subs-plan-card {
    display: block;
    padding: 24px;
    border: 2px solid #e0e0e0;
    border-radius: 12px;
    background: #fff;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
    height: 100%;
    box-sizing: border-box;
}

.subs-plan-card:hover {
    border-color: #007cba;
    box-shadow: 0 4px 12px rgba(0, 124, 186, 0.15);
    transform: translateY(-2px);
}

.subs-plan-radio:checked + .subs-plan-card,
.subs-plan-selected {
    border-color: #007cba;
    background: linear-gradient(135deg, #f0f8ff 0%, #fff 100%);
    box-shadow: 0 6px 20px rgba(0, 124, 186, 0.2);
}

.subs-plan-radio:checked + .subs-plan-card::before {
    content: "✓";
    position: absolute;
    top: 15px;
    right: 15px;
    width: 24px;
    height: 24px;
    background: #007cba;
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: bold;
}

.subs-plan-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
    flex-wrap: wrap;
}

.subs-plan-name {
    margin: 0;
    font-size: 1.3em;
    color: #333;
    flex: 1;
    font-weight: 600;
}

.subs-plan-badges {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-left: 15px;
}

.subs-plan-badge {
    padding: 4px 10px;
    border-radius: 15px;
    font-size: 0.75em;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.subs-popular-badge {
    background: linear-gradient(135deg, #28a745, #20c997);
    color: white;
    box-shadow: 0 2px 4px rgba(40, 167, 69, 0.3);
}

.subs-savings-badge {
    background: linear-gradient(135deg, #ffc107, #fd7e14);
    color: #333;
    box-shadow: 0 2px 4px rgba(255, 193, 7, 0.3);
}

.subs-plan-pricing {
    margin-bottom: 16px;
}

.subs-plan-price {
    font-size: 1.4em;
    font-weight: bold;
    color: #333;
    margin-bottom: 6px;
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: 8px;
}

.subs-price-amount {
    color: #007cba;
    font-size: 1.2em;
}

.subs-price-period {
    font-size: 0.7em;
    color: #666;
    font-weight: normal;
}

.subs-plan-original-price {
    margin-top: 4px;
}

.subs-original-price {
    text-decoration: line-through;
    color: #999;
    font-size: 0.9em;
    font-weight: normal;
}

.subs-plan-description {
    margin-bottom: 16px;
    color: #666;
    font-size: 0.95em;
    line-height: 1.5;
}

.subs-plan-features {
    margin-bottom: 16px;
}

.subs-features-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.subs-feature-item {
    display: flex;
    align-items: center;
    margin: 8px 0;
    font-size: 0.9em;
    color: #555;
    line-height: 1.4;
}

.subs-feature-icon {
    color: #28a745;
    font-weight: bold;
    margin-right: 10px;
    font-size: 1.1em;
    min-width: 16px;
}

.subs-plan-trial {
    margin: 16px 0 8px 0;
}

.subs-trial-badge {
    display: inline-block;
    padding: 8px 16px;
    background: linear-gradient(135deg, #e8f5e8, #d4edda);
    color: #28a745;
    border-radius: 25px;
    font-size: 0.85em;
    font-weight: 600;
    border: 2px solid #28a745;
}

.subs-plan-signup-fee {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #eee;
}

.subs-signup-fee-text {
    color: #666;
    font-style: italic;
    font-size: 0.85em;
}

.subs-no-plans-message {
    text-align: center;
    padding: 30px 20px;
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 8px;
    color: #856404;
}

.subs-field-notices {
    margin-top: 20px;
}

.subs-loading {
    padding: 10px;
    text-align: center;
    background: #f0f8ff;
    border: 1px solid #007cba;
    border-radius: 4px;
    color: #007cba;
}

.subs-error {
    padding: 10px;
    background: #f8d7da;
    border: 1px solid #dc3545;
    border-radius: 4px;
    color: #721c24;
}

/* Responsive Design */
@media (max-width: 768px) {
    .subs-plans-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }

    .subs-plan-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .subs-plan-badges {
        margin-left: 0;
        margin-top: 10px;
        flex-direction: row;
        flex-wrap: wrap;
    }

    .subs-plan-price {
        font-size: 1.2em;
    }

    .subs-subscription-fields {
        padding: 15px;
        margin: 15px 0;
    }
}

@media (max-width: 480px) {
    .subs-plan-card {
        padding: 20px 16px;
    }

    .subs-plan-price {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
    }

    .subs-price-period {
        font-size: 0.8em;
    }
}
</style>

<script>
jQuery(document).ready(function($) {

    // Show/hide subscription fields based on purchase type selection
    $('input[name="subs_purchase_type"]').on('change', function() {
        if ($(this).val() === 'subscription') {
            $('#subs-subscription-fields').slideDown(400);
        } else {
            $('#subs-subscription-fields').slideUp(400);
        }
    });

    // Handle subscription plan selection
    $('.subs-plan-radio').on('change', function() {
        if ($(this).is(':checked')) {
            // Check if subs_product object exists (from localized script)
            if (typeof subs_product !== 'undefined') {
                // Update product price display via AJAX
                var productId = <?php echo $product->get_id(); ?>;
                var planId = $(this).val();

                $.ajax({
                    url: subs_product.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'subs_get_subscription_price',
                        nonce: subs_product.nonce,
                        product_id: productId,
                        plan_id: planId
                    },
                    beforeSend: function() {
                        $('.subs-field-notices').html('<div class="subs-loading">' + (subs_product.strings.loading || 'Loading...') + '</div>');
                    },
                    success: function(response) {
                        $('.subs-field-notices').empty();

                        if (response.success) {
                            // Update price display
                            $('.price .amount, .price ins .amount').html(response.data.price_html);

                            // Trigger price update event for other plugins
                            $(document.body).trigger('subs_price_updated', [response.data]);
                        } else {
                            $('.subs-field-notices').html('<div class="subs-error">' + response.data + '</div>');
                        }
                    },
                    error: function() {
                        $('.subs-field-notices').html('<div class="subs-error">' + (subs_product.strings.error_occurred || 'An error occurred. Please try again.') + '</div>');
                    }
                });
            }
        }
    });

    // Initialize with selected plan (if any)
    $('.subs-plan-radio:checked').trigger('change');

    // Initialize subscription fields visibility
    if ($('input[name="subs_purchase_type"]:checked').val() === 'subscription') {
        $('#subs-subscription-fields').show();
    }

    // Add form validation
    $('form.cart').on('submit', function(e) {
        // Check if subscription fields are visible and no plan is selected
        if ($('#subs-subscription-fields').is(':visible')) {
            if (!$('.subs-plan-radio:checked').length) {
                e.preventDefault();
                $('.subs-field-notices').html('<div class="subs-error">' + (typeof subs_product !== 'undefined' ? subs_product.strings.select_plan : 'Please select a subscription plan') + '</div>');

                // Scroll to the error
                $('html, body').animate({
                    scrollTop: $('.subs-field-notices').offset().top - 100
                }, 500);

                return false;
            }
        }
    });

});
</script>
