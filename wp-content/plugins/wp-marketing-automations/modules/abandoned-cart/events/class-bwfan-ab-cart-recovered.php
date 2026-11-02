<?php
defined( 'ABSPATH' ) || exit;

#[AllowDynamicProperties]
final class BWFAN_AB_Cart_Recovered extends BWFAN_Event {
	private static $instance = null;
	public $order_id = null;
	/** @var WC_Order $order */
	public $order = null;
	public $cart_details = array();

	public function __construct( $source_slug ) {
		$this->source_type            = $source_slug;
		$this->optgroup_label         = __( 'Cart', 'wp-marketing-automations' );
		$this->event_name             = __( 'Cart Recovered', 'wp-marketing-automations' );
		$this->event_desc             = __( 'This automation would trigger when the user abandoned cart is recovered.', 'wp-marketing-automations' );
		$this->event_merge_tag_groups = array( 'bwf_contact', 'wc_order' );
		$this->event_rule_groups      = array(
			'wc_order',
			'wc_customer',
			'aerocheckout',
			'bwf_contact_segments',
			'bwf_contact',
			'bwf_contact_fields',
			'bwf_contact_user',
			'bwf_contact_wc',
			'bwf_contact_geo',
			'bwf_engagement',
			'bwf_broadcast'
		);
		$this->support_lang           = true;
		$this->priority               = 5.1;
		$this->customer_email_tag     = '{{admin_email}}';
		$this->v2                     = true;
		$this->optgroup_priority      = 5;
		$this->supported_blocks       = [ 'cart' ];
		$this->automation_add         = true;
	}

	public function load_hooks() {
		add_action( 'abandoned_cart_recovered', array( $this, 'process' ), 10, 2 );
	}

	public static function get_instance( $source_slug ) {
		if ( null === self::$instance ) {
			self::$instance = new self( $source_slug );
		}

		return self::$instance;
	}

	public function pre_executable_actions( $value ) {
		BWFAN_Core()->rules->setRulesData( $this->order, 'wc_order' );
		BWFAN_Core()->rules->setRulesData( BWFAN_Common::get_bwf_customer( $this->order->get_billing_email(), $this->order->get_user_id() ), 'bwf_customer' );
	}

	/**
	 * Make the required data for the current event and send it asynchronously.
	 *
	 * @param $order_id
	 */
	public function process( $cart_details, $order_id ) {
		$data                 = $this->get_default_data();
		$data['order_id']     = $order_id;
		$data['cart_details'] = $cart_details;
		$this->send_async_call( $data );
	}

	/**
	 * Set global data for all the merge tags which are supported by this event.
	 *
	 * @param $task_meta
	 */
	public function set_merge_tags_data( $task_meta ) {
		$order_id = BWFAN_Merge_Tag_Loader::get_data( 'order_id' );
		if ( empty( $order_id ) || $order_id !== $task_meta['global']['order_id'] ) {
			$set_data = array(
				'order_id'     => $task_meta['global']['order_id'],
				'email'        => $task_meta['global']['email'],
				'cart_details' => $task_meta['global']['cart_details']
			);
			BWFAN_Merge_Tag_Loader::set_data( $set_data );
		}
	}

	/**
	 * Capture the async data for the current event.
	 * @return array|bool
	 */
	public function capture_async_data() {
		$order_id           = BWFAN_Common::$events_async_data['order_id'];
		$this->order_id     = $order_id;
		$order              = wc_get_order( $order_id );
		$this->order        = $order;
		$this->cart_details = BWFAN_Common::$events_async_data['cart_details'];

		return $this->run_automations();
	}

	/**
	 * Capture the async data for the current event.
	 * @return array|bool
	 */
	public function capture_v2_data( $automation_data ) {

		$this->order_id                  = BWFAN_Common::$events_async_data['order_id'];
		$order                           = wc_get_order( $this->order_id );
		$this->order                     = $order;
		$this->cart_details              = [];
		$automation_data['email']        = is_object( $order ) ? BWFAN_Woocommerce_Compatibility::get_billing_email( $order ) : '';
		$automation_data['cart_details'] = [];
		$automation_data['order_id']     = $this->order_id;

		return $automation_data;
	}

	/**
	 * Registers the tasks for current event.
	 *
	 * @param $automation_id
	 * @param $integration_data
	 * @param $event_data
	 */
	public function register_tasks( $automation_id, $integration_data, $event_data ) {
		$data_to_send = $this->get_event_data();

		$this->create_tasks( $automation_id, $integration_data, $event_data, $data_to_send );
	}

	public function get_event_data() {
		$data_to_send                           = [];
		$data_to_send['global']['order_id']     = $this->order_id;
		$order                                  = $this->order;
		$data_to_send['global']['email']        = is_object( $order ) ? BWFAN_Woocommerce_Compatibility::get_billing_email( $order ) : '';
		$data_to_send['global']['cart_details'] = $this->cart_details;

		return $data_to_send;
	}

