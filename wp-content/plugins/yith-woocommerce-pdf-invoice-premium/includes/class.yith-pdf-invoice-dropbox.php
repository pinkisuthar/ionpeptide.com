<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * YITH PDF Invoice Dropbox class.
 *
 * Handles the invoice details.
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\PDFInvoice\Classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'YITH_PDF_Invoice_DropBox' ) ) {
	/**
	 * Class that handle the Dropbox feature.
	 *
	 * @class   YITH_PDF_Invoice_DropBox
	 * @package YITH\PDFInvoice\Classes
	 * @since   1.5.4
	 */
	class YITH_PDF_Invoice_DropBox {

		/**
		 * Dropbox redirect uri.
		 *
		 * @var string $dropbox_redirect_uri
		 */
		public $dropbox_redirect_uri = 'https://update.yithemes.com/dropbox-apps/authorize-dropbox-api-v2.php?app=pdf-invoice';

		/**
		 * Dropbox app key.
		 *
		 * @var string $dropbox_app_key
		 */
		public $dropbox_app_key = '58dmyrhs688d3zs';

		/**
		 * Single instance of the class
		 *
		 * @var object
		 */
		protected static $instance;

		/**
		 * Returns single instance of the class
		 */
		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}


		/**
		 * Return the auth uri
		 *
		 * @return string
		 */
		public function get_auth_uri() {
			$url = 'https://www.dropbox.com/oauth2/authorize';

			return add_query_arg(
				[
					'client_id'         => $this->dropbox_app_key,
					'response_type'     => 'code',
					'redirect_uri'      => $this->dropbox_redirect_uri,
					'state'             => $this->encrypt_state(),
					'token_access_type' => 'offline',
				],
				$url
			);
		}

		protected function encrypt_state() {
			$admin_url = admin_url( 'admin.php' );
			$args      = [
				'action' => 'yith_ywpi_dropbox_onboarding',
				'page'   => 'yith_woocommerce_pdf_invoice_panel',
			];
			$admin_url = rawurlencode( add_query_arg( $args, $admin_url ) );
			$key       = hash( 'sha256', $this->dropbox_app_key, true );
			$iv_length = openssl_cipher_iv_length( 'AES-256-CBC' );
			$iv        = openssl_random_pseudo_bytes( $iv_length );

			$ciphertext = openssl_encrypt( $admin_url, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
			if ( $ciphertext === false ) {
				throw new \Exception( 'Encryption error' );
			}

			// Combine IV +  encrypted test and base64 code.
			$state = base64_encode( $iv . $ciphertext );

			return urlencode( $state );
		}



		/**
		 * Retrieve access token starting from an authorization code
		 */
		public function get_dropbox_access_token() {
			$token         = get_transient( 'ywpi_dropbox_key' );
			$refresh_token = get_option( 'ywpi_refresh_token', false );
			if ( false === $token || ! $this->is_user_valid( $token ) ) {
				if ( false !== $refresh_token ) {
					$token_request_url = add_query_arg( [ 'action' => 'refresh_token', 'refresh_token' => $refresh_token ], $this->dropbox_redirect_uri );

					$response = wp_remote_get( $token_request_url );

					if ( is_wp_error( $response ) ) {
						error_log( 'Error on refresh token : ' . $response->get_error_message() );

						return false;
					} else {
						$body = json_decode( wp_remote_retrieve_body( $response ), ARRAY_A );
						if ( isset( $body['access_token'] ) ) {
							$token = $body['access_token'];
							set_transient( 'ywpi_dropbox_key', $body['access_token'], $body['expires_in'] );
						}

					}
				}
			}

			return $token;
		}

		/**
		 * Check if the token is valid
		 *
		 * @param string $token The token.
		 *
		 * @return false|string
		 */
		public function is_user_valid( $token ) {
			$url      = 'https://api.dropboxapi.com/2/users/get_current_account';
			$args     = array(
				'headers' => array(
					'Content-Type'  => 'text/plain; charset=dropbox-cors-hack',
					// The Authorization header containing the Bearer token
					'Authorization' => 'Bearer ' . $token,
				),
				'method'  => 'POST',
				'timeout' => 45,// Recommended for external POSTs
				'body'    => wp_json_encode( null ),
			);
			$response = wp_remote_post( $url, $args );

			if ( is_wp_error( $response ) ) {
				error_log( 'Error on getting user info : ' . $response->get_error_message() );

				return false;
			} else {
				return isset( $response['account_id'] ) && isset( $response['disabled'] ) && ! $response['disabled'];
			}

		}


		/**
		 * Upload document to dropbox, if access token is valid
		 *
		 * @param object $document The document object.
		 */
		public function send_document_to_dropbox( $document ) {
			$access_token = $this->get_dropbox_access_token();
			if ( ! $access_token ) {
				error_log( esc_html__( 'Error: no Access Token.', 'yith-woocommerce-pdf-invoice' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return;
			}


			if ( file_exists( $document->get_full_path() ) ) {
				/**
				 * APPLY_FILTERS: ywpi_dropbox_folder
				 *
				 * Filter the DropBox folder to save the documents.
				 *
				 * @param string folder name.
				 * @param object $document the document object.
				 *
				 * @return string
				 */
				$doc_full_path = $document->get_full_path();
				$doc_folder    = apply_filters( 'ywpi_dropbox_folder', ywpi_get_option( 'ywpi_dropbox_folder' ), $document );
				$doc_path      = $document->save_path;
				$file          = file_get_contents( $doc_full_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$date_val      = strtotime( $document->order->get_date_created() );
				$date          = getdate( $date_val );

				$doc_folder = str_replace(
					array(
						'[year]',
						'[month]',
						'[day]',
					),
					array(
						$date['year'],
						sprintf( '%02d', $date['mon'] ),
						sprintf( '%02d', $date['mday'] ),
					),
					$doc_folder
				);

				$ch = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init

				// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt
				curl_setopt( $ch, CURLOPT_URL, 'https://content.dropboxapi.com/2/files/upload' );
				curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $file );
				curl_setopt( $ch, CURLOPT_POST, 1 );

				$headers   = array();
				$headers[] = "Authorization: Bearer $access_token";
				$headers[] = "Dropbox-Api-Arg: {\"path\": \"/$doc_folder/$doc_path\",\"mode\": \"overwrite\",\"autorename\": true,\"mute\": false}";
				$headers[] = 'Content-Type: application/octet-stream';
				curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );

				// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_setopt

				$result = curl_exec( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec

				if ( curl_errno( $ch ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno
					error_log( esc_html__( 'Error: unable to send file to Dropbox.', 'yith-woocommerce-pdf-invoice' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'Error:' . curl_error( $ch ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error, WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}

				curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
			}
		}
	}
}
