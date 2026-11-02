jQuery( document ).on(
	"change",
	"#email_type",
	function () {

		jQuery( '.customize-preview' ).addClass( 'customizer-unloading' );
		setTimeout(
			function () {
				jQuery( '.customize-preview' ).removeClass( 'customizer-unloading' );
			},
			2000
		)

	}
);
