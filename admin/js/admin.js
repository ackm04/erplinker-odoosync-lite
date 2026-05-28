/**
 * WooCommerce Odoo Connector - Admin JS
 */
(function ($) {
    'use strict';

    function showResult(el, message, isError) {
        el.removeClass('pending success error')
            .addClass(isError ? 'error' : 'success')
            .html(message)
            .show();
        setTimeout(function () {
            el.fadeOut();
        }, 5000);
    }

    function showPending(el, message) {
        el.removeClass('success error').addClass('pending').html(message).show();
    }

    $('#woo-odoo-test-connection').on('click', function () {
        var $btn = $(this);
        var $result = $('#woo-odoo-test-result');
        $btn.prop('disabled', true);
        showPending($result, 'Testing...');

        $.post(wooOdooAdmin.ajaxUrl, {
            action: 'woo_odoo_test_connection',
            nonce: wooOdooAdmin.nonce
        }).done(function (r) {
            if (r.success) {
                showResult($result, r.data.message, false);
            } else {
                showResult($result, r.data.message || 'Connection failed', true);
            }
        }).fail(function () {
            showResult($result, 'Request failed', true);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    function runSync(action, btnId, resultId) {
        var $btn = $('#' + btnId);
        var $result = $('#' + resultId);
        $btn.prop('disabled', true);
        showPending($result, 'Syncing...');

        $.post(wooOdooAdmin.ajaxUrl, {
            action: action,
            nonce: wooOdooAdmin.nonce
        }).done(function (r) {
            if (r.success) {
                var msg = '';
                if (r.data.synced !== undefined) {
                    msg = 'Synced: ' + r.data.synced + (r.data.failed ? ', Failed: ' + r.data.failed : '');
                } else if (r.data.updated !== undefined) {
                    msg = 'Updated: ' + r.data.updated + (r.data.failed ? ', Failed: ' + r.data.failed : '');
                } else {
                    msg = 'Done.';
                }
                showResult($result, msg, false);
            } else {
                showResult($result, r.data.message || 'Sync failed', true);
            }
        }).fail(function () {
            showResult($result, 'Request failed', true);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    }

    $('#woo-odoo-sync-products').on('click', function () {
        runSync('woo_odoo_sync_products', 'woo-odoo-sync-products', 'woo-odoo-sync-result');
    });
    $('#woo-odoo-sync-customers').on('click', function () {
        runSync('woo_odoo_sync_customers', 'woo-odoo-sync-customers', 'woo-odoo-sync-result');
    });
    $('#woo-odoo-sync-orders').on('click', function () {
        runSync('woo_odoo_sync_orders', 'woo-odoo-sync-orders', 'woo-odoo-sync-result');
    });
    $('#woo-odoo-sync-stock').on('click', function () {
        runSync('woo_odoo_sync_stock', 'woo-odoo-sync-stock', 'woo-odoo-sync-result');
    });

    function refreshQueueStats() {
        $.post(wooOdooAdmin.ajaxUrl, {
            action: 'woo_odoo_queue_stats',
            nonce: wooOdooAdmin.nonce
        }).done(function (r) {
            if (r.success && r.data) {
                $('#woo-odoo-queue-stats').text(
                    'Pending: ' + (r.data.pending || 0) + ' | Running: ' + (r.data.running || 0)
                );
            }
        });
    }

    var progressInterval = null;

    function runQueue(type, btnId) {
        var $btn = $('#' + btnId);
        var $result = $('#woo-odoo-queue-result');
        $btn.prop('disabled', true);
        showPending($result, 'Enqueuing...');
        $('#woo-odoo-queue-progress').removeClass('is-visible').hide();

        var dateFrom = $('#woo-odoo-date-from').val() || '';
        var dateTo = $('#woo-odoo-date-to').val() || '';
        $.post(wooOdooAdmin.ajaxUrl, {
            action: 'woo_odoo_queue_sync',
            nonce: wooOdooAdmin.nonce,
            type: type,
            date_from: dateFrom,
            date_to: dateTo
        }).done(function (r) {
            if (r.success) {
                showResult($result, r.data.message || 'Enqueued', false);
                refreshQueueStats();
                if (r.data.batches > 0) {
                    $('#woo-odoo-queue-progress').addClass('is-visible').show();
                    startProgressPolling(type);
                }
            } else {
                showResult($result, r.data.message || 'Failed', true);
            }
        }).fail(function () {
            showResult($result, 'Request failed', true);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    }

    function startProgressPolling(type) {
        if (progressInterval) clearInterval(progressInterval);
        progressInterval = setInterval(function () {
            $.post(wooOdooAdmin.ajaxUrl, {
                action: 'woo_odoo_queue_progress',
                nonce: wooOdooAdmin.nonce
            }).done(function (r) {
                if (r.success && r.data && r.data.type === type) {
                    var total = r.data.total || 1;
                    var processed = r.data.processed || 0;
                    var pct = Math.min(100, Math.round((processed / total) * 100));
                    $('#woo-odoo-progress-bar').css('width', pct + '%');
                    $('#woo-odoo-progress-text').text(processed + ' / ' + total + ' batches');
                    if (processed >= total) {
                        clearInterval(progressInterval);
                        progressInterval = null;
                        refreshQueueStats();
                    }
                }
            });
        }, 2000);
    }

    $('#woo-odoo-queue-products').on('click', function () {
        runQueue('products', 'woo-odoo-queue-products');
    });
    $('#woo-odoo-queue-customers').on('click', function () {
        runQueue('customers', 'woo-odoo-queue-customers');
    });
    $('#woo-odoo-queue-orders').on('click', function () {
        runQueue('orders', 'woo-odoo-queue-orders');
    });
    $('#woo-odoo-queue-stock').on('click', function () {
        runQueue('stock', 'woo-odoo-queue-stock');
    });

    $('#woo-odoo-queue-cancel').on('click', function () {
        var $btn = $(this);
        var $result = $('#woo-odoo-queue-result');
        $btn.prop('disabled', true);
        $.post(wooOdooAdmin.ajaxUrl, {
            action: 'woo_odoo_queue_cancel',
            nonce: wooOdooAdmin.nonce
        }).done(function (r) {
            if (r.success) {
                showResult($result, r.data.message || 'Cancelled', false);
                refreshQueueStats();
            }
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    refreshQueueStats();

    function refreshConnectionStatus() {
        $.post(wooOdooAdmin.ajaxUrl, {
            action: 'woo_odoo_connection_status',
            nonce: wooOdooAdmin.nonce
        }).done(function (r) {
            var $el = $('#woo-odoo-connection-status');
            $el.removeClass('woo-odoo-status-pending woo-odoo-status-success woo-odoo-status-error');
            if (r.success && r.data && r.data.connected) {
                $el.addClass('woo-odoo-status-success').text('Connected').attr('title', 'Odoo account is connected.');
            } else {
                $el.addClass('woo-odoo-status-error').text('Not connected').attr('title', 'Please provide valid Odoo credentials.');
            }
        }).fail(function () {
            $('#woo-odoo-connection-status').removeClass('woo-odoo-status-pending').addClass('woo-odoo-status-error').text('Not connected');
        });
    }
    refreshConnectionStatus();

    $('#woo-odoo-import-products').on('click', function () {
        var $btn = $(this);
        var $result = $('#woo-odoo-sync-result');
        $btn.prop('disabled', true);
        showPending($result, 'Importing from Odoo...');
        $.post(wooOdooAdmin.ajaxUrl, {
            action: 'woo_odoo_import_products',
            nonce: wooOdooAdmin.nonce
        }).done(function (r) {
            if (r.success && r.data) {
                var msg = 'Imported: ' + (r.data.imported || 0) + ', Updated: ' + (r.data.updated || 0) + (r.data.failed ? ', Failed: ' + r.data.failed : '');
                showResult($result, msg, false);
            } else {
                showResult($result, r.data && r.data.message ? r.data.message : 'Import failed', true);
            }
        }).fail(function () {
            showResult($result, 'Request failed', true);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    function refreshRetryList() {
        $.post(wooOdooAdmin.ajaxUrl, {
            action: 'woo_odoo_retry_list',
            nonce: wooOdooAdmin.nonce
        }).done(function (r) {
            var $list = $('#woo-odoo-retry-list');
            if (r.success && r.data.jobs && r.data.jobs.length > 0) {
                var html = '<table class="woo-odoo-retry-table"><thead><tr><th>Entity</th><th>ID</th><th>Retries</th><th>Next</th><th>Error</th></tr></thead><tbody>';
                r.data.jobs.forEach(function (j) {
                    html += '<tr><td>' + (j.entity || '') + '</td><td>' + (j.entity_id || '') + '</td><td>' + (j.retry_count || 0) + '</td><td>' + (j.next_retry ? new Date(j.next_retry * 1000).toLocaleString() : '') + '</td><td>' + (j.error || '').substring(0, 50) + '</td></tr>';
                });
                html += '</tbody></table>';
                $list.removeClass('woo-odoo-retry-empty').html(html);
            } else {
                $list.addClass('woo-odoo-retry-empty').html('<p class="woo-odoo-retry-empty">' + (r.data && r.data.jobs && r.data.jobs.length === 0 ? 'No failed jobs.' : '') + '</p>');
            }
        });
    }

    $('#woo-odoo-retry-process').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);
        $.post(wooOdooAdmin.ajaxUrl, {
            action: 'woo_odoo_retry_process',
            nonce: wooOdooAdmin.nonce
        }).done(function (r) {
            if (r.success) {
                refreshRetryList();
            }
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $('#woo-odoo-retry-clear').on('click', function () {
        $.post(wooOdooAdmin.ajaxUrl, {
            action: 'woo_odoo_retry_clear',
            nonce: wooOdooAdmin.nonce
        }).done(function (r) {
            if (r.success) {
                refreshRetryList();
            }
        });
    });

    refreshRetryList();

    $('#woo-odoo-inst-add-btn').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);
        $.post(wooOdooAdmin.ajaxUrl, {
            action: 'woo_odoo_instance_add',
            nonce: wooOdooAdmin.nonce,
            name: $('#woo-odoo-inst-name').val(),
            url: $('#woo-odoo-inst-url').val(),
            db: $('#woo-odoo-inst-db').val(),
            username: $('#woo-odoo-inst-user').val(),
            password: $('#woo-odoo-inst-pass').val()
        }).done(function (r) {
            if (r.success) {
                location.reload();
            } else {
                alert(r.data && r.data.message ? r.data.message : 'Failed');
            }
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    function refreshInstanceList() {
        $.post(wooOdooAdmin.ajaxUrl, {
            action: 'woo_odoo_instance_list',
            nonce: wooOdooAdmin.nonce
        }).done(function (r) {
            var $list = $('#woo-odoo-instances-list');
            if (r.success && r.data.instances && r.data.instances.length > 1) {
                var html = '';
                r.data.instances.forEach(function (i) {
                    if ((i.id || '') === 'default') return;
                    html += '<div class="woo-odoo-instance-item">' + (i.name || i.id) + ' <button type="button" class="woo-odoo-btn woo-odoo-btn-secondary" data-remove="' + (i.id || '') + '">Remove</button></div>';
                });
                $list.html(html);
                $list.find('[data-remove]').on('click', function () {
                    var id = $(this).data('remove');
                    $.post(wooOdooAdmin.ajaxUrl, { action: 'woo_odoo_instance_remove', nonce: wooOdooAdmin.nonce, id: id }).done(function () { location.reload(); });
                });
            } else {
                $list.empty();
            }
        });
    }
    refreshInstanceList();

    $('#woo-odoo-load-accounting').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Loading...');
        $.post(wooOdooAdmin.ajaxUrl, {
            action: 'woo_odoo_load_accounting',
            nonce: wooOdooAdmin.nonce
        }).done(function (r) {
            if (r.success) {
                location.reload();
            } else {
                $btn.prop('disabled', false).text('Load from Odoo');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Load from Odoo');
        });
    });
    // ── Stock Breakdown Metabox ──────────────────────────────────────────────
    if (typeof wooOdooStockBreakdown !== 'undefined') {
        var sbData = wooOdooStockBreakdown;
        function loadBreakdown(pid) {
            var $box = $('.woo-odoo-stock-breakdown-metabox[data-product-id="' + pid + '"]');
            $box.find('.woo-odoo-breakdown-loading').show()
                .siblings('.woo-odoo-stock-breakdown-table, .woo-odoo-breakdown-error').hide();
            $.post(sbData.ajaxUrl, {
                action: 'woo_odoo_get_stock_breakdown',
                nonce: sbData.nonce,
                product_id: pid
            }).done(function(r) {
                $box.find('.woo-odoo-breakdown-loading').hide();
                if (r.success && r.data.breakdown && r.data.breakdown.length) {
                    var $tbody = $box.find('.woo-odoo-stock-breakdown-table tbody').empty();
                    $.each(r.data.breakdown, function(_, row) {
                        $tbody.append(
                            '<tr><td>' + (row.warehouse || '') +
                            '</td><td>' + (row.available != null ? row.available : '') +
                            '</td><td>' + (row.reserved != null ? row.reserved : '') +
                            '</td><td>' + (row.quantity != null ? row.quantity : '') +
                            '</td><td>' + (row.last_updated || '\u2014') + '</td></tr>'
                        );
                    });
                    $box.find('.woo-odoo-stock-breakdown-table').show();
                } else {
                    var msg = (r.data && r.data.message) ? r.data.message : sbData.i18n.noData;
                    $box.find('.woo-odoo-breakdown-error').text(msg).show();
                }
            }).fail(function() {
                $box.find('.woo-odoo-breakdown-loading').hide();
                $box.find('.woo-odoo-breakdown-error').text(sbData.i18n.requestFailed).show();
            });
        }
        if (sbData.productId) {
            loadBreakdown(sbData.productId);
        }
        $(document).on('click', '.woo-odoo-breakdown-refresh', function() {
            loadBreakdown($(this).closest('.woo-odoo-stock-breakdown-metabox').data('product-id'));
        });
    }

})(jQuery);
