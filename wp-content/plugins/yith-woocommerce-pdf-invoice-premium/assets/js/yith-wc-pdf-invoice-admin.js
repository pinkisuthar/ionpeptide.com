/**
 * Manage the admin javascript
 *
 * @package YITH\PDF_Invoice\JS
 */

jQuery(
	function ( $ ) {
		var initial_element   = '.yith-plugins_page_yith_woocommerce_pdf_invoice_panel';
		var selected_elements = [
			'ul.yith-plugin-fw-tabs li a',
			'.yith-plugin-fw-sub-tabs-nav .nav-tab-wrapper a',
			'.ywpi-documents-table .page-title-action',
			'.invoice_date_pickers input[type="submit"]',
			'.yith-ywpi-actions-container a.yith-plugin-fw__action-button__menu__item',
			'.tablenav input'
		];

		$.each(
			selected_elements,
			function ( key, element ) {
				$( document ).on(
					'click',
					initial_element + ' ' + element,
					function ( e ) {
						window.onbeforeunload = null;
					}
				);
			}
		);

		// ======= click in the upload button to check when the logo is inserted ========
		var upload_button_clicked = false;

		$( 'body' ).on(
			'change',
			'#ywpi_company_logo',
			function () {
				var tmpImg = new Image();
				tmpImg.src = $( this ).val();

				$( tmpImg ).one(
					'load',
					function () {
						orgWidth  = tmpImg.width;
						orgHeight = tmpImg.height;

						if ( orgWidth > 300 || orgHeight > 150 ) {
							alert(
								yith_wc_pdf_invoice_free_object.logo_message_1 +
								orgWidth + 'x' + orgHeight + ' pixels. ' +
								yith_wc_pdf_invoice_free_object.logo_message_2
							);

							$( 'body #ywpi_company_logo' ).val( '' );
							$( 'body #ywpi_company_logo-container .upload_img_preview img' ).remove();
						}
					}
				);
			}
		);

		/*
		Check data before to proceed with refund
		*/
		if ( yith_wc_pdf_invoice_free_object.electronic_invoice == 'yes' ) {
			var woocommerceOrderItems = $( '.post-type-shop_order' ).find( '#woocommerce-order-items' );

			woocommerceOrderItems.find( '#refund_amount' ).parent().addClass( 'wrap-input ywpi-disabled' );

			$( '.ywpi-disabled' ).on(
				'click',
				function () {
					alert( yith_wc_pdf_invoice_free_object.alert_refund_credit_note );
				}
			);

			woocommerceOrderItems.find( 'label[for="refund_reason"]' ).text( 'Reason for refund(mandatory)' );

			$( '.do-manual-refund' ).click(
				function ( e ) {
					if ( $( '#refund_reason' ).val() === '' ) {
						alert( yith_wc_pdf_invoice_free_object.alert_refund_credit_reason );
						e.stopImmediatePropagation();
					}
				}
			);
		}

		/**
		 * ADMIN OPTIONS
		 */
			// dropbox upload onoff.
		var ywpiFieldsVisibility = {
				conditions: {
					allow_dropbox_upload: $( '#ywpi_dropbox_allow_upload' )
				},
				dom       : {
					dropbox_key: $( '#ywpi_dropbox_key' )
				},
				init      : function () {
					var self = ywpiFieldsVisibility;

					self.conditions.allow_dropbox_upload.on(
						'change',
						function () {
							self.handle(
								self.dom.dropbox_key,
								'yes' === self.conditions.allow_dropbox_upload.val()
							);
						}
					).trigger( 'change' );
				},
				handle    : function ( target, condition ) {
					var targetHide = target.closest( '.yith-plugin-fw__panel__option' );

					if ( condition ) {
						$( targetHide ).fadeIn();
					} else {
						$( targetHide ).hide();
					}
				}
			};

		ywpiFieldsVisibility.init();

		initListTables = function () {
			$( '#yith-ywpi-list-table #posts-filter .search-box input[type="search"]' ).attr( 'placeholder', yith_wc_pdf_invoice_free_object.search_invoice_placeholder );

			$( '.yith-ywpi-list-table-elements .ywpi_preview_action a.yith-plugin-fw__action-button__link' ).each(
				function () {
					$( this ).attr( 'target', '_blank' );
				}
			);

			let tablenav_bottom = $( '#yith-ywpi-list-table .tablenav.bottom' );

			if ( tablenav_bottom.find( '.tablenav-pages .pagination-links a.next-page' ).length ) {
				tablenav_bottom.show();
			}

			$( '#yith-ywpi-list-table #bulk-action-selector-top' ).selectWoo(
				{
					minimumResultsForSearch: Infinity
				}
			).addClass( 'enhanced' );
		};

		initListTables();

		/** Bulk actions for invoice and credit note tables */
		$( document ).on(
			'click',
			'#yith-ywpi-list-table div.bulkactions #doaction',
			function ( e ) {
				var value      = $( this ).parent().find( 'select option:selected' ).val(),
					is_invoice = $( 'form.invoices-table' ).length;

				if ( 'regenerate' === value ) {
					let regenerate_confirm_message = '';

					if ( is_invoice ) {
						regenerate_confirm_message = yith_wc_pdf_invoice_free_object.regenerate_confirm_message_invoices;
					} else {
						regenerate_confirm_message = yith_wc_pdf_invoice_free_object.regenerate_confirm_message_credit_notes;
					}

					e.preventDefault();

					yith.ui.confirm(
						{
							title            : yith_wc_pdf_invoice_free_object.regenerate_confirm_title,
							message          : regenerate_confirm_message,
							confirmButtonType: 'confirm',
							confirmButton    : yith_wc_pdf_invoice_free_object.regenerate_confirm_button,
							closeAfterConfirm: true,
							width            : 400,
							onConfirm        : function () {
								$( '#posts-filter' ).unbind( 'submit' ).submit();
							}
						}
					);

					// Prevent WooCommerce warning for changes without saving.
					window.onbeforeunload = null;
				}

				if ( 'delete' === value ) {
					let delete_confirm_message = '';

					if ( is_invoice ) {
						delete_confirm_message = yith_wc_pdf_invoice_free_object.delete_confirm_message_invoices;
					} else {
						delete_confirm_message = yith_wc_pdf_invoice_free_object.delete_confirm_message_credit_notes;
					}
					e.preventDefault();

					yith.ui.confirm(
						{
							title            : yith_wc_pdf_invoice_free_object.delete_confirm_title,
							message          : delete_confirm_message,
							confirmButtonType: 'delete',
							confirmButton    : yith_wc_pdf_invoice_free_object.delete_confirm_button,
							cancelButton     : yith_wc_pdf_invoice_free_object.delete_cancel_button,
							closeAfterConfirm: true,
							width            : 400,
							onConfirm        : function () {
								$( '#posts-filter' ).unbind( 'submit' ).submit();
							}
						}
					);
					// Prevent WooCommerce warning for changes without saving.
					window.onbeforeunload = null;
				}
			}
		);

		/** Template panel **/
		if ( $( '.hide-tab-templates' ).length > 0 ) {
			$( '.yith-plugin-fw__panel__menu-item.hide-tab-templates .yith-plugin-fw__panel__submenu .yith-plugin-fw__panel__submenu__wrap .yith-plugin-fw__panel__submenu-item:nth-child(4)' ).hide();
		} else if ( $( '.show-tab-templates' ).length > 0 ) {
			$( '.yith-plugin-fw__panel__menu-item.show-tab-templates .yith-plugin-fw__panel__submenu .yith-plugin-fw__panel__submenu__wrap .yith-plugin-fw__panel__submenu-item:nth-child(3)' ).hide();
		}

		$( '#ywpi_pdf_template_to_use' ).on(
			'change',
			function () {
				var $t                 = $( this ),
					currentValue       = $t.val(),
					style_tab          = $( '#yith-plugin-fw__panel__menu-item-template .yith-plugin-fw__panel__submenu .yith-plugin-fw__panel__submenu__wrap .yith-plugin-fw__panel__submenu-item:nth-child(3)' ),
					template_tab       = $( '#yith-plugin-fw__panel__menu-item-template .yith-plugin-fw__panel__submenu .yith-plugin-fw__panel__submenu__wrap .yith-plugin-fw__panel__submenu-item:nth-child(4)' ),
					company_info       = $( '#ywpi_show_company_name' ).closest( '.yith-plugin-fw__panel__option' ),
					sections_to_toggle = $( '#ywpi_customer_shipping_details, #ywpi_show_credit_note_notes, #ywpi_show_invoice_notes' ).closest( '.yith-plugin-fw__panel__option' ).parent().prev().parent();

				if ( 'builder' === currentValue ) {
					// hide options
					style_tab.hide();
					template_tab.show();
					company_info.hide();
					sections_to_toggle.hide();
				} else {
					// show options
					style_tab.show();
					template_tab.hide();
					company_info.show();
					sections_to_toggle.show();
				}
			}
		).change();

		// Move button to download all documents near the page title.
		var download_all_button = $( '.ywpi-documents-table > a.yith-plugin-fw__button--primary' ),
			main_content        = $( '#yith_woocommerce_pdf_invoice_panel_documents_type-invoices, #yith_woocommerce_pdf_invoice_panel_documents_type-credit-notes, #yith_woocommerce_pdf_invoice_panel_documents_type' ),
			content             = main_content.find( '.yith-plugin-fw__panel__content__page .yith-plugin-fw__panel__content__page__title' );

		content.append( download_all_button );

		var documents_format_page = $( '#yith_woocommerce_pdf_invoice_panel_settings-documents' );

		if ( yith_wc_pdf_invoice_free_object.is_credit_note_disabled ) {
			documents_format_page.find( '.yith-plugin-fw__panel__section:nth-child(4)' ).remove();
		}

		if ( yith_wc_pdf_invoice_free_object.is_packing_slip_disabled ) {
			documents_format_page.find( '.yith-plugin-fw__panel__section:nth-child(5)' ).remove();
		}

		let modal       = false;
		const openModal = function ( url ) {
			let h = 600;
			let w = 500;
			let y = window.outerHeight / 2 + window.screenY - ( h / 2 );
			let x = window.outerWidth / 2 + window.screenX - ( w / 2 );

			modal = window.open(
				url,
				'Go Dropbox',
				'toolbar=no, location=no, directories=no, status=no, menubar=no, scrollbars=no, resizable=no, copyhistory=no,' +
				'width=' + w +
				', height=' + h +
				', top=' + y +
				', left=' + x
			);

		}

		const updateButton = function ( ev ) {

			var data = ev.detail.connected ? {} : { ywpi_disconnect_to_dropbox: 'yes' };

			$.post( document.location.href, data ).done( function ( data ) {
				if ( data !== '' ) {
					var c      = $( '<div></div>' ).html( data ),
						button = c.find( '#ywpi_dropbox_status' );
					$( '#ywpi_dropbox_status' ).html( button.html() );
				}
			} );
		};

		$( document ).on( 'ywpi-update-connection-button', updateButton );

		$( document ).on( 'click', '.ywpi-disconnect-account', function ( e ) {
			e.preventDefault();
			yith.ui.confirm(
				{
					title            : yith_wc_pdf_invoice_free_object.disconnect_popup.title,
					message          : yith_wc_pdf_invoice_free_object.disconnect_popup.message,
					confirmButtonType: 'delete',
					confirmButton    : yith_wc_pdf_invoice_free_object.disconnect_popup.button,
					onConfirm        : function () {
						updateButton( { detail: { connected: false } } );
					}
				}
			);
		} );

		$( document ).on( 'click', '.ywpi-connect-account', function ( e ) {
			e.preventDefault();
			openModal( $( this ).data( 'url' ) );
		} );

		const template = $( '.ywpi_update_dropbox_modal_content' );

		if ( template.length > 0 ) {

			const hideModal = function ( force = true ) {
				$.post( document.location.href, { ywpi_hide_modal: 'yes' } ).done( function ( data ) {
					modal.close();
					if ( force ) {
						$( document ).find( '.ywpi-connect-account' ).click();
					}
				} );
			}
			const modal     = yith.ui.modal( {
												 title  : yith_wc_pdf_invoice_free_object.update_modal.title,
												 content: template,
												 onClose: function () {
													 hideModal( false );
												 }
											 } );

			$( document ).on( 'click', '.ywpi_reconnect_button', function ( e ) {
				e.preventDefault();
				hideModal();
			} )
		}


        $(document).on('change', '#ywpi_enable_pro_forma',function () {
            const value = $( this ).val();
            const renew_desc = $(document).find('.ywpi_extra_desc_renews');

            if( renew_desc.length && !renew_desc.hasClass('ywpi_extra_desc_renews_force_hide')){

                if( 'yes' === value ){
                    renew_desc.show();
                }else{
                    renew_desc.hide();
                }
            }
        });

        $(document).on('click', '.on-off-module .on_off', function () {

            const
                input = jQuery(this),
                wrap = input.closest('.module'),
                module = input.attr('data-module');

            let data = {
                action: 'ywpi_module_activation_switch',
                security: yith_wc_pdf_invoice_free_object.modules_nonce,
                module,
            };

            // Make sure the WC alert is removed
            window.onbeforeunload = '';

            $.ajax({
                url: yith_wc_pdf_invoice_free_object.ajaxurl,
                data: data,
                type: 'POST',
                beforeSend: function () {
                    wrap.block({
                        message: null,
                        overlayCSS: {
                            background: '#fff',
                            opacity: 0.6,
                        }
                    });
                },
                success: function (res) {
                    window.onbeforeunload = '';
                    window.location.reload();
                }
            });
        });
	}
);
