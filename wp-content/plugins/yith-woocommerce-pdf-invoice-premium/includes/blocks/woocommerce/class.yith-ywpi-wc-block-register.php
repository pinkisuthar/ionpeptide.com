<?php
/**
 * This class register the vat & ssn block, added in the checkout block page
 *
 * @package YITH\PDFInvoice
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry;
use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields;
use Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry;
use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;


/**
 * Class WCBlockRegister
 *
 */
class YITH_YWPI_WC_Block_Register {

	/**
	 * Single instance of the class
	 *
	 * @var object
	 * @since 5.0.0
	 */
	protected static $instance;

	/**
	 * Returns single instance of the class
	 *
	 * @since 5.0.0
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
	}

	/**
	 * The construct
	 */
	private function __construct() {
		add_action( 'woocommerce_blocks_loaded', array( $this, 'add_plugin_blocks' ) );
		add_action( 'block_categories_all', array( $this, 'add_block_category' ), 200, 2 );
		add_filter( 'block_editor_settings_all', array( $this, 'inject_editor_styles' ) );
		add_filter(
			'__experimental_woocommerce_blocks_add_data_attributes_to_block',
			array( $this, 'add_vat_ssn_block_data_attributes' ),
		);
		add_filter( 'render_block_data', array( $this, 'ensure_checkout_blocks' ), 10, 3 );
	}


