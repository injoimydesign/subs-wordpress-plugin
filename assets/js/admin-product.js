jQuery(document).ready(function($) {
    'use strict';

    /**
     * Product Subscription Settings Handler
     */
    const ProductSubscriptionSettings = {

        init: function() {
            this.bindEvents();
            this.toggleSubscriptionFields();
        },

        bindEvents: function() {
            // Toggle subscription fields when checkbox changes
            $(document).on('change', '#_subscription_enabled', this.toggleSubscriptionFields);

            // Update preview when fields change
            $(document).on('change keyup', '.subs-conditional-fields input, .subs-conditional-fields select, .subs-conditional-fields textarea', this.debounce(this.updatePreview, 500));

            // Handle tab activation
            $(document).on('click', '.wc-tabs .subscription_tab a', this.activateSubscriptionTab);
        },

        toggleSubscriptionFields: function() {
            const $checkbox = $('#_subscription_enabled');
            const $fields = $('.subs-conditional-fields');

            if ($checkbox.is(':checked')) {
                $fields.slideDown(300);
            } else {
                $fields.slideUp(300);
            }
        },

        updatePreview: function() {
            const $preview = $('#subs-subscription-preview');

            if (!$preview.length) {
                return;
            }

            const productId = $('#post_ID').val();

            if (!productId) {
                return;
            }

            const data = {
                action: 'subs_preview_subscription_settings',
                nonce: subs_product_admin.nonce,
                product_id: productId,
                _subscription_period: $('#_subscription_period').val(),
                _subscription_period_interval: $('#_subscription_period_interval').val(),
                _subscription_trial_period: $('#_subscription_trial_period').val(),
                _subscription_signup_fee: $('#_subscription_signup_fee').val(),
                _subscription_length: $('#_subscription_length').val(),
                _subscription_include_stripe_fees: $('#_subscription_include_stripe_fees').is(':checked') ? 'yes' : 'no'
            };

            $preview.html('<div class="subs-loading">' + subs_product_admin.strings.preview_loading + '</div>');

            $.ajax({
                url: subs_product_admin.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success && response.data.preview) {
                        $preview.html(response.data.preview);
                    } else {
                        $preview.html('<div class="subs-error">' + subs_product_admin.strings.preview_error + '</div>');
                    }
                },
                error: function() {
                    $preview.html('<div class="subs-error">' + subs_product_admin.strings.preview_error + '</div>');
                }
            });
        },

        activateSubscriptionTab: function(e) {
            e.preventDefault();

            // Remove active class from all tabs
            $('.wc-tabs li').removeClass('active');
            $('.panel').hide();

            // Add active class to clicked tab
            $(this).parent().addClass('active');

            // Show the subscription panel
            $('#subs_subscription_product_data').show();

            // Trigger field visibility check
            ProductSubscriptionSettings.toggleSubscriptionFields();
        },

        debounce: function(func, wait, immediate) {
            let timeout;
            return function() {
                const context = this;
                const args = arguments;
                const later = function() {
                    timeout = null;
                    if (!immediate) func.apply(context, args);
                };
                const callNow = immediate && !timeout;
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
                if (callNow) func.apply(context, args);
            };
        }
    };

    /**
     * Product List Enhancements
     */
    const ProductListEnhancements = {

        init: function() {
            this.handleQuickEdit();
            this.addInlineData();
        },

        handleQuickEdit: function() {
            // Handle quick edit inline data
            $('#the-list').on('click', '.editinline', function() {
                const postId = $(this).closest('tr').attr('id').replace('post-', '');
                const $row = $(this).closest('tr');

                // Get subscription status from the row
                const hasSubscriptionIcon = $row.find('.subs-enabled-indicator').length > 0;

                // Set checkbox in quick edit form
                setTimeout(() => {
                    $('input[name="_subscription_enabled"]', '.inline-edit-row').prop('checked', hasSubscriptionIcon);
                }, 100);
            });
        },

        addInlineData: function() {
            // Add inline data for WooCommerce compatibility
            $('.type-product').each(function() {
                const $row = $(this);
                const postId = $row.attr('id').replace('post-', '');
                const hasSubscription = $row.find('.subs-enabled-indicator').length > 0;

                // Create or update inline data
                let $inlineData = $('#woocommerce_inline_' + postId);
                if ($inlineData.length === 0) {
                    $inlineData = $('<div id="woocommerce_inline_' + postId + '" class="hidden"></div>');
                    $row.after($inlineData);
                }

                // Add subscription data
                $inlineData.append('<div class="subscription_enabled">' + (hasSubscription ? 'yes' : 'no') + '</div>');
            });
        }
    };

    /**
     * Variation Subscription Settings
     */
    const VariationSubscriptionSettings = {

        init: function() {
            this.bindVariationEvents();
        },

        bindVariationEvents: function() {
            // Handle variation subscription checkbox
            $(document).on('change', 'input[name^="_subscription_enabled["]', this.toggleVariationSubscription);
        },

        toggleVariationSubscription: function() {
            const $checkbox = $(this);
            const $container = $checkbox.closest('.woocommerce_variation');

            if ($checkbox.is(':checked')) {
                $container.addClass('subs-variation-enabled');
            } else {
                $container.removeClass('subs-variation-enabled');
            }
        }
    };

    /**
     * Initialize all components
     */
    function init() {
        ProductSubscriptionSettings.init();
        ProductListEnhancements.init();
        VariationSubscriptionSettings.init();

        // Make sure subscription fields are properly shown/hidden on page load
        setTimeout(() => {
            ProductSubscriptionSettings.toggleSubscriptionFields();
        }, 500);
    }

    // Initialize
    init();

    // Re-initialize after WooCommerce updates
    $(document).on('woocommerce_variations_loaded', function() {
        VariationSubscriptionSettings.init();
    });
});
