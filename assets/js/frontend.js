/**
 * Subs Frontend JavaScript
 *
 * Handles frontend subscription interactions
 *
 * @package Subs
 * @version 1.0.0
 */

jQuery(document).ready(function($) {
    'use strict';

    // Initialize Stripe if available
    let stripe = null;
    if (typeof Stripe !== 'undefined' && subs_frontend.stripe_publishable_key) {
        stripe = Stripe(subs_frontend.stripe_publishable_key);
    }

    /**
     * Subscription Options Handler
     */
    const SubscriptionOptions = {
        init: function() {
            this.bindEvents();
            this.updatePricing();
        },

        bindEvents: function() {
            // Toggle subscription option
            $(document).on('change', '.subs-enable-subscription', this.toggleSubscription);

            // Update pricing when quantity changes
            $(document).on('change keyup', '.qty, input[name="quantity"]', this.updatePricing);

            // Subscription period changes
            $(document).on('change', '.subs-billing-period, .subs-billing-interval', this.updatePricing);
        },

        toggleSubscription: function() {
            const isChecked = $(this).is(':checked');
            const $container = $(this).closest('.subs-subscription-options');

            $container.find('.subs-subscription-details').toggle(isChecked);
            $container.find('.subs-pricing-display').toggle(isChecked);

            if (isChecked) {
                SubscriptionOptions.updatePricing();
            }
        },

        updatePricing: function() {
            const $container = $('.subs-subscription-options');
            if (!$container.length || !$('.subs-enable-subscription').is(':checked')) {
                return;
            }

            const productId = $container.data('product-id');
            const quantity = parseInt($('input[name="quantity"]').val() || 1);

            if (!productId) return;

            // Show loading state
            $container.find('.subs-pricing-display').addClass('loading');

            $.post(subs_frontend.ajax_url, {
                action: 'subs_calculate_subscription_price',
                nonce: subs_frontend.nonce,
                product_id: productId,
                quantity: quantity
            })
            .done(function(response) {
                if (response.success) {
                    SubscriptionOptions.displayPricing(response.data);
                } else {
                    console.error('Pricing calculation failed:', response.data);
                }
            })
            .fail(function() {
                console.error('AJAX request failed');
            })
            .always(function() {
                $container.find('.subs-pricing-display').removeClass('loading');
            });
        },

        displayPricing: function(data) {
            const $container = $('.subs-subscription-options');
            const $display = $container.find('.subs-pricing-display');

            let html = '<div class="subs-price-breakdown">';
            html += '<p class="subs-base-price">Base Price: ' + data.base_price_formatted + '</p>';

            if (data.stripe_fee > 0) {
                html += '<p class="subs-stripe-fee">Processing Fee: ' + data.stripe_fee_formatted + '</p>';
            }

            html += '<p class="subs-total-price"><strong>Total: ' + data.total_price_formatted + '</strong></p>';
            html += '<p class="subs-billing-info">' + data.billing_text + '</p>';
            html += '</div>';

            $display.html(html);
        }
    };

    /**
     * Subscription Management
     */
    const SubscriptionManager = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Action buttons
            $(document).on('click', '.subs-pause-btn', this.pauseSubscription);
            $(document).on('click', '.subs-resume-btn', this.resumeSubscription);
            $(document).on('click', '.subs-cancel-btn', this.showCancelModal);
            $(document).on('click', '.subs-update-address-btn', this.showAddressModal);
            $(document).on('click', '.subs-change-payment-btn', this.showPaymentMethodModal);

            // Modal actions
            $(document).on('click', '.subs-confirm-cancel', this.cancelSubscription);
            $(document).on('click', '.subs-save-address', this.updateAddress);
            $(document).on('click', '.subs-modal-close, .subs-modal-overlay', this.closeModal);

            // Prevent modal close when clicking inside
            $(document).on('click', '.subs-modal-content', function(e) {
                e.stopPropagation();
            });
        },

        pauseSubscription: function(e) {
            e.preventDefault();

            if (!confirm(subs_frontend.strings.confirm_pause)) {
                return;
            }

            const subscriptionId = $(this).data('subscription-id');
            SubscriptionManager.performAction('pause_subscription', subscriptionId);
        },

        resumeSubscription: function(e) {
            e.preventDefault();

            const subscriptionId = $(this).data('subscription-id');
            SubscriptionManager.performAction('resume_subscription', subscriptionId);
        },

        showCancelModal: function(e) {
            e.preventDefault();

            const subscriptionId = $(this).data('subscription-id');
            const modal = SubscriptionManager.createCancelModal(subscriptionId);
            $('body').append(modal);
        },

        cancelSubscription: function(e) {
            e.preventDefault();

            const subscriptionId = $(this).data('subscription-id');
            const reason = $('#cancel-reason').val();

            SubscriptionManager.performAction('cancel_subscription', subscriptionId, { reason: reason });
        },

        showAddressModal: function(e) {
            e.preventDefault();

            const subscriptionId = $(this).data('subscription-id');
            const currentAddress = $(this).data('current-address') || '';
            const modal = SubscriptionManager.createAddressModal(subscriptionId, currentAddress);
            $('body').append(modal);
        },

        updateAddress: function(e) {
            e.preventDefault();

            const subscriptionId = $(this).data('subscription-id');
            const address = $('#flag-address').val();

            if (!address.trim()) {
                alert('Please enter a valid address.');
                return;
            }

            SubscriptionManager.performAction('update_flag_address', subscriptionId, { flag_address: address });
        },

        showPaymentMethodModal: function(e) {
            e.preventDefault();

            if (!stripe) {
                alert('Payment method updates are not available at this time.');
                return;
            }

            const subscriptionId = $(this).data('subscription-id');
            const modal = SubscriptionManager.createPaymentMethodModal(subscriptionId);
            $('body').append(modal);

            // Initialize Stripe Elements
            SubscriptionManager.initializeStripeElements();
        },

        performAction: function(action, subscriptionId, extraData = {}) {
            const $button = $(`[data-subscription-id="${subscriptionId}"]`);
            const originalText = $button.text();

            $button.prop('disabled', true).text(subs_frontend.strings.processing);

            const data = {
                action: 'subs_' + action,
                nonce: subs_frontend.nonce,
                subscription_id: subscriptionId,
                ...extraData
            };

            $.post(subs_frontend.ajax_url, data)
                .done(function(response) {
                    if (response.success) {
                        SubscriptionManager.showSuccess(response.data.message);
                        SubscriptionManager.closeModal();

                        // Update UI if status changed
                        if (response.data.new_status) {
                            SubscriptionManager.updateSubscriptionStatus(subscriptionId, response.data);
                        }

                        // Refresh page for address updates
                        if (action === 'update_flag_address') {
                            window.location.reload();
                        }
                    } else {
                        SubscriptionManager.showError(response.data.message || response.data);
                    }
                })
                .fail(function() {
                    SubscriptionManager.showError(subs_frontend.strings.error);
                })
                .always(function() {
                    $button.prop('disabled', false).text(originalText);
                });
        },

        updateSubscriptionStatus: function(subscriptionId, data) {
            const $row = $(`[data-subscription-id="${subscriptionId}"]`).closest('.subs-subscription-item');

            // Update status badge
            $row.find('.subs-status-badge')
                .removeClass()
                .addClass(`subs-status-badge subs-status-${data.new_status}`)
                .text(data.status_label);

            // Update action buttons
            SubscriptionManager.updateActionButtons($row, data.new_status);
        },

        updateActionButtons: function($row, status) {
            const $actions = $row.find('.subs-subscription-actions');

            // Hide all action buttons first
            $actions.find('button').hide();

            // Show appropriate buttons based on status
            switch (status) {
                case 'active':
                    $actions.find('.subs-pause-btn, .subs-cancel-btn').show();
                    break;
                case 'paused':
                    $actions.find('.subs-resume-btn, .subs-cancel-btn').show();
                    break;
                case 'cancelled':
                    // No actions available for cancelled subscriptions
                    break;
            }
        },

        createCancelModal: function(subscriptionId) {
            return `
                <div class="subs-modal-overlay">
                    <div class="subs-modal">
                        <div class="subs-modal-header">
                            <h3>Cancel Subscription</h3>
                            <button class="subs-modal-close">&times;</button>
                        </div>
                        <div class="subs-modal-content">
                            <p>Are you sure you want to cancel this subscription?</p>
                            <div class="subs-form-group">
                                <label for="cancel-reason">Reason (optional):</label>
                                <textarea id="cancel-reason" placeholder="Tell us why you're cancelling..."></textarea>
                            </div>
                        </div>
                        <div class="subs-modal-footer">
                            <button class="subs-btn subs-btn-secondary subs-modal-close">Keep Subscription</button>
                            <button class="subs-btn subs-btn-danger subs-confirm-cancel" data-subscription-id="${subscriptionId}">
                                Cancel Subscription
                            </button>
                        </div>
                    </div>
                </div>
            `;
        },

        createAddressModal: function(subscriptionId, currentAddress) {
            return `
                <div class="subs-modal-overlay">
                    <div class="subs-modal">
                        <div class="subs-modal-header">
                            <h3>Update Flag Delivery Address</h3>
                            <button class="subs-modal-close">&times;</button>
                        </div>
                        <div class="subs-modal-content">
                            <div class="subs-form-group">
                                <label for="flag-address">Delivery Address:</label>
                                <textarea id="flag-address" required>${currentAddress}</textarea>
                            </div>
                        </div>
                        <div class="subs-modal-footer">
                            <button class="subs-btn subs-btn-secondary subs-modal-close">Cancel</button>
                            <button class="subs-btn subs-btn-primary subs-save-address" data-subscription-id="${subscriptionId}">
                                Update Address
                            </button>
                        </div>
                    </div>
                </div>
            `;
        },

        createPaymentMethodModal: function(subscriptionId) {
            return `
                <div class="subs-modal-overlay">
                    <div class="subs-modal subs-modal-large">
                        <div class="subs-modal-header">
                            <h3>Update Payment Method</h3>
                            <button class="subs-modal-close">&times;</button>
                        </div>
                        <div class="subs-modal-content">
                            <div id="payment-element">
                                <!-- Stripe Elements will be inserted here -->
                            </div>
                            <div id="payment-message" class="hidden"></div>
                        </div>
                        <div class="subs-modal-footer">
                            <button class="subs-btn subs-btn-secondary subs-modal-close">Cancel</button>
                            <button class="subs-btn subs-btn-primary" id="submit-payment-method" data-subscription-id="${subscriptionId}">
                                Update Payment Method
                            </button>
                        </div>
                    </div>
                </div>
            `;
        },

        initializeStripeElements: function() {
            if (!stripe) return;

            const elements = stripe.elements();
            const paymentElement = elements.create('payment');
            paymentElement.mount('#payment-element');

            $('#submit-payment-method').click(function(e) {
                e.preventDefault();

                const subscriptionId = $(this).data('subscription-id');

                $(this).prop('disabled', true).text('Processing...');

                stripe.confirmSetup({
                    elements,
                    confirmParams: {
                        return_url: window.location.href,
                    },
                }).then(function(result) {
                    if (result.error) {
                        SubscriptionManager.showPaymentError(result.error.message);
                        $('#submit-payment-method').prop('disabled', false).text('Update Payment Method');
                    } else {
                        // Payment method setup successful
                        SubscriptionManager.performAction('update_payment_method', subscriptionId, {
                            payment_method_id: result.setupIntent.payment_method
                        });
                    }
                });
            });
        },

        closeModal: function(e) {
            if (e) e.preventDefault();
            $('.subs-modal-overlay').remove();
        },

        showSuccess: function(message) {
            SubscriptionManager.showNotice(message, 'success');
        },

        showError: function(message) {
            SubscriptionManager.showNotice(message, 'error');
        },

        showPaymentError: function(message) {
            const $messageEl = $('#payment-message');
            $messageEl.removeClass('hidden').addClass('error').text(message);
        },

        showNotice: function(message, type = 'info') {
            // Create notice element
            const notice = $(`
                <div class="subs-notice subs-notice-${type}">
                    <p>${message}</p>
                    <button class="subs-notice-dismiss">&times;</button>
                </div>
            `);

            // Add to page
            if ($('.woocommerce-notices-wrapper').length) {
                $('.woocommerce-notices-wrapper').prepend(notice);
            } else {
                $('body').prepend(notice);
            }

            // Auto-dismiss success messages
            if (type === 'success') {
                setTimeout(function() {
                    notice.fadeOut();
                }, 5000);
            }

            // Manual dismiss
            notice.find('.subs-notice-dismiss').click(function() {
                notice.fadeOut();
            });

            // Scroll to notice
            $('html, body').animate({
                scrollTop: notice.offset().top - 100
            }, 500);
        }
    };

    /**
     * Subscription Details
     */
    const SubscriptionDetails = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $(document).on('click', '.subs-view-details', this.toggleDetails);
            $(document).on('click', '.subs-load-history', this.loadHistory);
        },

        toggleDetails: function(e) {
            e.preventDefault();

            const $details = $(this).next('.subs-subscription-details');
            const $icon = $(this).find('.subs-toggle-icon');

            $details.slideToggle();
            $icon.toggleClass('rotated');
        },

        loadHistory: function(e) {
            e.preventDefault();

            const subscriptionId = $(this).data('subscription-id');
            const $container = $(this).next('.subs-history-container');

            if ($container.hasClass('loaded')) {
                return;
            }

            $(this).text('Loading...');

            $.post(subs_frontend.ajax_url, {
                action: 'subs_get_subscription_details',
                nonce: subs_frontend.nonce,
                subscription_id: subscriptionId
            })
            .done(function(response) {
                if (response.success && response.data.history) {
                    SubscriptionDetails.displayHistory($container, response.data.history);
                    $container.addClass('loaded');
                }
            })
            .always(function() {
                $('.subs-load-history').text('View History');
            });
        },

        displayHistory: function($container, history) {
            if (!history.length) {
                $container.html('<p>No history available.</p>');
                return;
            }

            let html = '<div class="subs-history-list">';

            history.forEach(function(entry) {
                html += `
                    <div class="subs-history-item">
                        <div class="subs-history-date">${entry.date}</div>
                        <div class="subs-history-action">${entry.action.replace('_', ' ').toUpperCase()}</div>
                        <div class="subs-history-note">${entry.note}</div>
                    </div>
                `;
            });

            html += '</div>';
            $container.html(html);
        }
    };

    /**
     * Form Enhancements
     */
    const FormEnhancements = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Auto-save preferences
            $(document).on('change', '.subs-preference-field', this.savePreference);

            // Form validation
            $(document).on('submit', '.subs-form', this.validateForm);
        },

        savePreference: function() {
            const $field = $(this);
            const preference = $field.data('preference');
            const value = $field.is(':checkbox') ? ($field.is(':checked') ? 'yes' : 'no') : $field.val();

            // Visual feedback
            $field.after('<span class="subs-saving">Saving...</span>');

            $.post(subs_frontend.ajax_url, {
                action: 'subs_save_preference',
                nonce: subs_frontend.nonce,
                preference: preference,
                value: value
            })
            .always(function() {
                $field.siblings('.subs-saving').remove();
            });
        },

        validateForm: function(e) {
            let isValid = true;
            const $form = $(this);

            // Clear previous errors
            $form.find('.subs-field-error').remove();

            // Required fields
            $form.find('[required]').each(function() {
                const $field = $(this);
                if (!$field.val().trim()) {
                    $field.after('<span class="subs-field-error">This field is required.</span>');
                    isValid = false;
                }
            });

            // Email validation
            $form.find('input[type="email"]').each(function() {
                const $field = $(this);
                const email = $field.val();
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

                if (email && !emailRegex.test(email)) {
                    $field.after('<span class="subs-field-error">Please enter a valid email address.</span>');
                    isValid = false;
                }
            });

            if (!isValid) {
                e.preventDefault();
                // Scroll to first error
                $('html, body').animate({
                    scrollTop: $form.find('.subs-field-error').first().offset().top - 100
                }, 500);
            }
        }
    };

    /**
     * Initialize all components
     */
    function init() {
        SubscriptionOptions.init();
        SubscriptionManager.init();
        SubscriptionDetails.init();
        FormEnhancements.init();

        // Trigger initial updates
        if ($('.subs-enable-subscription').is(':checked')) {
            SubscriptionOptions.updatePricing();
        }
    }

    // Initialize when DOM is ready
    init();

    // Re-initialize on AJAX complete (for dynamic content)
    $(document).ajaxComplete(function() {
        // Small delay to ensure DOM updates are complete
        setTimeout(init, 100);
    });
});
