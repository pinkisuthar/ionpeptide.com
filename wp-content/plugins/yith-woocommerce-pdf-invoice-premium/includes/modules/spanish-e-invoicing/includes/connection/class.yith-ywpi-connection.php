<?php
/**
 * Verifacti connection service for the Spanish Electronic Invoice module.
 *
 * @package YITH\PDFInvoice
 */

defined( 'ABSPATH' ) || exit;


use Bluehost\VerifactiApi\Client\ClientFactory;
use Bluehost\VerifactiApi\Dto\ApiResponse;

class YITH_YWPI_Connection {

	const OPTION_ENV = 'ywpi_spanish_electronic_invoice_env';
	const OPTION_TEST_API_KEY = 'ywpi_spanish_electronic_invoice_test_api_key';
	const OPTION_PRODUCTION_API_KEY = 'ywpi_spanish_electronic_invoice_production_api_key';
	const OPTION_NIF = 'ywpi_spanish_electronic_invoice_nif';
	const OPTION_CONNECTION_STATUS = 'ywpi_spanish_electronic_invoice_connection_status';
	const OPTION_CONNECTION_MESSAGE = 'ywpi_spanish_electronic_invoice_connection_message';
	const OPTION_CONNECTION_CHECKED_AT = 'ywpi_spanish_electronic_invoice_connection_checked_at';

	const STATUS_CONNECTED = 'connected';
	const STATUS_DISCONNECTED = 'disconnected';
	const STATUS_MISSING_KEY = 'missing_key';

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