	/**
	 * Make the view data for the current event which will be shown in task listing screen.
	 *
	 * @param $global_data
	 *
	 * @return false|string
	 */
	public function get_task_view( $global_data ) {
		ob_start();
		$order = wc_get_order( $global_data['order_id'] );
		if ( $order instanceof WC_Order ) {
			?>
            <li>
                <strong><?php echo esc_html__( 'Recovered Order:', 'wp-marketing-automations' ); ?> </strong>
                <a target="_blank" href="<?php echo get_edit_post_link( $global_data['order_id'] ); //phpcs:ignore WordPress.Security.EscapeOutput
				?>"><?php echo '#' . esc_attr( $global_data['order_id'] . ' ' . $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ); ?></a>
            </li>
		<?php } ?>
        <li>
            <strong><?php echo esc_html__( 'Email:', 'wp-marketing-automations' ); ?> </strong>
            <span><?php echo $global_data['email']; //phpcs:ignore WordPress.Security.EscapeOutput ?></span>
        </li>
		<?php
		return ob_get_clean();
	}

	public function get_email_event() {
		if ( $this->order instanceof WC_Order ) {
			return $this->order->get_billing_email();
		}

		if ( ! empty( absint( $this->order_id ) ) ) {
			$order = wc_get_order( absint( $this->order_id ) );

			return $order instanceof WC_Order ? $order->get_billing_email() : false;
		}

		return false;
	}

	/**
	 * get contact automation data
	 *
	 * @param $automation_data
	 * @param $cid
	 *
	 * @return array|null[]
	 */
	public function get_manually_added_contact_automation_data( $automation_data, $cid ) {
		$contact = new BWFCRM_Contact( $cid );
		if ( ! $contact->is_contact_exists() ) {
			return [ 'status' => 0, 'type' => 'contact_not_found' ];
		}

		$last_recovered_cart = $this->fetch_last_recovered_cart( $contact->contact->get_email() );
		if ( empty( $last_recovered_cart ) ) {
			return [ 'status' => 0, 'type' => '', 'message' => "Contact doesn't have any recovered order." ];
		}

		$this->order_id = $last_recovered_cart;
		$this->order    = wc_get_order( $this->order_id );

		$data = array(
			'contact_id' => $cid,
			'order_id'   => $this->order_id,
			'email'      => $contact->contact->get_email(),
		);

		return array_merge( $automation_data, $data );
	}

	/**
	 * Fetch last recovered cart's order id
	 *
	 * @param $email
	 *
	 * @return int
	 */
	public function fetch_last_recovered_cart( $email ) {
		global $wpdb;
		$post_statuses = apply_filters( 'bwfan_recovered_cart_excluded_statuses', array(
			'wc-pending',
			'wc-failed',
			'wc-cancelled',
			'wc-refunded',
			'trash',
			'draft'
		) );
		$post_statuses = is_array( $post_statuses ) ? esc_sql( $post_statuses ) : $post_statuses; // filter output is placed inside quoted SQL literals
		$post_statuses = implode( "','", $post_statuses );

		if ( BWF_WC_Compatibility::is_hpos_enabled() ) {
			$hpos_left_join = " LEFT JOIN {$wpdb->prefix}wc_orders_meta as m1 ON p.id = m1.order_id ";
			$query          = $wpdb->prepare( "SELECT p.id as id FROM {$wpdb->prefix}wc_orders as p LEFT JOIN {$wpdb->prefix}wc_orders_meta as m ON p.id = m.order_id $hpos_left_join WHERE p.type = %s AND p.status NOT IN ('$post_statuses') AND m.meta_key = %s AND p.billing_email LIKE %s ORDER BY p.id DESC LIMIT 1", 'shop_order', '_bwfan_ab_cart_recovered_a_id', $email ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- excluded statuses esc_sql()ed after the filter; tables from $wpdb->prefix; values prepared
		} else {
			$left_join = " LEFT JOIN {$wpdb->prefix}postmeta as m1 ON p.ID = m1.post_id ";
			$where     = ' AND m1.meta_key = "_billing_email" ';
			$query     = $wpdb->prepare( "SELECT p.ID as id FROM {$wpdb->prefix}posts as p LEFT JOIN {$wpdb->prefix}postmeta as m ON p.ID = m.post_id $left_join WHERE p.post_type = %s AND p.post_status NOT IN ('$post_statuses') AND m.meta_key = %s $where AND m1.meta_value LIKE %s ORDER BY p.ID DESC LIMIT 1", 'shop_order', '_bwfan_ab_cart_recovered_a_id', $email ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- excluded statuses esc_sql()ed after the filter; tables from $wpdb->prefix; values prepared
		}

		return intval( $wpdb->get_var( $query ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- excluded statuses esc_sql()ed after the filter; tables from $wpdb->prefix; values prepared
	}

}

/**
 * Register this event to a source.
 * This will show the current event in dropdown in single automation screen.
 */
if ( bwfan_is_woocommerce_active() ) {
	return 'BWFAN_AB_Cart_Recovered';
}
