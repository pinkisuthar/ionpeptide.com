<?php
/**
 * Plugin Name: Ion Affiliate Compliance Flow
 * Description: Two-step Ion Peptide affiliate agreement/application flow with immutable agreement storage and YITH WooCommerce Affiliates Premium integration.
 * Version: 1.0.9
 * Author: BuildRight / Ion Peptide
 * Text Domain: ion-affiliate-compliance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ion_Affiliate_Compliance_Flow {
	const VERSION       = '1.0.9';
	const DB_VERSION    = '1.0.0';
	const TERMS_VERSION = 'Ion Affiliate Guidelines - 2026-06-10';
	const COOKIE_NAME   = 'ion_affiliate_agreement_key';
	const ENDPOINT      = 'affiliate-dashboard';

	private static $instance = null;
	private $messages = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'wp_head', array( $this, 'print_affiliate_dashboard_page_styles' ), 40 );
		add_action( 'template_redirect', array( $this, 'handle_frontend_posts' ), 1 );
		add_action( 'template_redirect', array( $this, 'maybe_redirect_existing_affiliate' ), 2 );

		add_shortcode( 'ion_affiliate_agreement', array( $this, 'shortcode_agreement' ) );
		add_shortcode( 'ion_affiliate_application', array( $this, 'shortcode_application' ) );
		add_shortcode( 'ion_affiliate_account', array( $this, 'shortcode_account' ) );

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_post_ion_affiliate_export', array( $this, 'admin_export_csv' ) );
		add_action( 'admin_post_ion_affiliate_admin_action', array( $this, 'admin_action' ) );

		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_my_account_menu_item' ), 40 );
		add_filter( 'woocommerce_get_endpoint_url', array( $this, 'route_my_account_affiliate_menu_to_dashboard_page' ), 20, 4 );
		add_action( 'template_redirect', array( $this, 'redirect_my_account_affiliate_endpoint_to_dashboard_page' ), 0 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_my_account_endpoint' ) );

		add_action( 'yith_wcaf_affiliate_enabled', array( $this, 'sync_yith_approval' ), 10, 2 );
		add_action( 'yith_wcaf_affiliate_disabled', array( $this, 'sync_yith_denial' ), 10, 2 );
	}

	public static function activate() {
		self::create_tables();
		self::create_default_pages();
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	public function init() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	private static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'ion_affiliate_agreements';
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			agreement_key varchar(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			affiliate_id bigint(20) unsigned NOT NULL DEFAULT 0,
			full_name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			social_profiles longtext NULL,
			about_you longtext NULL,
			requested_code varchar(90) NOT NULL DEFAULT '',
			application_status varchar(40) NOT NULL DEFAULT 'agreement_accepted',
			terms_version varchar(120) NOT NULL DEFAULT '',
			terms_hash varchar(64) NOT NULL DEFAULT '',
			agreement_snapshot longtext NULL,
			signature_name varchar(190) NOT NULL DEFAULT '',
			accepted_at datetime NULL,
			submitted_at datetime NULL,
			decision_at datetime NULL,
			ip_address varchar(100) NOT NULL DEFAULT '',
			user_agent text NULL,
			admin_notes longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY agreement_key (agreement_key),
			KEY email (email),
			KEY user_id (user_id),
			KEY affiliate_id (affiliate_id),
			KEY application_status (application_status)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( 'ion_affiliate_compliance_db_version', self::DB_VERSION );
	}

	private static function create_default_pages() {
		$pages = array(
			'affiliate-agreement'   => array(
				'title'   => 'Affiliate Agreement',
				'content' => '[ion_affiliate_agreement]',
				'option'  => 'ion_affiliate_agreement_page_id',
			),
			'affiliate-application' => array(
				'title'   => 'Affiliate Application',
				'content' => '[ion_affiliate_application]',
				'option'  => 'ion_affiliate_application_page_id',
			),
			'affiliate-dashboard'   => array(
				'title'   => 'Affiliate Dashboard',
				'content' => '[ion_affiliate_account]',
				'option'  => 'ion_affiliate_dashboard_page_id',
			),
		);

		foreach ( $pages as $slug => $page ) {
			$existing = get_page_by_path( $slug );

			if ( $existing ) {
				update_option( $page['option'], (int) $existing->ID );
				continue;
			}

			$page_id = wp_insert_post(
				array(
					'post_title'   => $page['title'],
					'post_name'    => $slug,
					'post_content' => $page['content'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);

			if ( ! is_wp_error( $page_id ) ) {
				update_option( $page['option'], (int) $page_id );
			}
		}
	}

	private static function agreement_html() {
		$path = plugin_dir_path( __FILE__ ) . 'templates/agreement-html.php';
		if ( file_exists( $path ) ) {
			$html = include $path;
			return is_string( $html ) ? $html : '';
		}

		return '<h2>Affiliate Guidelines</h2><p>Agreement content file missing. Reinstall the plugin.</p>';
	}

	private static function agreement_text() {
		$text = wp_strip_all_tags( self::agreement_html() );
		$text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) );
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( $text );
	}

	private static function terms_hash() {
		return hash( 'sha256', self::agreement_text() );
	}

	private function get_ip_address() {
		$keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );

		foreach ( $keys as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			$value = explode( ',', $value )[0];
			$value = trim( $value );

			if ( filter_var( $value, FILTER_VALIDATE_IP ) ) {
				return $value;
			}
		}

		return '';
	}

	private function get_user_agent() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_textarea_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	}

	private function get_application_url( $key = '' ) {
		$page_id = absint( get_option( 'ion_affiliate_application_page_id' ) );
		$url     = $page_id ? get_permalink( $page_id ) : home_url( '/affiliate-application/' );

		if ( $key ) {
			$url = add_query_arg( 'agreement', rawurlencode( $key ), $url );
		}

		return $url;
	}

	private function get_agreement_url() {
		$page_id = absint( get_option( 'ion_affiliate_agreement_page_id' ) );
		return $page_id ? get_permalink( $page_id ) : home_url( '/affiliate-agreement/' );
	}

	private function get_dashboard_url() {
		$page_id = absint( get_option( 'ion_affiliate_dashboard_page_id' ) );
		return $page_id ? get_permalink( $page_id ) : home_url( '/affiliate-dashboard/' );
	}


	private function is_affiliate_page( $option_name, $fallback_slug ) {
		$page_id = absint( get_option( $option_name ) );

		if ( $page_id && is_page( $page_id ) ) {
			return true;
		}

		return is_page( $fallback_slug );
	}

	private function is_affiliate_agreement_page() {
		return $this->is_affiliate_page( 'ion_affiliate_agreement_page_id', 'affiliate-agreement' );
	}

	private function is_affiliate_application_page() {
		return $this->is_affiliate_page( 'ion_affiliate_application_page_id', 'affiliate-application' );
	}

	private function is_affiliate_dashboard_page() {
		return $this->is_affiliate_page( 'ion_affiliate_dashboard_page_id', 'affiliate-dashboard' );
	}

	private function get_latest_record_for_user( $user_id = 0 ) {
		global $wpdb;

		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		if ( ! $user_id ) {
			return null;
		}

		$user  = get_user_by( 'id', $user_id );
		$email = ( $user && ! empty( $user->user_email ) ) ? $user->user_email : '';

		if ( $email ) {
			$record = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . self::table_name() . ' WHERE user_id = %d OR email = %s ORDER BY updated_at DESC, id DESC LIMIT 1',
					$user_id,
					$email
				)
			);
		} else {
			$record = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . self::table_name() . ' WHERE user_id = %d ORDER BY updated_at DESC, id DESC LIMIT 1',
					$user_id
				)
			);
		}

		if ( $record && empty( $record->user_id ) ) {
			$wpdb->update(
				self::table_name(),
				array(
					'user_id'    => $user_id,
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => (int) $record->id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
			$record->user_id = $user_id;
		}

		return $record;
	}

	private function record_has_current_terms( $record ) {
		if ( ! $record ) {
			return false;
		}

		return isset( $record->terms_hash, $record->terms_version )
			&& hash_equals( self::terms_hash(), (string) $record->terms_hash )
			&& self::TERMS_VERSION === (string) $record->terms_version;
	}

	private function redirect_for_existing_record( $record ) {
		if ( ! $record ) {
			wp_safe_redirect( $this->get_dashboard_url() );
			exit;
		}

		if ( 'agreement_accepted' === $record->application_status ) {
			if ( $this->user_has_enabled_yith_affiliate( (int) $record->user_id ) ) {
				$this->mark_existing_affiliate_agreement_approved( $record->agreement_key, (int) $record->user_id );
				wp_safe_redirect( $this->get_dashboard_url() );
				exit;
			}

			wp_safe_redirect( $this->get_application_url( $record->agreement_key ) );
			exit;
		}

		wp_safe_redirect( $this->get_dashboard_url() );
		exit;
	}

	public function maybe_redirect_existing_affiliate() {
		if ( is_admin() || wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}

		$is_agreement_page   = $this->is_affiliate_agreement_page();
		$is_application_page = $this->is_affiliate_application_page();

		if ( ! $is_agreement_page && ! $is_application_page ) {
			return;
		}

		$record = $this->get_latest_record_for_user();

		if ( ! $record ) {
			return;
		}

		if ( $is_agreement_page && $this->record_has_current_terms( $record ) ) {
			$this->redirect_for_existing_record( $record );
		}

		if ( $is_application_page && ! $this->record_has_current_terms( $record ) ) {
			wp_safe_redirect( $this->get_agreement_url() );
			exit;
		}

		if ( $is_application_page && in_array( $record->application_status, array( 'submitted', 'approved', 'denied' ), true ) ) {
			wp_safe_redirect( $this->get_dashboard_url() );
			exit;
		}
	}

	private function create_agreement_record( $full_name, $email, $signature_name ) {
		global $wpdb;

		$key = wp_generate_password( 40, false, false );
		$now = current_time( 'mysql' );

		$wpdb->insert(
			self::table_name(),
			array(
				'agreement_key'      => $key,
				'user_id'            => (int) get_current_user_id(),
				'full_name'          => $full_name,
				'email'              => $email,
				'application_status' => 'agreement_accepted',
				'terms_version'      => self::TERMS_VERSION,
				'terms_hash'         => self::terms_hash(),
				'agreement_snapshot' => self::agreement_text(),
				'signature_name'     => $signature_name,
				'accepted_at'        => $now,
				'ip_address'         => $this->get_ip_address(),
				'user_agent'         => $this->get_user_agent(),
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $wpdb->insert_id ? $key : false;
	}

	private function get_record_by_key( $key ) {
		global $wpdb;
		$key = sanitize_text_field( $key );

		if ( ! $key ) {
			return null;
		}

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE agreement_key = %s LIMIT 1', $key ) );
	}

	private function get_record_by_id( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d LIMIT 1', absint( $id ) ) );
	}

	private function get_current_agreement_key() {
		if ( isset( $_GET['agreement'] ) ) {
			return sanitize_text_field( wp_unslash( $_GET['agreement'] ) );
		}

		if ( isset( $_POST['ion_agreement_key'] ) ) {
			return sanitize_text_field( wp_unslash( $_POST['ion_agreement_key'] ) );
		}

		if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		}

		return '';
	}

	private function set_agreement_cookie( $key ) {
		$key = sanitize_text_field( $key );

		if ( ! headers_sent() && $key ) {
			setcookie( self::COOKIE_NAME, $key, time() + DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
			$_COOKIE[ self::COOKIE_NAME ] = $key;
		}
	}

	private function clear_agreement_cookie() {
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE_NAME, '', time() - HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		}
		unset( $_COOKIE[ self::COOKIE_NAME ] );
	}

	public function handle_frontend_posts() {
		if ( empty( $_POST['ion_affiliate_action'] ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['ion_affiliate_action'] ) );

		if ( 'accept_agreement' === $action ) {
			$this->handle_accept_agreement();
			return;
		}

		if ( 'submit_application' === $action ) {
			$this->handle_submit_application();
			return;
		}
	}

	private function handle_accept_agreement() {
		if ( ! isset( $_POST['ion_affiliate_agreement_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ion_affiliate_agreement_nonce'] ) ), 'ion_affiliate_accept_agreement' ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'ion-affiliate-compliance' ) );
		}

		if ( is_user_logged_in() ) {
			$existing_record = $this->get_latest_record_for_user();
			if ( $this->record_has_current_terms( $existing_record ) ) {
				$this->redirect_for_existing_record( $existing_record );
			}
		}

		$full_name      = isset( $_POST['full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['full_name'] ) ) : '';
		$email          = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$signature_name = isset( $_POST['signature_name'] ) ? sanitize_text_field( wp_unslash( $_POST['signature_name'] ) ) : '';
		$agree_terms    = ! empty( $_POST['agree_terms'] );
		$agree_ftc      = ! empty( $_POST['agree_ftc'] );
		$agree_ruo      = ! empty( $_POST['agree_ruo'] );

		$errors = array();
		if ( ! $full_name ) {
			$errors[] = 'Full legal name is required.';
		}
		if ( ! is_email( $email ) ) {
			$errors[] = 'A valid email address is required.';
		}
		if ( ! $signature_name ) {
			$errors[] = 'Digital signature is required.';
		}
		if ( ! $agree_terms || ! $agree_ftc || ! $agree_ruo ) {
			$errors[] = 'All agreement confirmation boxes are required.';
		}

		if ( $errors ) {
			$this->store_flash( 'error', implode( ' ', $errors ) );
			wp_safe_redirect( $this->get_agreement_url() );
			exit;
		}

		$key = $this->create_agreement_record( $full_name, $email, $signature_name );

		if ( ! $key ) {
			$this->store_flash( 'error', 'The agreement could not be stored. Please try again.' );
			wp_safe_redirect( $this->get_agreement_url() );
			exit;
		}

		if ( is_user_logged_in() && $this->user_has_enabled_yith_affiliate() ) {
			$this->mark_existing_affiliate_agreement_approved( $key, get_current_user_id() );
			$this->clear_agreement_cookie();
			wp_safe_redirect( $this->get_dashboard_url() );
			exit;
		}

		$this->set_agreement_cookie( $key );
		wp_safe_redirect( $this->get_application_url( $key ) );
		exit;
	}

	private function handle_submit_application() {
		if ( ! isset( $_POST['ion_affiliate_application_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ion_affiliate_application_nonce'] ) ), 'ion_affiliate_submit_application' ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'ion-affiliate-compliance' ) );
		}

		$key    = $this->get_current_agreement_key();
		$record = $this->get_record_by_key( $key );

		if ( ! $record ) {
			$this->store_flash( 'error', 'Agreement record not found. Please accept the agreement first.' );
			wp_safe_redirect( $this->get_agreement_url() );
			exit;
		}

		$full_name       = isset( $_POST['full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['full_name'] ) ) : $record->full_name;
		$email           = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : $record->email;
		$social_profiles = isset( $_POST['social_profiles'] ) ? sanitize_textarea_field( wp_unslash( $_POST['social_profiles'] ) ) : '';
		$about_you       = isset( $_POST['about_you'] ) ? sanitize_textarea_field( wp_unslash( $_POST['about_you'] ) ) : '';
		$requested_code  = isset( $_POST['requested_code'] ) ? sanitize_title( wp_unslash( $_POST['requested_code'] ) ) : '';
		$confirm         = ! empty( $_POST['application_confirm'] );

		$errors = array();
		if ( ! $full_name ) {
			$errors[] = 'Name is required.';
		}
		if ( ! is_email( $email ) ) {
			$errors[] = 'A valid email is required.';
		}
		if ( ! $social_profiles ) {
			$errors[] = 'Social media links are required.';
		}
		if ( ! $about_you ) {
			$errors[] = 'The about you field is required.';
		}
		if ( ! $requested_code ) {
			$errors[] = 'Requested code is required.';
		}
		if ( ! $confirm ) {
			$errors[] = 'Final confirmation is required.';
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$existing = get_user_by( 'email', $email );
			if ( $existing ) {
				$errors[] = 'An account already exists with that email. Please log in first, then return to this application link.';
			}
		}

		if ( $errors ) {
			$this->store_flash( 'error', implode( ' ', $errors ) );
			wp_safe_redirect( $this->get_application_url( $key ) );
			exit;
		}

		if ( ! $user_id ) {
			$user_id = $this->create_affiliate_user( $full_name, $email );
			if ( is_wp_error( $user_id ) ) {
				$this->store_flash( 'error', $user_id->get_error_message() );
				wp_safe_redirect( $this->get_application_url( $key ) );
				exit;
			}
		}

		$affiliate_id = $this->create_or_update_yith_affiliate( $user_id, $full_name, $email, $social_profiles, $about_you, $requested_code, $record->id );

		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array(
				'user_id'            => (int) $user_id,
				'affiliate_id'       => (int) $affiliate_id,
				'full_name'          => $full_name,
				'email'              => $email,
				'social_profiles'    => $social_profiles,
				'about_you'          => $about_you,
				'requested_code'     => $requested_code,
				'application_status' => 'submitted',
				'submitted_at'       => current_time( 'mysql' ),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( 'id' => (int) $record->id ),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		$this->send_application_emails( $record->id, $full_name, $email, $requested_code );
		$this->clear_agreement_cookie();

		if ( is_user_logged_in() ) {
			wp_safe_redirect( add_query_arg( 'ion_affiliate_submitted', '1', $this->get_dashboard_url() ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'ion_affiliate_submitted', '1', $this->get_application_url() ) );
		exit;
	}

	private function create_affiliate_user( $full_name, $email ) {
		$username_base = sanitize_user( current( explode( '@', $email ) ), true );
		if ( ! $username_base ) {
			$username_base = sanitize_user( preg_replace( '/[^a-z0-9]+/i', '', $full_name ), true );
		}
		if ( ! $username_base ) {
			$username_base = 'affiliate';
		}

		$username = $username_base;
		$i        = 1;
		while ( username_exists( $username ) ) {
			$username = $username_base . $i;
			$i++;
		}

		$password = wp_generate_password( 24, true, true );
		$user_id  = wp_create_user( $username, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$name_parts = preg_split( '/\s+/', trim( $full_name ) );
		$first_name = array_shift( $name_parts );
		$last_name  = implode( ' ', $name_parts );

		wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => $full_name,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
			)
		);

		$user = get_user_by( 'id', $user_id );
		if ( $user && get_role( 'customer' ) ) {
			$user->set_role( 'customer' );
		}

		wp_new_user_notification( $user_id, null, 'user' );

		return (int) $user_id;
	}

	private function create_or_update_yith_affiliate( $user_id, $full_name, $email, $social_profiles, $about_you, $requested_code, $agreement_id ) {
		if ( ! class_exists( 'YITH_WCAF_Affiliate_Factory' ) ) {
			update_user_meta( $user_id, '_ion_affiliate_pending_yith_create', 1 );
			return 0;
		}

		$name_parts = preg_split( '/\s+/', trim( $full_name ) );
		$first_name = array_shift( $name_parts );
		$last_name  = implode( ' ', $name_parts );

		$fields = array(
			'first_name'                  => $first_name,
			'last_name'                   => $last_name,
			'payment_email'               => $email,
			'ion_social_profiles'         => $social_profiles,
			'ion_about_you'               => $about_you,
			'ion_requested_code'          => $requested_code,
			'ion_agreement_id'            => (int) $agreement_id,
			'ion_agreement_terms_version' => self::TERMS_VERSION,
			'ion_agreement_terms_hash'    => self::terms_hash(),
			'ion_agreement_accepted_at'   => current_time( 'mysql' ),
		);

		try {
			$affiliate = YITH_WCAF_Affiliate_Factory::make_user_an_affiliate( (int) $user_id, $fields );
			if ( $affiliate && is_object( $affiliate ) && method_exists( $affiliate, 'set_status' ) ) {
				$affiliate->set_status( 'new' );
				$affiliate->save();
				return (int) $affiliate->get_id();
			}
		} catch ( Exception $e ) {
			update_user_meta( $user_id, '_ion_affiliate_yith_error', $e->getMessage() );
		}

		return 0;
	}

	private function send_application_emails( $agreement_id, $full_name, $email, $requested_code ) {
		$admin_url = admin_url( 'users.php?page=ion-affiliate-agreements&view=' . absint( $agreement_id ) );
		$subject   = 'New Ion affiliate application: ' . $full_name;
		$message   = "A new affiliate application has been submitted.\n\n";
		$message  .= "Name: {$full_name}\n";
		$message  .= "Email: {$email}\n";
		$message  .= "Requested Code: {$requested_code}\n\n";
		$message  .= "Review it here: {$admin_url}\n";

		wp_mail( get_option( 'admin_email' ), $subject, $message );

		$applicant_subject = 'Ion Peptide affiliate application received';
		$applicant_message = "Hi {$full_name},\n\nYour Ion Peptide affiliate application has been received. Our team will review it and follow up after approval or denial.\n\nThank you,\nIon Peptide";
		wp_mail( $email, $applicant_subject, $applicant_message );
	}

	private function store_flash( $type, $message ) {
		$key = 'ion_affiliate_flash_' . wp_generate_password( 12, false, false );
		set_transient(
			$key,
			array(
				'type'    => $type,
				'message' => $message,
			),
			5 * MINUTE_IN_SECONDS
		);

		if ( ! headers_sent() ) {
			setcookie( 'ion_affiliate_flash', $key, time() + 300, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
			$_COOKIE['ion_affiliate_flash'] = $key;
		}
	}

	private function get_flash() {
		if ( empty( $_COOKIE['ion_affiliate_flash'] ) ) {
			return '';
		}

		$key  = sanitize_text_field( wp_unslash( $_COOKIE['ion_affiliate_flash'] ) );
		$data = get_transient( $key );
		delete_transient( $key );

		if ( ! headers_sent() ) {
			setcookie( 'ion_affiliate_flash', '', time() - 3600, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		}

		if ( ! is_array( $data ) || empty( $data['message'] ) ) {
			return '';
		}

		$type = 'error' === $data['type'] ? 'error' : 'success';
		return '<div class="ion-aff-alert ion-aff-alert--' . esc_attr( $type ) . '">' . esc_html( $data['message'] ) . '</div>';
	}

	private function affiliate_dashboard_page_css() {
		$page_id = absint( get_option( 'ion_affiliate_dashboard_page_id' ) );
		$root    = $page_id ? 'body.page-id-' . $page_id : 'body';

		return $root . '{--ion-blue:#007eff;--ion-navy:#0d0d67;--ion-deep:#050539;--ion-text:#2f3a4c;--ion-muted:#5f6d80;--ion-soft:#f7f9ff;--ion-line:#dfe9fb;--ion-shadow:0 18px 45px rgba(13,13,103,.10);}'
		. $root . ' #primary,' . $root . ' .site-main,' . $root . ' .content-area{width:100%;max-width:none;}'
		. $root . ' .entry-title,' . $root . ' .page-title{max-width:1180px;margin:34px auto 0;padding:0 24px;color:var(--ion-navy);font-family:"Poppins","Inter",Arial,sans-serif;font-size:clamp(38px,5vw,64px);line-height:1;font-weight:900;letter-spacing:-.055em;}'
		. $root . ' .entry-content,' . $root . ' .page-content,' . $root . ' .wp-block-post-content{max-width:1180px;margin:0 auto;padding:34px 24px 82px;font-family:"Poppins","Inter",Arial,sans-serif;color:var(--ion-text);}'
		. $root . ' .entry-content:before{content:"";display:block;height:1px;margin:0 0 26px;background:linear-gradient(90deg,rgba(0,126,255,.22),rgba(13,13,103,.08),transparent);}'
		. $root . ' .entry-content>p:first-child,' . $root . ' .page-content>p:first-child{margin-top:0;}'
		. $root . ' .entry-content .woocommerce,' . $root . ' .entry-content .yith-wcaf,' . $root . ' .entry-content .yith-wcaf-dashboard,' . $root . ' .entry-content .yith-wcaf-affiliate-dashboard,' . $root . ' .entry-content .yith-wcaf-dashboard-wrapper,' . $root . ' .entry-content .ion-aff-yith-shell{position:relative;max-width:100%;border-radius:32px;background:#fff;border:1px solid rgba(0,126,255,.14);box-shadow:var(--ion-shadow);}'
		. $root . ' .entry-content .woocommerce,' . $root . ' .entry-content .yith-wcaf-dashboard,' . $root . ' .entry-content .yith-wcaf-affiliate-dashboard,' . $root . ' .entry-content .yith-wcaf-dashboard-wrapper,' . $root . ' .entry-content .ion-aff-yith-shell{padding:30px;}'
		. $root . ' .entry-content .yith-wcaf h1,' . $root . ' .entry-content .yith-wcaf h2,' . $root . ' .entry-content .yith-wcaf h3,' . $root . ' .entry-content .yith-wcaf-dashboard h1,' . $root . ' .entry-content .yith-wcaf-dashboard h2,' . $root . ' .entry-content .yith-wcaf-dashboard h3,' . $root . ' .entry-content .ion-aff-yith-shell h1,' . $root . ' .entry-content .ion-aff-yith-shell h2,' . $root . ' .entry-content .ion-aff-yith-shell h3{color:var(--ion-navy);font-weight:900;letter-spacing:-.035em;line-height:1.08;}'
		. $root . ' .entry-content a{color:var(--ion-blue);font-weight:900;text-decoration:none;}'
		. $root . ' .entry-content a:hover{color:var(--ion-navy);}'
		. $root . ' .entry-content .button,' . $root . ' .entry-content button,' . $root . ' .entry-content input[type="submit"],' . $root . ' .entry-content a.button{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 20px;border:0;border-radius:999px;background:var(--ion-blue);color:#fff!important;font-size:14px;font-weight:900;line-height:1;text-decoration:none;box-shadow:0 10px 22px rgba(0,126,255,.16);cursor:pointer;transition:.18s ease;}'
		. $root . ' .entry-content .button:hover,' . $root . ' .entry-content button:hover,' . $root . ' .entry-content input[type="submit"]:hover,' . $root . ' .entry-content a.button:hover{background:var(--ion-navy);color:#fff!important;}'
		. $root . ' .entry-content input[type="text"],' . $root . ' .entry-content input[type="url"],' . $root . ' .entry-content input[type="email"],' . $root . ' .entry-content input[type="number"],' . $root . ' .entry-content select,' . $root . ' .entry-content textarea{max-width:100%;border:1px solid rgba(13,13,103,.14);border-radius:14px;background:#fff;color:var(--ion-text);font-size:15px;line-height:1.45;padding:12px 14px;box-shadow:none;outline:none;}'
		. $root . ' .entry-content input:focus,' . $root . ' .entry-content select:focus,' . $root . ' .entry-content textarea:focus{border-color:var(--ion-blue);box-shadow:0 0 0 4px rgba(0,126,255,.10);}'
		. $root . ' .entry-content table{width:100%;border-collapse:separate;border-spacing:0;overflow:hidden;border:1px solid var(--ion-line);border-radius:18px;background:#fff;}'
		. $root . ' .entry-content table th{background:#f5f8ff;color:var(--ion-navy);font-weight:900;}'
		. $root . ' .entry-content table th,' . $root . ' .entry-content table td{padding:14px 16px;border-bottom:1px solid var(--ion-line);text-align:left;vertical-align:middle;}'
		. $root . ' .entry-content table tr:last-child td{border-bottom:0;}'
		. $root . ' .entry-content .yith-wcaf-dashboard-navigation,' . $root . ' .entry-content .yith-wcaf-tabs{margin:0 0 26px;padding:0 0 16px;border-bottom:1px solid var(--ion-line);}'
		. $root . ' .entry-content .yith-wcaf-dashboard-navigation ul,' . $root . ' .entry-content .yith-wcaf-tabs ul{display:flex;flex-wrap:wrap;gap:10px 14px;align-items:center;margin:0;padding:0;list-style:none;}'
		. $root . ' .entry-content .yith-wcaf-dashboard-navigation li,' . $root . ' .entry-content .yith-wcaf-tabs li{margin:0;padding:0;list-style:none;}'
		. $root . ' .entry-content .yith-wcaf-dashboard-navigation a,' . $root . ' .entry-content .yith-wcaf-tabs a{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 16px;border-radius:999px;background:#f5f8ff;border:1px solid rgba(0,126,255,.12);color:var(--ion-blue)!important;font-size:14px;font-weight:900;text-decoration:none;}'
		. $root . ' .entry-content .yith-wcaf-dashboard-navigation .active a,' . $root . ' .entry-content .yith-wcaf-dashboard-navigation .current a,' . $root . ' .entry-content .yith-wcaf-dashboard-navigation a:hover,' . $root . ' .entry-content .yith-wcaf-tabs .active a,' . $root . ' .entry-content .yith-wcaf-tabs .current a,' . $root . ' .entry-content .yith-wcaf-tabs a:hover{background:var(--ion-blue);color:#fff!important;border-color:var(--ion-blue);}'
		. $root . ' .entry-content .yith-wcaf-dashboard-summary,' . $root . ' .entry-content .yith-wcaf-dashboard-stats,' . $root . ' .entry-content .yith-wcaf-summary,' . $root . ' .entry-content .dashboard-summary{margin-bottom:28px;}'
		. $root . ' .entry-content .yith-wcaf-dashboard-summary>div,' . $root . ' .entry-content .yith-wcaf-dashboard-stats>div,' . $root . ' .entry-content .yith-wcaf-summary>div,' . $root . ' .entry-content .dashboard-summary>div{border-color:rgba(13,13,103,.14)!important;border-radius:20px!important;background:#fff!important;box-shadow:0 12px 30px rgba(13,13,103,.06)!important;}'
		. $root . ' .entry-content .yith-wcaf-dashboard-summary strong,' . $root . ' .entry-content .yith-wcaf-dashboard-stats strong,' . $root . ' .entry-content .yith-wcaf-summary strong,' . $root . ' .entry-content .dashboard-summary strong{color:#167d78;font-weight:900;}'
		. '@media (max-width:760px){' . $root . ' .entry-title,' . $root . ' .page-title{padding:0 14px;margin-top:24px;font-size:38px;}' . $root . ' .entry-content,' . $root . ' .page-content,' . $root . ' .wp-block-post-content{padding:24px 14px 58px;}' . $root . ' .entry-content .woocommerce,' . $root . ' .entry-content .yith-wcaf-dashboard,' . $root . ' .entry-content .yith-wcaf-affiliate-dashboard,' . $root . ' .entry-content .yith-wcaf-dashboard-wrapper,' . $root . ' .entry-content .ion-aff-yith-shell{padding:18px;border-radius:22px;}' . $root . ' .entry-content table{display:block;overflow-x:auto;white-space:nowrap;}' . $root . ' .entry-content .yith-wcaf-dashboard-navigation ul,' . $root . ' .entry-content .yith-wcaf-tabs ul{gap:8px;}' . $root . ' .entry-content .yith-wcaf-dashboard-navigation a,' . $root . ' .entry-content .yith-wcaf-tabs a{min-height:40px;padding:0 13px;font-size:13px;}}';
	}

	public function print_affiliate_dashboard_page_styles() {
		if ( is_admin() || ! $this->is_affiliate_dashboard_page() ) {
			return;
		}

		echo "\n<style id=\"ion-affiliate-dashboard-page-style\">" . $this->affiliate_dashboard_page_css() . "</style>\n";
	}

	private function frontend_shell_open( $page_class = '' ) {
		ob_start();
		?>
		<style>
			.ion-aff-page,.ion-aff-page *{box-sizing:border-box}.ion-aff-page{--ion-blue:#007eff;--ion-blue-2:#527df4;--ion-navy:#0d0d67;--ion-deep:#050539;--ion-text:#2f3a4c;--ion-muted:#5f6d80;--ion-soft:#f7f9ff;--ion-soft-2:#eef5ff;--ion-line:#dfe9fb;--ion-white:#fff;--ion-shadow:0 18px 45px rgba(13,13,103,.10);--ion-font:"Poppins","Inter",Arial,sans-serif;width:100%;margin:0;padding:0;overflow:hidden;color:var(--ion-text);font-family:var(--ion-font);background:radial-gradient(circle at 90% 4%,rgba(0,126,255,.10),transparent 30%),radial-gradient(circle at 8% 14%,rgba(13,13,103,.06),transparent 28%),linear-gradient(180deg,#fff 0%,#f7f9ff 52%,#fff 100%)}.ion-aff-page a{color:inherit;text-decoration:none}.ion-aff-container{width:min(1180px,calc(100% - 32px));margin:0 auto}.ion-aff-hero{position:relative;padding:58px 0 38px;overflow:hidden}.ion-aff-hero:before{content:"";position:absolute;inset:0;background-image:linear-gradient(rgba(13,13,103,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(13,13,103,.04) 1px,transparent 1px);background-size:42px 42px;mask-image:linear-gradient(90deg,rgba(0,0,0,.58),transparent 84%);pointer-events:none}.ion-aff-hero-card{position:relative;z-index:2;display:grid;grid-template-columns:minmax(0,1.05fr) minmax(320px,.75fr);gap:34px;align-items:center;min-height:430px;padding:42px;border-radius:34px;overflow:hidden;isolation:isolate;background:radial-gradient(circle at 84% 16%,rgba(0,126,255,.34),transparent 28%),radial-gradient(circle at 62% 70%,rgba(82,125,244,.18),transparent 28%),linear-gradient(135deg,#050539 0%,#0d0d67 48%,#0c4ed7 100%);border:1px solid rgba(255,255,255,.16);box-shadow:0 24px 70px rgba(13,13,103,.22)}.ion-aff-hero-card:before{content:"";position:absolute;inset:0;background:linear-gradient(rgba(255,255,255,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.045) 1px,transparent 1px);background-size:46px 46px;opacity:.62;pointer-events:none;z-index:0}.ion-aff-hero-card>*{position:relative;z-index:2}.ion-aff-breadcrumb{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin:0 0 15px;color:rgba(255,255,255,.72);font-size:13px;font-weight:700}.ion-aff-breadcrumb a{color:#fff;font-weight:900}.ion-aff-badge{display:inline-flex;align-items:center;justify-content:center;min-height:32px;padding:5px 13px;margin:0 0 18px;border-radius:999px;background:rgba(255,255,255,.14);color:#fff;font-size:12px;line-height:1;font-weight:900;letter-spacing:.08em;text-transform:uppercase;border:1px solid rgba(255,255,255,.16);backdrop-filter:blur(10px)}.ion-aff-hero h1{max-width:760px;margin:0 0 16px;color:#fff;font-size:clamp(42px,5.4vw,74px);line-height:.95;font-weight:900;letter-spacing:-.065em}.ion-aff-hero h1 span{color:#63adff}.ion-aff-hero p{max-width:650px;margin:0;color:rgba(255,255,255,.78);font-size:16px;line-height:1.78}.ion-aff-proof{display:grid;gap:12px}.ion-aff-proof-card{padding:18px;border-radius:20px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.16);backdrop-filter:blur(12px)}.ion-aff-proof-card strong{display:block;margin-bottom:6px;color:#fff;font-size:16px;font-weight:900}.ion-aff-proof-card span{display:block;color:rgba(255,255,255,.68);font-size:12px;line-height:1.45;font-weight:700}.ion-aff-section{padding:38px 0 78px}.ion-aff-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:28px;align-items:start}.ion-aff-card{position:relative;overflow:hidden;padding:30px;border-radius:28px;background:#fff;border:1px solid rgba(0,126,255,.14);box-shadow:var(--ion-shadow)}.ion-aff-card h2,.ion-aff-card h3{margin:0 0 14px;color:var(--ion-navy);font-size:clamp(26px,3vw,38px);line-height:1.08;font-weight:900;letter-spacing:-.04em}.ion-aff-card p{margin:0 0 14px;color:#526071;font-size:15px;line-height:1.75}.ion-aff-card p:last-child{margin-bottom:0}.ion-aff-sidebar{position:sticky;top:118px;display:grid;gap:16px}.ion-aff-mini-card{padding:22px;border-radius:24px;background:#fff;border:1px solid var(--ion-line);box-shadow:0 12px 30px rgba(15,23,42,.06)}.ion-aff-mini-card h3{margin:0 0 12px;color:var(--ion-navy);font-size:19px;line-height:1.2;font-weight:900}.ion-aff-steps{display:grid;gap:10px}.ion-aff-step{display:grid;grid-template-columns:44px minmax(0,1fr);gap:12px;align-items:start;padding:13px;border-radius:16px;background:var(--ion-soft);border:1px solid rgba(0,126,255,.10)}.ion-aff-step-number{display:inline-grid!important;place-items:center!important;width:38px!important;height:38px!important;min-width:38px!important;margin:0!important;border-radius:999px;background:var(--ion-navy);color:#fff!important;font-size:13px!important;font-weight:900!important;line-height:1!important;box-shadow:0 8px 18px rgba(13,13,103,.16)}.ion-aff-step strong{display:block;color:var(--ion-navy);font-size:13px;line-height:1.25;font-weight:900}.ion-aff-step span{display:block;margin-top:3px;color:var(--ion-muted);font-size:12px;line-height:1.42;font-weight:600}.ion-aff-step>span:not(.ion-aff-step-number){margin-top:0;color:inherit}.ion-aff-step .ion-aff-step-number+span{padding-top:1px}.ion-aff-form{display:grid;gap:16px;margin-top:18px}.ion-aff-field{display:grid;gap:7px}.ion-aff-field label,.ion-aff-check strong{color:var(--ion-navy);font-size:13px;line-height:1.35;font-weight:900}.ion-aff-field input,.ion-aff-field textarea{width:100%;border:1px solid rgba(13,13,103,.14);border-radius:15px;background:#fff;color:var(--ion-text);font-size:15px;line-height:1.5;padding:13px 14px;box-shadow:none;outline:none}.ion-aff-field textarea{min-height:120px;resize:vertical}.ion-aff-field input:focus,.ion-aff-field textarea:focus{border-color:var(--ion-blue);box-shadow:0 0 0 4px rgba(0,126,255,.10)}.ion-aff-help{color:var(--ion-muted);font-size:12px;line-height:1.45;font-weight:600}.ion-aff-check{display:grid;grid-template-columns:22px minmax(0,1fr);gap:10px;align-items:start;padding:14px;border-radius:16px;background:var(--ion-soft);border:1px solid rgba(0,126,255,.10)}.ion-aff-check input{width:18px;height:18px;margin:2px 0 0}.ion-aff-check span{display:block;color:var(--ion-muted);font-size:13px;line-height:1.55;font-weight:600}.ion-aff-btn{display:inline-flex;align-items:center;justify-content:center;min-height:52px;padding:0 26px;border:0;border-radius:999px;background:var(--ion-blue);color:#fff!important;font-size:15px;font-weight:900;line-height:1;box-shadow:0 12px 24px rgba(0,126,255,.18);cursor:pointer;transition:.18s ease}.ion-aff-btn:hover{background:var(--ion-navy);transform:translateY(-1px);box-shadow:0 16px 30px rgba(13,13,103,.20)}.ion-aff-btn--ghost{background:#fff;color:var(--ion-navy)!important;border:1px solid rgba(13,13,103,.14);box-shadow:none}.ion-aff-btn--ghost:hover{background:var(--ion-navy);color:#fff!important}.ion-aff-actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:4px}.ion-aff-alert{margin:0 0 18px;padding:14px 16px;border-radius:16px;font-size:14px;line-height:1.55;font-weight:800}.ion-aff-alert--error{background:#fff1f1;border:1px solid #ffd2d2;color:#8a1f1f}.ion-aff-alert--success{background:#effaf2;border:1px solid #ccefd5;color:#1f6c35}.ion-aff-terms{max-height:540px;overflow:auto;padding:24px;border-radius:22px;background:linear-gradient(180deg,#fff 0%,#f8fbff 100%);border:1px solid rgba(0,126,255,.12)}.ion-aff-terms h2{font-size:28px;margin:0 0 16px}.ion-aff-terms p{font-size:14px;line-height:1.72;color:#394759}.ion-aff-terms .ion-aff-term-line{padding:10px 12px;border-radius:14px;background:rgba(247,249,255,.82);border:1px solid rgba(0,126,255,.08)}.ion-aff-accepted-line{font-weight:900;color:var(--ion-navy)!important}.ion-aff-status-pill{display:inline-flex;align-items:center;min-height:30px;padding:5px 10px;border-radius:999px;background:rgba(0,126,255,.10);color:var(--ion-blue);font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.07em}.ion-aff-final{padding:0 0 84px}.ion-aff-final-card{position:relative;overflow:hidden;padding:42px;border-radius:34px;background:linear-gradient(135deg,#050539 0%,#0d0d67 58%,#0c4ed7 100%);border:1px solid rgba(255,255,255,.14);box-shadow:0 24px 70px rgba(13,13,103,.22);color:#fff;text-align:center}.ion-aff-final-card h2{max-width:760px;margin:0 auto;color:#fff;font-size:clamp(30px,4vw,50px);line-height:1.02;font-weight:900;letter-spacing:-.055em}.ion-aff-final-card p{max-width:680px;margin:14px auto 0;color:rgba(255,255,255,.76);font-size:15px;line-height:1.75}@media (max-width:1080px){.ion-aff-hero-card,.ion-aff-grid{grid-template-columns:1fr}.ion-aff-sidebar{position:static}}@media (max-width:760px){.ion-aff-container{width:calc(100% - 22px)}.ion-aff-hero{padding:18px 0 34px}.ion-aff-hero-card{padding:22px;border-radius:26px;min-height:auto}.ion-aff-hero h1{font-size:38px}.ion-aff-proof{display:none}.ion-aff-section{padding:24px 0 56px}.ion-aff-card,.ion-aff-final-card{padding:24px 20px;border-radius:24px}.ion-aff-actions{display:grid}.ion-aff-btn{width:100%}.ion-aff-terms{max-height:430px;padding:18px}.ion-aff-card h2,.ion-aff-card h3{font-size:28px}}
			.ion-aff-account-page .ion-aff-card{overflow:visible}.ion-aff-account-page .ion-aff-yith-shell{width:100%;max-width:100%;overflow:visible}.ion-aff-account-page .ion-aff-yith-shell h1,.ion-aff-account-page .ion-aff-yith-shell h2,.ion-aff-account-page .ion-aff-yith-shell h3{color:var(--ion-navy);font-weight:900;letter-spacing:-.035em}.ion-aff-account-page .ion-aff-yith-shell a:not(.button):not(.ion-aff-btn){color:var(--ion-blue);font-weight:900}.ion-aff-account-page .ion-aff-yith-shell .button,.ion-aff-account-page .ion-aff-yith-shell button,.ion-aff-account-page .ion-aff-yith-shell input[type="submit"]{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 20px;border:0;border-radius:999px;background:var(--ion-blue);color:#fff!important;font-size:14px;font-weight:900;line-height:1;text-decoration:none;box-shadow:0 10px 22px rgba(0,126,255,.16);cursor:pointer}.ion-aff-account-page .ion-aff-yith-shell .button:hover,.ion-aff-account-page .ion-aff-yith-shell button:hover,.ion-aff-account-page .ion-aff-yith-shell input[type="submit"]:hover{background:var(--ion-navy);color:#fff!important}.ion-aff-account-page .ion-aff-yith-shell input[type="text"],.ion-aff-account-page .ion-aff-yith-shell input[type="url"],.ion-aff-account-page .ion-aff-yith-shell input[type="email"],.ion-aff-account-page .ion-aff-yith-shell select,.ion-aff-account-page .ion-aff-yith-shell textarea{width:100%;max-width:100%;border:1px solid rgba(13,13,103,.14);border-radius:14px;background:#fff;color:var(--ion-text);font-size:15px;line-height:1.45;padding:12px 14px;box-shadow:none;outline:none}.ion-aff-account-page .ion-aff-yith-shell input:focus,.ion-aff-account-page .ion-aff-yith-shell select:focus,.ion-aff-account-page .ion-aff-yith-shell textarea:focus{border-color:var(--ion-blue);box-shadow:0 0 0 4px rgba(0,126,255,.10)}.ion-aff-account-page .ion-aff-yith-shell table{width:100%;border-collapse:separate;border-spacing:0;overflow:hidden;border:1px solid var(--ion-line);border-radius:18px;background:#fff}.ion-aff-account-page .ion-aff-yith-shell table th{background:#f5f8ff;color:var(--ion-navy);font-weight:900}.ion-aff-account-page .ion-aff-yith-shell table th,.ion-aff-account-page .ion-aff-yith-shell table td{padding:14px 16px;border-bottom:1px solid var(--ion-line);text-align:left}.ion-aff-account-page .ion-aff-yith-shell table tr:last-child td{border-bottom:0}.ion-aff-account-page .ion-aff-yith-shell .yith-wcaf-dashboard-navigation ul,.ion-aff-account-page .ion-aff-yith-shell .yith-wcaf-tabs ul{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:0 0 24px;padding:0 0 16px;list-style:none;border-bottom:1px solid var(--ion-line)}.ion-aff-account-page .ion-aff-yith-shell .yith-wcaf-dashboard-navigation li,.ion-aff-account-page .ion-aff-yith-shell .yith-wcaf-tabs li{margin:0;padding:0;list-style:none}.ion-aff-account-page .ion-aff-yith-shell .yith-wcaf-dashboard-navigation a,.ion-aff-account-page .ion-aff-yith-shell .yith-wcaf-tabs a{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 16px;border-radius:999px;background:#f5f8ff;border:1px solid rgba(0,126,255,.12);color:var(--ion-blue)!important;font-size:14px;font-weight:900;text-decoration:none}.ion-aff-account-page .ion-aff-yith-shell .yith-wcaf-dashboard-navigation .active a,.ion-aff-account-page .ion-aff-yith-shell .yith-wcaf-tabs .active a,.ion-aff-account-page .ion-aff-yith-shell .yith-wcaf-dashboard-navigation a:hover,.ion-aff-account-page .ion-aff-yith-shell .yith-wcaf-tabs a:hover{background:var(--ion-blue);color:#fff!important;border-color:var(--ion-blue)}@media (max-width:760px){.ion-aff-account-page .ion-aff-yith-shell table{display:block;overflow-x:auto;white-space:nowrap}.ion-aff-account-page .ion-aff-yith-shell .yith-wcaf-dashboard-navigation ul,.ion-aff-account-page .ion-aff-yith-shell .yith-wcaf-tabs ul{gap:8px}.ion-aff-account-page .ion-aff-yith-shell .yith-wcaf-dashboard-navigation a,.ion-aff-account-page .ion-aff-yith-shell .yith-wcaf-tabs a{min-height:40px;padding:0 13px;font-size:13px}}

		</style>
		<main class="ion-aff-page <?php echo esc_attr( $page_class ); ?>">
		<?php
		return ob_get_clean();
	}

	private function frontend_shell_close() {
		return '</main>';
	}

	public function shortcode_agreement() {
		$prefill_name  = is_user_logged_in() ? wp_get_current_user()->display_name : '';
		$prefill_email = is_user_logged_in() ? wp_get_current_user()->user_email : '';

		ob_start();
		echo $this->frontend_shell_open( 'ion-aff-agreement-page' );
		?>
		<section class="ion-aff-hero" aria-label="Ion Peptide affiliate agreement">
			<div class="ion-aff-container">
				<div class="ion-aff-hero-card">
					<div>
						<div class="ion-aff-breadcrumb"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a><span>/</span><span>Affiliate Agreement</span></div>
						<span class="ion-aff-badge">Affiliate Compliance</span>
						<h1>Affiliate <span>Agreement</span></h1>
						<p>Before applying, please review and accept Ion Peptide’s affiliate guidelines, FTC disclosure rules, and research-use-only promotion standards.</p>
					</div>
					<div class="ion-aff-proof" aria-label="Agreement requirements">
						<div class="ion-aff-proof-card"><strong>FTC Disclosure</strong><span>Affiliate relationship disclosures must be clear, visible, and easy to understand.</span></div>
						<div class="ion-aff-proof-card"><strong>Research Use Only</strong><span>Promotions must stay focused on laboratory research use only.</span></div>
						<div class="ion-aff-proof-card"><strong>Program Standards</strong><span>Approved affiliates are expected to follow the guidelines on every promotion.</span></div>
					</div>
				</div>
			</div>
		</section>

		<section class="ion-aff-section" aria-label="Affiliate agreement form">
			<div class="ion-aff-container">
				<?php echo $this->get_flash(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="ion-aff-grid">
					<div class="ion-aff-card">
						<h2>Review and accept the guidelines</h2>
						<div class="ion-aff-terms">
							<?php echo wp_kses_post( self::agreement_html() ); ?>
						</div>

						<form class="ion-aff-form" method="post">
							<input type="hidden" name="ion_affiliate_action" value="accept_agreement">
							<?php wp_nonce_field( 'ion_affiliate_accept_agreement', 'ion_affiliate_agreement_nonce' ); ?>

							<div class="ion-aff-field">
								<label for="ion-aff-full-name">Full legal name</label>
								<input id="ion-aff-full-name" name="full_name" type="text" value="<?php echo esc_attr( $prefill_name ); ?>" autocomplete="name" required>
							</div>

							<div class="ion-aff-field">
								<label for="ion-aff-email">Email address</label>
								<input id="ion-aff-email" name="email" type="email" value="<?php echo esc_attr( $prefill_email ); ?>" autocomplete="email" required>
							</div>

							<label class="ion-aff-check">
								<input type="checkbox" name="agree_terms" value="1" required>
								<span><strong>I have read and agree to the Affiliate Guidelines.</strong><span>I understand this acceptance is required before I can apply for affiliate access.</span></span>
							</label>

							<label class="ion-aff-check">
								<input type="checkbox" name="agree_ftc" value="1" required>
								<span><strong>I agree to follow FTC disclosure rules.</strong><span>I will clearly disclose my material connection using plain, unavoidable language such as #ad or #sponsored.</span></span>
							</label>

							<label class="ion-aff-check">
								<input type="checkbox" name="agree_ruo" value="1" required>
								<span><strong>I agree to research-use-only promotion rules.</strong><span>I will not make medical, health, dosage, administration, human/animal consumption, bodybuilding, or biohacking claims.</span></span>
							</label>

							<div class="ion-aff-field">
								<label for="ion-aff-signature">Digital signature</label>
								<input id="ion-aff-signature" name="signature_name" type="text" placeholder="Type your full legal name" required>
								<span class="ion-aff-help">Typing your name confirms your acceptance of this agreement.</span>
							</div>

							<div class="ion-aff-actions">
								<button class="ion-aff-btn" type="submit">Accept Agreement &amp; Continue</button>
							</div>
						</form>
					</div>

					<aside class="ion-aff-sidebar">
						<div class="ion-aff-mini-card">
							<h3>How it works</h3>
							<div class="ion-aff-steps">
								<div class="ion-aff-step"><span class="ion-aff-step-number">1</span><span><strong>Review guidelines</strong><span>Read the affiliate terms and promotion standards.</span></span></div>
								<div class="ion-aff-step"><span class="ion-aff-step-number">2</span><span><strong>Apply</strong><span>Submit your details, social links, and requested code.</span></span></div>
								<div class="ion-aff-step"><span class="ion-aff-step-number">3</span><span><strong>Application review</strong><span>The Ion Peptide team reviews each request.</span></span></div>
								<div class="ion-aff-step"><span class="ion-aff-step-number">4</span><span><strong>Affiliate access</strong><span>Approved affiliates receive dashboard access.</span></span></div>
							</div>
						</div>
						<div class="ion-aff-mini-card">
							<h3>Before you apply</h3>
							<p>Please make sure your promotional channels can follow the affiliate guidelines, including clear disclosure and research-use-only positioning.</p>
						</div>
					</aside>
				</div>
			</div>
		</section>
		<?php
		echo $this->frontend_shell_close();
		return ob_get_clean();
	}

	public function shortcode_application() {
		if ( isset( $_GET['ion_affiliate_submitted'] ) ) {
			ob_start();
			echo $this->frontend_shell_open( 'ion-aff-application-submitted' );
			?>
			<section class="ion-aff-hero" aria-label="Application submitted">
				<div class="ion-aff-container"><div class="ion-aff-hero-card"><div><div class="ion-aff-breadcrumb"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a><span>/</span><span>Affiliate Application</span></div><span class="ion-aff-badge">Submitted</span><h1>Application <span>Received</span></h1><p>Your affiliate application has been submitted for review. You will receive an email after approval or denial.</p></div><div class="ion-aff-proof"><div class="ion-aff-proof-card"><strong>Next Step</strong><span>Ion Peptide will review your application, social profiles, and requested code.</span></div></div></div></div>
			</section>
			<section class="ion-aff-final"><div class="ion-aff-container"><div class="ion-aff-final-card"><h2>Thank you for applying.</h2><p>Keep your login email available. If approved, your affiliate dashboard access will be connected to your account.</p></div></div></section>
			<?php
			echo $this->frontend_shell_close();
			return ob_get_clean();
		}

		$key    = $this->get_current_agreement_key();
		$record = $this->get_record_by_key( $key );

		if ( ! $record ) {
			return $this->application_missing_agreement_output();
		}

		ob_start();
		echo $this->frontend_shell_open( 'ion-aff-application-page' );
		?>
		<section class="ion-aff-hero" aria-label="Ion Peptide affiliate application">
			<div class="ion-aff-container">
				<div class="ion-aff-hero-card">
					<div>
						<div class="ion-aff-breadcrumb"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a><span>/</span><span>Affiliate Application</span></div>
						<span class="ion-aff-badge">Step 2</span>
						<h1>Affiliate <span>Application</span></h1>
						<p>Your agreement has been accepted. Now submit your application details for review.</p>
					</div>
					<div class="ion-aff-proof"><div class="ion-aff-proof-card"><strong>Agreement Accepted</strong><span><?php echo esc_html( $record->accepted_at ? mysql2date( 'M j, Y g:i A', $record->accepted_at ) : 'Today' ); ?></span></div><div class="ion-aff-proof-card"><strong>Status</strong><span><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) ( $record->application_status ?? '' ) ) ) ); ?></span></div></div>
				</div>
			</div>
		</section>

		<section class="ion-aff-section" aria-label="Affiliate application form">
			<div class="ion-aff-container">
				<?php echo $this->get_flash(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="ion-aff-grid">
					<div class="ion-aff-card">
						<h2>Submit your application</h2>
						<p>Use the same name and email that accepted the affiliate guidelines. Your requested code can be approved, adjusted, or denied during review.</p>
						<form class="ion-aff-form" method="post">
							<input type="hidden" name="ion_affiliate_action" value="submit_application">
							<input type="hidden" name="ion_agreement_key" value="<?php echo esc_attr( (string) ( $record->agreement_key ?? '' ) ); ?>">
							<?php wp_nonce_field( 'ion_affiliate_submit_application', 'ion_affiliate_application_nonce' ); ?>

							<div class="ion-aff-field"><label for="ion-app-name">Name</label><input id="ion-app-name" name="full_name" type="text" value="<?php echo esc_attr( (string) ( $record->full_name ?? '' ) ); ?>" required></div>
							<div class="ion-aff-field"><label for="ion-app-email">Email</label><input id="ion-app-email" name="email" type="email" value="<?php echo esc_attr( (string) ( $record->email ?? '' ) ); ?>" required></div>
							<div class="ion-aff-field"><label for="ion-app-socials">Social media links</label><textarea id="ion-app-socials" name="social_profiles" placeholder="Instagram, TikTok, YouTube, X, Facebook, website, group links, etc." required><?php echo esc_textarea( (string) ( $record->social_profiles ?? '' ) ); ?></textarea></div>
							<div class="ion-aff-field"><label for="ion-app-about">About you</label><textarea id="ion-app-about" name="about_you" placeholder="Tell us about your audience, content style, experience, and why you want to represent Ion Peptide." required><?php echo esc_textarea( (string) ( $record->about_you ?? '' ) ); ?></textarea></div>
							<div class="ion-aff-field"><label for="ion-app-code">Requested affiliate code</label><input id="ion-app-code" name="requested_code" type="text" value="<?php echo esc_attr( (string) ( $record->requested_code ?? '' ) ); ?>" placeholder="Example: ION10" required><span class="ion-aff-help">Letters, numbers, and dashes are safest. Ion Peptide can adjust before approval.</span></div>
							<label class="ion-aff-check"><input type="checkbox" name="application_confirm" value="1" required><span><strong>I confirm this application is accurate.</strong><span>I understand approval is not guaranteed and affiliate access begins only after Ion Peptide approval.</span></span></label>
							<div class="ion-aff-actions"><button class="ion-aff-btn" type="submit">Submit for Approval</button></div>
						</form>
					</div>

					<aside class="ion-aff-sidebar"><div class="ion-aff-mini-card"><h3>Application checklist</h3><div class="ion-aff-steps"><div class="ion-aff-step"><span class="ion-aff-step-number">✓</span><span><strong>Agreement accepted</strong><span>Affiliate guidelines have been accepted.</span></span></div><div class="ion-aff-step"><span class="ion-aff-step-number">✓</span><span><strong>Application details</strong><span>Name, email, social links, about you, and requested code.</span></span></div><div class="ion-aff-step"><span class="ion-aff-step-number">✓</span><span><strong>Review required</strong><span>Affiliate access opens after Ion Peptide approval.</span></span></div></div></div><div class="ion-aff-mini-card"><h3>After approval</h3><p>Approved affiliates receive access to their affiliate dashboard from their account area.</p></div></aside>
				</div>
			</div>
		</section>
		<?php
		echo $this->frontend_shell_close();
		return ob_get_clean();
	}

	private function application_missing_agreement_output() {
		ob_start();
		echo $this->frontend_shell_open( 'ion-aff-missing-agreement' );
		?>
		<section class="ion-aff-hero"><div class="ion-aff-container"><div class="ion-aff-hero-card"><div><div class="ion-aff-breadcrumb"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a><span>/</span><span>Affiliate Application</span></div><span class="ion-aff-badge">Agreement Required</span><h1>Agreement <span>Required First</span></h1><p>The application form unlocks after the affiliate guidelines are reviewed and accepted.</p><div class="ion-aff-actions" style="margin-top:26px;"><a class="ion-aff-btn" href="<?php echo esc_url( $this->get_agreement_url() ); ?>">Accept Agreement</a></div></div><div class="ion-aff-proof"><div class="ion-aff-proof-card"><strong>Agreement first</strong><span>The affiliate guidelines must be accepted before the application step.</span></div></div></div></div></section>
		<?php
		echo $this->frontend_shell_close();
		return ob_get_clean();
	}

	public function shortcode_account() {
		ob_start();
		echo $this->frontend_shell_open( 'ion-aff-account-page' );
		?>
		<section class="ion-aff-section"><div class="ion-aff-container"><div class="ion-aff-card"><div class="ion-aff-yith-shell">
			<?php $this->render_my_account_endpoint(); ?>
		</div></div></div></section>
		<?php
		echo $this->frontend_shell_close();
		return ob_get_clean();
	}

	private function get_yith_affiliate_for_user( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id || ! class_exists( 'YITH_WCAF_Affiliate_Factory' ) ) {
			return false;
		}

		try {
			return YITH_WCAF_Affiliate_Factory::get_affiliate_by_user_id( $user_id );
		} catch ( Exception $e ) {
			return false;
		}
	}

	private function get_yith_affiliate_id_for_user( $user_id = 0 ) {
		$affiliate = $this->get_yith_affiliate_for_user( $user_id );
		if ( $affiliate && is_object( $affiliate ) && method_exists( $affiliate, 'get_id' ) ) {
			return (int) $affiliate->get_id();
		}

		return 0;
	}

	private function user_has_enabled_yith_affiliate( $user_id = 0 ) {
		$affiliate = $this->get_yith_affiliate_for_user( $user_id );
		return $affiliate && is_object( $affiliate ) && method_exists( $affiliate, 'has_status' ) && $affiliate->has_status( 'enabled' );
	}

	private function mark_existing_affiliate_agreement_approved( $agreement_key, $user_id = 0 ) {
		global $wpdb;

		$user_id       = $user_id ? (int) $user_id : get_current_user_id();
		$agreement_key = sanitize_text_field( $agreement_key );
		$record        = $this->get_record_by_key( $agreement_key );

		if ( ! $record || ! $user_id ) {
			return false;
		}

		$now          = current_time( 'mysql' );
		$affiliate_id = $this->get_yith_affiliate_id_for_user( $user_id );

		$wpdb->update(
			self::table_name(),
			array(
				'user_id'            => $user_id,
				'affiliate_id'       => $affiliate_id,
				'application_status' => 'approved',
				'submitted_at'       => $record->submitted_at ? $record->submitted_at : $now,
				'decision_at'        => $now,
				'updated_at'         => $now,
			),
			array( 'id' => (int) $record->id ),
			array( '%d', '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		update_user_meta( $user_id, '_ion_affiliate_agreement_id', (int) $record->id );
		update_user_meta( $user_id, '_ion_affiliate_terms_version', self::TERMS_VERSION );
		update_user_meta( $user_id, '_ion_affiliate_terms_hash', self::terms_hash() );
		update_user_meta( $user_id, '_ion_affiliate_agreement_accepted_at', $record->accepted_at ? $record->accepted_at : $now );

		return true;
	}

	public function route_my_account_affiliate_menu_to_dashboard_page( $url, $endpoint, $value, $permalink ) {
		if ( self::ENDPOINT !== $endpoint ) {
			return $url;
		}

		return $this->get_dashboard_url();
	}

	public function redirect_my_account_affiliate_endpoint_to_dashboard_page() {
		if ( is_admin() || wp_doing_ajax() || ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		global $wp;
		if ( empty( $wp->query_vars ) || ! array_key_exists( self::ENDPOINT, $wp->query_vars ) ) {
			return;
		}

		$dashboard_url = trailingslashit( $this->get_dashboard_url() );
		$tab           = sanitize_title( (string) $wp->query_vars[ self::ENDPOINT ] );

		if ( $tab ) {
			$dashboard_url = trailingslashit( $dashboard_url . $tab );
		}

		wp_safe_redirect( $dashboard_url );
		exit;
	}

	public function add_my_account_menu_item( $items ) {
		if ( ! is_user_logged_in() || ! $this->user_has_enabled_yith_affiliate() ) {
			return $items;
		}

		$new = array();
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'dashboard' === $key ) {
				$new[ self::ENDPOINT ] = 'Affiliate Dashboard';
			}
		}

		return $new;
	}

	public function render_my_account_endpoint() {
		if ( ! is_user_logged_in() ) {
			echo '<h2>Affiliate Dashboard</h2><p>Please log in to view affiliate access.</p>';
			return;
		}

		if ( isset( $_GET['ion_affiliate_submitted'] ) ) {
			echo '<div class="ion-aff-alert ion-aff-alert--success">Your affiliate application has been received and is waiting for review.</div>';
		}

		$record = $this->get_latest_record_for_user();

		if ( $this->user_has_enabled_yith_affiliate() && ! $this->record_has_current_terms( $record ) ) {
			echo '<h2>Affiliate Agreement Required</h2>';
			echo '<p>Please review and accept the current affiliate agreement before continuing to your affiliate dashboard.</p>';
			echo '<p><a class="ion-aff-btn" href="' . esc_url( $this->get_agreement_url() ) . '">Review Affiliate Agreement</a></p>';
			return;
		}

		if ( ! class_exists( 'YITH_WCAF_Affiliate_Factory' ) ) {
			echo '<h2>Affiliate Dashboard</h2><p>Your affiliate application record is saved. The affiliate dashboard will display here once the affiliate system is active and your application is approved.</p>';
			return;
		}

		if ( ! $this->user_has_enabled_yith_affiliate() ) {
			echo '<h2>Affiliate Dashboard</h2>';

			if ( $record && 'agreement_accepted' === $record->application_status ) {
				echo '<p>Your agreement is accepted. Please finish the affiliate application to request access.</p><p><a class="ion-aff-btn" href="' . esc_url( $this->get_application_url( $record->agreement_key ) ) . '">Finish Application</a></p>';
				return;
			}

			if ( $record && 'denied' === $record->application_status ) {
				echo '<p>Your affiliate application was not approved at this time. Contact support if you believe this needs another review.</p>';
				return;
			}

			echo '<p>Your affiliate account is not approved yet. Once approved, your dashboard tools will appear here.</p>';
			return;
		}

		echo do_shortcode( '[yith_wcaf_affiliate_dashboard]' );
	}

	public function admin_menu() {
		add_users_page( 'Ion Affiliate Agreements', 'Affiliate Agreements', 'manage_options', 'ion-affiliate-agreements', array( $this, 'admin_page' ) );
	}

	public function admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'ion-affiliate-compliance' ) );
		}

		if ( isset( $_GET['view'] ) ) {
			$this->admin_view_record( absint( $_GET['view'] ) );
			return;
		}

		global $wpdb;
		$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$where  = '1=1';
		$args   = array();
		if ( $status ) {
			$where  .= ' AND application_status = %s';
			$args[] = $status;
		}

		$sql = 'SELECT * FROM ' . self::table_name() . ' WHERE ' . $where . ' ORDER BY created_at DESC LIMIT 300';
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );
		?>
		<div class="wrap">
			<h1>Ion Affiliate Agreements</h1>
			<p>This screen stores the legal agreement acceptance, application details, YITH affiliate link, and approval status.</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin-post.php?action=ion_affiliate_export&_wpnonce=' . wp_create_nonce( 'ion_affiliate_export' ) ) ); ?>">Export CSV</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'users.php?page=ion-affiliate-agreements' ) ); ?>">All</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'users.php?page=ion-affiliate-agreements&status=submitted' ) ); ?>">Submitted</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'users.php?page=ion-affiliate-agreements&status=approved' ) ); ?>">Approved</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'users.php?page=ion-affiliate-agreements&status=denied' ) ); ?>">Denied</a>
			</p>
			<table class="widefat striped">
				<thead><tr><th>ID</th><th>Status</th><th>Name</th><th>Email</th><th>Requested Code</th><th>YITH Affiliate</th><th>Accepted</th><th>Submitted</th><th>Actions</th></tr></thead>
				<tbody>
				<?php if ( $rows ) : foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row->id ); ?></td>
						<td><strong><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) ( $row->application_status ?? '' ) ) ) ); ?></strong></td>
						<td><?php echo esc_html( (string) ( $row->full_name ?? '' ) ); ?></td>
						<td><a href="mailto:<?php echo esc_attr( (string) ( $row->email ?? '' ) ); ?>"><?php echo esc_html( (string) ( $row->email ?? '' ) ); ?></a></td>
						<td><?php echo esc_html( (string) ( $row->requested_code ?? '' ) ); ?></td>
						<td><?php echo $row->affiliate_id ? esc_html( '#' . $row->affiliate_id ) : '<em>Not created</em>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo esc_html( (string) ( $row->accepted_at ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row->submitted_at ?? '' ) ); ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( admin_url( 'users.php?page=ion-affiliate-agreements&view=' . absint( $row->id ) ) ); ?>">View</a>
							<?php if ( in_array( $row->application_status, array( 'submitted', 'agreement_accepted' ), true ) ) : ?>
								<a class="button button-small button-primary" href="<?php echo esc_url( $this->admin_action_url( $row->id, 'approve' ) ); ?>">Approve</a>
								<a class="button button-small" href="<?php echo esc_url( $this->admin_action_url( $row->id, 'deny' ) ); ?>">Deny</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; else : ?>
					<tr><td colspan="9">No affiliate agreement records yet.</td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function admin_action_url( $id, $do ) {
		return admin_url( 'admin-post.php?action=ion_affiliate_admin_action&record_id=' . absint( $id ) . '&do=' . sanitize_key( $do ) . '&_wpnonce=' . wp_create_nonce( 'ion_affiliate_admin_action_' . absint( $id ) ) );
	}

	private function admin_view_record( $id ) {
		$row = $this->get_record_by_id( $id );
		if ( ! $row ) {
			echo '<div class="wrap"><h1>Record not found</h1></div>';
			return;
		}
		?>
		<div class="wrap">
			<h1>Affiliate Agreement #<?php echo esc_html( $row->id ); ?></h1>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'users.php?page=ion-affiliate-agreements' ) ); ?>">Back to Agreements</a>
			<?php if ( in_array( $row->application_status, array( 'submitted', 'agreement_accepted' ), true ) ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( $this->admin_action_url( $row->id, 'approve' ) ); ?>">Approve</a>
				<a class="button" href="<?php echo esc_url( $this->admin_action_url( $row->id, 'deny' ) ); ?>">Deny</a>
			<?php endif; ?></p>
			<table class="widefat striped" style="max-width:1100px;">
				<tbody>
					<?php
					$fields = array(
						'Status'          => ucwords( str_replace( '_', ' ', $row->application_status ) ),
						'Full Name'       => $row->full_name,
						'Email'           => $row->email,
						'User ID'         => $row->user_id,
						'YITH Affiliate'  => $row->affiliate_id,
						'Requested Code'  => $row->requested_code,
						'Accepted At'     => $row->accepted_at,
						'Submitted At'    => $row->submitted_at,
						'Decision At'     => $row->decision_at,
						'Terms Version'   => $row->terms_version,
						'Terms SHA-256'   => $row->terms_hash,
						'Signature Name'  => $row->signature_name,
						'IP Address'      => $row->ip_address,
						'User Agent'      => $row->user_agent,
						'Social Profiles' => $row->social_profiles,
						'About You'       => $row->about_you,
					);
					foreach ( $fields as $label => $value ) :
						?>
						<tr><th style="width:220px;"><?php echo esc_html( $label ); ?></th><td><?php echo nl2br( esc_html( (string) $value ) ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<h2>Stored Agreement Snapshot</h2>
			<textarea readonly style="width:100%;max-width:1100px;height:420px;font-family:monospace;white-space:pre-wrap;"><?php echo esc_textarea( (string) ( $row->agreement_snapshot ?? '' ) ); ?></textarea>
		</div>
		<?php
	}

	public function admin_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ion-affiliate-compliance' ) );
		}

		$id = isset( $_GET['record_id'] ) ? absint( $_GET['record_id'] ) : 0;
		if ( ! $id || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'ion_affiliate_admin_action_' . $id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ion-affiliate-compliance' ) );
		}

		$do     = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$record = $this->get_record_by_id( $id );
		if ( ! $record ) {
			wp_die( esc_html__( 'Record not found.', 'ion-affiliate-compliance' ) );
		}

		if ( 'approve' === $do ) {
			$this->approve_record( $record );
		} elseif ( 'deny' === $do ) {
			$this->deny_record( $record );
		}

		wp_safe_redirect( admin_url( 'users.php?page=ion-affiliate-agreements&view=' . $id ) );
		exit;
	}

	private function approve_record( $record ) {
		global $wpdb;
		$affiliate_id = (int) $record->affiliate_id;

		if ( class_exists( 'YITH_WCAF_Affiliate_Factory' ) && $record->user_id ) {
			try {
				$affiliate = YITH_WCAF_Affiliate_Factory::get_affiliate_by_user_id( (int) $record->user_id );
				if ( ! $affiliate ) {
					$affiliate_id = $this->create_or_update_yith_affiliate( (int) $record->user_id, $record->full_name, $record->email, $record->social_profiles, $record->about_you, $record->requested_code, $record->id );
					$affiliate    = YITH_WCAF_Affiliate_Factory::get_affiliate_by_user_id( (int) $record->user_id );
				}
				if ( $affiliate && method_exists( $affiliate, 'set_status' ) ) {
					$affiliate->set_status( 'enabled' );
					$affiliate->save();
					$affiliate_id = (int) $affiliate->get_id();
				}
			} catch ( Exception $e ) {
				update_user_meta( (int) $record->user_id, '_ion_affiliate_yith_approval_error', $e->getMessage() );
			}
		}

		$wpdb->update(
			self::table_name(),
			array(
				'application_status' => 'approved',
				'affiliate_id'       => $affiliate_id,
				'decision_at'        => current_time( 'mysql' ),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( 'id' => (int) $record->id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		wp_mail( $record->email, 'Ion Peptide affiliate application approved', "Hi {$record->full_name},\n\nYour Ion Peptide affiliate application has been approved. You can access your affiliate dashboard here:\n" . $this->get_dashboard_url() . "\n\nThank you,\nIon Peptide" );
	}

	private function deny_record( $record ) {
		global $wpdb;

		if ( class_exists( 'YITH_WCAF_Affiliate_Factory' ) && $record->user_id ) {
			try {
				$affiliate = YITH_WCAF_Affiliate_Factory::get_affiliate_by_user_id( (int) $record->user_id );
				if ( $affiliate && method_exists( $affiliate, 'set_status' ) ) {
					$affiliate->set_status( 'disabled' );
					$affiliate->save();
				}
			} catch ( Exception $e ) {
				update_user_meta( (int) $record->user_id, '_ion_affiliate_yith_denial_error', $e->getMessage() );
			}
		}

		$wpdb->update(
			self::table_name(),
			array(
				'application_status' => 'denied',
				'decision_at'        => current_time( 'mysql' ),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( 'id' => (int) $record->id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		wp_mail( $record->email, 'Ion Peptide affiliate application update', "Hi {$record->full_name},\n\nYour Ion Peptide affiliate application was not approved at this time.\n\nThank you,\nIon Peptide" );
	}

	public function admin_export_csv() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'ion_affiliate_export' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ion-affiliate-compliance' ) );
		}

		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table_name() . ' ORDER BY created_at DESC', ARRAY_A );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ion-affiliate-agreements-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		if ( ! empty( $rows ) ) {
			fputcsv( $out, array_keys( $rows[0] ) );
			foreach ( $rows as $row ) {
				fputcsv( $out, $row );
			}
		} else {
			fputcsv( $out, array( 'No records' ) );
		}
		fclose( $out );
		exit;
	}

	public function sync_yith_approval( $affiliate_id, $affiliate ) {
		$this->sync_yith_status( $affiliate_id, $affiliate, 'approved' );
	}

	public function sync_yith_denial( $affiliate_id, $affiliate ) {
		$this->sync_yith_status( $affiliate_id, $affiliate, 'denied' );
	}

	private function sync_yith_status( $affiliate_id, $affiliate, $status ) {
		if ( ! $affiliate || ! is_object( $affiliate ) || ! method_exists( $affiliate, 'get_user_id' ) ) {
			return;
		}

		global $wpdb;
		$user_id = (int) $affiliate->get_user_id();
		if ( ! $user_id ) {
			return;
		}

		$wpdb->update(
			self::table_name(),
			array(
				'application_status' => $status,
				'affiliate_id'       => (int) $affiliate_id,
				'decision_at'        => current_time( 'mysql' ),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( 'user_id' => $user_id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}
}

Ion_Affiliate_Compliance_Flow::instance();
register_activation_hook( __FILE__, array( 'Ion_Affiliate_Compliance_Flow', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Ion_Affiliate_Compliance_Flow', 'deactivate' ) );
