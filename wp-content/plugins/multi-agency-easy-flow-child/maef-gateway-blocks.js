( function() {
    var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
    var getSetting            = window.wc.wcSettings.getSetting;
    var decodeEntities        = window.wp.htmlEntities.decodeEntities;
    var createElement         = window.wp.element.createElement;
    var RawHTML               = window.wp.element.RawHTML;

    // Key must be: {gateway_id}_data
    var settings    = getSetting( 'maef_child_gateway_data', {} );
    var title       = decodeEntities( settings.title || 'Secure Checkout (Card / Apple Pay / Google Pay)' );
    var description = decodeEntities( settings.description || '' );

    var Content = function() {
        if ( ! description ) return null;
        return createElement( RawHTML, null, description );
    };

    var Label = function( props ) {
        var PaymentMethodLabel = props.components.PaymentMethodLabel;
        return createElement( PaymentMethodLabel, { text: title } );
    };

    registerPaymentMethod( {
        name:           'maef_child_gateway',
        label:          createElement( Label, null ),
        content:        createElement( Content, null ),
        edit:           createElement( Content, null ),
        canMakePayment: function() { return true; },
        ariaLabel:      title,
        supports: {
            features: settings.supports || [ 'products' ],
        },
    } );

    // Move MAEF to first position in the payment methods list once the store is ready
    // This fires after all gateways register and re-sorts so MAEF is always on top.
    if ( window.wp && window.wp.data ) {
        var unsubscribe = window.wp.data.subscribe( function() {
            try {
                var store = window.wp.data.select( 'wc/store/payment' );
                if ( ! store ) return;
                var methods = store.getAvailablePaymentMethods ? store.getAvailablePaymentMethods() : null;
                if ( methods && methods['maef_child_gateway'] ) {
                    unsubscribe();
                    // Dispatch reorder if the API supports it
                    var dispatch = window.wp.data.dispatch( 'wc/store/payment' );
                    if ( dispatch && dispatch.setPaymentStatus ) {
                        // No direct reorder API — handled via PHP get_order()
                    }
                }
            } catch(e) {}
        } );
    }
} )();
