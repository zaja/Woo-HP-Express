/**
 * HP Express Paketomat Picker
 * Leaflet map integration for parcel locker selection
 */
(function($) {
    'use strict';

    var HPPaketomatPicker = {
        map: null,
        markers: [],
        markersLayer: null,
        paketomati: [],
        selectedPaketomat: null,
        searchTimeout: null,
        mapInitialized: false,
        
        init: function() {
            this.bindEvents();
            this.checkShippingMethod();
        },
        
        bindEvents: function() {
            var self = this;
            
            // Shipping method change - WooCommerce classic checkout
            $(document.body).on('updated_checkout', function() {
                self.checkShippingMethod();
            });
            
            // Direct shipping method change listener
            $(document).on('change', 'input[name^="shipping_method"]', function() {
                self.checkShippingMethod();
            });
            
            // Also check on click (for radio buttons)
            $(document).on('click', '.shipping_method', function() {
                setTimeout(function() {
                    self.checkShippingMethod();
                }, 50);
            });
            
            // WooCommerce Blocks checkout events
            $(document).on('change', '.wc-block-components-radio-control__input', function() {
                setTimeout(function() {
                    self.checkShippingMethod();
                }, 100);
            });
            
            $(document).on('click', '.wc-block-components-radio-control__option', function() {
                setTimeout(function() {
                    self.checkShippingMethod();
                }, 100);
            });
            
            // Generic click on shipping options area
            $(document).on('click', '[class*="shipping"]', function() {
                setTimeout(function() {
                    self.checkShippingMethod();
                }, 200);
            });
            
            // MutationObserver for dynamic content
            if (typeof MutationObserver !== 'undefined') {
                var observer = new MutationObserver(function(mutations) {
                    self.checkShippingMethod();
                });
                
                // Observe checkout form for changes
                setTimeout(function() {
                    var checkoutForm = document.querySelector('.woocommerce-checkout, .wp-block-woocommerce-checkout, #checkout');
                    if (checkoutForm) {
                        observer.observe(checkoutForm, { childList: true, subtree: true, attributes: true });
                    }
                }, 500);
            }
            
            // Search input
            $(document).on('input', '#hp-paketomat-search', function() {
                var query = $(this).val();
                clearTimeout(self.searchTimeout);
                self.searchTimeout = setTimeout(function() {
                    self.filterPaketomati(query);
                }, 300);
            });
            
            // Change button
            $(document).on('click', '#hp-paketomat-change', function(e) {
                e.preventDefault();
                self.showPicker();
            });
            
            // List item click
            $(document).on('click', '.hp-paketomat-item', function() {
                var code = $(this).data('code');
                self.selectPaketomat(code);
            });
            
            // Popup select button click (bind early for dynamically created content)
            $(document).on('click', '.hp-paketomat-select-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var code = $(this).data('code');
                self.selectPaketomat(code);
            });
        },
        
        checkShippingMethod: function() {
            var self = this;
            var $wrapper = $('#hp-paketomat-picker-wrapper');
            var isPaketomatSelected = false;
            
            // Method 1: Classic WooCommerce checkout inputs
            var $inputs = $('input[name^="shipping_method"]');
            
            $inputs.each(function() {
                var $input = $(this);
                var method = $input.val();
                var isChecked = $input.is(':checked') || $input.attr('type') === 'hidden';
                var $li = $input.closest('li');
                var $label = $('label[for="' + $input.attr('id') + '"]');
                var labelText = $label.length ? $label.text() : $li.text();
                
                if (isChecked && method && method.indexOf('hp_express') !== -1) {
                    if (labelText.toLowerCase().indexOf('paketomat') !== -1) {
                        isPaketomatSelected = true;
                    }
                    if ($li.find('.hp-paketomat-indicator').length > 0) {
                        isPaketomatSelected = true;
                    }
                    if ($li.hasClass('hp-paketomat-delivery') || $li.data('delivery-type') === '3') {
                        isPaketomatSelected = true;
                    }
                }
            });
            
            // Method 2: WooCommerce Blocks / Custom checkout - check by visible selected text
            if (!isPaketomatSelected) {
                // Look for selected shipping option containing "paketomat"
                var selectors = [
                    '.wc-block-components-shipping-rates-control__package input:checked + label',
                    '.wc-block-components-radio-control__option--checked',
                    '.shipping_method:checked + label',
                    '.woocommerce-shipping-methods .selected',
                    '[data-shipping-method] .active',
                    '.shipping-method.selected',
                    '.wc-block-components-shipping-rates-control input:checked ~ label'
                ];
                
                selectors.forEach(function(selector) {
                    var $el = $(selector);
                    if ($el.length) {
                        var text = $el.text().toLowerCase();
                        if (text.indexOf('paketomat') !== -1 && text.indexOf('hp') !== -1) {
                            isPaketomatSelected = true;
                        }
                    }
                });
                
                // Also check any element with paketomat in text that looks selected
                $('.wc-block-components-radio-control__input:checked').each(function() {
                    var $parent = $(this).closest('.wc-block-components-radio-control__option');
                    if ($parent.text().toLowerCase().indexOf('paketomat') !== -1) {
                        isPaketomatSelected = true;
                    }
                });
            }
            
            // Method 3: Check the order summary for shipping method display
            if (!isPaketomatSelected) {
                var summaryText = $('.woocommerce-shipping-totals, .wc-block-components-totals-shipping, .order-total').text().toLowerCase();
                if (summaryText.indexOf('paketomat') !== -1 && summaryText.indexOf('hp') !== -1) {
                    isPaketomatSelected = true;
                }
            }
            
            if (isPaketomatSelected) {
                // Create picker HTML if it doesn't exist (for Blocks checkout)
                if ($wrapper.length === 0) {
                    self.createPickerHTML();
                    $wrapper = $('#hp-paketomat-picker-wrapper');
                }
                
                $wrapper.show();
                
                if (!this.mapInitialized && $('#hp-paketomat-map').length > 0) {
                    this.mapInitialized = true; // Prevent multiple init attempts
                    setTimeout(function() {
                        if ($('#hp-paketomat-map').is(':visible')) {
                            self.initMap();
                            self.loadPaketomati();
                        }
                    }, 300);
                } else if (this.map) {
                    // Refresh map size
                    setTimeout(function() {
                        self.map.invalidateSize();
                    }, 100);
                }
            } else {
                $wrapper.hide();
            }
        },
        
        createPickerHTML: function() {
            var html = '<div id="hp-paketomat-picker-wrapper" style="margin: 20px 0; padding: 20px; background: #f8f8f8; border: 1px solid #ddd; border-radius: 5px;">' +
                '<h3 style="margin: 0 0 15px 0;">' + (hpPaketomatPicker.strings.select || 'Odaberi paketomat') + '</h3>' +
                '<div class="hp-paketomat-search" style="margin-bottom: 15px;">' +
                    '<input type="text" id="hp-paketomat-search" placeholder="' + (hpPaketomatPicker.strings.search_placeholder || 'Pretraži po gradu ili adresi...') + '" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">' +
                '</div>' +
                '<div class="hp-paketomat-container" style="display: flex; gap: 15px; min-height: 350px;">' +
                    '<div id="hp-paketomat-map" style="flex: 1; min-height: 350px; border-radius: 4px;"></div>' +
                    '<div id="hp-paketomat-list" style="width: 300px; max-height: 350px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px; background: #fff;"></div>' +
                '</div>' +
                '<div id="hp-paketomat-selected" style="display: none; margin-top: 15px; padding: 12px; background: #e8f5e9; border: 1px solid #c8e6c9; border-radius: 4px;">' +
                    '<strong>Odabrani paketomat:</strong> ' +
                    '<span id="hp-paketomat-selected-name"></span> ' +
                    '<button type="button" id="hp-paketomat-change" style="margin-left: 10px; color: #1976d2; text-decoration: underline; cursor: pointer; background: none; border: none;">Promijeni</button>' +
                '</div>' +
                '<input type="hidden" name="hp_paketomat_code" id="hp_paketomat_code" value="">' +
                '<input type="hidden" name="hp_paketomat_name" id="hp_paketomat_name" value="">' +
                '<input type="hidden" name="hp_paketomat_address" id="hp_paketomat_address" value="">' +
            '</div>';
            
            // Find best place to insert
            var $insertPoint = $('.wc-block-components-shipping-rates-control, .woocommerce-shipping-methods, .wc-block-checkout__shipping-option').first();
            if ($insertPoint.length) {
                $insertPoint.after(html);
            } else {
                // Fallback - insert before payment section
                var $payment = $('.wc-block-checkout__payment-method, #payment, .woocommerce-checkout-payment').first();
                if ($payment.length) {
                    $payment.before(html);
                } else {
                    // Last resort - append to checkout form
                    $('.wc-block-checkout, .woocommerce-checkout, form.checkout').first().append(html);
                }
            }
            
        },
        
        initMap: function() {
            var self = this;
            var center = hpPaketomatPicker.defaultCenter;
            var zoom = hpPaketomatPicker.defaultZoom;
            
            // Initialize map
            this.map = L.map('hp-paketomat-map').setView(center, zoom);
            
            // Add OpenStreetMap tiles
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors',
                maxZoom: 19
            }).addTo(this.map);
            
            // Create markers layer
            this.markersLayer = L.layerGroup().addTo(this.map);
            
            // Fix map display issues
            setTimeout(function() {
                self.map.invalidateSize();
            }, 100);
        },
        
        loadPaketomati: function() {
            var self = this;
            var $list = $('#hp-paketomat-list');
            
            $list.html('<div class="hp-paketomat-loading">' + hpPaketomatPicker.strings.loading + '</div>');
            
            $.ajax({
                url: hpPaketomatPicker.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_get_paketomati',
                    nonce: hpPaketomatPicker.nonce,
                    search: ''
                },
                success: function(response) {
                    if (response.success && response.data.paketomati) {
                        self.paketomati = response.data.paketomati;
                        self.renderPaketomati(self.paketomati);
                        self.addMarkers(self.paketomati);
                    } else {
                        $list.html('<div class="hp-paketomat-no-results">' + hpPaketomatPicker.strings.error + '</div>');
                    }
                },
                error: function() {
                    $list.html('<div class="hp-paketomat-no-results">' + hpPaketomatPicker.strings.error + '</div>');
                }
            });
        },
        
        renderPaketomati: function(paketomati) {
            var self = this;
            var $list = $('#hp-paketomat-list');
            
            if (paketomati.length === 0) {
                $list.html('<div class="hp-paketomat-no-results">' + hpPaketomatPicker.strings.no_results + '</div>');
                return;
            }
            
            var html = '';
            paketomati.forEach(function(p) {
                var isSelected = self.selectedPaketomat && self.selectedPaketomat.code === p.code;
                html += '<div class="hp-paketomat-item' + (isSelected ? ' selected' : '') + '" data-code="' + p.code + '">';
                html += '<div class="hp-paketomat-item-name">' + self.escapeHtml(p.name) + '</div>';
                html += '<div class="hp-paketomat-item-address">' + self.escapeHtml(p.address) + '</div>';
                html += '<div class="hp-paketomat-item-city">' + self.escapeHtml(p.zip + ' ' + p.city) + '</div>';
                html += '</div>';
            });
            
            $list.html(html);
        },
        
        addMarkers: function(paketomati) {
            var self = this;
            
            // Clear existing markers
            this.markersLayer.clearLayers();
            this.markers = [];
            
            var bounds = [];
            
            paketomati.forEach(function(p) {
                if (p.lat && p.lng) {
                    var marker = L.marker([p.lat, p.lng], {
                        icon: self.createMarkerIcon(p.code)
                    });
                    
                    // Popup content
                    var popupContent = '<div class="hp-paketomat-popup">';
                    popupContent += '<div class="hp-paketomat-popup-name">' + self.escapeHtml(p.name) + '</div>';
                    popupContent += '<div class="hp-paketomat-popup-address">' + self.escapeHtml(p.address) + '<br>' + self.escapeHtml(p.zip + ' ' + p.city) + '</div>';
                    popupContent += '<button type="button" class="button hp-paketomat-select-btn" data-code="' + p.code + '">' + hpPaketomatPicker.strings.select + '</button>';
                    popupContent += '</div>';
                    
                    marker.bindPopup(popupContent);
                    marker.pakCode = p.code;
                    
                    marker.on('click', function() {
                        self.highlightListItem(p.code);
                    });
                    
                    self.markersLayer.addLayer(marker);
                    self.markers.push(marker);
                    bounds.push([p.lat, p.lng]);
                }
            });
            
            // Fit bounds if we have markers
            if (bounds.length > 0) {
                self.map.fitBounds(bounds, { padding: [20, 20] });
            }
        },
        
        createMarkerIcon: function(code) {
            var isSelected = this.selectedPaketomat && this.selectedPaketomat.code === code;
            
            return L.divIcon({
                className: 'hp-marker-wrapper',
                html: '<div class="hp-marker-icon' + (isSelected ? ' selected' : '') + '">P</div>',
                iconSize: [28, 28],
                iconAnchor: [14, 14],
                popupAnchor: [0, -14]
            });
        },
        
        filterPaketomati: function(query) {
            var self = this;
            query = query.toLowerCase().trim();
            
            if (query === '') {
                this.renderPaketomati(this.paketomati);
                this.addMarkers(this.paketomati);
                return;
            }
            
            var filtered = this.paketomati.filter(function(p) {
                return p.name.toLowerCase().indexOf(query) !== -1 ||
                       p.address.toLowerCase().indexOf(query) !== -1 ||
                       p.city.toLowerCase().indexOf(query) !== -1 ||
                       p.zip.indexOf(query) !== -1;
            });
            
            this.renderPaketomati(filtered);
            this.addMarkers(filtered);
        },
        
        selectPaketomat: function(code) {
            var self = this;
            code = String(code); // Ensure string comparison
            
            
            // Find paketomat
            var paketomat = this.paketomati.find(function(p) {
                return String(p.code) === code;
            });
            
            if (!paketomat) {
                return;
            }
            
            this.selectedPaketomat = paketomat;
            
            // Update hidden fields
            $('#hp_paketomat_code').val(paketomat.code);
            $('#hp_paketomat_name').val(paketomat.name);
            $('#hp_paketomat_address').val(paketomat.address + ', ' + paketomat.zip + ' ' + paketomat.city);
            
            
            // Save to session via AJAX (for Blocks checkout support)
            $.ajax({
                url: hpPaketomatPicker.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hp_save_paketomat',
                    nonce: hpPaketomatPicker.nonce,
                    code: paketomat.code,
                    name: paketomat.name,
                    address: paketomat.address + ', ' + paketomat.zip + ' ' + paketomat.city
                },
                success: function(response) {},
                error: function() {}
            });
            
            // Update selected display
            var selectedText = paketomat.name + ' - ' + paketomat.address + ', ' + paketomat.city;
            $('#hp-paketomat-selected-name').text(selectedText);
            $('#hp-paketomat-selected').show().css('display', 'block');
            
            // Hide picker, show selected confirmation
            $('.hp-paketomat-container').hide();
            $('.hp-paketomat-search').hide();
            $('h3', '#hp-paketomat-picker-wrapper').text('✓ Paketomat odabran');
            
            // Update list styling
            $('.hp-paketomat-item').removeClass('selected');
            $('.hp-paketomat-item[data-code="' + code + '"]').addClass('selected');
            
            // Update marker icons
            this.markers.forEach(function(marker) {
                marker.setIcon(self.createMarkerIcon(marker.pakCode));
            });
            
            // Close popup
            this.map.closePopup();
            
            // Center on selected
            if (paketomat.lat && paketomat.lng) {
                this.map.setView([paketomat.lat, paketomat.lng], 15);
            }
        },
        
        showPicker: function() {
            $('.hp-paketomat-container').show();
            $('.hp-paketomat-search').show();
            $('#hp-paketomat-selected').hide();
            
            // Invalidate map size
            var self = this;
            setTimeout(function() {
                if (self.map) {
                    self.map.invalidateSize();
                }
            }, 100);
        },
        
        highlightListItem: function(code) {
            var $item = $('.hp-paketomat-item[data-code="' + code + '"]');
            if ($item.length) {
                // Scroll to item
                var $list = $('#hp-paketomat-list');
                $list.animate({
                    scrollTop: $item.offset().top - $list.offset().top + $list.scrollTop() - 50
                }, 300);
                
                // Highlight
                $item.addClass('highlighted');
                setTimeout(function() {
                    $item.removeClass('highlighted');
                }, 1000);
            }
        },
        
        escapeHtml: function(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };
    
    $(document).ready(function() {
        HPPaketomatPicker.init();
    });
    
})(jQuery);
