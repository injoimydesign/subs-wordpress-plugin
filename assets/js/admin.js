/**
 * Subs Admin JavaScript
 *
 * Handles admin interface interactions
 *
 * @package Subs
 * @version 1.0.0
 */

jQuery(document).ready(function($) {
    'use strict';

    /**
     * Settings Page Functionality
     */
    const SettingsManager = {
        init: function() {
            this.bindEvents();
            this.toggleConditionalFields();
        },

        bindEvents: function() {
            // Conditional field toggles
            $(document).on('change', 'input[name="subs_pass_stripe_fees"]', this.toggleConditionalFields);
            $(document).on('change', 'input[name="subs_enable_trials"]', this.toggleConditionalFields);

            // Test Stripe connection
            $(document).on('click', '.subs-test-stripe-connection', this.testStripeConnection);

            // Settings import/export
            $(document).on('click', '.subs-export-settings', this.exportSettings);
            $(document).on('click', '.subs-import-settings', this.importSettings);

            // Reset settings
            $(document).on('click', '.subs-reset-settings', this.resetSettings);
        },

        toggleConditionalFields: function() {
            // Stripe fee fields
            const passFeesEnabled = $('input[name="subs_pass_stripe_fees"]').is(':checked');
            $('.subs-stripe-fee-field').toggle(passFeesEnabled);

            // Trial fields
            const trialsEnabled = $('input[name="subs_enable_trials"]').is(':checked');
            $('.subs-trial-field').toggle(trialsEnabled);
        },

        testStripeConnection: function(e) {
            e.preventDefault();

            const $button = $(this);
            const originalText = $button.text();

            $button.prop('disabled', true).text(subs_admin.strings.test_connection || 'Testing...');

            const testMode = $('input[name="subs_stripe_test_mode"]').is(':checked');
            const publishableKey = testMode ?
                $('input[name="subs_stripe_test_publishable_key"]').val() :
                $('input[name="subs_stripe_live_publishable_key"]').val();
            const secretKey = testMode ?
                $('input[name="subs_stripe_test_secret_key"]').val() :
                $('input[name="subs_stripe_live_secret_key"]').val();

            $.post(subs_admin.ajax_url, {
                action: 'subs_test_stripe_connection',
                nonce: subs_admin.nonce,
                test_mode: testMode ? 'yes' : 'no',
                publishable_key: publishableKey,
                secret_key: secretKey
            })
            .done(function(response) {
                if (response.success) {
                    SettingsManager.showNotice(response.data.message || subs_admin.strings.connection_success, 'success');
                } else {
                    SettingsManager.showNotice(response.data.message || subs_admin.strings.connection_failed, 'error');
                }
            })
            .fail(function() {
                SettingsManager.showNotice(subs_admin.strings.connection_failed, 'error');
            })
            .always(function() {
                $button.prop('disabled', false).text(originalText);
            });
        },

        exportSettings: function(e) {
            e.preventDefault();

            const $button = $(this);
            $button.prop('disabled', true);

            $.post(subs_admin.ajax_url, {
                action: 'subs_export_settings',
                nonce: subs_admin.nonce
            })
            .done(function(response) {
                if (response.success) {
                    // Create download link
                    const blob = new Blob([JSON.stringify(response.data, null, 2)], {type: 'application/json'});
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'subs-settings-' + new Date().toISOString().split('T')[0] + '.json';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    window.URL.revokeObjectURL(url);

                    SettingsManager.showNotice('Settings exported successfully', 'success');
                } else {
                    SettingsManager.showNotice(response.data.message || 'Export failed', 'error');
                }
            })
            .always(function() {
                $button.prop('disabled', false);
            });
        },

        importSettings: function(e) {
            e.preventDefault();

            const input = document.createElement('input');
            input.type = 'file';
            input.accept = '.json';

            input.onchange = function(e) {
                const file = e.target.files[0];
                if (!file) return;

                const reader = new FileReader();
                reader.onload = function(e) {
                    try {
                        const settings = JSON.parse(e.target.result);
                        SettingsManager.performImport(settings);
                    } catch (error) {
                        SettingsManager.showNotice('Invalid settings file', 'error');
                    }
                };
                reader.readAsText(file);
            };

            input.click();
        },

        performImport: function(settings) {
            if (!confirm('This will overwrite your current settings. Continue?')) {
                return;
            }

            $.post(subs_admin.ajax_url, {
                action: 'subs_import_settings',
                nonce: subs_admin.nonce,
                settings: JSON.stringify(settings)
            })
            .done(function(response) {
                if (response.success) {
                    SettingsManager.showNotice('Settings imported successfully. Reloading page...', 'success');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    SettingsManager.showNotice(response.data.message || 'Import failed', 'error');
                }
            });
        },

        resetSettings: function(e) {
            e.preventDefault();

            if (!confirm('This will reset all settings to their defaults. This cannot be undone. Continue?')) {
                return;
            }

            const $button = $(this);
            $button.prop('disabled', true);

            $.post(subs_admin.ajax_url, {
                action: 'subs_reset_settings',
                nonce: subs_admin.nonce
            })
            .done(function(response) {
                if (response.success) {
                    SettingsManager.showNotice('Settings reset successfully. Reloading page...', 'success');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    SettingsManager.showNotice(response.data.message || 'Reset failed', 'error');
                }
            })
            .always(function() {
                $button.prop('disabled', false);
            });
        },

        showNotice: function(message, type) {
            const notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            $('.wrap h1').after(notice);

            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                notice.fadeOut();
            }, 5000);
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
            // Individual subscription actions
            $(document).on('click', '.subs-action-pause', this.pauseSubscription);
            $(document).on('click', '.subs-action-resume', this.resumeSubscription);
            $(document).on('click', '.subs-action-cancel', this.cancelSubscription);
            $(document).on('click', '.subs-action-delete', this.deleteSubscription);

            // Bulk actions
            $(document).on('click', '#doaction, #doaction2', this.handleBulkActions);

            // Quick actions in list table
            $(document).on('click', '.subs-quick-action', this.handleQuickAction);
        },

        pauseSubscription: function(e) {
            e.preventDefault();

            const subscriptionId = $(this).data('subscription-id');

            if (!confirm(subs_admin.strings.confirm_pause || 'Are you sure you want to pause this subscription?')) {
                return;
            }

            SubscriptionManager.performAction('pause_subscription', subscriptionId);
        },

        resumeSubscription: function(e) {
            e.preventDefault();

            const subscriptionId = $(this).data('subscription-id');
            SubscriptionManager.performAction('resume_subscription', subscriptionId);
        },

        cancelSubscription: function(e) {
            e.preventDefault();

            const subscriptionId = $(this).data('subscription-id');

            if (!confirm(subs_admin.strings.confirm_cancel || 'Are you sure you want to cancel this subscription?')) {
                return;
            }

            SubscriptionManager.performAction('cancel_subscription', subscriptionId);
        },

        deleteSubscription: function(e) {
            e.preventDefault();

            const subscriptionId = $(this).data('subscription-id');

            if (!confirm(subs_admin.strings.confirm_delete || 'Are you sure you want to delete this subscription? This cannot be undone.')) {
                return;
            }

            SubscriptionManager.performAction('delete_subscription', subscriptionId);
        },

        performAction: function(action, subscriptionId, extraData = {}) {
            const $row = $('[data-subscription-id="' + subscriptionId + '"]').closest('tr');
            $row.addClass('subs-loading');

            const data = {
                action: 'subs_admin_action',
                nonce: subs_admin.nonce,
                subs_action: action,
                subscription_id: subscriptionId,
                ...extraData
            };

            $.post(subs_admin.ajax_url, data)
                .done(function(response) {
                    if (response.success) {
                        SubscriptionManager.showNotice(response.data.message || 'Action completed successfully', 'success');

                        // Refresh the page or update the row
                        if (action === 'delete_subscription') {
                            $row.fadeOut();
                        } else {
                            // Reload the page to reflect changes
                            setTimeout(() => window.location.reload(), 1000);
                        }
                    } else {
                        SubscriptionManager.showNotice(response.data.message || 'Action failed', 'error');
                    }
                })
                .fail(function() {
                    SubscriptionManager.showNotice('Request failed', 'error');
                })
                .always(function() {
                    $row.removeClass('subs-loading');
                });
        },

        handleBulkActions: function(e) {
            const action = $(this).attr('id') === 'doaction' ?
                $('#bulk-action-selector-top').val() :
                $('#bulk-action-selector-bottom').val();

            if (action === '-1') {
                return;
            }

            const checkedBoxes = $('input[name="subscription[]"]:checked');

            if (checkedBoxes.length === 0) {
                e.preventDefault();
                alert('Please select at least one subscription.');
                return;
            }

            const actionLabels = {
                'pause': 'pause',
                'resume': 'resume',
                'cancel': 'cancel',
                'delete': 'delete'
            };

            if (actionLabels[action]) {
                const confirmMessage = `Are you sure you want to ${actionLabels[action]} ${checkedBoxes.length} subscription(s)?`;
                if (!confirm(confirmMessage)) {
                    e.preventDefault();
                    return;
                }
            }
        },

        handleQuickAction: function(e) {
            e.preventDefault();

            const $link = $(this);
            const action = $link.data('action');
            const subscriptionId = $link.data('subscription-id');

            SubscriptionManager.performAction(action, subscriptionId);
        },

        showNotice: function(message, type) {
            const notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            $('.wrap h1').after(notice);

            setTimeout(function() {
                notice.fadeOut();
            }, 5000);
        }
    };

    /**
     * Product Settings Integration
     */
    const ProductSettings = {
        init: function() {
            this.bindEvents();
            this.toggleSubscriptionFields();
        },

        bindEvents: function() {
            // Toggle subscription fields based on checkbox
            $(document).on('change', '#_subscription_enabled', this.toggleSubscriptionFields);

            // Update billing preview
            $(document).on('change', '#_subscription_period, #_subscription_period_interval', this.updateBillingPreview);
        },

        toggleSubscriptionFields: function() {
            const isEnabled = $('#_subscription_enabled').is(':checked');
            $('.subs-conditional-field').toggleClass('active', isEnabled);

            if (isEnabled) {
                ProductSettings.updateBillingPreview();
            }
        },

        updateBillingPreview: function() {
            const period = $('#_subscription_period').val();
            const interval = parseInt($('#_subscription_period_interval').val()) || 1;

            const periods = {
                'day': interval === 1 ? 'day' : 'days',
                'week': interval === 1 ? 'week' : 'weeks',
                'month': interval === 1 ? 'month' : 'months',
                'year': interval === 1 ? 'year' : 'years'
            };

            const periodText = periods[period] || period;
            const billingText = interval === 1 ?
                `Every ${periodText}` :
                `Every ${interval} ${periodText}`;

            $('.subs-billing-preview').text(billingText);
        }
    };

    /**
     * Reports and Analytics
     */
    const ReportsManager = {
        init: function() {
            this.bindEvents();
            this.loadCharts();
        },

        bindEvents: function() {
            // Date range picker
            $(document).on('change', '#subs-date-range', this.updateDateRange);

            // Refresh reports
            $(document).on('click', '.subs-refresh-reports', this.refreshReports);

            // Export reports
            $(document).on('click', '.subs-export-report', this.exportReport);
        },

        updateDateRange: function() {
            const range = $(this).val();
            const $customDates = $('.subs-custom-date-range');

            if (range === 'custom') {
                $customDates.show();
            } else {
                $customDates.hide();
                ReportsManager.loadReports(range);
            }
        },

        loadReports: function(dateRange = '30days') {
            $('.subs-reports-container').addClass('subs-loading');

            $.post(subs_admin.ajax_url, {
                action: 'subs_load_reports',
                nonce: subs_admin.nonce,
                date_range: dateRange
            })
            .done(function(response) {
                if (response.success) {
                    ReportsManager.updateReportsDisplay(response.data);
                }
            })
            .always(function() {
                $('.subs-reports-container').removeClass('subs-loading');
            });
        },

        updateReportsDisplay: function(data) {
            // Update statistics
            $('.subs-stat-total-subscriptions').text(data.stats.total_subscriptions || 0);
            $('.subs-stat-active-subscriptions').text(data.stats.active_subscriptions || 0);
            $('.subs-stat-mrr').text(data.stats.mrr_formatted || '$0');
            $('.subs-stat-churn-rate').text((data.stats.churn_rate || 0) + '%');

            // Update charts if available
            if (window.Chart && data.chart_data) {
                ReportsManager.updateCharts(data.chart_data);
            }
        },

        loadCharts: function() {
            // Load Chart.js charts if library is available
            if (!window.Chart) {
                return;
            }

            this.initSubscriptionGrowthChart();
            this.initRevenueChart();
            this.initStatusDistributionChart();
        },

        initSubscriptionGrowthChart: function() {
            const ctx = document.getElementById('subs-growth-chart');
            if (!ctx) return;

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [], // Will be populated via AJAX
                    datasets: [{
                        label: 'Subscriptions',
                        borderColor: '#0073aa',
                        backgroundColor: 'rgba(0, 115, 170, 0.1)',
                        data: []
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        },

        initRevenueChart: function() {
            const ctx = document.getElementById('subs-revenue-chart');
            if (!ctx) return;

            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Revenue',
                        backgroundColor: '#00a32a',
                        data: []
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return ' + value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        },

        initStatusDistributionChart: function() {
            const ctx = document.getElementById('subs-status-chart');
            if (!ctx) return;

            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Active', 'Paused', 'Cancelled', 'Past Due'],
                    datasets: [{
                        data: [],
                        backgroundColor: ['#00a32a', '#dba617', '#d63638', '#f56e28']
                    }]
                },
                options: {
                    responsive: true
                }
            });
        },

        refreshReports: function(e) {
            e.preventDefault();

            const dateRange = $('#subs-date-range').val();
            ReportsManager.loadReports(dateRange);
        },

        exportReport: function(e) {
            e.preventDefault();

            const format = $(this).data('format') || 'csv';
            const dateRange = $('#subs-date-range').val();

            // Create download URL
            const url = new URL(subs_admin.ajax_url);
            url.searchParams.set('action', 'subs_export_report');
            url.searchParams.set('nonce', subs_admin.nonce);
            url.searchParams.set('format', format);
            url.searchParams.set('date_range', dateRange);

            // Trigger download
            window.open(url.toString(), '_blank');
        }
    };

    /**
     * Modal Management
     */
    const ModalManager = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Open modals
            $(document).on('click', '[data-subs-modal]', this.openModal);

            // Close modals
            $(document).on('click', '.subs-modal-close, .subs-modal-overlay', this.closeModal);

            // Prevent modal content clicks from closing modal
            $(document).on('click', '.subs-modal-content', function(e) {
                e.stopPropagation();
            });

            // ESC key to close modal
            $(document).on('keyup', this.handleKeyUp);
        },

        openModal: function(e) {
            e.preventDefault();

            const modalId = $(this).data('subs-modal');
            const $modal = $('#' + modalId);

            if ($modal.length) {
                $modal.addClass('active');
                $('body').addClass('subs-modal-open');
            }
        },

        closeModal: function(e) {
            if (e) e.preventDefault();

            $('.subs-modal').removeClass('active');
            $('body').removeClass('subs-modal-open');
        },

        handleKeyUp: function(e) {
            if (e.keyCode === 27) { // ESC key
                ModalManager.closeModal();
            }
        }
    };

    /**
     * Form Validation
     */
    const FormValidator = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Validate forms before submission
            $(document).on('submit', '.subs-form', this.validateForm);

            // Real-time validation
            $(document).on('blur', '.subs-required', this.validateField);
        },

        validateForm: function(e) {
            let isValid = true;
            const $form = $(this);

            // Clear previous errors
            $form.find('.subs-field-error').remove();
            $form.find('.error').removeClass('error');

            // Validate required fields
            $form.find('.subs-required').each(function() {
                if (!FormValidator.validateField.call(this)) {
                    isValid = false;
                }
            });

            // Validate specific field types
            $form.find('input[type="email"]').each(function() {
                const email = $(this).val();
                if (email && !FormValidator.isValidEmail(email)) {
                    FormValidator.showFieldError($(this), 'Please enter a valid email address.');
                    isValid = false;
                }
            });

            if (!isValid) {
                e.preventDefault();
                FormValidator.scrollToFirstError($form);
            }
        },

        validateField: function() {
            const $field = $(this);
            const value = $field.val().trim();

            if ($field.hasClass('subs-required') && !value) {
                FormValidator.showFieldError($field, 'This field is required.');
                return false;
            }

            FormValidator.clearFieldError($field);
            return true;
        },

        showFieldError: function($field, message) {
            $field.addClass('error');
            $field.after('<span class="subs-field-error">' + message + '</span>');
        },

        clearFieldError: function($field) {
            $field.removeClass('error');
            $field.siblings('.subs-field-error').remove();
        },

        scrollToFirstError: function($form) {
            const $firstError = $form.find('.error').first();
            if ($firstError.length) {
                $('html, body').animate({
                    scrollTop: $firstError.offset().top - 100
                }, 500);
                $firstError.focus();
            }
        },

        isValidEmail: function(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        }
    };

    /**
     * Data Tables Enhancement
     */
    const DataTablesManager = {
        init: function() {
            this.enhanceListTables();
        },

        enhanceListTables: function() {
            // Add loading states to bulk actions
            $('.bulkactions select').on('change', function() {
                const action = $(this).val();
                if (action !== '-1') {
                    $('.bulk-action-description').text('Select items and click Apply to ' + action + ' them.');
                }
            });

            // Enhance search functionality
            $('.search-box input[type="search"]').on('keyup', this.debounce(function() {
                // Could add live search here
            }, 300));

            // Add row hover effects
            $('.wp-list-table tbody tr').hover(
                function() {
                    $(this).addClass('subs-row-hover');
                },
                function() {
                    $(this).removeClass('subs-row-hover');
                }
            );
        },

        debounce: function(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
    };

    /**
     * Utility Functions
     */
    const Utils = {
        showNotice: function(message, type = 'info', duration = 5000) {
            const notice = $(`
                <div class="notice notice-${type} is-dismissible">
                    <p>${message}</p>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text">Dismiss this notice.</span>
                    </button>
                </div>
            `);

            $('.wrap h1').after(notice);

            // Auto-dismiss
            if (duration > 0) {
                setTimeout(() => notice.fadeOut(), duration);
            }

            // Manual dismiss
            notice.find('.notice-dismiss').on('click', function() {
                notice.fadeOut();
            });
        },

        formatCurrency: function(amount, currency = 'USD') {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: currency
            }).format(amount);
        },

        formatDate: function(dateString) {
            return new Date(dateString).toLocaleDateString();
        },

        copyToClipboard: function(text) {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(() => {
                    Utils.showNotice('Copied to clipboard', 'success', 2000);
                });
            } else {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                Utils.showNotice('Copied to clipboard', 'success', 2000);
            }
        }
    };

    /**
     * Initialize all components
     */
    function init() {
        SettingsManager.init();
        SubscriptionManager.init();
        ProductSettings.init();
        ReportsManager.init();
        ModalManager.init();
        FormValidator.init();
        DataTablesManager.init();

        // Add global utility functions
        window.SubsAdmin = {
            Utils: Utils,
            showNotice: Utils.showNotice
        };
    }

    // Initialize when DOM is ready
    init();

    // Re-initialize components after AJAX requests
    $(document).ajaxComplete(function() {
        // Small delay to ensure DOM updates are complete
        setTimeout(() => {
            ProductSettings.toggleSubscriptionFields();
        }, 100);
    });
});
