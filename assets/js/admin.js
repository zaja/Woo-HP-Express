/**
 * HP Express Admin JavaScript
 */
(function($) {
    'use strict';

    var HPExpress = {
        
        init: function() {
            this.bindEvents();
        },
        
        bindEvents: function() {
            // Create shipment
            $(document).on('click', '.hp-create-shipment-btn', this.createShipment);
            
            // Cancel shipment
            $(document).on('click', '.hp-cancel-shipment', this.cancelShipment);
            
            // Get label
            $(document).on('click', '.hp-get-label', this.getLabel);
            
            // Refresh status
            $(document).on('click', '.hp-refresh-status', this.refreshStatus);
            
            // Toggle parcel size field
            $(document).on('change', '#hp_delivery_type', this.toggleParcelSize);
        },
        
        showMessage: function($container, message, type) {
            var $msg = $container.find('#hp-message');
            $msg.removeClass('success error').addClass(type).html(message).show();
            
            if (type === 'success') {
                setTimeout(function() {
                    $msg.fadeOut();
                }, 5000);
            }
        },
        
        setLoading: function($container, loading) {
            if (loading) {
                $container.addClass('loading');
            } else {
                $container.removeClass('loading');
            }
        },
        
        createShipment: function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var $container = $btn.closest('.hp-express-metabox');
            var orderId = $container.data('order-id');
            
            if (!orderId) {
                HPExpress.showMessage($container, hpExpressAdmin.strings.error + ': Invalid order ID', 'error');
                return;
            }
            
            $btn.prop('disabled', true).text(hpExpressAdmin.strings.creating);
            HPExpress.setLoading($container, true);
            
            $.ajax({
                url: hpExpressAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_express_create_shipment',
                    nonce: hpExpressAdmin.nonce,
                    order_id: orderId,
                    service: $('#hp_service').val(),
                    delivery_type: $('#hp_delivery_type').val(),
                    parcel_size: $('#hp_parcel_size').val(),
                    weight: $('#hp_weight').val(),
                    cod_enabled: $('#hp_cod_enabled').is(':checked') ? '1' : '0'
                },
                success: function(response) {
                    if (response.success) {
                        HPExpress.showMessage($container, hpExpressAdmin.strings.success + ': ' + response.data.barcode, 'success');
                        // Reload page to show updated metabox
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        HPExpress.showMessage($container, hpExpressAdmin.strings.error + ': ' + response.data.message, 'error');
                        $btn.prop('disabled', false).text('Kreiraj pošiljku');
                        HPExpress.setLoading($container, false);
                    }
                },
                error: function() {
                    HPExpress.showMessage($container, hpExpressAdmin.strings.error + ': Ajax error', 'error');
                    $btn.prop('disabled', false).text('Kreiraj pošiljku');
                    HPExpress.setLoading($container, false);
                }
            });
        },
        
        cancelShipment: function(e) {
            e.preventDefault();
            
            if (!confirm(hpExpressAdmin.strings.confirm_cancel)) {
                return;
            }
            
            var $btn = $(this);
            var $container = $btn.closest('.hp-express-metabox');
            var orderId = $container.data('order-id');
            
            $btn.prop('disabled', true).text(hpExpressAdmin.strings.canceling);
            HPExpress.setLoading($container, true);
            
            $.ajax({
                url: hpExpressAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_express_cancel_shipment',
                    nonce: hpExpressAdmin.nonce,
                    order_id: orderId
                },
                success: function(response) {
                    if (response.success) {
                        HPExpress.showMessage($container, hpExpressAdmin.strings.success, 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        HPExpress.showMessage($container, hpExpressAdmin.strings.error + ': ' + response.data.message, 'error');
                        $btn.prop('disabled', false).text('Otkaži');
                        HPExpress.setLoading($container, false);
                    }
                },
                error: function() {
                    HPExpress.showMessage($container, hpExpressAdmin.strings.error + ': Ajax error', 'error');
                    $btn.prop('disabled', false).text('Otkaži');
                    HPExpress.setLoading($container, false);
                }
            });
        },
        
        getLabel: function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var $container = $btn.closest('.hp-express-metabox');
            var orderId = $container.data('order-id');
            var format = $btn.data('format') || 1;
            
            $btn.prop('disabled', true);
            var originalText = $btn.text();
            $btn.text(hpExpressAdmin.strings.loading);
            
            $.ajax({
                url: hpExpressAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_express_get_label',
                    nonce: hpExpressAdmin.nonce,
                    order_id: orderId,
                    format: format
                },
                success: function(response) {
                    if (response.success && response.data.label) {
                        // Open PDF in new tab
                        var pdfData = response.data.label;
                        var blob = HPExpress.base64ToBlob(pdfData, 'application/pdf');
                        var url = URL.createObjectURL(blob);
                        window.open(url, '_blank');
                    } else {
                        HPExpress.showMessage($container, hpExpressAdmin.strings.error + ': ' + (response.data.message || 'No label'), 'error');
                    }
                },
                error: function() {
                    HPExpress.showMessage($container, hpExpressAdmin.strings.error + ': Ajax error', 'error');
                },
                complete: function() {
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        },
        
        refreshStatus: function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var $container = $btn.closest('.hp-express-metabox');
            var orderId = $container.data('order-id');
            var $statusDisplay = $container.find('#hp-status-display');
            var $trackingInfo = $container.find('#hp-tracking-info');
            
            $btn.prop('disabled', true);
            $statusDisplay.text(hpExpressAdmin.strings.loading);
            
            $.ajax({
                url: hpExpressAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_express_get_status',
                    nonce: hpExpressAdmin.nonce,
                    order_id: orderId
                },
                success: function(response) {
                    if (response.success) {
                        $statusDisplay.text(response.data.status + ' - ' + response.data.status_description);
                        
                        // Show tracking history
                        if (response.data.scans && response.data.scans.length > 0) {
                            var html = '';
                            response.data.scans.reverse().forEach(function(scan) {
                                html += '<div class="scan-item">';
                                html += '<span class="scan-status">' + scan.Scan + '</span> - ';
                                html += scan.ScanDescription;
                                html += '<br><span class="scan-time">' + scan.ScanTime + ' @ ' + scan.Center + '</span>';
                                html += '</div>';
                            });
                            $trackingInfo.html(html).show();
                        }
                    } else {
                        HPExpress.showMessage($container, hpExpressAdmin.strings.error + ': ' + response.data.message, 'error');
                    }
                },
                error: function() {
                    HPExpress.showMessage($container, hpExpressAdmin.strings.error + ': Ajax error', 'error');
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        },
        
        toggleParcelSize: function() {
            var val = $(this).val();
            if (val === '3') {
                $('#hp_parcel_size_row').show();
            } else {
                $('#hp_parcel_size_row').hide();
            }
        },
        
        base64ToBlob: function(base64, mimeType) {
            var byteCharacters = atob(base64);
            var byteNumbers = new Array(byteCharacters.length);
            for (var i = 0; i < byteCharacters.length; i++) {
                byteNumbers[i] = byteCharacters.charCodeAt(i);
            }
            var byteArray = new Uint8Array(byteNumbers);
            return new Blob([byteArray], { type: mimeType });
        }
    };
    
    $(document).ready(function() {
        HPExpress.init();
    });
    
})(jQuery);
