<?php
/**
 * Affiliate Session class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH/Affiliates/Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Session_Premium' ) ) {
	/**
	 * Offer methods to retrieve and set current affiliate
	 *
	 * @since 2.0.0
	 */
	class YITH_WCAF_Session_Premium extends YITH_WCAF_Session {

		/**
		 * Whether we should save tokens history
		 *
		 * @var bool
		 */
		protected $history_cookie_enabled = false;

		/**
		 * Referral cookie name
		 *
		 * @var string
		 */
		protected $history_cookie_name = 'yith_wcaf_referral_history';

		/**
		 * Referral cookie expiration
		 *
		 * @var int
		 */
		protected $history_cookie_exp = WEEK_IN_SECONDS;

		/**
		 * Whether cookie should change once set or not
		 *
		 * @var bool
		 */
		protected $make_cookie_change = false;

		/**
		 * Whether persistent commission calculation is enabled
		 *
		 * @var bool
		 */
		protected $persistent_calculation = false;

		/**
		 * Whether persistent token should be changed whenever a new affiliation link is visited or not
		 *
		 * @var bool
		 */
		protected $avoid_referral_change = false;

		/**
		 * Stores tokens history
		 *
		 * @var string
		 */
		protected $history = array();

		/* === GETTERS === */

		/**
		 * Get current token, whether from query string or cookie
		 * Returns false if no token is currently set
		 *
		 * @return string|bool Current token; false if no valid token is set
		 */
		public function get_token() {
			// retrieve history, if any.
			$this->get_history();

			if ( is_null( $this->token ) ) {
				$query_var    = $this->get_query_var();
				$change_token = ! $this->has_cookie() || $this->make_cookie_change;

				if ( $query_var && $change_token ) {
					$token = $query_var;

					// sets token origin as query-string.
					$this->token_origin = 'query-string';
				} elseif ( $this->has_cookie() ) {
					$token = $this->get_cookie();

					// sets token origin as cookie.
					$this->token_origin = 'cookie';
				} else {
					$token              = false;
					$this->token_origin = false;
				}

				if ( is_user_logged_in() ) {
					$current_user_id  = get_current_user_id();
					$persistent_token = get_user_meta( $current_user_id, '_yith_wcaf_persistent_token', true );

					/**
					 * APPLY_FILTERS: yith_wcaf_apply_persistent_token
					 *
					 * Filters whether to apply the persistent token.
					 *
					 * @param bool   $apply_persistent_token Whether to apply persisten token or not.
					 * @param int    $current_user_id        Current user id.
					 * @param string $persistent_token       Persistent token.
					 * @param string $token                  Referral token.
					 * @param string $token_origin           Token origin.
					 */
					if ( $this->persistent_calculation && $persistent_token && apply_filters( 'yith_wcaf_apply_persistent_token', true, $current_user_id, $persistent_token, $token, $this->token_origin ) ) {
						if ( $this->avoid_referral_change ) {
							$token = $persistent_token;
						} else {
							$token = ( ! $token ) ? $persistent_token : $token;
						}

						if ( $token === $persistent_token ) {
							$this->token_origin = 'persistent';
						}
					}
				}

				if ( ! YITH_WCAF_Affiliates()->is_valid_token( $token ) ) {
					$token = false;
				}

				$this->token = $token;

				$this->maybe_update_history();
			}

			// sets cookie with current token.
			$this->set_cookie();
			$this->set_history_cookie();

			return $this->token;
		}

		/**
		 * Returns current history
		 *
		 * @return array Current tokens history.
		 */
		public function get_history() {
			if ( ! $this->history_cookie_enabled ) {
				return array();
			}

			$cookie_name = $this->get_history_cookie_name();

			if ( ! $this->history && isset( $_COOKIE[ $cookie_name ] ) ) {
				$this->history = explode( ',', sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) );
			}

			return $this->history;
		}

		/**
		 * Updates history to contain last retrieved token, if it isn't already last element of the array
		 *
		 * @return void.
		 */
		public function maybe_update_history() {
			if ( ! $this->history_cookie_enabled ) {
				return;
			}

			$history    = $this->get_history();
			$last_token = ! empty( $history ) ? end( $history ) : false;

			if ( $last_token === $this->token ) {
				return;
			}

			array_push( $this->history, $this->token );
		}

		/**
		 * Init class attributes for admin options
		 *
		 * @return void
		 */
		protected function retrieve_options() {
			$make_cookie_expire = get_option( 'yith_wcaf_history_make_cookie_expire', 'yes' );
			$cookie_expire      = get_option( 'yith_wcaf_history_cookie_expire', $this->history_cookie_exp );

			$this->history_cookie_name    = get_option( 'yith_wcaf_history_cookie_name', $this->history_cookie_name );
			$this->history_cookie_exp     = 'yes' === $make_cookie_expire ? yith_wcaf_duration_to_secs( $cookie_expire ) : 15 * YEAR_IN_SECONDS;
			$this->history_cookie_enabled = 'yes' === get_option( 'yith_wcaf_history_cookie_enable', 'yes' );
			$this->make_cookie_change     = 'yes' === get_option( 'yith_wcaf_make_cookie_change', 'yes' );
			$this->persistent_calculation = 'yes' === get_option( 'yith_wcaf_commission_persistent_calculation', 'no' );
			$this->avoid_referral_change  = 'yes' === get_option( 'yith_wcaf_avoid_referral_change', 'no' );

			parent::retrieve_options();
		}

		/* === SETTERS === */

		/**
		 * Set a new session token, different from the one automatically retrieved by this class
		 *
		 * @param string $token        Token to set.
		 * @param string $token_origin Origin for current token.
		 * @param bool   $set_cookie   Whether to set cookie with new token or not.
		 *
		 * @return void.
		 */
		public function set_token( $token, $token_origin = 'constructor', $set_cookie = false ) {
			parent::set_token( $token, $token_origin, $set_cookie );

			$this->maybe_update_history();
		}

		/* === COOKIE HANDLING === */

		/**
		 * Delete all sessions cookies
		 *
		 * @return void
		 */
		public function delete_cookies() {
			$this->delete_cookie();
			$this->delete_history_cookie();
		}

		/* === HISTORY COOKIE HANDLING === */

		/**
		 * Returns history cookie name
		 *
		 * @return string Ref name.
		 */
		public function get_history_cookie_name() {
			/**
			 * APPLY_FILTERS: yith_wcaf_history_cookie_name
			 *
			 * Filters the history cookie name.
			 *
			 * @param string $history_cookie_name History cookie name.
			 */
			return apply_filters( 'yith_wcaf_history_cookie_name', $this->history_cookie_name );
		}

		/**
		 * Returns true if history cookie is set
		 *
		 * @return bool Whether referral cookie is set.
		 */
		public function has_history_cookie() {
			/**
			 * APPLY_FILTERS: yith_wcaf_session_has_history_cookie
			 *
			 * Filters whether the history cookie has been set.
			 *
			 * @param bool $has_history_cookie Whether the history cookie is set or not.
			 */
			return apply_filters( 'yith_wcaf_session_has_history_cookie', ! empty( $_COOKIE[ $this->get_history_cookie_name() ] ) );
		}

		/**
		 * Send headers to delete history cookie
		 *
		 * @return void
		 */
		public function delete_history_cookie() {
			if ( ! $this->has_history_cookie() ) {
				return;
			}

			yith_wcaf_delete_cookie( $this->get_history_cookie_name() );
		}

		/**
		 * Set value for the history cookie, with current history
		 * Updates history with last token, when necessary
		 *
		 * @retun void
		 */
		protected function set_history_cookie() {
			if ( ! $this->history_cookie_enabled || ! $this->history || ! $this->should_set_cookie() || headers_sent() ) {
				return;
			}

			yith_wcaf_set_cookie( $this->get_history_cookie_name(), implode( ',', $this->history ), (int) $this->history_cookie_exp );
		}
	}
}