		return self::$instance;
	}

	/**
	 * The constructor
	 */
	private function __construct() {}

	/**
	 * Get the selected environment.
	 *
	 * @return string
	 */
	public function get_environment() {
		$environment = sanitize_key( (string) ywpi_get_option( self::OPTION_ENV, null, 'test' ) );

		return 'production' === $environment ? 'production' : 'test';
	}

	/**
	 * Get the API key for an environment.
	 *
	 * @param string|null $environment The environment.
	 *
	 * @return string
	 */
	public function get_api_key( $environment = null ) {
		$option = $this->get_api_key_option_name( $environment );

		return trim( (string) ywpi_get_option( $option, null, '' ) );
	}

	/**
	 * Get the saved NIF.
	 *
	 * @param string|null $environment The environment.
	 *
	 * @return string
	 */
	public function get_saved_nif( $environment = null ) {
		$option_name = null === $environment ? self::OPTION_NIF : $this->get_environment_option_name( 'nif', $environment );

		return trim( (string) get_option( $option_name, '' ) );
	}

	/**
	 * Get the saved connection summary.
	 *
	 * @param string|null $environment The environment.
	 *
	 * @return array
	 */
	public function get_connection_summary( $environment = null ) {
		$resolved_environment = null === $environment ? $this->get_environment() : $this->normalize_environment( $environment );
		$status_option        = null === $environment ? self::OPTION_CONNECTION_STATUS : $this->get_environment_option_name( 'connection_status', $resolved_environment );
		$message_option       = null === $environment ? self::OPTION_CONNECTION_MESSAGE : $this->get_environment_option_name( 'connection_message', $resolved_environment );
		$checked_at_option    = null === $environment ? self::OPTION_CONNECTION_CHECKED_AT : $this->get_environment_option_name( 'connection_checked_at', $resolved_environment );
		$status               = (string) get_option( $status_option, '' );
		$message              = (string) get_option( $message_option, '' );
		$checked_at           = (string) get_option( $checked_at_option, '' );
		$nif                  = $this->get_saved_nif( $environment );

		if ( '' === $message ) {
			$message = '' === $this->get_api_key( $resolved_environment )
				? __( 'Add an API key and save the settings to check the connection.', 'yith-woocommerce-pdf-invoice' )
				: __( 'Save the settings to check the connection.', 'yith-woocommerce-pdf-invoice' );
		}

		return array(
			'environment' => $resolved_environment,
			'status'      => $status,
			'connected'   => self::STATUS_CONNECTED === $status,
			'message'     => $message,
			'nif'         => $nif,
			'checked_at'  => $checked_at,
		);
	}

	/**
	 * Check the current environment and persist the result.
	 *
	 * @param string|null $environment The environment.
	 *
	 * @return array
	 */
	public function sync_connection_data( $environment = null ) {
		$environment = $this->normalize_environment( $environment );
		$api_key     = $this->get_api_key( $environment );

		if ( '' === $api_key ) {
			return $this->clear_connection_data( $environment );
		}

		try {
			$client       = ClientFactory::fromApiKey(
				$api_key,
				array(
					'environment' => $environment,
				)
			);
			$health       = $client->healthCheck();


			$health_state = '';
			$nif          = $this->extract_nif( $health );

			if ( method_exists( $health, 'getHealthStatus' ) ) {
				$health_state = (string) $health->getHealthStatus();
			}

			$result = array(
				'environment'   => $environment,
				'status'        => self::STATUS_CONNECTED,
				'connected'     => true,
				'nif'           => $nif,
				'health_status' => $health_state,
				'message'       => '' !== $nif
					? __( 'Connection successful and NIF retrieved from Verifacti.', 'yith-woocommerce-pdf-invoice' )
					: __( 'Connection successful, but Verifacti did not return a NIF in the declaration response.', 'yith-woocommerce-pdf-invoice' ),
			);
		} catch ( \Throwable $exception ) {
			$result = array(
				'environment'   => $environment,
				'status'        => self::STATUS_DISCONNECTED,
				'connected'     => false,
				'nif'           => '',
				'health_status' => '',
				'message'       => sanitize_text_field( $exception->getMessage() ),
			);
		}

		return $this->persist_connection_result( $result );
	}

	/**
	 * Clear saved connection data for an environment.
	 *
	 * @param string|null $environment The environment.
	 *
	 * @return array
	 */
	public function clear_connection_data( $environment = null ) {
		return $this->persist_connection_result(
			array(
				'environment'   => $this->normalize_environment( $environment ),
				'status'        => self::STATUS_MISSING_KEY,
				'connected'     => false,
				'nif'           => '',
				'health_status' => '',
				'message'       => __( 'Add an API key and save the settings to check the connection.', 'yith-woocommerce-pdf-invoice' ),
			)
		);
	}

	/**
	 * Persist a connection result.
	 *
	 * @param array $result The connection result.
	 *
	 * @return array
	 */
	private function persist_connection_result( $result ) {
		$environment = $this->normalize_environment( $result['environment'] ?? null );
		$checked_at  = gmdate( 'c' );
		$nif         = isset( $result['nif'] ) ? (string) $result['nif'] : '';
		$status      = isset( $result['status'] ) ? (string) $result['status'] : self::STATUS_DISCONNECTED;
		$message     = isset( $result['message'] ) ? (string) $result['message'] : '';

		ywpi_update_option( $this->get_environment_option_name( 'nif', $environment ), $nif, 0 );
		ywpi_update_option( $this->get_environment_option_name( 'connection_status', $environment ), $status, 0 );
		ywpi_update_option( $this->get_environment_option_name( 'connection_message', $environment ), $message, 0 );
		ywpi_update_option( $this->get_environment_option_name( 'connection_checked_at', $environment ), $checked_at, 0 );

		if ( $environment === $this->get_environment() ) {
			ywpi_update_option( self::OPTION_NIF, $nif, 0 );
			ywpi_update_option( self::OPTION_CONNECTION_STATUS, $status, 0 );
			ywpi_update_option( self::OPTION_CONNECTION_MESSAGE, $message, 0 );
			ywpi_update_option( self::OPTION_CONNECTION_CHECKED_AT, $checked_at, 0 );
		}

		$result['environment'] = $environment;
		$result['nif']         = $nif;
		$result['status']      = $status;
		$result['message']     = $message;
		$result['checked_at']  = $checked_at;

		return $result;
	}

	/**
	 * Extract the NIF from the declaration payload.
	 *
	 * @param ApiResponse $response The declaration response.
	 *
	 * @return string
	 */
	private function extract_nif( ApiResponse $response ) {

		return $this->find_nif_in_array( $response->getData() );
	}

	/**
	 * Recursively search for a NIF-like value.
	 *
	 * @param array $data The payload.
	 *
	 * @return string
	 */
	private function find_nif_in_array( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$candidate = $this->find_nif_in_array( $value );

				if ( '' !== $candidate ) {
					return $candidate;
				}

				continue;
			}

			if ( ! is_string( $key ) || ! $this->is_nif_like_key( $key ) ) {
				continue;
			}

			$candidate = $this->normalize_nif_candidate( $value );

			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Normalize a candidate NIF.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string
	 */
	private function normalize_nif_candidate( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$candidate = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $value ) );

		if ( ! $this->is_valid_nif_candidate( $candidate ) ) {
			return '';
		}

		return $candidate;
	}

	/**
	 * Check if a string looks like a NIF/CIF.
	 *
	 * @param string $candidate The raw candidate.
	 *
	 * @return bool
	 */
	private function is_valid_nif_candidate( $candidate ) {
		if ( '' === $candidate ) {
			return false;
		}

		$length = strlen( $candidate );

		return $length >= 7 && $length <= 16 && 1 === preg_match( '/^[A-Z0-9]+$/', $candidate );
	}

	/**
	 * Check if an array key may contain a NIF.
	 *
	 * @param string $key The array key.
	 *
	 * @return bool
	 */
	private function is_nif_like_key( $key ) {
		return 1 === preg_match( '/(^|_|-)(nif|cif|vat|vat_id|tax_id|tin)(_|-|$)/i', $key );
	}

	/**
	 * Get the API key option name.
	 *
	 * @param string|null $environment The environment.
	 *
	 * @return string
	 */
	private function get_api_key_option_name( $environment = null ) {
		return 'production' === $this->normalize_environment( $environment ) ? self::OPTION_PRODUCTION_API_KEY : self::OPTION_TEST_API_KEY;
	}

	/**
	 * Build an option name for a specific environment.
	 *
	 * @param string $suffix The option suffix.
	 * @param string $environment The environment.
	 *
	 * @return string
	 */
	private function get_environment_option_name( $suffix, $environment ) {
		return sprintf( 'ywpi_spanish_electronic_invoice_%s_%s', $this->normalize_environment( $environment ), $suffix );
	}

	/**
	 * Normalize an environment identifier.
	 *
	 * @param string|null $environment The environment.
	 *
	 * @return string
	 */
	private function normalize_environment( $environment = null ) {
		$environment = sanitize_key( (string) ( $environment ?: $this->get_environment() ) );

		return 'production' === $environment ? 'production' : 'test';
	}
}
