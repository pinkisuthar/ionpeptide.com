<?php

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;


/**
 * Class YITH_YWPI_Vat_Ssn_Checkout_Block
 *
 * This class handles the VAT and SSN checkout block for WooCommerce.
 *
 * @package YITH\PDFInvoice
 */
class YITH_YWPI_Vat_Ssn_Checkout_Block implements IntegrationInterface {

	/**
	 * Get the block name
	 *
	 * @return string
	 */
	public function get_name() {
		return 'ywpi/vat-ssn';
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
		$dest_path = YITH_YWPI_DIR . 'assets/js/dist/wc-blocks/vat-ssn-block/editor/index.asset.php';
		$deps      = [];
		if ( file_exists( $dest_path ) ) {

			$script_asset = require_once $dest_path;
			$deps         = $script_asset['dependencies'];
			wp_register_script(
				'ywpi-vat-ssn-block-editor',
				YITH_YWPI_ASSETS_URL . '/js/dist/wc-blocks/vat-ssn-block/editor/index.js',
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
		$dest_path = YITH_YWPI_DIR . 'assets/js/dist/wc-blocks/vat-ssn-block/frontend/index.asset.php';
		$deps      = [];
		if ( file_exists( $dest_path ) ) {
			$script_asset = require_once $dest_path;
			$deps         = $script_asset['dependencies'];
		}

		wp_register_script(
			'ywpi-vat-ssn-block-frontend',
			YITH_YWPI_ASSETS_URL . '/js/dist/wc-blocks/vat-ssn-block/frontend/index.js',
			$deps,
			YITH_YWPI_VERSION,
			true
		);

		wp_set_script_translations(
			'ywpi-vat-ssn-block-frontend', // script handle
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
		return array( 'ywpi-vat-ssn-block-frontend' );
	}

	/**
	 * Return the editor script handle
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array( 'ywpi-vat-ssn-block-editor' );
	}

	/**
	 * Return the script data
	 *
	 * @return array
	 */
	public function get_script_data() {

		$user = wp_get_current_user();
		$vat  = $user instanceof \WP_User ? get_user_meta( $user->ID, 'billing_vat_number', true ) : '';
		$ssn  = $user instanceof \WP_User ? get_user_meta( $user->ID, 'billing_vat_ssn', true ) : '';

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
				'showVat'            => ywpi_show_vat_field(),
				'vatRequired'        => ywpi_vat_is_required() ? 'required' : 'optional',
				'billing_vat_number' => $vat,
				'billing_vat_ssn'    => $ssn,
				'showSsn'            => ywpi_show_ssn_field(),
				'ssnRequired'        => ywpi_ssn_is_required() ? 'required' : 'optional',
				'itModuleActive'     => YITH_YWPI_Modules::is_module_active( 'italian-e-invoicing' ),
				'esModuleActive'     => YITH_YWPI_Modules::is_module_active( 'spanish-e-invoicing' ),
				'ueCountries'        => WC()->countries->get_european_union_countries( 'eu_vat' )
			)
		);
	}
}
