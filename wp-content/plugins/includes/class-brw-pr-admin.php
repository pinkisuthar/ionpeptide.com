<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BRW_PR_Admin {

	const SETTINGS_KEY     = 'brw_pr_settings_v8';
	const OLD_SETTINGS_V7  = 'brw_pr_settings_v7';
	const OLD_SETTINGS_V2  = 'brw_pr_settings_v2';
	const OLD_SETTINGS_V4  = 'brw_pr_settings_v4';
	const OLD_SETTINGS_V5  = 'brw_pr_settings_v5';
	const OLD_SETTINGS_V6  = 'brw_pr_settings_v6';

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade_settings' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
	}

	public static function admin_menu() {
		add_submenu_page(
			'woocommerce',
			'Payment Rotator',
			'Payment Rotator',
			'manage_woocommerce',
			'brw-payment-rotator',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function maybe_upgrade_settings() {
		$current = get_option( self::SETTINGS_KEY, null );

		if ( is_array( $current ) && isset( $current['gateways'], $current['rotating_visible_count'], $current['show_secondary'], $current['daily_reset_time'], $current['daily_reset_timezone'] ) ) {
			return;
		}

		$defaults = self::get_default_settings();
		$old      = get_option( self::OLD_SETTINGS_V7, null );

		if ( ! is_array( $old ) || empty( $old ) ) {
			$old = get_option( self::OLD_SETTINGS_V6, null );
		}

		if ( ! is_array( $old ) || empty( $old ) ) {
			$old = get_option( self::OLD_SETTINGS_V5, null );
		}

		if ( ! is_array( $old ) || empty( $old ) ) {
			$old = get_option( self::OLD_SETTINGS_V4, null );
		}

		if ( ! is_array( $old ) || empty( $old ) ) {
			$old = get_option( self::OLD_SETTINGS_V2, array() );
		}

		if ( ! is_array( $old ) || empty( $old ) ) {
			update_option( self::SETTINGS_KEY, $defaults, false );
			return;
		}

		$new = $defaults;

		if ( isset( $old['label_prefix'] ) ) {
			$new['label_prefix'] = sanitize_text_field( (string) $old['label_prefix'] );
		}

		if ( isset( $old['fee_label'] ) ) {
			$new['fee_label'] = sanitize_text_field( (string) $old['fee_label'] );
		}

		if ( isset( $old['rotating_visible_count'] ) ) {
			$new['rotating_visible_count'] = max( 1, min( 10, (int) $old['rotating_visible_count'] ) );
		}

		if ( isset( $old['show_secondary'] ) ) {
			$new['show_secondary'] = ! empty( $old['show_secondary'] ) ? 1 : 0;
		}

		if ( isset( $old['daily_reset_time'] ) ) {
			$new['daily_reset_time'] = self::sanitize_daily_reset_time( $old['daily_reset_time'] );
		}

		if ( isset( $old['daily_reset_timezone'] ) ) {
			$new['daily_reset_timezone'] = self::sanitize_daily_reset_timezone( $old['daily_reset_timezone'] );
		}

		if ( ! empty( $old['gateways'] ) && is_array( $old['gateways'] ) ) {
			foreach ( $old['gateways'] as $gateway_id => $row ) {
				if ( ! is_array( $row ) ) {
					$row = array();
				}

				$gateway_id = sanitize_text_field( (string) $gateway_id );

				$role = 'secondary';
				if ( isset( $row['role'] ) ) {
					$role = self::sanitize_role( $row['role'] );
				} else {
					$enabled = ! empty( $row['enabled'] );
					$fixed   = ! empty( $row['fixed'] );

					if ( $enabled && $fixed ) {
						$role = 'fixed';
					} elseif ( $enabled ) {
						$role = 'rotating';
					} else {
						$role = 'secondary';
					}
				}

				$new['gateways'][ $gateway_id ] = array(
					'role'             => $role,
					'fixed_order'      => isset( $row['fixed_order'] ) ? max( 1, (int) $row['fixed_order'] ) : 1,
					'weight'           => isset( $row['weight'] ) ? max( 0, min( 100, (float) $row['weight'] ) ) : 0,
					'discount'         => isset( $row['discount'] ) ? max( 0, min( 100, (float) $row['discount'] ) ) : 0,
					'fee'              => isset( $row['fee'] ) ? max( 0, min( 100, (float) $row['fee'] ) ) : 0,
					'min_order_amount' => isset( $row['min_order_amount'] ) ? max( 0, (float) $row['min_order_amount'] ) : 0,
					'daily_cap'        => isset( $row['daily_cap'] ) ? max( 0, (float) $row['daily_cap'] ) : 0,
					'auto_pause'       => ! empty( $row['auto_pause'] ) ? 1 : 0,
					'pause_threshold'  => isset( $row['pause_threshold'] ) ? max( 1, min( 100, (float) $row['pause_threshold'] ) ) : 50,
					'pause_min_count'  => isset( $row['pause_min_count'] ) ? max( 1, (int) $row['pause_min_count'] ) : 4,
					'message'          => isset( $row['message'] ) ? sanitize_text_field( (string) $row['message'] ) : '',
				);
			}
		} elseif ( ! empty( $old['slots'] ) && is_array( $old['slots'] ) ) {
			$position = 1;

			foreach ( $old['slots'] as $slot ) {
				if ( empty( $slot['gateway_id'] ) ) {
					continue;
				}

				$gateway_id = sanitize_text_field( (string) $slot['gateway_id'] );

				$new['gateways'][ $gateway_id ] = array(
					'role'             => 'rotating',
					'fixed_order'      => $position,
					'weight'           => isset( $slot['weight'] ) ? max( 0, min( 100, (float) $slot['weight'] ) ) : 0,
					'discount'         => 0,
					'fee'              => 0,
					'min_order_amount' => 0,
					'daily_cap'        => 0,
					'auto_pause'       => 0,
					'pause_threshold'  => 50,
					'pause_min_count'  => 4,
					'message'          => '',
				);

				$position++;
			}
		}

		update_option( self::SETTINGS_KEY, $new, false );
	}

	public static function get_default_settings() {
		return array(
			'label_prefix'           => 'Save',
			'fee_label'              => 'Payment method discount',
			'rotating_visible_count' => 1,
			'show_secondary'         => 1,
			'daily_reset_time'       => '00:00',
			'daily_reset_timezone'   => '',
			'paid_statuses'          => array( 'processing', 'completed' ),
			'gateways'               => array(),
		);
	}

	public static function get_settings() {
		$settings = get_option( self::SETTINGS_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings = wp_parse_args( $settings, self::get_default_settings() );

		$settings['label_prefix']           = sanitize_text_field( (string) $settings['label_prefix'] );
		$settings['fee_label']              = sanitize_text_field( (string) $settings['fee_label'] );
		$settings['rotating_visible_count'] = max( 1, min( 10, (int) $settings['rotating_visible_count'] ) );
		$settings['show_secondary']         = ! empty( $settings['show_secondary'] ) ? 1 : 0;
		$settings['daily_reset_time']       = self::sanitize_daily_reset_time( isset( $settings['daily_reset_time'] ) ? $settings['daily_reset_time'] : '00:00' );
		$settings['daily_reset_timezone']   = self::sanitize_daily_reset_timezone( isset( $settings['daily_reset_timezone'] ) ? $settings['daily_reset_timezone'] : '' );
		$settings['paid_statuses']          = self::sanitize_paid_statuses( isset( $settings['paid_statuses'] ) ? $settings['paid_statuses'] : array() );

		if ( ! isset( $settings['gateways'] ) || ! is_array( $settings['gateways'] ) ) {
			$settings['gateways'] = array();
		}

		$clean = array();

		foreach ( $settings['gateways'] as $gateway_id => $row ) {
			if ( ! is_array( $row ) ) {
				$row = array();
			}

			$gateway_id = sanitize_text_field( (string) $gateway_id );

			$clean[ $gateway_id ] = array(
				'role'             => isset( $row['role'] ) ? self::sanitize_role( $row['role'] ) : 'secondary',
				'fixed_order'      => isset( $row['fixed_order'] ) ? max( 1, (int) $row['fixed_order'] ) : 1,
				'weight'           => isset( $row['weight'] ) ? max( 0, min( 100, (float) $row['weight'] ) ) : 0,
				'discount'         => isset( $row['discount'] ) ? max( 0, min( 100, (float) $row['discount'] ) ) : 0,
				'fee'              => isset( $row['fee'] ) ? max( 0, min( 100, (float) $row['fee'] ) ) : 0,
				'min_order_amount' => isset( $row['min_order_amount'] ) ? max( 0, (float) $row['min_order_amount'] ) : 0,
				'daily_cap'        => isset( $row['daily_cap'] ) ? max( 0, (float) $row['daily_cap'] ) : 0,
				'auto_pause'       => ! empty( $row['auto_pause'] ) ? 1 : 0,
				'pause_threshold'  => isset( $row['pause_threshold'] ) ? max( 1, min( 100, (float) $row['pause_threshold'] ) ) : 50,
				'pause_min_count'  => isset( $row['pause_min_count'] ) ? max( 1, (int) $row['pause_min_count'] ) : 4,
				'message'          => isset( $row['message'] ) ? sanitize_text_field( (string) $row['message'] ) : '',
			);
		}

		$settings['gateways'] = $clean;

		return $settings;
	}

	public static function save_settings_from_post() {
		$defaults = self::get_default_settings();

		$settings = array(
			'label_prefix'           => isset( $_POST['brw_pr_label_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['brw_pr_label_prefix'] ) ) : $defaults['label_prefix'],
			'fee_label'              => isset( $_POST['brw_pr_fee_label'] ) ? sanitize_text_field( wp_unslash( $_POST['brw_pr_fee_label'] ) ) : $defaults['fee_label'],
			'rotating_visible_count' => isset( $_POST['brw_pr_rotating_visible_count'] ) ? max( 1, min( 10, (int) $_POST['brw_pr_rotating_visible_count'] ) ) : 1,
			'show_secondary'         => ! empty( $_POST['brw_pr_show_secondary'] ) ? 1 : 0,
			'daily_reset_time'       => self::sanitize_daily_reset_time( isset( $_POST['brw_pr_daily_reset_time'] ) ? wp_unslash( $_POST['brw_pr_daily_reset_time'] ) : $defaults['daily_reset_time'] ),
			'daily_reset_timezone'   => self::sanitize_daily_reset_timezone( isset( $_POST['brw_pr_daily_reset_timezone'] ) ? wp_unslash( $_POST['brw_pr_daily_reset_timezone'] ) : $defaults['daily_reset_timezone'] ),
			'paid_statuses'          => self::sanitize_paid_statuses( isset( $_POST['brw_pr_paid_statuses'] ) ? wp_unslash( $_POST['brw_pr_paid_statuses'] ) : array() ),
			'gateways'               => array(),
		);

		$raw_gateways = isset( $_POST['brw_pr_gateways'] ) && is_array( $_POST['brw_pr_gateways'] )
			? $_POST['brw_pr_gateways']
			: array();

		foreach ( $raw_gateways as $gateway_id => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$gateway_id = sanitize_text_field( (string) wp_unslash( $gateway_id ) );

			$settings['gateways'][ $gateway_id ] = array(
				'role'             => isset( $row['role'] ) ? self::sanitize_role( wp_unslash( $row['role'] ) ) : 'secondary',
				'fixed_order'      => isset( $row['fixed_order'] ) ? max( 1, (int) $row['fixed_order'] ) : 1,
				'weight'           => isset( $row['weight'] ) ? max( 0, min( 100, (float) wp_unslash( $row['weight'] ) ) ) : 0,
				'discount'         => isset( $row['discount'] ) ? max( 0, min( 100, (float) wp_unslash( $row['discount'] ) ) ) : 0,
				'fee'              => isset( $row['fee'] ) ? max( 0, min( 100, (float) wp_unslash( $row['fee'] ) ) ) : 0,
				'min_order_amount' => isset( $row['min_order_amount'] ) ? max( 0, (float) wp_unslash( $row['min_order_amount'] ) ) : 0,
				'daily_cap'        => isset( $row['daily_cap'] ) ? max( 0, (float) wp_unslash( $row['daily_cap'] ) ) : 0,
				'auto_pause'       => ! empty( $row['auto_pause'] ) ? 1 : 0,
				'pause_threshold'  => isset( $row['pause_threshold'] ) ? max( 1, min( 100, (float) wp_unslash( $row['pause_threshold'] ) ) ) : 50,
				'pause_min_count'  => isset( $row['pause_min_count'] ) ? max( 1, (int) wp_unslash( $row['pause_min_count'] ) ) : 4,
				'message'          => isset( $row['message'] ) ? sanitize_text_field( wp_unslash( $row['message'] ) ) : '',
			);
		}

		update_option( self::SETTINGS_KEY, $settings, false );

		$license_key = isset( $_POST['brw_pr_license_key'] )
			? sanitize_text_field( wp_unslash( $_POST['brw_pr_license_key'] ) )
			: '';

		update_option( BRW_PR_License::LICENSE_OPTION, $license_key, false );
		delete_transient( BRW_PR_License::LICENSE_TRANSIENT );
		BRW_PR_License::check_license( true );
	}


	public static function get_paid_status_options() {
		$options = array();

		if ( function_exists( 'wc_get_order_statuses' ) ) {
			$options = wc_get_order_statuses();
		}

		if ( empty( $options ) || ! is_array( $options ) ) {
			$options = array(
				'wc-pending'    => 'Pending payment',
				'wc-processing' => 'Processing',
				'wc-on-hold'    => 'On hold',
				'wc-completed'  => 'Completed',
				'wc-cancelled'  => 'Cancelled',
				'wc-refunded'   => 'Refunded',
				'wc-failed'     => 'Failed',
			);
		}

		return $options;
	}


	public static function sanitize_daily_reset_time( $time ) {
		$time = trim( (string) $time );

		if ( '' === $time ) {
			return '00:00';
		}

		if ( preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			return $time;
		}

		return '00:00';
	}

	public static function sanitize_daily_reset_timezone( $timezone ) {
		$timezone = trim( sanitize_text_field( (string) $timezone ) );

		if ( '' === $timezone ) {
			return '';
		}

		try {
			new DateTimeZone( $timezone );
			return $timezone;
		} catch ( Exception $e ) {
			return '';
		}
	}

	public static function get_effective_daily_reset_timezone( $settings = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = self::get_settings();
		}

		$timezone = isset( $settings['daily_reset_timezone'] ) ? self::sanitize_daily_reset_timezone( $settings['daily_reset_timezone'] ) : '';

		if ( '' !== $timezone ) {
			return $timezone;
		}

		if ( function_exists( 'wp_timezone_string' ) ) {
			$wp_timezone = wp_timezone_string();
			if ( is_string( $wp_timezone ) && '' !== $wp_timezone ) {
				return $wp_timezone;
			}
		}

		return 'UTC';
	}

	public static function sanitize_paid_statuses( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			$statuses = array();
		}

		$allowed = array_keys( self::get_paid_status_options() );
		$clean   = array();

		foreach ( $statuses as $status ) {
			$status = sanitize_key( str_replace( 'wc-', '', (string) $status ) );

			if ( '' === $status ) {
				continue;
			}

			if ( in_array( 'wc-' . $status, $allowed, true ) ) {
				$clean[] = $status;
			}
		}

		$clean = array_values( array_unique( $clean ) );

		if ( empty( $clean ) ) {
			$clean = array( 'processing', 'completed' );
		}

		return $clean;
	}

	public static function get_admin_available_gateways() {
		$result = array();

		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->payment_gateways() ) {
			return $result;
		}

		$gateways_obj = WC()->payment_gateways();
		$all          = array();

		if ( method_exists( $gateways_obj, 'payment_gateways' ) ) {
			$all = $gateways_obj->payment_gateways();
		}

		if ( ! is_array( $all ) || empty( $all ) ) {
			$all = $gateways_obj->get_available_payment_gateways();
		}

		if ( ! is_array( $all ) ) {
			return $result;
		}

		foreach ( $all as $id => $gateway ) {
			if ( ! is_object( $gateway ) ) {
				continue;
			}

			$title = ! empty( $gateway->method_title )
				? $gateway->method_title
				: ( ! empty( $gateway->title ) ? wp_strip_all_tags( $gateway->title ) : $id );

			$result[ (string) $id ] = array(
				'label'        => (string) $title,
				'wc_enabled'   => isset( $gateway->enabled ) && 'yes' === $gateway->enabled,
			);
		}

		uasort(
			$result,
			static function( $a, $b ) {
				return strnatcasecmp( $a['label'], $b['label'] );
			}
		);

		return $result;
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_POST['brw_pr_save'] ) ) {
			check_admin_referer( 'brw_pr_save_settings' );
			self::save_settings_from_post();
			echo '<div class="notice notice-success is-dismissible"><p>Payment rotator settings saved.</p></div>';
		}

		$settings        = self::get_settings();
		$available       = self::get_admin_available_gateways();
		$license_key     = (string) get_option( BRW_PR_License::LICENSE_OPTION, '' );
		$license_status  = BRW_PR_License::get_license_status();
		$license_message = ! empty( $license_status['message'] ) ? (string) $license_status['message'] : '';

		$total_gateways     = count( $available );
		$active_wc_gateways = 0;
		$paused_gateways    = 0;
		$role_counts        = array(
			'fixed'     => 0,
			'rotating'  => 0,
			'secondary' => 0,
			'hidden'    => 0,
		);

		foreach ( $available as $gateway_id => $gateway_data ) {
			if ( ! empty( $gateway_data['wc_enabled'] ) ) {
				$active_wc_gateways++;
			}

			$row_role = isset( $settings['gateways'][ $gateway_id ]['role'] ) ? self::sanitize_role( $settings['gateways'][ $gateway_id ]['role'] ) : 'secondary';
			if ( isset( $role_counts[ $row_role ] ) ) {
				$role_counts[ $row_role ]++;
			}

			if ( class_exists( 'BRW_PR_Router' ) && BRW_PR_Router::is_gateway_auto_paused_public( $gateway_id ) ) {
				$paused_gateways++;
			}
		}

		?>
		<div class="wrap brw-pr-admin">
			<style>
				.brw-pr-admin{
					--brw-bg:#efedeb;
					--brw-bg2:#f7f5f3;
					--brw-panel:rgba(255,255,255,.84);
					--brw-panel-solid:#ffffff;
					--brw-line:#d9d3ce;
					--brw-line-2:#cec7c0;
					--brw-text:#17181d;
					--brw-muted:#585a63;
					--brw-soft:#72757d;
					--brw-blue:#4f60e2;
					--brw-blue-2:#3e4fcb;
					--brw-blue-3:#edf0ff;
					--brw-green:#2ea76b;
					--brw-amber:#9a6a16;
					--brw-red:#c54a58;
					--brw-red-bg:#fff0f2;
					--brw-shadow:0 16px 38px rgba(23,24,29,.08);
					--brw-shadow-soft:0 10px 24px rgba(23,24,29,.06);
					margin:20px 20px 0 0;
				}
				.brw-pr-admin *,
				.brw-pr-admin *::before,
				.brw-pr-admin *::after{box-sizing:border-box;}
				.brw-pr-shell{
					max-width:100%;
					width:100%;
					background:
						radial-gradient(circle at top left, rgba(255,255,255,.8), transparent 34%),
						radial-gradient(circle at 88% 8%, rgba(79,96,226,.08), transparent 22%),
						linear-gradient(180deg,var(--brw-bg2) 0%,var(--brw-bg) 100%);
					border:1px solid var(--brw-line);
					border-radius:26px;
					box-shadow:var(--brw-shadow);
					padding:24px;
					color:var(--brw-text);
					position:relative;
					overflow:hidden;
				}
				.brw-pr-shell::before{
					content:"";
					position:absolute;
					inset:0;
					pointer-events:none;
					background:
						linear-gradient(135deg, rgba(255,255,255,.14) 0%, transparent 26%, transparent 100%),
						linear-gradient(180deg, rgba(255,255,255,.12), transparent 180px);
				}
				.brw-pr-admin h1,
				.brw-pr-admin h2,
				.brw-pr-admin h3{color:var(--brw-text); margin:0;}
				.brw-pr-admin p{color:var(--brw-muted);}
				.brw-pr-topbar,
				.brw-pr-grid,
				.brw-pr-savebar,
				.brw-pr-toolbar,
				.brw-pr-card,
				.brw-pr-table-wrap{position:relative; z-index:1;}
				.brw-pr-topbar{
					display:flex;
					gap:18px;
					justify-content:space-between;
					align-items:flex-start;
					flex-wrap:wrap;
					margin-bottom:22px;
				}
				.brw-pr-kicker{
					display:inline-flex;
					align-items:center;
					justify-content:center;
					gap:8px;
					width:max-content;
					max-width:100%;
					height:44px;
					padding:0 18px;
					border-radius:999px;
					border:1px solid #d5dafd;
					background:#f1f3ff;
					color:#3340a8;
					font-size:11px;
					font-weight:800;
					letter-spacing:.04em;
					text-transform:uppercase;
					margin-bottom:10px;
				}
				.brw-pr-title{
					font-size:32px;
					font-weight:800;
					line-height:1.06;
					letter-spacing:-.03em;
					margin-bottom:8px;
				}
				.brw-pr-subtitle{
					max-width:760px;
					font-size:14px;
					line-height:1.7;
					margin:0;
				}
				.brw-pr-actions{display:flex; flex-wrap:wrap; gap:10px; align-items:center;}
				.brw-pr-button,
				.brw-pr-admin .button-primary{
					border:none !important;
					border-radius:14px !important;
					padding:0 18px !important;
					min-height:46px !important;
					display:inline-flex !important;
					align-items:center;
					justify-content:center;
					font-weight:760 !important;
					font-size:14px !important;
					box-shadow:none !important;
				}
				.brw-pr-admin .button-primary{
					background:linear-gradient(180deg,var(--brw-blue),var(--brw-blue-2)) !important;
					color:#fff !important;
					box-shadow:0 12px 24px rgba(79,96,226,.20) !important;
				}
				.brw-pr-button{
					background:linear-gradient(180deg,#fff,#f2efec);
					border:1px solid var(--brw-line-2) !important;
					color:var(--brw-text);
					text-decoration:none;
					box-shadow:var(--brw-shadow-soft);
				}
				.brw-pr-grid{display:grid; grid-template-columns:repeat(12,minmax(0,1fr)); gap:18px;}
				.brw-pr-card{
					background:
						linear-gradient(180deg, rgba(255,255,255,.84), rgba(255,255,255,.74)),
						repeating-linear-gradient(0deg, rgba(0,0,0,.012) 0px, rgba(0,0,0,.012) 1px, transparent 1px, transparent 9px);
					border:1px solid var(--brw-line);
					border-radius:22px;
					box-shadow:var(--brw-shadow-soft);
					overflow:hidden;
				}
				.brw-pr-card-inner{padding:22px;}
				.brw-pr-summary{grid-column:span 12;}
				.brw-pr-summary-grid{display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:14px;}
				.brw-pr-stat{
					padding:16px;
					border-radius:18px;
					background:rgba(255,255,255,.72);
					border:1px solid var(--brw-line);
					box-shadow:var(--brw-shadow-soft);
				}
				.brw-pr-stat-label{display:block; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--brw-soft); margin-bottom:8px;}
				.brw-pr-stat-value{font-size:28px; font-weight:800; line-height:1; color:var(--brw-text);}
				.brw-pr-stat-note{margin-top:6px; font-size:12px; color:var(--brw-muted);}
				.brw-pr-license{grid-column:span 4;}
				.brw-pr-help-grid{display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; margin-top:14px;}
				.brw-pr-help-card{padding:16px; border-radius:18px; background:rgba(255,255,255,.76); border:1px solid var(--brw-line); box-shadow:var(--brw-shadow-soft);}
				.brw-pr-help-card h3{font-size:14px; margin:0 0 8px;}
				.brw-pr-help-card p{margin:0; font-size:13px; line-height:1.65;}
				.brw-pr-help-card ul{margin:10px 0 0; padding-left:18px;}
				.brw-pr-help-card li{margin:0 0 6px; color:var(--brw-muted);}
				.brw-pr-router{grid-column:span 8;}
				.brw-pr-section-head{display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:14px; flex-wrap:wrap;}
				.brw-pr-section-copy{margin:6px 0 0; font-size:13px; line-height:1.65;}
				.brw-pr-list{margin:0; padding-left:18px;}
				.brw-pr-list li{color:var(--brw-muted); margin:0 0 8px;}
				.brw-pr-list strong{color:var(--brw-text);}
				.brw-pr-list code,
				.brw-pr-gateway code{
					display:inline-block;
					padding:4px 8px;
					border-radius:8px;
					background:#f5f6fa;
					color:#4b5364;
					border:1px solid var(--brw-line);
				}
				.brw-pr-license-status{
					display:inline-flex;
					align-items:center;
					gap:8px;
					padding:8px 12px;
					border-radius:999px;
					font-size:12px;
					font-weight:800;
					background:#f6f7fb;
					border:1px solid var(--brw-line);
					color:var(--brw-muted);
				}
				.brw-pr-license-status.is-valid{color:var(--brw-green); border-color:rgba(46,167,107,.25); background:rgba(46,167,107,.08);}
				.brw-pr-license-status.is-invalid{color:var(--brw-red); border-color:rgba(197,74,88,.25); background:rgba(197,74,88,.08);}
				.brw-pr-admin .form-table th,
				.brw-pr-admin .form-table td{padding:14px 10px 14px 0; vertical-align:top;}
				.brw-pr-admin .form-table th{width:240px; color:var(--brw-text); font-weight:700;}
				.brw-pr-admin label{color:var(--brw-text);}
				.brw-pr-admin .description{color:var(--brw-muted);}
				.brw-pr-admin input[type="text"],
				.brw-pr-admin input[type="number"],
				.brw-pr-admin input[type="search"],
				.brw-pr-admin select{
					min-height:42px;
					border-radius:14px !important;
					border:1px solid var(--brw-line-2) !important;
					background:#fff !important;
					color:var(--brw-text) !important;
					padding:8px 12px !important;
					box-shadow:none !important;
				}
				.brw-pr-admin input[type="checkbox"]{border-radius:6px !important;}
				.brw-pr-admin input::placeholder{color:#878b95;}
				.brw-pr-admin input:focus,
				.brw-pr-admin select:focus,
				.brw-pr-admin .button-primary:focus,
				.brw-pr-button:focus{outline:none; border-color:var(--brw-blue) !important; box-shadow:0 0 0 3px rgba(79,96,226,.14) !important;}
				.brw-pr-toolbar{grid-column:span 12; display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:14px;}
				.brw-pr-toolbar-left,
				.brw-pr-toolbar-right{display:flex; flex-wrap:wrap; align-items:center; gap:10px;}
				.brw-pr-search{min-width:260px;}
				.brw-pr-chip{
					display:inline-flex;
					align-items:center;
					gap:7px;
					padding:8px 12px;
					border-radius:999px;
					border:1px solid #d7ddff;
					background:#eef1ff;
					color:#3340a8;
					font-size:12px;
					font-weight:800;
				}
				.brw-pr-pill{display:inline-flex; align-items:center; gap:6px; padding:5px 10px; border-radius:999px; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.03em; border:1px solid transparent;}
				.brw-pr-pill.role-fixed{background:#eef1ff; color:#3340a8; border-color:#d7ddff;}
				.brw-pr-pill.role-rotating{background:#edf9f1; color:#1f7b50; border-color:#cdebd9;}
				.brw-pr-pill.role-secondary{background:#fff8e8; color:#9a6a16; border-color:#f0dfae;}
				.brw-pr-pill.role-hidden{background:#f2f3f5; color:#58606d; border-color:#dfe3e8;}
				.brw-pr-pill.status-paused{background:var(--brw-red-bg); color:var(--brw-red); border-color:#f3c7cd;}
				.brw-pr-table-wrap{grid-column:span 12; overflow:visible; border-radius:22px;}
				.brw-pr-admin table.widefat{
					width:100% !important;
					table-layout:fixed;
					border:1px solid var(--brw-line) !important;
					border-radius:22px !important;
					overflow:hidden;
					background:rgba(255,255,255,.88) !important;
					box-shadow:var(--brw-shadow-soft) !important;
				}
				.brw-pr-admin table.widefat thead th{
					background:#f5f6fa !important;
					color:var(--brw-text) !important;
					border-bottom:1px solid var(--brw-line) !important;
					padding:13px 6px !important;
					font-size:10px;
					text-transform:uppercase;
					letter-spacing:.04em;
					white-space:nowrap;
				}
				.brw-pr-admin table.widefat th{word-wrap:normal; overflow-wrap:normal;}
				.brw-pr-admin table.widefat td{word-wrap:break-word; overflow-wrap:anywhere;}
				.brw-pr-admin table.widefat select{min-width:0; width:100%; max-width:100%; padding-right:34px !important; appearance:auto;}
				.brw-pr-admin table.widefat input[type="number"]{min-width:0; width:100%; max-width:100%;}
				.brw-pr-admin table.widefat input[type="text"]{width:100%;}
				.brw-pr-admin table.widefat td{background:transparent !important; color:var(--brw-text); border-bottom:1px solid rgba(23,24,29,.06) !important; padding:12px 6px !important; vertical-align:middle;}
				.brw-pr-admin table.widefat th:nth-child(1),
				.brw-pr-admin table.widefat td:nth-child(1){width:16%;}
				.brw-pr-admin table.widefat th:nth-child(2),
				.brw-pr-admin table.widefat td:nth-child(2){width:11%;}
				.brw-pr-admin table.widefat th:nth-child(3),
				.brw-pr-admin table.widefat td:nth-child(3){width:5%;}
				.brw-pr-admin table.widefat th:nth-child(4),
				.brw-pr-admin table.widefat td:nth-child(4),
				.brw-pr-admin table.widefat th:nth-child(5),
				.brw-pr-admin table.widefat td:nth-child(5),
				.brw-pr-admin table.widefat th:nth-child(6),
				.brw-pr-admin table.widefat td:nth-child(6){width:6.5%;}
				.brw-pr-admin table.widefat th:nth-child(7),
				.brw-pr-admin table.widefat td:nth-child(7){width:8%;}
				.brw-pr-admin table.widefat th:nth-child(8),
				.brw-pr-admin table.widefat td:nth-child(8),
				.brw-pr-admin table.widefat th:nth-child(9),
				.brw-pr-admin table.widefat td:nth-child(9),
				.brw-pr-admin table.widefat th:nth-child(10),
				.brw-pr-admin table.widefat td:nth-child(10),
				.brw-pr-admin table.widefat th:nth-child(11),
				.brw-pr-admin table.widefat td:nth-child(11){width:4.5%;}
				.brw-pr-admin table.widefat th:nth-child(12),
				.brw-pr-admin table.widefat td:nth-child(12),
				.brw-pr-admin table.widefat th:nth-child(13),
				.brw-pr-admin table.widefat td:nth-child(13){width:6.5%;}
				.brw-pr-admin table.widefat th:nth-child(14),
				.brw-pr-admin table.widefat td:nth-child(14){width:20%;}
				.brw-pr-admin table.widefat tbody tr:nth-child(odd) td{background:rgba(255,255,255,.50) !important;}
				.brw-pr-admin table.widefat tbody tr.is-paused td{background:rgba(197,74,88,.06) !important;}
				.brw-pr-gateway{min-width:0;}
				.brw-pr-message-col{min-width:0; width:auto;}
				.brw-pr-message-input{min-width:0;}
				.brw-pr-gateway strong{display:block; margin-bottom:8px; color:var(--brw-text);}
				.brw-pr-row-note{margin-top:8px; font-size:12px; font-weight:700;}
				.brw-pr-row-note.warn{color:var(--brw-amber);}
				.brw-pr-row-note.danger{color:var(--brw-red);}
				.brw-pr-metric{font-weight:700; white-space:nowrap; color:var(--brw-text);}
				.brw-pr-savebar{
					position:sticky;
					bottom:16px;
					z-index:20;
					display:flex;
					align-items:center;
					justify-content:space-between;
					gap:16px;
					margin-top:18px;
					padding:14px 16px;
					background:rgba(255,255,255,.88);
					border:1px solid var(--brw-line);
					border-radius:18px;
					backdrop-filter:blur(10px);
					box-shadow:var(--brw-shadow);
				}
				.brw-pr-savebar-copy{font-size:13px; line-height:1.5; color:var(--brw-muted);}
				.brw-pr-admin .notice.notice-success{border-left-color:var(--brw-green);}
				.brw-pr-admin .notice.notice-success p{color:#1d2327;}
				@media (max-width: 1380px){
					.brw-pr-admin table.widefat thead{display:none;}
					.brw-pr-admin table.widefat,
					.brw-pr-admin table.widefat tbody,
					.brw-pr-admin table.widefat tr,
					.brw-pr-admin table.widefat td{display:block; width:100% !important;}
					.brw-pr-admin table.widefat tr{padding:16px 16px 8px; border-bottom:1px solid var(--brw-line);}
					.brw-pr-admin table.widefat td{padding:8px 0 !important; border-bottom:none !important;}
					.brw-pr-admin table.widefat td::before{content:attr(data-label); display:block; margin-bottom:6px; color:var(--brw-soft); font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.04em;}
					.brw-pr-gateway,
					.brw-pr-message-col{min-width:0; width:auto;}
					.brw-pr-message-input{width:100% !important;}
					.brw-pr-admin table.widefat select,
					.brw-pr-admin table.widefat input[type="number"],
					.brw-pr-admin table.widefat input[type="text"]{width:100% !important; max-width:100%;}
				}
				@media (max-width: 1280px){
					.brw-pr-license,
					.brw-pr-router{grid-column:span 12;}
					.brw-pr-summary-grid{grid-template-columns:repeat(3,minmax(0,1fr));}
					.brw-pr-help-grid{grid-template-columns:1fr;}
				}
				@media (max-width: 782px){
					.brw-pr-shell{padding:18px;}
					.brw-pr-title{font-size:26px;}
					.brw-pr-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
					.brw-pr-admin table.widefat thead{display:none;}
					.brw-pr-admin table.widefat,
					.brw-pr-admin table.widefat tbody,
					.brw-pr-admin table.widefat tr,
					.brw-pr-admin table.widefat td{display:block; width:100% !important;}
					.brw-pr-admin table.widefat tr{padding:14px 14px 6px; border-bottom:1px solid var(--brw-line);}
					.brw-pr-admin table.widefat td{padding:8px 0 !important; border-bottom:none !important;}
					.brw-pr-admin table.widefat td::before{content:attr(data-label); display:block; margin-bottom:6px; color:var(--brw-soft); font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.04em;}
					.brw-pr-gateway{min-width:0;}
					.brw-pr-search{min-width:100%;}
					.brw-pr-savebar{position:static;}
				}
				@media (max-width: 560px){
					.brw-pr-summary-grid{grid-template-columns:1fr;}
				}
			</style>

			<div class="brw-pr-shell">
				<div class="brw-pr-topbar">
					<div>
						<div class="brw-pr-kicker">WooCommerce checkout control</div>
						<h1 class="brw-pr-title">BRW Payment Rotator</h1>
						<p class="brw-pr-subtitle">Configure gateway order, weighted rotation, pricing incentives, and protection rules from one clean screen built for real store use.</p>
					</div>
					<div class="brw-pr-actions">
						<a href="#brw-pr-gateway-rules" class="brw-pr-button">Jump to gateway rules</a>
					</div>
				</div>

				<form method="post">
					<?php wp_nonce_field( 'brw_pr_save_settings' ); ?>

					<div class="brw-pr-grid">
						<section class="brw-pr-card brw-pr-summary">
							<div class="brw-pr-card-inner">
								<div class="brw-pr-section-head">
									<div>
										<h2>Overview</h2>
										<p class="brw-pr-section-copy">Manage which payment methods appear at checkout, how they are prioritized, and what customers see during payment selection.</p>
									</div>
									<div class="brw-pr-chip">Configured gateways: <?php echo esc_html( (string) $total_gateways ); ?></div>
								</div>
								<div class="brw-pr-summary-grid">
									<div class="brw-pr-stat">
										<span class="brw-pr-stat-label">WooCommerce active</span>
										<div class="brw-pr-stat-value"><?php echo esc_html( (string) $active_wc_gateways ); ?></div>
										<div class="brw-pr-stat-note">Gateways enabled in WooCommerce settings</div>
									</div>
									<div class="brw-pr-stat">
										<span class="brw-pr-stat-label">Fixed</span>
										<div class="brw-pr-stat-value"><?php echo esc_html( (string) $role_counts['fixed'] ); ?></div>
										<div class="brw-pr-stat-note">Shown first by position</div>
									</div>
									<div class="brw-pr-stat">
										<span class="brw-pr-stat-label">Rotating</span>
										<div class="brw-pr-stat-value"><?php echo esc_html( (string) $role_counts['rotating'] ); ?></div>
										<div class="brw-pr-stat-note">Weighted pool assignments</div>
									</div>
									<div class="brw-pr-stat">
										<span class="brw-pr-stat-label">Secondary</span>
										<div class="brw-pr-stat-value"><?php echo esc_html( (string) $role_counts['secondary'] ); ?></div>
										<div class="brw-pr-stat-note">Optional fallback section</div>
									</div>
									<div class="brw-pr-stat">
										<span class="brw-pr-stat-label">Hidden</span>
										<div class="brw-pr-stat-value"><?php echo esc_html( (string) $role_counts['hidden'] ); ?></div>
										<div class="brw-pr-stat-note">Excluded from checkout display</div>
									</div>
									<div class="brw-pr-stat">
										<span class="brw-pr-stat-label">Paused today</span>
										<div class="brw-pr-stat-value"><?php echo esc_html( (string) $paused_gateways ); ?></div>
										<div class="brw-pr-stat-note">Benchmarked by auto-pause rules</div>
									</div>
								</div>
							</div>
						</section>

						<section class="brw-pr-card brw-pr-license">
							<div class="brw-pr-card-inner">
								<div class="brw-pr-section-head">
									<div>
										<h2>License</h2>
										<p class="brw-pr-section-copy">Attach the domain license key used for production activation.</p>
									</div>
									<div class="brw-pr-license-status <?php echo ! empty( $license_status['valid'] ) ? 'is-valid' : 'is-invalid'; ?>">
										<?php echo ! empty( $license_status['valid'] ) ? 'Active' : 'Inactive'; ?>
									</div>
								</div>

								<table class="form-table" role="presentation">
									<tbody>
										<tr>
											<th scope="row"><label for="brw_pr_license_key">License key</label></th>
											<td>
												<input type="text" name="brw_pr_license_key" id="brw_pr_license_key" value="<?php echo esc_attr( $license_key ); ?>" style="width:100%;max-width:460px;" />
												<p class="description">Paste the BuildRightWeb license key for this domain.</p>
												<?php if ( $license_message ) : ?>
													<p class="description" style="margin-top:8px;"><?php echo esc_html( $license_message ); ?></p>
												<?php endif; ?>
											</td>
										</tr>
										<tr>
											<th scope="row">Statuses that count as paid today</th>
											<td>
												<?php $paid_status_options = self::get_paid_status_options(); ?>
												<fieldset>
													<legend class="screen-reader-text"><span>Statuses that count as paid today</span></legend>
													<?php foreach ( $paid_status_options as $paid_status_key => $paid_status_label ) : ?>
														<?php $paid_status_value = sanitize_key( str_replace( 'wc-', '', (string) $paid_status_key ) ); ?>
														<label style="display:inline-flex;align-items:center;gap:8px;margin:0 18px 10px 0;">
															<input type="checkbox" name="brw_pr_paid_statuses[]" value="<?php echo esc_attr( $paid_status_value ); ?>" <?php checked( in_array( $paid_status_value, $settings['paid_statuses'], true ) ); ?> />
															<span><?php echo esc_html( $paid_status_label ); ?></span>
														</label>
													<?php endforeach; ?>
												</fieldset>
												<p class="description">Choose which WooCommerce order statuses should count toward the daily revenue total shown in this dashboard.</p>
											</td>
										</tr>
									</tbody>
								</table>
							</div>
						</section>

						<section class="brw-pr-card brw-pr-router">
							<div class="brw-pr-card-inner">
								<div class="brw-pr-section-head">
									<div>
										<h2>Router settings</h2>
										<p class="brw-pr-section-copy">Top-level controls that affect visible rotating slots, fallback visibility, and front-end wording.</p>
									</div>
								</div>

								<table class="form-table" role="presentation">
									<tbody>
										<tr>
											<th scope="row"><label for="brw_pr_rotating_visible_count">Rotating gateways to show at once</label></th>
											<td>
												<input type="number" min="1" max="10" step="1" id="brw_pr_rotating_visible_count" name="brw_pr_rotating_visible_count" value="<?php echo esc_attr( (int) $settings['rotating_visible_count'] ); ?>" style="width:110px;" />
												<p class="description">Each visitor gets this many unique rotating gateways assigned from the eligible rotating pool.</p>
											</td>
										</tr>
										<tr>
											<th scope="row">Show Secondary Gateways</th>
											<td>
												<label>
													<input type="checkbox" name="brw_pr_show_secondary" value="1" <?php checked( ! empty( $settings['show_secondary'] ) ); ?> />
													Show gateways marked as Secondary after fixed + rotating options.
												</label>
											</td>
										</tr>
									<tr>
										<th scope="row"><label for="brw_pr_daily_reset_time">Daily cap reset time</label></th>
										<td>
											<div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
												<input type="time" id="brw_pr_daily_reset_time" name="brw_pr_daily_reset_time" value="<?php echo esc_attr( $settings['daily_reset_time'] ); ?>" step="60" style="width:150px;" />
												<select id="brw_pr_daily_reset_timezone" name="brw_pr_daily_reset_timezone" style="min-width:280px;max-width:100%;">
													<?php echo wp_timezone_choice( self::get_effective_daily_reset_timezone( $settings ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
												</select>
											</div>
											<p class="description">Global reset for all daily caps and today-tracking. Default is 00:00 (midnight). Leave the timezone as-is to use the site timezone.</p>
										</td>
									</tr>
										<tr>
											<th scope="row"><label for="brw_pr_label_prefix">Discount badge prefix</label></th>
											<td>
												<input type="text" id="brw_pr_label_prefix" name="brw_pr_label_prefix" value="<?php echo esc_attr( $settings['label_prefix'] ); ?>" style="width:100%;max-width:420px;" />
												<p class="description">Example: Save → Save 5%</p>
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="brw_pr_fee_label">Discount total label</label></th>
											<td>
												<input type="text" id="brw_pr_fee_label" name="brw_pr_fee_label" value="<?php echo esc_attr( $settings['fee_label'] ); ?>" style="width:100%;max-width:420px;" />
											</td>
										</tr>
									</tbody>
								</table>
							</div>
						</section>

						<section class="brw-pr-card brw-pr-summary">
							<div class="brw-pr-card-inner">
								<div class="brw-pr-section-head">
									<div>
										<h2>How routing works</h2>
										<p class="brw-pr-section-copy">Quick guide for setting up checkout behavior with confidence.</p>
									</div>
								</div>
								<ul class="brw-pr-list">
									<li><strong>Fixed</strong> = always shown first in the position you choose, unless daily cap or auto-pause removes it.</li>
									<li><strong>Rotating</strong> = part of the sticky weighted rotation pool. Each visitor gets the configured number of rotating gateways assigned to them.</li>
									<li><strong>Secondary</strong> = optional fallback section shown after fixed + rotating, only if “Show Secondary Gateways” is on.</li>
									<li><strong>Hidden</strong> = excluded from this plugin’s checkout display.</li>
									<li><strong>Today Proc $</strong> = total from orders that reached the WooCommerce statuses you selected in Router settings for daily revenue tracking.</li>
									<li><strong>Reset time</strong> = one global business-day cutoff used for every gateway daily cap so totals stay aligned across the dashboard.</li>
									<li><strong>Auto Pause</strong> = if failure % reaches the threshold after minimum tries, that gateway is benched for the rest of the day.</li>
									<li><strong>Min $</strong> = optional minimum order subtotal needed before that gateway can appear at checkout.</li>
								</ul>
							</div>
						</section>

						<section class="brw-pr-toolbar" id="brw-pr-gateway-rules">
							<div class="brw-pr-toolbar-left">
								<h2>Gateway rules</h2>
								<span class="brw-pr-chip">Quick filters</span>
							</div>
							<div class="brw-pr-toolbar-right">
								<input type="search" class="brw-pr-search" id="brw-pr-gateway-search" placeholder="Filter gateways by name or ID…" />
								<select id="brw-pr-role-filter">
									<option value="all">All roles</option>
									<option value="fixed">Fixed</option>
									<option value="rotating">Rotating</option>
									<option value="secondary">Secondary</option>
									<option value="hidden">Hidden</option>
									<option value="paused">Paused today</option>
								</select>
							</div>
						</section>

						<div class="brw-pr-table-wrap">
							<table class="widefat striped" style="max-width:none;width:100%;">
								<thead>
									<tr>
										<th style="width:180px;">Gateway</th>
										<th style="width:110px;">Role</th>
										<th style="width:50px;">Pos.</th>
										<th style="width:60px;">Weight %</th>
										<th style="width:60px;">Discount %</th>
										<th style="width:56px;">Fee %</th>
										<th style="width:72px;">Min $</th>
										<th style="width:70px;">Daily Cap $</th>
										<th style="width:64px;">Today $</th>
										<th style="width:42px;">Proc</th>
										<th style="width:42px;">Fail</th>
										<th style="width:48px;">Fail %</th>
										<th style="width:44px;">Pause</th>
										<th style="width:56px;">Pause %</th>
										<th style="width:56px;">Min Try</th>
										<th class="brw-pr-message-col" style="width:260px;">Checkout Message</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $available as $gateway_id => $gateway_data ) :
										$gateway_label = $gateway_data['label'];
										$wc_enabled    = ! empty( $gateway_data['wc_enabled'] );

										$row = isset( $settings['gateways'][ $gateway_id ] ) ? $settings['gateways'][ $gateway_id ] : array(
											'role'             => 'secondary',
											'fixed_order'      => 1,
											'weight'           => 0,
											'discount'         => 0,
											'fee'              => 0,
											'min_order_amount' => 0,
											'daily_cap'        => 0,
											'auto_pause'       => 0,
											'pause_threshold'  => 50,
											'pause_min_count'  => 4,
											'message'          => '',
										);

										$today_processing_total = class_exists( 'BRW_PR_Router' ) ? BRW_PR_Router::get_gateway_today_processing_total( $gateway_id ) : 0;
										$today_processing_count = class_exists( 'BRW_PR_Router' ) ? BRW_PR_Router::get_gateway_today_processing_count( $gateway_id ) : 0;
										$today_failed_count     = class_exists( 'BRW_PR_Router' ) ? BRW_PR_Router::get_gateway_today_failed_count( $gateway_id ) : 0;
										$today_fail_percent     = class_exists( 'BRW_PR_Router' ) ? BRW_PR_Router::get_gateway_today_fail_percent( $gateway_id ) : 0;
										$is_paused_today        = class_exists( 'BRW_PR_Router' ) ? BRW_PR_Router::is_gateway_auto_paused_public( $gateway_id ) : false;
										?>
										<tr class="brw-pr-row<?php echo $is_paused_today ? ' is-paused' : ''; ?>" data-role="<?php echo esc_attr( $row['role'] ); ?>" data-paused="<?php echo $is_paused_today ? '1' : '0'; ?>" data-search="<?php echo esc_attr( strtolower( $gateway_label . ' ' . $gateway_id ) ); ?>">
											<td class="brw-pr-gateway" data-label="Gateway">
												<strong><?php echo esc_html( $gateway_label ); ?></strong>
												<code><?php echo esc_html( $gateway_id ); ?></code>
												<div style="margin-top:10px;">
													<span class="brw-pr-pill role-<?php echo esc_attr( $row['role'] ); ?>"><?php echo esc_html( ucfirst( $row['role'] ) ); ?></span>
													<?php if ( $is_paused_today ) : ?>
														<span class="brw-pr-pill status-paused">Paused today</span>
													<?php endif; ?>
												</div>
												<?php if ( ! $wc_enabled ) : ?>
													<div class="brw-pr-row-note warn">Disabled in WooCommerce Payments</div>
												<?php endif; ?>
												<?php if ( $is_paused_today ) : ?>
													<div class="brw-pr-row-note danger">Auto-pause is currently benching this gateway.</div>
												<?php endif; ?>
											</td>
											<td data-label="Role">
												<select class="brw-pr-role-select" name="brw_pr_gateways[<?php echo esc_attr( $gateway_id ); ?>][role]">
													<option value="fixed" <?php selected( $row['role'], 'fixed' ); ?>>Fixed</option>
													<option value="rotating" <?php selected( $row['role'], 'rotating' ); ?>>Rotating</option>
													<option value="secondary" <?php selected( $row['role'], 'secondary' ); ?>>Secondary</option>
													<option value="hidden" <?php selected( $row['role'], 'hidden' ); ?>>Hidden</option>
												</select>
											</td>
											<td>
												<input class="brw-pr-fixed-order-input" type="number" min="1" step="1" name="brw_pr_gateways[<?php echo esc_attr( $gateway_id ); ?>][fixed_order]" value="<?php echo esc_attr( (int) $row['fixed_order'] ); ?>" style="width:68px;" />
											</td>
											<td>
												<input class="brw-pr-weight-input" type="number" min="0" max="100" step="0.01" name="brw_pr_gateways[<?php echo esc_attr( $gateway_id ); ?>][weight]" value="<?php echo esc_attr( self::format_admin_number( $row['weight'] ) ); ?>" style="width:68px;" />
											</td>
											<td>
												<input class="brw-pr-discount-input" type="number" min="0" max="100" step="0.01" name="brw_pr_gateways[<?php echo esc_attr( $gateway_id ); ?>][discount]" value="<?php echo esc_attr( self::format_admin_number( $row['discount'] ) ); ?>" style="width:68px;" />
											</td>
											<td>
												<input class="brw-pr-fee-input" type="number" min="0" max="100" step="0.01" name="brw_pr_gateways[<?php echo esc_attr( $gateway_id ); ?>][fee]" value="<?php echo esc_attr( self::format_admin_number( isset( $row['fee'] ) ? $row['fee'] : 0 ) ); ?>" style="width:68px;" />
											</td>
										<td>
											<input class="brw-pr-min-order-input" type="number" min="0" step="0.01" name="brw_pr_gateways[<?php echo esc_attr( $gateway_id ); ?>][min_order_amount]" value="<?php echo esc_attr( self::format_admin_number( isset( $row['min_order_amount'] ) ? $row['min_order_amount'] : 0 ) ); ?>" style="width:84px;" />
										</td>
											<td>
												<input class="brw-pr-cap-input" type="number" min="0" step="0.01" name="brw_pr_gateways[<?php echo esc_attr( $gateway_id ); ?>][daily_cap]" value="<?php echo esc_attr( self::format_admin_number( $row['daily_cap'] ) ); ?>" style="width:84px;" />
											</td>
											<td data-label="Today proc $"><span class="brw-pr-metric"><?php echo wp_kses_post( wc_price( $today_processing_total ) ); ?></span></td>
											<td data-label="Proc cnt"><span class="brw-pr-metric"><?php echo esc_html( (string) (int) $today_processing_count ); ?></span></td>
											<td data-label="Failed"><span class="brw-pr-metric"><?php echo esc_html( (string) (int) $today_failed_count ); ?></span></td>
											<td data-label="Fail %"><span class="brw-pr-metric"><?php echo esc_html( self::format_admin_number( $today_fail_percent ) ); ?>%</span></td>
											<td>
												<input class="brw-pr-auto-pause-input" type="checkbox" name="brw_pr_gateways[<?php echo esc_attr( $gateway_id ); ?>][auto_pause]" value="1" <?php checked( ! empty( $row['auto_pause'] ) ); ?> />
											</td>
											<td>
												<input class="brw-pr-pause-threshold-input" type="number" min="1" max="100" step="0.01" name="brw_pr_gateways[<?php echo esc_attr( $gateway_id ); ?>][pause_threshold]" value="<?php echo esc_attr( self::format_admin_number( $row['pause_threshold'] ) ); ?>" style="width:68px;" />
											</td>
											<td>
												<input class="brw-pr-pause-min-input" type="number" min="1" step="1" name="brw_pr_gateways[<?php echo esc_attr( $gateway_id ); ?>][pause_min_count]" value="<?php echo esc_attr( (int) $row['pause_min_count'] ); ?>" style="width:68px;" />
											</td>
											<td class="brw-pr-message-col" data-label="Message">
												<input class="brw-pr-message-input" type="text" name="brw_pr_gateways[<?php echo esc_attr( $gateway_id ); ?>][message]" value="<?php echo esc_attr( $row['message'] ); ?>" style="width:100%;min-width:0;" placeholder="Example: Save 5%" aria-label="Checkout message shown for this payment method" />
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>

							<p class="description" style="margin:14px 0 0;">
								Use the guide above to set up each gateway. The layout automatically adapts to the available screen width so each gateway stays readable without side-to-side scrolling.
							</p>
						</div>
					</div>

					<div class="brw-pr-savebar">
						<div class="brw-pr-savebar-copy">
							<strong style="display:block;color:var(--brw-txt);margin-bottom:4px;">Ready to publish</strong>
							Save changes to update your checkout setup and customer-facing messaging.
						</div>
						<button type="submit" name="brw_pr_save" class="button button-primary">Save changes</button>
					</div>
				</form>
			</div>
		</div>

		<script>
		(function(){
			function setEnabled(el, enabled){
				if(!el) return;
				if(enabled){
					el.removeAttribute('readonly');
					el.removeAttribute('disabled');
					el.style.background = '';
					el.style.opacity = '';
				}else{
					if(el.type === 'checkbox' || el.tagName === 'SELECT'){
						el.setAttribute('disabled', 'disabled');
					}else{
						el.setAttribute('readonly', 'readonly');
					}
					el.style.background = '#f3f4f7';
					el.style.opacity = '0.55';
				}
			}

			function syncRow(row){
				if(!row) return;

				var role = row.querySelector('.brw-pr-role-select');
				var fixedOrder = row.querySelector('.brw-pr-fixed-order-input');
				var weight = row.querySelector('.brw-pr-weight-input');
				var discount = row.querySelector('.brw-pr-discount-input');
				var fee = row.querySelector('.brw-pr-fee-input');
				var minOrder = row.querySelector('.brw-pr-min-order-input');
				var cap = row.querySelector('.brw-pr-cap-input');
				var autoPause = row.querySelector('.brw-pr-auto-pause-input');
				var pauseThreshold = row.querySelector('.brw-pr-pause-threshold-input');
				var pauseMin = row.querySelector('.brw-pr-pause-min-input');
				var message = row.querySelector('.brw-pr-message-input');
				var rolePill = row.querySelector('.brw-pr-pill[class*="role-"]');

				if(!role) return;

				var roleVal = role.value;
				row.setAttribute('data-role', roleVal);

				if(rolePill){
					rolePill.className = 'brw-pr-pill role-' + roleVal;
					rolePill.textContent = roleVal.charAt(0).toUpperCase() + roleVal.slice(1);
				}

				setEnabled(fixedOrder, roleVal === 'fixed');
				setEnabled(weight, roleVal === 'rotating');

				var showCommon = roleVal !== 'hidden';
				setEnabled(discount, showCommon);
				setEnabled(fee, showCommon);
				setEnabled(minOrder, showCommon);
				setEnabled(cap, showCommon);
				setEnabled(autoPause, showCommon);
				setEnabled(message, showCommon);

				var autoPauseEnabled = showCommon && autoPause && autoPause.checked;
				setEnabled(pauseThreshold, autoPauseEnabled);
				setEnabled(pauseMin, autoPauseEnabled);
			}

			function applyFilters(){
				var searchEl = document.getElementById('brw-pr-gateway-search');
				var roleFilterEl = document.getElementById('brw-pr-role-filter');
				var search = searchEl ? searchEl.value.toLowerCase().trim() : '';
				var roleFilter = roleFilterEl ? roleFilterEl.value : 'all';

				document.querySelectorAll('.brw-pr-row').forEach(function(row){
					var haystack = row.getAttribute('data-search') || '';
					var matchesSearch = !search || haystack.indexOf(search) !== -1;
					var rowRole = row.getAttribute('data-role') || '';
					var isPaused = row.getAttribute('data-paused') === '1';
					var matchesRole = roleFilter === 'all'
						? true
						: (roleFilter === 'paused' ? isPaused : rowRole === roleFilter);

					row.style.display = (matchesSearch && matchesRole) ? '' : 'none';
				});
			}

			document.querySelectorAll('.brw-pr-row').forEach(function(row){
				syncRow(row);

				var role = row.querySelector('.brw-pr-role-select');
				var autoPause = row.querySelector('.brw-pr-auto-pause-input');

				if(role){
					role.addEventListener('change', function(){
						syncRow(row);
						applyFilters();
					});
				}

				if(autoPause){
					autoPause.addEventListener('change', function(){
						syncRow(row);
					});
				}
			});

			var searchEl = document.getElementById('brw-pr-gateway-search');
			var roleFilterEl = document.getElementById('brw-pr-role-filter');

			if(searchEl){
				searchEl.addEventListener('input', applyFilters);
			}

			if(roleFilterEl){
				roleFilterEl.addEventListener('change', applyFilters);
			}

			applyFilters();
		})();
		</script>
		<?php
	}

	private static function sanitize_role( $role ) {
		$role = sanitize_key( (string) $role );
		$allowed = array( 'fixed', 'rotating', 'secondary', 'hidden' );

		return in_array( $role, $allowed, true ) ? $role : 'secondary';
	}

	private static function format_admin_number( $value ) {
		$value = (float) $value;

		if ( floor( $value ) == $value ) {
			return (string) (int) $value;
		}

		return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}
}
