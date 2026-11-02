<?php

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;


/**
 * Class YITH_YWPI_Document_Type_Checkout_Block
 *
 * This class handles the VAT and SSN checkout block for WooCommerce.
 *
 * @package YITH\PDFInvoice
 */
class YITH_YWPI_Document_Type_Checkout_Block implements IntegrationInterface {

	/**
	 * Get the block name
	 *
	 * @return string
	 */
	public function get_name() {
		return 'ywpi/document-type';
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
	 * Register the admin scripts for the block
	 *
	 * @return void
	 * @author Bluehost
	 */
	public function register_admin_scripts() {
		$dest_path = YITH_YWPI_DIR . 'assets/js/dist/wc-blocks/document-type-block/editor/index.asset.php';
		$deps      = [];
		if ( file_exists( $dest_path ) ) {

			$script_asset = require_once $dest_path;
			$deps         = $script_asset['dependencies'];
			wp_register_script(
				'ywpi-document-type-block-editor',
				YITH_YWPI_ASSETS_URL . '/js/dist/wc-blocks/document-type-block/editor/index.js',
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
	 * @author Bluehost
	 */
	public function register_frontend_scripts() {
		$dest_path = YITH_YWPI_DIR . 'assets/js/dist/wc-blocks/document-type-block/frontend/index.asset.php';
		$deps      = [];
		if ( file_exists( $dest_path ) ) {
			$script_asset = require_once $dest_path;
			$deps         = $script_asset['dependencies'];
		}

		wp_register_script(
			'ywpi-document-type-block-frontend',
			YITH_YWPI_ASSETS_URL . '/js/dist/wc-blocks/document-type-block/frontend/index.js',
			$deps,
			YITH_YWPI_VERSION,
			true
		);

		wp_set_script_translations(
			'ywpi-document-type-block-frontend', // script handle
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
		return array( 'ywpi-document-type-block-frontend' );
	}

	/**
	 * Return the editor script handle
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array( 'ywpi-document-type-block-editor' );
	}

	/**
	 * Return the script data
	 *
	 * @return array
	 */
	public function get_script_data() {

		$user            = wp_get_current_user();
		$document_type   = $user instanceof \WP_User ? get_user_meta( $user->ID, 'billing_document_type', true ) : '03';
		$document_number = $user instanceof \WP_User ? get_user_meta( $user->ID, 'billing_document_number', true ) : '';


		/**
		 * APPLY_FILTERS: ywpi_vat_ssn_block_script_data
		 *
		 * Filter the script data passed to the VAT/SSN checkout block.
		 *
		 * @param array $data The script data.
		 *
		 * @return array
		 */
		return apply_filters(
			'ywpi_vat_ssn_block_script_data',
			array(
				'documentNumber' => $document_number,
				'documentType'   => empty( $document_type ) ? '03' : $document_type,
				'esModuleActive' => YITH_YWPI_Modules::is_module_active( 'spanish-e-invoicing' ),
				'ueCountries'    => WC()->countries->get_european_union_countries( 'eu_vat' )
			)
		);
	}
}
