<?php
defined( 'ABSPATH' ) || exit;
/** checking if woocommerce exists other wise return */
if ( ! function_exists( 'bwfan_is_woocommerce_active' ) || ! bwfan_is_woocommerce_active() ) {
	return;
}

if ( true === BWFAN_Merge_Tag_Loader::get_data( 'is_preview' ) ) {
	$order = wc_get_orders( array(
		'numberposts' => 1,
	) );
	if ( is_array( $order ) && count( $order ) > 0 ) {
		$this->order = $order[0];
	}
}

add_action( 'bwfan_output_email_style', function () { ?>
    .bwfan-email-order-table table {
    width: 100%;
    border-collapse: collapse;
    max-width:700px;
    font-size:14px;
    }
    .bwfan-email-order-table #template_header {
    width: 100% !important;
    }
    .bwfan-email-order-table table img {
    max-width: 75px;
    }
<?php } );

if ( ! isset( $this->order ) || ! $this->order instanceof WC_Order ) {
	return;
}
$order_lang = BWFAN_Common::get_order_language( $this->order );
do_action( 'bwfan_email_setup_locale', $order_lang );

$show_downloads = $this->order->has_downloadable_item() && $this->order->is_download_permitted();

echo "<div class='bwfan-email-order-table'>";
?>
    <!--[if mso]>
    <table>
        <tr>
            <td width="700">
    <![endif]-->

<?php

/** Remove wc template filter to disallow modify template path. */
if ( true === apply_filters( 'bwfan_remove_wc_template_filters', true ) ) {
	remove_all_filters( 'woocommerce_locate_template' );
	remove_all_filters( 'wc_get_template' );
}

if ( $show_downloads ) {
	$downloads = $this->order->get_downloadable_items();
	$columns   = apply_filters( 'woocommerce_email_downloads_columns', array(
		'download-product' => __( 'Product', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		'download-expires' => __( 'Expires', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		'download-file'    => __( 'Download', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
	) );

	wc_get_template( 'emails/email-downloads.php', array(
		'order'         => $this->order,
		'sent_to_admin' => false,
		'plain_text'    => false,
		'email'         => '',
		'downloads'     => $downloads,
		'columns'       => $columns,
	) );

}

wc_get_template( 'emails/email-order-details.php', array(
	'order'         => $this->order,
	'sent_to_admin' => false,
	'plain_text'    => false,
	'email'         => '',
) );
?>
    <!--[if mso]>
    </td></tr></table>
    <![endif]-->
<?php
echo '</div>';
