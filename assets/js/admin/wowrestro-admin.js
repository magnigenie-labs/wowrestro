jQuery(function($) {

  /* Admin uploader */
  var file_frame;
  window.formfield = '';

  $( document ).on('click', '.wowrestro_settings_upload_button', function(e) {

    e.preventDefault();

    var button = $(this);

    window.formfield = $(this).parent().prev();

    // If the media frame already exists, reopen it.
    if ( file_frame ) {
      //file_frame.uploader.uploader.param( 'post_id', set_to_post_id );
      file_frame.open();
      return;
    }

    // Create the media frame.
    file_frame = wp.media.frames.file_frame = wp.media({
      frame: 'post',
      state: 'insert',
      title: button.data( 'uploader_title' ),
      button: {
        text: button.data( 'uploader_button_text' )
      },
      multiple: false
    });

    file_frame.on( 'menu:render:default', function( view ) {
      // Store our views in an object.
      var views = {};

      // Unset default menu items
      view.unset( 'library-separator' );
      view.unset( 'gallery' );
      view.unset( 'featured-image' );
      view.unset( 'embed' );

      // Initialize the views in our view object.
      view.set( views );
    } );

    // When an image is selected, run a callback.
    file_frame.on( 'insert', function() {

      var selection = file_frame.state().get('selection');
      selection.each( function( attachment, index ) {
        attachment = attachment.toJSON();
        window.formfield.val(attachment.url);
      });
    });

    // Finally, open the modal
    file_frame.open();
  });


  // WP 3.5+ uploader
  var file_frame;
  window.formfield = '';

  //Timepicker for service hours
  $('input.wowrestro_service_time').timepicker( {
    dropdown: true,
    scrollbar: true,
  } );


  //Tip tip tooltip
  $( '.tips, .help_tip, .wowrestrohelp-tip' ).tipTip( {
    'attribute': 'data-tip',
    'fadeIn': 50,
    'fadeOut': 50,
    'delay': 200
  } );

  // ColorPicker
  $('.wowrestro-colorpicker').wpColorPicker();

  // Show hide food option for products
  show_and_hide_food_tab();
  $( 'input#_food_item' ).change( function() {
    show_and_hide_food_tab();
  });

  function show_and_hide_food_tab() {

    var is_food_item = $( 'input#_food_item:checked' ).length;

    if ( is_food_item ) {
      $( '.food-options_tab' ).show();
    }else{
      $( '.food-options_tab' ).hide();
    }

    if ( $( 'input[type=checkbox]#_food_item' ).length == 0 ) {
      $( '.food-options_tab' ).show();
    }

  }

  // Show product pricing for food items
  $('.options_group.pricing').addClass('show_if_food_item');

  // Show add new modifier block
  $(document).on('click', '.create-new-modifier-category-btn', function(e){
    e.preventDefault();
    $('.modifier-content-list-wrap').hide();
    $('.add-new-modifier-content-wrap').show();
    $('.food-modifier-category-name-input').val('');
    $('.food-modifier-category-type-select').val('single');
    $('.new-modifier-item-row').each(function(index){
      if( index != 0 ) {
        $(this).remove();
      }
    });
    $('.food-modifier-item-name').val('');
    $('.food-modifier-item-price').val('');
  });

  // Close add new modifier block
  $(document).on('click', '.close-create-new-modifier-block', function(){
    $('.add-new-modifier-content-wrap').hide();
    $('.modifier-content-list-wrap').show();
  });

  // Add new modifier item
  $(document).on('click', '.add-new-modifier-item-btn', function(e){
    e.preventDefault();
    $('.new-modifier-item-row:first').clone().appendTo('.food-modifier-items');
    $('.new-modifier-item-row:last').find('.food-modifier-item-name').val('').removeClass('has-error');
    $('.new-modifier-item-row:last').find('.food-modifier-item-price').val('').removeClass('has-error');
  });

  // Remove the modifier row
  $(document).on('click', '.remove-modifier-item', function(e){
    e.preventDefault();
    if ( $('.new-modifier-item-row').length <= 1 ) 
      return false;

    $(this).parent('.new-modifier-item-row').remove();
  });

  // Add new modifiers
  $(document).on('click', '.add-new-modifier-category-btn', function(e){
    e.preventDefault();
    if ( $('.food-modifier-category-name-input').val() == '' ) {
      $('.food-modifier-category-name-input').addClass('has-error').focus();
      return false;
    }

    if ( $('.food-modifier-item-name').first().val() == '' ) {
      $('.food-modifier-item-name').first().addClass('has-error').focus();
      return false;
    }

    if ( $('.food-modifier-item-price').first().val() == '' ) {
      $('.food-modifier-item-price').first().addClass('has-error').focus();
      return false;
    }

    // $('.add-new-modifier-content-wrap').hide();
    // $('.modifier-content-list-wrap').hide();
    // $('.wwr-loads.text').show();
    
    var modifier_category_name = $('.food-modifier-category-name-input').val();
    var modifier_category_type = $('.food-modifier-category-type-select').val();
    var modifier_item_names = [];
    var modifier_item_price = [];

    $('.food-modifier-item-name').each(function(){
        modifier_item_names.push($(this).val());
    });

    $('.food-modifier-item-price').each(function(){
        modifier_item_price.push($(this).val());
    });

    var nonce = $(this).data('nonce');

    var data = {
      action: "add_food_category",
      nonce: nonce,
      modifier_category_name: modifier_category_name,
      modifier_category_type: modifier_category_type,
      modifier_item_names: modifier_item_names,
      modifier_item_price: modifier_item_price,
    }

    // Add modifiers
    $.ajax({
      type : "post",
      dataType : "json",
      url : wwro_ajax.ajaxurl,
      data : data,
      success: function(response) {
        $('.wwr-loads.text').hide();
        if ( response.success == false ) {
          $('.add-new-modifier-content-wrap').show();
          $('.food-modifier-category-name-input').addClass('has-error').focus();
          $('.error-exists').remove();
          $('<p class="error-exists">'+ response.data.message +'</p>').insertAfter('.food-modifier-category-name-input')
          return false;
        }else{
          $('.add-new-modifier-content-wrap').hide();
          $('.modifier-content-list-wrap').show();
          $('.modifier-category-select').append('<option value="'+ response.data.food_category_id +'">'+ response.data.food_category_name +'</option>');
        }
      }
    });

  });

  $(document).on('blur', '.food-modifier-item-name', function(){
    if ( $(this).val().length > 0 ) {
      $(this).removeClass('has-error');
    }
  });

  $(document).on('blur', '.food-modifier-item-price', function(){
    if ( $(this).val().length > 0 ) {
      $(this).removeClass('has-error');
    }
  });

  $(document).on('blur', '.food-modifier-category-name-input', function(){
    if ( $(this).val().length > 0 ) {
      $(this).removeClass('has-error');
    }
  });

   // Select modifiers
  $(document).on('change', '.modifier-category-select', function(){
    var selected = $(this);
    selected.parents('.modifier-category-content').find('.modifier-category-inner-content-empty').hide();
    var data = {
      action: "select_modifier_category",
      modifier_category_id: $(this).val(),
    }

    $.ajax({
      type : "post",
      dataType : "json",
      url : wwro_ajax.ajaxurl,
      data : data,
      success: function(response) {
        selected.parents('.modifier-category-content').find('.modifier-category-inner-content-wrap').show();
        selected.parents('.modifier-category-content').find('.modifier-category-inner-content-wrap').html(response.data.html);
      }
    });
  });

 // Add modifiers row
 $(document).on('click', '.add-modifier-category-btn', function(e){
  e.preventDefault();
  $('.modifier-category-content:first').clone().appendTo('.modifier-category-block');
  $('.modifier-category-content:last').find('.modifier-category-inner-content-wrap').hide();
  $('.modifier-category-content:last').find('.modifier-category-inner-content-empty').show();
  $('.modifier-category-content:last').find('.modifier-category-select').val('');
  $('.modifier-category-content:last').find('.remove-modifier-category').show();
 });

 // Remove modifiers row
 $(document).on('click', '.remove-modifier-category', function(e){
  e.preventDefault();
  if ( $('.modifier-category-content').length > 1 ) {
    $(this).parents('.modifier-category-content').remove();
  }
 });

});

