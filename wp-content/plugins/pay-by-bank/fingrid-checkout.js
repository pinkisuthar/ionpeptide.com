(function() {
    // ✅ Gateway-specific settings object
    const fingridSettings = window.wc.wcSettings.getSetting('my_fingrid_gateway_data', {});

    // ✅ Gateway-specific label
    const fingridLabel =
        window.wp.htmlEntities.decodeEntities(fingridSettings.title) ||
        window.wp.i18n.__('Pay By Bank', 'my_fingrid_gateway');

    // ✅ Scoped React helpers
    const { createElement: fingridCreateElement, useState: fingridUseState } = window.wp.element;

    // ✅ Content component for gateway
    const FingridContent = () => {
        const savedBanks = fingridSettings.savedBanks || [];
        const defaultValue = savedBanks.length ? savedBanks[0].token : 'new';
        const [selected, setSelected] = fingridUseState(defaultValue);

        // Ensure hidden input exists + has default value immediately
        if (!document.getElementById('fingrid_saved_bank_token')) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.id = 'fingrid_saved_bank_token';
            input.name = 'fingrid_saved_bank_token';
            input.value = defaultValue;
            const form =
                document.querySelector('form.wc-block-checkout__form') ||
                document.querySelector('form');
            if (form) form.appendChild(input);
        } else {
            document.getElementById('fingrid_saved_bank_token').value = selected;
        }

        // Handle radio change
        const updateSelection = (value) => {
            setSelected(value);
            let input = document.getElementById('fingrid_saved_bank_token');
            if (input) {
                input.value = value;
            }
        };

        return fingridCreateElement(
            'div',
            {},
            fingridCreateElement('p', {}, fingridSettings.description || ''),

            savedBanks.length > 0 &&
                fingridCreateElement(
                    'div',
                    { className: 'fingrid-saved-banks' },
                    savedBanks.map((bank) =>
                        fingridCreateElement(
                            'label',
                            {
                                key: bank.token,
                                style: { display: 'block', margin: '5px 0' },
                            },
                            fingridCreateElement('input', {
                                type: 'radio',
                                name: 'fingrid_bank_choice',
                                value: bank.token,
                                checked: selected === bank.token,
                                onChange: (e) => updateSelection(e.target.value),
                            }),
                            ` ${bank.bank_name} (****${bank.last4})`
                        )
                    ),
                    fingridCreateElement(
                        'label',
                        { style: { display: 'block', margin: '5px 0' } },
                        fingridCreateElement('input', {
                            type: 'radio',
                            name: 'fingrid_bank_choice',
                            value: 'new',
                            checked: selected === 'new',
                            onChange: () => updateSelection('new'),
                        }),
                        ' Use a new bank account'
                    )
                )
        );
    };

    // ✅ Payment method registration object
    const FingridBlockGateway = {
        name: 'my_fingrid_gateway',
        label: fingridLabel,
        ariaLabel: fingridLabel,
        canMakePayment: () => true,
        content: fingridCreateElement(FingridContent, null),
        edit: fingridCreateElement(FingridContent, null),
        supports: { features: fingridSettings.supports },

        onSubmit: () => {
            console.log('✅ Fingrid onSubmit fired!');
            return {
                type: 'success',
                meta: {
                    paymentMethodData: {
                        fingrid_saved_bank_token:
                            document.getElementById('fingrid_saved_bank_token')?.value || '',
                    },
                },
            };
        },
    };

    // ✅ Register payment method with WooCommerce Blocks
    window.wc.wcBlocksRegistry.registerPaymentMethod(FingridBlockGateway);
})();
