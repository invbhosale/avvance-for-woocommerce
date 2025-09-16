const { registerPaymentMethod } = wc.wcBlocksRegistry;
const { getSettings } = wc.wcSettings;
const { createElement, Fragment } = wp.element;
const { __ } = wp.i18n;

const settings = getSettings( 'avvance_gateway' );

const AvvanceLabel = () => {
    const title = settings.title || __( 'Avvance', 'avvance-for-woocommerce' );
    const description = settings.description || __( 'Pay with Avvance financing.', 'avvance-for-woocommerce' );

    return createElement( 'div', { className: 'avvance-block-label' },
        createElement( 'span', { className: 'avvance-title' }, title ),
        createElement( 'span', { className: 'avvance-description' }, description )
    );
};

const AvvanceContent = () => {
    return createElement( 'p', null, settings.description );
};

const AvvanceBlockComponent = {
    name: settings.id,
    label: createElement( AvvanceLabel, null ),
    content: createElement( AvvanceContent ),
    edit: createElement( AvvanceContent ),
    canMakePayment: ( args ) => true,
    ariaLabel: __( 'Avvance Payment Method', 'avvance-for-woocommerce' ),
    placeOrder: async ( data ) => {
        // This is a crucial step for block-based payment methods
        // We will make a call to our custom REST API endpoint
        const response = await fetch( settings.api_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': settings.nonce
            },
            body: JSON.stringify({
                order_id: data.order_id
            })
        });

        const result = await response.json();

        // If the API call returns a redirect URL, open it in a new window
        if ( result.redirect_url ) {
            window.open( result.redirect_url, '_blank', 'noopener,noreferrer' );
            return {
                type: 'success',
                redirectUrl: data.redirectUrl // This redirect is handled by the window.open call
            };
        } else {
            return {
                type: 'error',
                message: result.message || 'An unknown error occurred.'
            };
        }
    }
};

registerPaymentMethod( AvvanceBlockComponent );