jQuery(function($) {
  if( wwro_ajax.is_admin == 1 && wwro_ajax.enable_order_notification == 'yes' ) {
    if ( typeof Notification !== "undefined" ) {
      Notification.requestPermission().then(function (result) {
        if (result === 'denied') {
          console.log('Permission wasn\'t granted. Allow a retry.');
          return;
        }

        if (result === 'default') {
          console.log('The permission request was dismissed.');
          return;
        }

        setInterval(function () {
          $.ajax({
            type: 'POST',
            data: {
              action: 'wowrestro_check_new_orders'
            },
            url: ajaxurl,
            success: function (response) {
              if (response != '0') {  

                if( typeof response.title === "undefined" ) return;

                var notifyTitle = response.title;
                var options = {
                    body: response.body,
                    icon: response.icon,
                    sound: response.sound,
                };
                var n = new Notification(notifyTitle, options);
                n.custom_options = {
                    url: response.url,
                }
                n.onclick = function (event) {
                    event.preventDefault(); // prevent the browser from focusing the Notification's tab
                    window.open(n.custom_options.url, '_blank');
                };

                //add audio notify because, this property is not currently supported in any browser.
                if (response.sound != '') {
                  var loopsound =  'yes' == wwro_ajax.loopsound ? 'loop' : '';
                  $("<audio controls "+loopsound+" class='wowrestro_notify_audio'></audio>").attr({
                      'src': response.sound,
                  }).appendTo("body");
                  $('.wowrestro_notify_audio').trigger("play");
                }

                //set time to notify is show
                var time_notify = parseInt(wwro_ajax.notification_duration);
                if (time_notify > 0) {
                    time_notify = time_notify * 1000;
                    setTimeout(n.close.bind(n), time_notify);
                }

                n.onclose = function (event) {
                    event.preventDefault();
                    $('.wowrestro_notify_audio').remove();
                };
              }
            },
            complete: function () { }
          });
        }, 10000);
      });
    }
  }
});