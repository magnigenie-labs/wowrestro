jQuery(function ($) {

    //complete ajax call while getting date from session in food page before changes
    $(document).ajaxComplete(function (ev, xhr, settings) {
        if (settings.url != undefined && settings.url.indexOf('update_order_review') != -1) {
            if (xhr.responseJSON) {
                if (xhr.responseJSON.result == 'success') {
                    var wowrestro_hidden_field = $('#wowrestro_hidden_field').val();
                    if (wowrestro_hidden_field == 'yes') {
                        var serviceHours = xhr.responseJSON.fragments.store_hours;

                        if (serviceHours == '' || serviceHours == undefined) {
                            $('.wowrestro_co_service_time').hide();
                            $('.wowrestro_co_service_time').addClass('d-none');
                        } else {
                            var serviceHtml = $('<select>');
                            var time_output = '';

                            for (i = 0; i < serviceHours.length; i++) {

                                time_output = serviceHours[i];

                                serviceHtml.append($('<option></option>').val(serviceHours[i]).html(time_output));
                            }
                            $('#wowrestro_service_time').find('option').remove().end().append(serviceHtml.html());
                            $('#wowrestro_service_time').val($("#wowrestro_service_time option:first").val());
                            $('.wowrestro_co_service_time').show();
                            $('.wowrestro_co_service_time').removeClass('d-none');
                        }
                    }
                }
            }
        }
    });

    // Checkout time and time option change save session
    $(document).on('change', '.wowrestro-service-time-option', function (e) {
        e.preventDefault();
        var service_type = $('.wowrestro_co_service_type .input-radio:checked').val();
        $.ajax({
            url: wowrestro_script.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: { action: 'update_service_time_option_checkout', service_type: service_type, service_time_option: $(this).val() },
        }).done(function (response) {
            return true;
        });
    });

    $(document).on('change', '#wowrestro_service_time', function (e) {
        e.preventDefault();
        var service_type = $('.wowrestro_co_service_type .input-radio:checked').val();
        $.ajax({
            url: wowrestro_script.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: { action: 'update_service_time_option_checkout', service_type: service_type, service_time: $(this).val() },
        }).done(function (response) {
            return true;
        });
    });

    // loader html
    var wwro_loader = '<div class="wwr-loader"><div class="wwr-loader-inner ball-scale-multiple"><div></div><div></div><div></div></div></div>';

    // Hide Mneu on Click Items
    $(document).on('click', '.wowrestro-loop-category__title', function () {
        $('.wwr-mobile-category-wrap').click();
    });
    // Hide overlay
    $(document).on('click', '.wwr-cat-overlay', function () {
        $('.wwr-mobile-category-wrap').click();
    });

    //Toggle mobile category menu
    $('.wwr-mobile-category-wrap').on('click', function () {
        $('#wowrestro-sticky-sidebar').toggleClass('d-none');
        $('body').toggleClass('wwr-cat-no-scroll');
        if ($('#wowrestro-sticky-sidebar').hasClass('d-none'))
            $('.wwr-cat-overlay').remove();
        else
            $('body').append('<div class="wwr-cat-overlay"></div>');
    });

    // Reset servicce time option on tab switch
    $('#wowrestroServiceModal-content .nav-item').on('click', function () {
        var service_type = $(this).find('a').attr('aria-controls');
        var service_option = $('.service-option:checked').val();
        $('.' + service_option + '-option-' + service_type).click();
    });

    // Hide time slot if asap option selected
    $(document).on('click', '.wwr-service-time-wrap', function (event) {
        if ($(this).find('.service-option').val() == 'asap') {
            $(this).parents('.tab-pane').find('.wwr-store-timing').addClass('d-none');
        } else {
            $(this).parents('.tab-pane').find('.wwr-store-timing').removeClass('d-none');
        }
    });

    // Make the Category Sidebar stick to left while scrolling
    if (wowrestro_script.sticky_category_list == 'yes' && $(window).width() > 768) {
        jQuery('#wowrestro-sticky-sidebar').theiaStickySidebar({
            additionalMarginTop: 40
        });
    }

    // Enable showing popup from Image and Title based on settings
    if (wowrestro_script.item_title_popup == 'yes') {
        $('a.wowrestro-food-item-title, .wowrestro-food-item-image-container img').on('click', function (event) {
            $(this).parents('.wowrestro-food-item-container').find('.wowrestro-product-modal').trigger('click');
        });
    }

    // Make the menu active when clicked
    $('a.wowrestro-loop-category__title').on('click', function (event) {
        event.preventDefault();
        /* Act on the event */
        $(this).addClass('active');
        $(this).find('span.wowrestro-items-count').addClass('active');

        /* Remove active Class from siblings */
        $(this).parents('.wowrestro-category-menu-li').siblings().each(function () {
            var other_menu = $(this).find('.wowrestro-loop-category__title');
            other_menu.removeClass('active');
            other_menu.find('span.wowrestro-items-count').removeClass('active');
        });
    });

    // Scroll to specfic section when category menu is clicked
    $('.wowrestro-loop-category__title').on('click', function (event) {
        event.preventDefault();
        /* Act on the event */
        var category = $(this).data('category-title');
        $('html, body').animate({
            scrollTop: $("#" + category + "_start").offset().top
        }, 350);
    });

    // Food Store Live Search
    $('#wowrestro-food-items').find('a.wowrestro-food-item-title').each(function () {
        $(this).attr('data-search-term', $(this).text().toLowerCase());
    });

    // Search items on Keyup
    $('.wowrestro-food-search').on('keyup', function () {

        var search_term = $(this).val().toLowerCase();
        var term_id;

        $('#wowrestro-food-items').find('.wowrestro-category-title-container').each(function (index, elem) {
            $(this).removeClass('not-in-search');
            $(this).removeClass('in-search');
        });

        $('#wowrestro-food-items').find('.wowrestro-food-item-summery a').each(function () {

            term_id = $(this).parents('.wowrestro-food-item-container').attr('data-term-id');

            if (search_term != '' && $(this).filter('[data-search-term *= ' + search_term + ']').length > 0 || search_term.length < 1) {

                $(this).parents('.wowrestro-food-item-container').parent().show();
                $('#wowrestro-food-items').find('.wowrestro-category-title-container').each(function (index, elem) {

                    if ($(this).attr('data-term-id') == term_id) {
                        $(this).addClass('in-search');
                    } else {
                        $(this).addClass('not-in-search');
                    }

                });

            } else {

                $(this).parents('.wowrestro-food-item-container').parent().hide();
                $('#wowrestro-food-items').find('.wowrestro-category-title-container').each(function (index, elem) {
                    $(this).addClass('not-in-search');
                });

            }
        });
    });

    function wowrestro_cart_fragments() {
        // update product attr for service
        $('.wowrestro-update-service').attr('data-add-item', '');

        $(document).on('click', '.wowrestro-cart-toggle', function (event) {
            event.preventDefault();

            /* Enable Clear Cart Button */
            $('.wowrestro-cart-purchase-actions .wowrestro-clear-cart').removeClass('wwr-hidden')

            /* Act on Cart Overview Area */
            $('.wowrestro-cart-overview').css('background-color', '#eae7e7');

            /* Act on Cart Expanded Area */
            $('.wowrestro-cart-expanded').addClass('active');
            $('.wowrestro-cart-expanded').css('bottom', ($('.wowrestro-cart-overview').outerHeight() + 40) + 'px');

            /* Switch the Toggle Buttons */
            $('.wowrestro-cart-toggle').addClass('active');
            // $('.wowrestro-compress-cart').removeClass('wwr-hidden');
            // $('.wowrestro-expand-cart').addClass('wwr-hidden');

            /* Enable Fade Effect */
            $('.wowrestro-body-fade').addClass('active');
        });

        $(document).on('click', '.wowrestro-cart-toggle.active, .wowrestro-close-cart-icon', function (event) {
            event.preventDefault();

            /* Disable Clear Cart Button */
            $('.wowrestro-cart-purchase-actions .wowrestro-clear-cart').addClass('wwr-hidden')

            /* Act on Cart Overview Area */
            $('.wowrestro-cart-overview').css('background-color', '#fff');

            /* Act on Cart Expanded Area */
            $('.wowrestro-cart-expanded').removeClass('active');

            /* Switch the Toggle Buttons */
            $('.wowrestro-cart-toggle').removeClass('active');
            // $('.wowrestro-compress-cart').addClass('wwr-hidden');
            // $('.wowrestro-expand-cart').removeClass('wwr-hidden');

            /* Disable Fade Area */
            $('.wowrestro-body-fade').removeClass('active');
        });

        // Updating mini cart content 
        $(document.body).trigger('wc_fragment_refresh');
    }

    /* Cart Visibility Actions */
    wowrestro_cart_fragments();

    // Add To Cart Modal
    $('body').on('click', '.wowrestro-product-modal', function (e) {

        e.preventDefault();


        var button = $(this);
        button.find('.wowrestro-food-item-summery .item-loader').show();
        button.find('.wowrestro-food-item-summery .price').hide();
        var product_id = button.data('product-id');

        /* Open service modal based on settings */
        if ((wowrestro_script.service_modal_option == 'manual_modal' || wowrestro_script.service_modal_option == 'auto_modal') && (wowrestro_script.service_type == null || wowrestro_script.service_time == null)) {
            $('.wowrestro-update-service').attr('data-add-item', product_id);
            MicroModal.show('wowrestroServiceModal');
            $('#wowrestroServiceModal li.nav-item').eq(0).find('a').trigger('click');
            return;
        }

        var product_id = button.data('product-id');

        if (typeof product_id !== 'undefined') {

            var data = {
                action: 'show_product_modal',
                product_id: product_id,
                security: wowrestro_script.product_modal_nonce,
            };

            $.ajax({
                type: "POST",
                data: data,
                url: wowrestro_script.ajaxurl,
                success: function (response) {
                    $('.item-loader').hide();
                    button.find('.wowrestro-food-item-summery .price').show();
                    if (response) {

                        $('#wowrestroModal .wowrestromodal-title').html(response.title);
                        $('#wowrestroModal .wowrestromodal-body').html(response.content);
                        $('#wowrestroModal .wowrestromodal-footer').find('.wowrestro-product-add-to-cart').attr('data-product-id', response.product_id);
                        $('#wowrestroModal .wowrestromodal-footer').find('.wowrestro-product-add-to-cart').attr('data-product-type', response.product_type);
                        $('#wowrestroModal .wowrestromodal-footer').find('.wowrestro-modal-quantity input').val(response.product_qty);
                        $('#wowrestroModal .wowrestromodal-footer').find('.wowrestro-product-add-to-cart').attr('data-item-qty', response.product_qty);
                        $('#wowrestroModal .wowrestromodal-footer').find('.wowrestro-product-add-to-cart').attr('data-cart-action', response.action);
                        $('#wowrestroModal .wowrestromodal-footer').find('.wowrestro-modal-add-to-cart .wowrestro-cart-action-text').html(response.action_text);

                        if (typeof response.is_essential !== 'undefined') {
                            $('#wowrestroModal .wowrestromodal-footer').find('.wowrestro-modal-add-to-cart .wowrestro-live-item-price').html('(' + response.price + ')');
                            $('#wowrestroModal .wowrestromodal-footer').find('.wowrestro-modal-add-to-cart .wowrestro-live-item-price').attr('data-price', response.raw_price);
                        }

                        if (response.product_type == 'variable') {
                            // $('#wowrestroModal .wowrestromodal-footer').find('.wowrestro-product-add-to-cart').addClass('disabled').addClass('variation-selection-needed');
                        }

                        if (response.product_type == 'simple') {
                            $('#wowrestroModal .wowrestromodal-footer').find('.wowrestro-product-add-to-cart').removeClass('disabled').removeClass('variation-selection-needed');
                        }

                        if ($('.variations_form').length > 0) {
                            $('.variations_form').each(function () {
                                $(this).wc_variation_form();
                            });
                        }
                        /* Open Modal */
                        MicroModal.show('wowrestroModal');

                        /* Trigger Modal Window Opened */
                        $(document.body).trigger('wowrestro_modal_opened');

                    }
                }
            });
        }
    });

    // Variations on change
    $('body').on('change', '#wowrestroModal .variations_form select', function () {
        var _self = $(this);
        variation_id = _self.parents('form').find('.variation_id').val();

        if (variation_id !== '') {
            _self.parents('#wowrestroModal').find('.wowrestro-product-add-to-cart').removeClass('disabled').removeClass('variation-selection-needed');
            _self.parents('#wowrestroModal').find('.wowrestro-product-add-to-cart').attr('data-variation-id', variation_id);
        } else {
            _self.parents('#wowrestroModal').find('.wowrestro-product-add-to-cart').addClass('disabled').addClass('variation-selection-needed');
            _self.parents('#wowrestroModal').find('.wowrestro-product-add-to-cart').attr('data-variation-id', '');
        }
    });

    // Add to cart through ajax from modal
    $('body').on('click', '.wowrestro-product-add-to-cart', function (e) {

        e.preventDefault();

        if ($(this).hasClass('variation-selection-needed')) {
            return false;
        }

        var _self = $(this);
        var action = _self.attr('data-cart-action');
        var item_key = _self.attr('data-item-key');
        var product_id = _self.attr('data-product-id');
        var quantity = _self.attr('data-item-qty');
        var variation_id = _self.attr('data-variation-id');

        var postdata = '';
        var security = '';
        var special_note = $('textarea#special_note').val();

        var modifierData = $('.wowrestro-item-modifiers-container :input').serializeArray();

        var prv_html = _self.html();

        _self.html(wwro_loader);


        if ($('.variations_form').length > 0 && variation_id == '') {
            $.toast({
                text: wowrestro_script.variation_error,
                position: 'top-right',
            });
            _self.html(prv_html);
            return false;
        }

        if ('add_to_cart' === action) {
            security = wowrestro_script.add_to_cart_nonce
        } else {
            security = wowrestro_script.update_cart_nonce
        }

        if (_self.parents('#wowrestroModal').find('.variations_form').length > 0) {
            postdata = _self.parents('#wowrestroModal').find('.variations_form').serializeArray();
        }

        if (typeof product_id !== 'undefined') {

            _self.find('span.wowrestro-cart-action-text').text(wowrestro_script.cart_process_message);

            var data = {
                action: action,
                item_key: item_key,
                product_id: product_id,
                quantity: quantity,
                variation_id: variation_id,
                postdata: postdata,
                security: security,
                modifier_data: modifierData,
                special_note: special_note,
            };

            $.ajax({
                type: "POST",
                data: data,
                url: wowrestro_script.ajaxurl,
                beforeSend: function () {
                    _self.addClass('disabled');
                },
                complete: function () {
                    _self.removeClass('disabled');
                },
                success: function (response) {

                    _self.html(prv_html);

                    $.toast({
                        text: response.success_message,
                        position: 'top-right',
                    });
                    _self.find('span.wowrestro-cart-action-text').text(wowrestro_script.add_to_cart_text);

                    if (response.status == 'error') {
                        return false;
                    }

                    $('.wowrestro-cart-wrapper').html(response.cart_content);
                    MicroModal.close('wowrestroModal');
                    wowrestro_cart_fragments();
                }
            });
        }
    });

    // Cart edit button
    $('body').on('click', '.wowrestro-cart-item-edit', function (e) {

        e.preventDefault();

        /* Close the Cart */
        $('.wowrestro-close-cart-icon').trigger('click');

        var product_id = $(this).attr('data-product-id');
        var cart_key = $(this).attr('data-cart-key');

        if (cart_key !== ''
            && product_id !== ''
            && typeof product_id !== 'undefined') {

            var data = {
                action: 'show_product_modal',
                product_id: product_id,
                cart_key: cart_key,
                security: wowrestro_script.product_modal_nonce,
            };

            $.ajax({
                type: "POST",
                data: data,
                url: wowrestro_script.ajaxurl,
                success: function (response) {
                    if (response) {

                        if (typeof response.variation_id !== 'undefined') {
                            $('#wowrestroModal .wowrestromodal-footer').find('.wowrestro-product-add-to-cart').attr('data-variation-id', response.variation_id);
                        }

                        $('#wowrestroModal .wowrestromodal-title').html(response.title);
                        $('#wowrestroModal .wowrestromodal-body').html(response.content);
                        $('#wowrestroModal .wowrestromodal-footer').find('.wowrestro-product-add-to-cart').attr('data-product-id', response.product_id);
                        $('#wowrestroModal .wowrestromodal-footer').find('.wowrestro-product-add-to-cart').attr('data-product-type', response.product_type);
                        $('#wowrestroModal .wowrestromodal-footer').find('.wowrestro-modal-quantity input').val(response.product_qty);
                        $('#wowrestroModal .wowrestromodal-footer').find('.wowrestro-product-add-to-cart').attr('data-item-qty', response.product_qty);
                        $('#wowrestroModal .wowrestromodal-footer').find('.wowrestro-product-add-to-cart').attr('data-cart-action', response.action);
                        $('#wowrestroModal .wowrestromodal-footer').find('.wowrestro-product-add-to-cart').attr('data-item-key', response.item_key);
                        $('#wowrestroModal .wowrestromodal-footer').find('.wowrestro-modal-add-to-cart .wowrestro-cart-action-text').html(response.action_text);
                        $('#wowrestroModal .wowrestromodal-body').find('#special_note').html(response.special_note);

                        if (typeof response.is_essential !== 'undefined') {
                            $('#wowrestroModal .wowrestromodal-footer').find('.wowrestro-modal-add-to-cart .wowrestro-live-item-price').html('(' + response.price + ')');
                            $('#wowrestroModal .wowrestromodal-footer').find('.wowrestro-modal-add-to-cart .wowrestro-live-item-price').attr('data-price', response.raw_price);
                        }

                        $('.variations_form').each(function () {
                            $(this).wc_variation_form();
                        });

                        /* Open Modal */
                        MicroModal.show('wowrestroModal');

                        /* Trigger Modal Window Opened */
                        $(document).trigger('wowrestro_modal_opened');
                    }
                }
            });
        }
    });

    // Empty Cart
    $('body').on('click', '.wowrestro-clear-cart', function (e) {

        e.preventDefault();

        var data = {
            action: 'empty_cart',
            security: wowrestro_script.empty_cart_nonce,
        };

        $.ajax({
            type: "POST",
            data: data,
            url: wowrestro_script.ajaxurl,
            success: function (response) {

                if (response.status == 'success') {

                    // Manually Clear the Service Values
                    wowrestro_script.service_type = '';
                    wowrestro_script.service_time = '';

                    $('.wowrestro-cart-service-settings').addClass('wwr-hidden');

                    $('.wowrestro-cart-wrapper').html(response.cart_content);

                    wowrestro_cart_fragments();

                    $.toast({
                        text: wowrestro_script.cart_empty_message,
                        position: 'top-right',
                    });
                }
            }
        });
    });

    // Remove Item From Cart
    $('body').on('click', '.wowrestro-cart-item-delete', function (e) {
        e.preventDefault();

        var product_id = $(this).attr('data-product-id');
        var cart_key = $(this).attr('data-cart-key');

        if (product_id !== '') {
            var data = {
                product_id: product_id,
                cart_key: cart_key,
                action: 'product_remove_cart',
                security: wowrestro_script.remove_item_nonce,
            };

            $.ajax({
                type: "POST",
                data: data,
                url: wowrestro_script.ajaxurl,
                success: function (response) {

                    if (response.status == 'success') {
                        $('.wowrestro-cart-wrapper').html(response.cart_content);
                        wowrestro_cart_fragments();

                        $.toast({
                            text: response.message,
                            position: 'top-right',
                        });
                    }
                }
            });
        }
    });

    // Show store close message when store is closed
    $('body').on('click', '.wowrestro-store-closed', function () {
        $.toast({
            icon: 'warning',
            text: wowrestro_script.store_closed_message,
            position: 'top-right',
        });
    });

    // Proceed to Checkout
    $('body').on('click', '.wowrestro-proceed-to-checkout', function (event) {

        event.preventDefault();

        var _this = $(this);
        var prv_html = _this.html();
        _this.html(wwro_loader);

        var data = {
            action: 'validate_proceed_checkout',
        };

        $.ajax({
            type: "POST",
            data: data,
            url: wowrestro_script.ajaxurl,
            success: function (response) {

                _this.html(prv_html);

                if (response.status == 'error') {

                    $.toast({
                        text: response.message,
                        position: 'top-right',
                    });

                    return false;

                } else {

                    /* Set URL based on Admin Settings */
                    $(location).attr('href', wowrestro_script.checkout_url);
                }
            }
        });
    });

    // Variations Radio Buttons
    $(document).on('click touch mouseover', '.wowrestro-variations', function () {
        $(this).attr('data-click', 1);
    });

    $('body').on('click', '.wowrestro-variation-radio', function () {

        var _this = $(this);
        var _variations = _this.closest('.wowrestro-variations');
        var _click = parseInt(_variations.attr('data-click'));
        var _variations_form = _this.closest('.variations_form');

        wowrestro_variations_select(_this, _variations, _variations_form, _click);
        _this.find('input[type="radio"]').prop('checked', true);

        /* Trigger Once Variation is Selected */
        $(document.body).trigger('wowrestro_variation_selected');
    });

    $(document).on('click', '.wowrestro-update-service', function (e) {

        e.preventDefault();

        var _this = jQuery(this);
        var _selected_method = _this.parents('.wowrestro-service-modal-container').find('.tab-pane.active');
        var selected_service = _selected_method.data('service-type');
        var selected_time = _selected_method.find('select.wowrestro-service-hours-' + selected_service + ' option:selected').text();
        var selected_timestamp = _selected_method.find('select.wowrestro-service-hours-' + selected_service).val();
        var add_item_id = _this.attr('data-add-item');

        var prv_html = _this.html();
        _this.html(wwro_loader);

        if ($('.asap-option-' + selected_service).is(':checked')) {
            selected_time = 'asap';
        }

        if (typeof selected_time === "undefined" || selected_time == '') {
            $.toast({
                text: wowrestro_script.empty_service_time,
                position: 'top-right',
            });
            _this.html(prv_html);
            return false;
        }

        var data = {
            action: 'update_service_time',
            selected_service: selected_service,
            selected_time: selected_time,
            selected_timestamp: selected_timestamp,
        };

        $.ajax({
            type: "POST",
            data: data,
            url: wowrestro_script.ajaxurl,
            beforeSend: function () {
                _this.attr('disabled', 'disabled')
            },
            complete: function () {
                _this.removeAttr('disabled')
            },
            success: function (response) {
                _this.html(prv_html);
                if (response) {

                    if (response.status == 'success') {

                        $('.wowrestro-cart-service-settings').find('.wowrestro-service-type').text(response.service_type);
                        $('.wowrestro-cart-service-settings').find('.wowrestro-service-time').text(response.service_time);

                        wowrestro_script.service_type = response.service_type;
                        wowrestro_script.service_time = response.service_time;
                        wowrestro_cart_fragments();
                    }

                    if (response.status == 'error') {
                        $.toast({
                            text: response.msg,
                            position: 'top-right',
                        });
                        return false;
                    }

                    $('.wowrestro-cart-service-settings').removeClass('wwr-hidden');
                    MicroModal.close('wowrestroServiceModal');

                    /* Open service modal based on settings */
                    if (wowrestro_script.service_modal_option == 'manual_modal' || wowrestro_script.service_modal_option == 'auto_modal') {
                        if (add_item_id !== '') {
                            $('.wowrestro-food-item-container[data-product-id=' + add_item_id + ']').trigger('click');
                        }
                    }
                }
            }
        });

    });

    // Open service modal on Manual Click
    jQuery(document).on('click', '.wowrestro-change-service', function ($) {
        MicroModal.show('wowrestroServiceModal');
        jQuery(document.body).trigger('wowrestro_service_manual_modal_trigger');
    });


    // Open service modal once page is loaded based on admin settings
    if (wowrestro_script.service_modal_option == 'auto_modal' && (wowrestro_script.service_type == null || wowrestro_script.service_time == null)) {
        if ($('#wowrestroServiceModal').length) {
            MicroModal.show('wowrestroServiceModal');
        }
        $(document.body).trigger('wowrestro_service_modal_trigger');
    }
});

jQuery(document).on('found_variation', function (e, t) {

    var variation_id = t['variation_id'];
    var $variations_default = jQuery(e['target']).find('.wowrestro-variations-default');

    if ($variations_default.length) {
        if (parseInt($variations_default.attr('data-click')) < 1) {
            $variations_default.find(
                '.wowrestro-variation-radio[data-id="' + variation_id + '"] input[type="radio"]').prop('checked', true);
        }
    }
});

function wowrestro_variations_select(selected, variations, variations_form, click) {

    if (click > 0) {

        variations_form.find('.reset_variations').trigger('click');

        if (selected.attr('data-attrs') !== '') {
            var attrs = jQuery.parseJSON(selected.attr('data-attrs'));

            if (attrs !== null) {
                for (var key in attrs) {
                    variations_form.find('select[name="' + key + '"]').val(attrs[key]).trigger('change');
                }
            }
        }
    }
    jQuery(document).trigger('wowrestro_selected', [selected, variations, variations_form]);
}