	/**
	 * Add the block in WooCommerce blocks
	 *
	 * @return void
	 */
	public function add_plugin_blocks() {
		add_action(
			'woocommerce_blocks_checkout_block_registration',
			array(
				$this,
				'update_default_fields_data_with_block',
			),
			999
		);
		add_action(
			'woocommerce_blocks_checkout_block_registration',
			array( $this, 'register_checkout_blocks' )
		);

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CheckoutSchema::IDENTIFIER,
				'namespace'       => 'ywpi/vat-ssn',
				'schema_callback' => array( $this, 'schema_callback' ),
				'schema_type'     => ARRAY_A,
			)
		);
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CheckoutSchema::IDENTIFIER,
				'namespace'       => 'ywpi/electronic-fields',
				'schema_callback' => array( $this, 'schema_callback_electronic_fields' ),
				'schema_type'     => ARRAY_A,
			)
		);
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CheckoutSchema::IDENTIFIER,
				'namespace'       => 'ywpi/invoice-type',
				'schema_callback' => array( $this, 'schema_callback_invoice_type' ),
				'schema_type'     => ARRAY_A,
			)
		);

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CheckoutSchema::IDENTIFIER,
				'namespace'       => 'ywpi/document-type',
				'schema_callback' => array( $this, 'schema_callback_document_type' ),
				'schema_type'     => ARRAY_A,
			)
		);
		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => 'ywpi/vat-ssn',
				'callback'  => array( $this, 'update_callback' ),
			)
		);

		add_action(
			'woocommerce_store_api_checkout_update_order_from_request',
			array(
				$this,
				'add_pdf_fields_api_update_order',
			),
			10,
			2
		);
	}

	/**
	 * Update block schema in WooCommerce
	 *
	 * @param array $data The data
	 *
	 * @return void
	 */
	public function update_callback( $data ) {
	}

	/**
	 * Block schema in WooCommerce
	 *
	 * @return array[]
	 */
	public function schema_callback() {
		return array(
			'billing_vat_number' => array(
				'description' => 'VAT Number',
				'type'        => 'string',
				'arg_options' => [
					'validate_callback' => function ( $value, $request, $param ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
						$show_vat = ywpi_show_vat_field();
						$vat_req  = ywpi_vat_is_required();

						if ( $show_vat ) {
							$country = $request['billing_address']['country'] ?? '';
							$vat     = $value ?? '';

							if ( ! empty( $vat ) ) {
								$message = YITH_Checkout_Addon::get_instance()->validate_vat_field( $value, $country, false );
								if ( ! empty( $message ) ) {
									$errors = new \WP_Error();
									$errors->add( 'invalid_vat_number', $message );

									return $errors;
								}
							} elseif ( $vat_req ) {
								$errors = new \WP_Error();
								$errors->add( 'vat_number_required', __( 'VAT number is required', 'yith-woocommerce-pdf-invoice' ) );

								return $errors;
							}
						}

						return true;
					},
				],
			),
			'billing_vat_ssn'    => array(
				'description' => 'SSN',
				'type'        => 'string',
				'arg_options' => [
					'validate_callback' => function ( $value, $request, $param ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
						$show_ssn = ywpi_show_ssn_field();
						$ssn_req  = ywpi_ssn_is_required();
						if ( $show_ssn ) {
							$ssn = $value ?? '';
							if ( $ssn_req && empty( $ssn ) ) {
								$errors = new \WP_Error();
								$errors->add( 'ssn_number_required', __( 'SSN number is required', 'yith-woocommerce-pdf-invoice' ) );

								return $errors;
							}
						}

						return true;
					},
				],
			),
		);
	}

	/**
	 * Block schema for electronic fields
	 *
	 * @return array[]
	 * @author YITH
	 */
	public function schema_callback_electronic_fields() {
		return array(
			'billing_receiver_type' => array(
				'description' => 'Customer Type',
				'type'        => 'string',
			),
			'billing_receiver_id'   => array(
				'description' => 'Receiver ID',
				'type'        => 'string',
			),
			'billing_receiver_pec'  => array(
				'description' => 'PEC',
				'type'        => 'string',
			),
		);
	}

	/**
	 * Block schema for invoice type
	 *
	 * @return array[]
	 * @author YITH
	 */
	public function schema_callback_invoice_type() {
		return array(
			'billing_invoice_type' => array(
				'description' => 'Customer Type',
				'type'        => 'string',
			),
		);
	}

	public function schema_callback_document_type() {
		return array(
			'billing_document_type'   => array(
				'description' => 'Document Type',
				'type'        => 'string',
			),
			'billing_document_number' => array(
				'description' => 'Document Number',
				'type'        => 'string',
			)
		);
	}

	/**
	 * Register the block
	 *
	 * @param IntegrationRegistry $integration_registry The registry
	 *
	 * @return void
	 */
	public function register_checkout_blocks( $integration_registry ) {
		require_once YITH_YWPI_INC_DIR.'/blocks/woocommerce/class.yith-ywpi-vat-ssn-checkout-block.php';
		require_once YITH_YWPI_INC_DIR.'/blocks/woocommerce/class.yith-ywpi-electronic-fields-checkout-block.php';
		require_once YITH_YWPI_INC_DIR.'/blocks/woocommerce/class.yith-ywpi-invoice-type-checkout-block.php';
		require_once YITH_YWPI_INC_DIR.'/blocks/woocommerce/class.yith-ywpi-document-type-checkout-block.php';
		$integration_registry->register( new YITH_YWPI_Vat_Ssn_Checkout_Block() );
		$integration_registry->register( new YITH_YWPI_Electronic_Fields_Checkout_Block() );
		$integration_registry->register( new YITH_YWPI_Invoice_Type_Checkout_Block() );
		$integration_registry->register( new YITH_YWPI_Document_Type_Checkout_Block() );
	}

	/**
	 * Add block category
	 *
	 * @param array   $categories Array block categories array.
	 * @param WP_Post $post Current post.
	 *
	 * @return array block categories
	 */
	public function add_block_category( $categories, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		$found_key = array_search( 'yith-blocks', array_column( $categories, 'slug' ), true );
		if ( ! $found_key ) {
			$categories[] = array(
				'slug'  => 'yith-blocks',
				'title' => _x( 'YITH', '[gutenberg]: Category Name', 'yith-plugin-fw' ),
			);
		}

		return $categories;
	}

	/**
	 * Add data attributes to the block
	 *
	 * @param array $allowed_blocks The allowed blocks.
	 *
	 * @return array
	 * @author YITH
	 */
	public function add_vat_ssn_block_data_attributes( $allowed_blocks ) {
		$allowed_blocks[] = 'ywpi/vat-ssn';
		$allowed_blocks[] = 'ywpi/electronic-fields';
		$allowed_blocks[] = 'ywpi/invoice-type';
		$allowed_blocks[] = 'ywpi/document-type';

		return $allowed_blocks;
	}

	/**
	 * Store all custom fields in the order and update user meta
	 *
	 * @param \WC_Order $order The order object.
	 * @param array     $request The request data.
	 *
	 * @throws Exception If validation fails.
	 * @author YITH
	 */
	public function add_pdf_fields_api_update_order( $order, $request ) {
		$meta_to_update = array();
		$show_vat       = ywpi_show_vat_field();
		$show_ssn       = ywpi_show_ssn_field();
		$vat_req        = ywpi_vat_is_required();
		$ssn_req        = ywpi_ssn_is_required();
		$vat_number     = '';
		$ssn_number     = '';
		$customer_type  = $request['extensions']['ywpi/electronic-fields']['billing_receiver_type'] ?? 'private';

		$meta_to_update['billing_receiver_type'] = $customer_type;
		if ( ( $show_vat || $show_ssn ) && isset( $request['extensions']['ywpi/vat-ssn'] ) ) {
			$vat_number = $request['extensions']['ywpi/vat-ssn']['billing_vat_number'] ?? '';
			$ssn_number = $request['extensions']['ywpi/vat-ssn']['billing_vat_ssn'] ?? '';

			if ( $show_vat && $vat_req && empty( $vat_number ) ) {
				throw new Exception( esc_html__( 'VAT number is required', 'yith-woocommerce-pdf-invoice' ) );
			}
			if ( $ssn_req && empty( $ssn_number ) ) {
				throw new Exception( esc_html__( 'SSN number is required', 'yith-woocommerce-pdf-invoice' ) );
			}

			$meta_to_update['billing_vat_number'] = $vat_number;
			$meta_to_update['billing_vat_ssn']    = $ssn_number;
		}

		if ( 'yes' === get_option( 'ywpi_enable_receipts' ) && isset( $request['extensions']['ywpi/invoice-type'] ) ) {

			$invoice_type = $request['extensions']['ywpi/invoice-type']['billing_invoice_type'] ?? 'invoice';

			if ( 'receipt' === $invoice_type && 'company' === $customer_type ) {
				throw new Exception( esc_html__( 'Receipts are not available for companies', 'yith-woocommerce-pdf-invoice' ) );
			}

			$meta_to_update['billing_invoice_type'] = $invoice_type;
		}

		if ( count( $meta_to_update ) > 0 ) {
			$user = $order->get_user();

			foreach ( $meta_to_update as $key => $value ) {
				$order->update_meta_data( '_' . $key, $value );
				if ( $user instanceof WP_User ) {
					update_user_meta( $user->ID, $key, $value );
				}
			}
			$order->save();
		}

		do_action( 'ywpi_after_api_update_order', $order, $request, $vat_number, $ssn_number );
	}

	/**
	 * Update default fields data with block
	 *
	 * This function updates the default fields data with the block data.
	 * It makes the company field visible and not required if electronic invoicing is enabled.
	 *
	 * @return void
	 * @author YITH
	 */
	public function update_default_fields_data_with_block() {
		if ( YITH_YWPI_Modules::is_module_active( 'italian-e-invoicing' ) || YITH_YWPI_Modules::is_module_active( 'spanish-e-invoicing' ) ) {
			$checkout_fields     = Package::container()->get( CheckoutFields::class );
			$asset_data_registry = Package::container()->get( AssetDataRegistry::class );
			$core_field          = $checkout_fields->get_core_fields();
			if ( isset( $core_field['company'] ) ) {
				$core_field['company']['hidden']   = false;
				$core_field['company']['required'] = false;
			}
			$asset_data_registry->add( 'defaultFields', array_merge( $core_field, $checkout_fields->get_additional_fields() ) );
		}
	}

	/**
	 * Auto-inject plugin checkout blocks into the checkout fields area when missing.
	 *
	 * @param array          $parsed_block Parsed block data.
	 * @param array          $source_block Source block data.
	 * @param \WP_Block|null $parent_block Parent block instance.
	 *
	 * @return array
	 */
	public function ensure_checkout_blocks( $parsed_block, $source_block, $parent_block ) {
		if ( empty( $parsed_block['blockName'] ) || 'woocommerce/checkout-fields-block' !== $parsed_block['blockName'] ) {
			return $parsed_block;
		}

		/**
		 * APPLY_FILTERS: ywpi_force_checkout_blocks
		 *
		 * Filters whether plugin checkout blocks should be auto-injected.
		 *
		 * @param bool $force_checkout_blocks Whether to auto-inject the blocks.
		 *
		 * @return bool
		 */
		if ( ! apply_filters( 'ywpi_force_checkout_blocks', true ) ) {
			return $parsed_block;
		}

		$inner_blocks = $parsed_block['innerBlocks'] ?? array();

		$add_invoice_type = ! $this->has_block_in_list( $inner_blocks, 'ywpi/invoice-type' );
		$add_electronic   = ! $this->has_block_in_list( $inner_blocks, 'ywpi/electronic-fields' );
		$add_vat_ssn      = ! $this->has_block_in_list( $inner_blocks, 'ywpi/vat-ssn' );
		$add_document     = ! $this->has_block_in_list( $inner_blocks, 'ywpi/document-type' );

		if ( ! $add_invoice_type && ! $add_electronic && ! $add_vat_ssn && ! $add_document ) {
			return $parsed_block;
		}

		$new_blocks = $inner_blocks;

		if ( $add_invoice_type ) {
			array_unshift( $new_blocks, $this->create_checkout_block( 'ywpi/invoice-type' ) );
		}

		if ( $add_electronic ) {
			array_splice( $new_blocks, 1, 0, array( $this->create_checkout_block( 'ywpi/electronic-fields' ) ) );
		}

		$final_blocks   = array();
		$vat_added      = ! $add_vat_ssn;
		$document_added = ! $add_document;

		foreach ( $new_blocks as $block ) {
			$final_blocks[] = $block;
			$block_name     = $block['blockName'] ?? '';

			if ( 'woocommerce/checkout-billing-address-block' !== $block_name ) {
				continue;
			}

			if ( $add_vat_ssn && ! $vat_added ) {
				$final_blocks[] = $this->create_checkout_block( 'ywpi/vat-ssn' );
				$vat_added      = true;
			}

			if ( $add_document && ! $document_added ) {
				$final_blocks[] = $this->create_checkout_block( 'ywpi/document-type' );
				$document_added = true;
			}
		}

		if ( $add_vat_ssn && ! $vat_added ) {
			$final_blocks[] = $this->create_checkout_block( 'ywpi/vat-ssn' );
		}

		if ( $add_document && ! $document_added ) {
			$final_blocks[] = $this->create_checkout_block( 'ywpi/document-type' );
		}

		/**
		 * APPLY_FILTERS: ywpi_checkout_fields_inner_blocks
		 *
		 * Filters the checkout fields inner blocks after auto-injection.
		 *
		 * @param array $final_blocks The inner blocks list.
		 * @param array $parsed_block The parsed checkout fields block.
		 *
		 * @return array
		 */
		$final_blocks = apply_filters( 'ywpi_checkout_fields_inner_blocks', $final_blocks, $parsed_block );

		return $this->sync_inner_content_for_parent( $parsed_block, $final_blocks );
	}

	/**
	 * Check if a block exists in the given list.
	 *
	 * @param array  $blocks The blocks list.
	 * @param string $block_name The block name.
	 *
	 * @return bool
	 */
	private function has_block_in_list( $blocks, $block_name ) {
		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] ?? '' ) === $block_name ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Create a parsed block structure for checkout injection.
	 *
	 * @param string $block_name The block name.
	 *
	 * @return array
	 */
	private function create_checkout_block( $block_name ) {
		$class = 'wp-block-' . str_replace( '/', '-', $block_name );
		$html  = sprintf(
			'<div data-block-name="%1$s" class="%2$s"></div>',
			esc_attr( $block_name ),
			esc_attr( $class )
		);

		return array(
			'blockName'    => $block_name,
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}

	/**
	 * Sync parent innerContent placeholders with the updated innerBlocks list.
	 *
	 * @param array $parsed_block The parent parsed block.
	 * @param array $inner_blocks The updated inner blocks.
	 *
	 * @return array
	 */
	/**
	 * Inject block CSS into the block editor canvas (iframed in WP 6.x).
	 *
	 * @param array $settings Block editor settings.
	 *
	 * @return array
	 */
	public function inject_editor_styles( $settings ) {
		$css_path = YITH_YWPI_ASSETS_DIR . '/css/wc-blocks/frontend.css';
		if ( file_exists( $css_path ) ) {
			$settings['styles'][] = array(
				'css' => file_get_contents( $css_path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			);
		}

		return $settings;
	}

	private function sync_inner_content_for_parent( $parsed_block, $inner_blocks ) {
		$original_inner_content = $parsed_block['innerContent'] ?? array();
		$opening_tag            = null;
		$closing_tag            = null;
		$has_wrapper            = false;

		if ( ! empty( $original_inner_content ) && is_string( $original_inner_content[0] ) && '' !== trim( $original_inner_content[0] ) ) {
			$opening_tag = $original_inner_content[0];
			$has_wrapper = true;
		}

		$last_index = count( $original_inner_content ) - 1;
		if ( $last_index > 0 && is_string( $original_inner_content[ $last_index ] ) && '' !== trim( $original_inner_content[ $last_index ] ) ) {
			$closing_tag = $original_inner_content[ $last_index ];
			$has_wrapper = true;
		}

		if ( $has_wrapper && $opening_tag && $closing_tag ) {
			$parsed_block['innerContent'] = array_merge(
				array( $opening_tag ),
				array_fill( 0, count( $inner_blocks ), null ),
				array( $closing_tag )
			);
		} else {
			$parsed_block['innerContent'] = array_fill( 0, count( $inner_blocks ), null );
		}

		$parsed_block['innerBlocks'] = $inner_blocks;

		return $parsed_block;
	}
}
