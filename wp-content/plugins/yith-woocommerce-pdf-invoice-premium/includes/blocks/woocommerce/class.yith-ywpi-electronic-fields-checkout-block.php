<?php

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;


/**
 * Class ElectronicFieldsCheckoutBlock
 *
 * This class registers the electronic fields block for WooCommerce checkout.
 *
 * @package YITH\PDFInvoice
 */
class YITH_YWPI_Electronic_Fields_Checkout_Block implements IntegrationInterface {

	/**
	 * Get the block name
	 *
	 * @return string
	 */
	public function get_name() {
		return 'ywpi/electronic-fields';
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
		$dest_path = YITH_YWPI_DIR . 'assets/js/dist/wc-blocks/electronic-fields-block/editor/index.asset.php';
		$deps      = [];
		if ( file_exists( $dest_path ) ) {

			$script_asset = require_once $dest_path;
			$deps         = $script_asset['dependencies'];
			wp_register_script(
				'ywpi-electronic-fields-block-editor',
				YITH_YWPI_ASSETS_URL . '/js/dist/wc-blocks/electronic-fields-block/editor/index.js',
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
		$dest_path = YITH_YWPI_DIR . 'assets/js/dist/wc-blocks/electronic-fields-block/frontend/index.asset.php';
		$deps      = [];
		if ( file_exists( $dest_path ) ) {
			$script_asset = require_once $dest_path;
			$deps         = $script_asset['dependencies'];
		}

		wp_register_script(
			'ywpi-electronic-fields-block-frontend',
			YITH_YWPI_ASSETS_URL . '/js/dist/wc-blocks/electronic-fields-block/frontend/index.js',
			$deps,
			YITH_YWPI_VERSION,
			true
		);

		wp_set_script_translations(
			'ywpi-electronic-fields-block-frontend', // script handle
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
		return array( 'ywpi-electronic-fields-block-frontend' );
	}

	/**
	 * Return the editor script handle
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array( 'ywpi-electronic-fields-block-editor' );
	}

	/**
	 * Return the script data
	 *
	 * @return array
	 */
	public function get_script_data() {

		$user         = wp_get_current_user();
		$user_type    = '';
		$receiver_pec = '';
		$receiver_id  = '';
		if ( $user ) {
			$user_type    = get_user_meta( $user->ID, 'billing_receiver_type', true );
			$receiver_id  = get_user_meta( $user->ID, 'billing_receiver_id', true );
			$receiver_pec = get_user_meta( $user->ID, 'billing_receiver_pec', true );
		}

		return [
			'itModuleActive'               => YITH_YWPI_Modules::is_module_active( 'italian-e-invoicing' ),
			'esModuleActive'               => YITH_YWPI_Modules::is_module_active( 'spanish-e-invoicing' ),
			'userTypeLabel'      => get_option( 'ywpi_electronic_invoice_receiver_type', 'Tipologia utente' ),
			'userType'           => !empty( $user_type ) ? $user_type : 'private',
			'receiverIdLabel'    => get_option( 'ywpi_electronic_invoice_receiver_id_label', 'Receiver ID' ),
			'receiverId'         => $receiver_id,
			'receiverPECLabel'   => get_option( 'ywpi_electronic_invoice_receiver_pec_label', 'Receiver PEC' ),
			'receiverPEC'        => $receiver_pec,
			'receiverIdPecError' => get_option( 'ywpi_electronic_invoice_receiver_mandatory_id_pec_message' ),
			'ssnError'           => get_option( 'ywpi_electronic_invoice_receiver_mandatory_ssn_message' ),
			'vatError'           => get_option( 'ywpi_electronic_invoice_receiver_mandatory_vat_message' ),
		];
	}
}
