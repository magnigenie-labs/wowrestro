jQuery(function($) {
  
  var wowrestroQty;

  function wowrestroQuantityChanger( method ) {

    var currentVal = parseInt( $('input[name=wowrestro-quantity]').val() );

    if ( method == 'add' ) {
      if ( !isNaN( currentVal ) ) {
        wowrestroQty = currentVal + 1;
      } else {
        wowrestroQty = 1;
      }
    }

    if( method == 'remove' ) {

      if ( !isNaN( currentVal ) && currentVal > 1 ) {
        wowrestroQty = currentVal - 1;
      } else {
        wowrestroQty = 1;
      }
    }

    $('input[name=wowrestro-quantity]').val( wowrestroQty );

    $('#wowrestroModal .wowrestromodal-footer').find('.wowrestro-product-add-to-cart').attr( 'data-item-qty', wowrestroQty );
  }

  $( document ).on('click', '.wowrestro-qtyplus', function(e) {
    wowrestroQuantityChanger( 'add' );
  });

  $( document ).on('click', '.wowrestro-qtyminus', function(e) {
    wowrestroQuantityChanger( 'remove' );
  });

});