<?php

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

/**
 * Class YITH_YWPI_Invoice_Type_Checkout_Block
 *
 * This class registers the electronic fields block for WooCommerce checkout.
 *
 * @package YITH\PDFInvoice
 */
class YITH_YWPI_Invoice_Type_Checkout_Block implements IntegrationInterface {

	/**
	 * Get the block name
	 *
	 * @return string
	 */
	public function get_name() {
		return 'ywpi/invoice-type';
	}

	/**
	 * Initialize the block
	 *
	 * @return void
	 */
	public function initialize() {
		$this->register_styles();
		$this->register_admin_scripts();
		$this->register_frontend_scripts();
	}

	/**
	 * Register the styles for the block
	 *
	 * @return void
	 * @author Bluehost
	 */
	public function register_styles() {
		wp_enqueue_style(
			'ywpi-vat-ssn-block-frontend',
			YITH_YWPI_ASSETS_URL . '/css/wc-blocks/frontend.css',
			[],
			YITH_YWPI_VERSION
		);
	}
	/**
	 * Register the admin scripts
	 *
	 * @return void
	 * @author Bluehost
	 */
	public function register_admin_scripts() {
		$dest_path = YITH_YWPI_DIR . 'assets/js/dist/wc-blocks/invoice-type-block/editor/index.asset.php';
		$deps      = [];
		if ( file_exists( $dest_path ) ) {

			$script_asset = require_once $dest_path;
			$deps         = $script_asset['dependencies'];
			wp_register_script(
				'ywpi-invoice-type-block-editor',
				YITH_YWPI_ASSETS_URL . '/js/dist/wc-blocks/invoice-type-block/editor/index.js',
				$deps,
				YITH_YWPI_VERSION,
				true
			);
		}
	}

	/**
	 * Register the frontend scripts
	 *
	 * @return void
	 */
	public function register_frontend_scripts() {
		$dest_path = YITH_YWPI_DIR . 'assets/js/dist/wc-blocks/invoice-type-block/frontend/index.asset.php';
		$deps      = [];
		if ( file_exists( $dest_path ) ) {
			$script_asset = require_once $dest_path;
			$deps         = $script_asset['dependencies'];
		}

		wp_register_script(
			'ywpi-invoice-type-block-frontend',
			YITH_YWPI_ASSETS_URL . '/js/dist/wc-blocks/invoice-type-block/frontend/index.js',
			$deps,
			YITH_YWPI_VERSION,
			true
		);

		wp_set_script_translations(
			'ywpi-invoice-type-block-frontend', // script handle
			'yith-woocommerce-pdf-invoice', // text domain
			plugin_basename( YITH_YWPI_DIR ) . '/languages'
		);
	}

	/**
	 * Return the script handles
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		return array( 'ywpi-invoice-type-block-frontend' );
	}

	/**
	 * Return the editor script handle
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array( 'ywpi-invoice-type-block-editor' );
	}

	/**
	 * Return the script data
	 *
	 * @return array
	 */
	public function get_script_data() {
		$user = wp_get_current_user();
		if ( $user instanceof \WP_User ) {
			$invoice_type = get_user_meta( $user->ID, 'billing_invoice_type', true );
		}
		$invoice_type = empty( $invoice_type ) ? 'invoice' : $invoice_type;

		
		return [
			'show'        => 'yes' === get_option( 'ywpi_enable_receipts' ),
			'defaultType' => $invoice_type,
		];
	}
}
