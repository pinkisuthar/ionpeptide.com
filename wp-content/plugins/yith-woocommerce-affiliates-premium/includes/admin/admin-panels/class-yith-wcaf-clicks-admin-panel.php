<?php
/**
 * Clicks admin panel handling
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Clicks_Admin_Panel' ) ) {
	/**
	 * Affiliates admin panel handling
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Clicks_Admin_Panel extends YITH_WCAF_Abstract_Admin_Panel {

		/**
		 * Current tab name
		 *
		 * @var string
		 */
		protected $tab = 'clicks';

		/**
		 * Init Affiliates admin panel
		 */
		public function __construct() {
			// init screen options.
			$this->screen_options = array(
				'per_page' => array(
					'label'    => _x( 'Visits', '[ADMIN] Clicks pagination label', 'yith-woocommerce-affiliates' ),
					'default'  => 20,
					'option'   => 'edit_clicks_per_page',
					'sanitize' => 'intval',
				),
			);

			// init screen columns.
			$this->screen_columns = array(
				'status'    => _x( 'Status', '[ADMIN] Clicks screen columns', 'yith-woocommerce-affiliates' ),
				'referrer'  => _x( 'Referrer', '[ADMIN] Clicks screen columns', 'yith-woocommerce-affiliates' ),
				'order'     => _x( 'Order', '[ADMIN] Clicks screen columns', 'yith-woocommerce-affiliates' ),
				'link'      => _x( 'Followed URL', '[ADMIN] Clicks screen columns', 'yith-woocommerce-affiliates' ),
				'origin'    => _x( 'Origin URL', '[ADMIN] Clicks screen columns', 'yith-woocommerce-affiliates' ),
				'date'      => _x( 'Date', '[ADMIN] Clicks screen columns', 'yith-woocommerce-affiliates' ),
				'conv_time' => _x( 'Conversion time', '[ADMIN] Clicks screen columns', 'yith-woocommerce-affiliates' ),
			);

			// init admin notices.
			$this->admin_notices = array(
				'deleted_clicks' => array(
					'success' => array(
						'singular' => _x( 'Visit deleted correctly.', '[ADMIN] Clicks action messages', 'yith-woocommerce-affiliates' ),
						// translators: 1. number fo clicks updated.
						'plural'   => _x( '%s visits deleted correctly.', '[ADMIN] Clicks action messages', 'yith-woocommerce-affiliates' ),
					),
					'error'   => _x( 'There was an error while deleting visits.', '[ADMIN] Clicks action messages', 'yith-woocommerce-affiliates' ),
				),
			);

			// enqueue tab assets.
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

			// set get method.
			$this->set_get_form();

			add_action( 'yith_wcaf_print_affiliate_clicks_list_tab', array( $this, 'render_list_table' ) );

			// call parent constructor.
			parent::__construct();
		}

		/**
		 * Get visits table.
		 *
		 * @return YITH_WCAF_Clicks_Admin_Table
		 */
		protected function get_list_table() {
			require_once YITH_WCAF_INC . 'admin/admin-tables/class-yith-wcaf-clicks-admin-table.php';

			return new YITH_WCAF_Clicks_Admin_Table();
		}

		/**
		 * Render the Affiliates Rates List tab.
		 */
		public function render_list_table() {
			$list_table = $this->get_list_table();

			$list_table->prepare_items();
			$list_table->views();
			?>
			<div id="yith_wcaf_clicks">
				<form method="get" id="yith-wcaf-list-table-form" class="yith_wcaf_clicks yith-plugin-ui--wp-list-auto-h-scroll">
					<?php $list_table->display(); ?>
				</form>
			</div>
			<?php
		}

		/**
		 * Get current tab url
		 *
		 * @param array $args Arguments to add to the url.
		 * @return string Current tab url.
		 */
		public function get_tab_url( $args = array() ) {
			/**
			 * APPLY_FILTERS: yith_wcaf_admin_tab_url
			 *
			 * Filters the url for the current tab in the backend.
			 *
			 * @param string $url Tab url.
			 */
			return apply_filters( 'yith_wcaf_admin_tab_url', add_query_arg( $args, YITH_WCAF_Admin()->get_tab_url( 'affiliates', 'affiliates-clicks', $args ) ) );
		}

		/* === PANEL HANDLING METHODS === */

		/* === BULK ACTIONS === */

		/**
		 * Process bulk actions for current view.
		 *
		 * @return array Array of parameters to be added to return url.
		 */
		public function process_bulk_actions() {
			// nonce was already verified, so there is no need to verify it again.
			// phpcs:disable WordPress.Security.NonceVerification
			$updated      = 0;
			$return_param = 'updated_clicks';

			$current_action = $this->get_current_action();
			$clicks         = isset( $_REQUEST['clicks'] ) ? array_map( 'intval', $_REQUEST['clicks'] ) : false;

			if ( ! $current_action || ! $clicks ) {
				return array();
			}

			$clicks = new YITH_WCAF_Clicks_Collection( $clicks );

			switch ( $current_action ) {
				case 'delete':
					$return_param = 'deleted_clicks';

					foreach ( $clicks as $click ) {
						$click->delete();
						$updated++;
					}
					break;
			}

			return array(
				$return_param => $updated,
			);
			// phpcs:enable WordPress.Security.NonceVerification
		}
	}
}
