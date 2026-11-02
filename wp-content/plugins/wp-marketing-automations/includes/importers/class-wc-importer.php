<?php

namespace BWFAN\Importers;

defined( 'ABSPATH' ) || exit;

use BWF_WC_Compatibility;
use BWFAN\Importers\Importer;
use BWFCRM_Contact;
use Exception;
use WC_Order;
use WooFunnels_DB_Updater;
use WP_Error;

/**
 * Class WC_Importer
 *
 * This class represents a WordPress importer for the CRM system.
 */
#[\AllowDynamicProperties]
class WC_Importer extends Importer {
	protected $import_type = 'wc';

	/** @var array cids already queued for a reindex in this run, so one batch queues each at most once */
	protected $reindex_queued = array();

	public function __construct( $params = array() ) {
		$this->slug        = 'wc';
		$this->name        = __( 'WooCommerce', 'wp-marketing-automations' );
		$this->description = __( 'Import contacts from WooCommerce orders', 'wp-marketing-automations' );
		$this->logo_url    = esc_url( plugin_dir_url( BWFAN_PLUGIN_FILE ) . '/admin/assets/img/importer/wc.png' );
		$this->has_fields  = false;
		$this->group       = 0;
		$this->priority    = 7;
		parent::__construct( $params );

		add_action( 'bwf_normalize_contact_meta_after_save', array( $this, 'update_contact_data_after_import' ), 20, 3 );
	}

	/**
	 * Prepares the import data for creating a new import.
	 *
	 * @param array $import_data The import data.
	 *
	 * @return array The modified import data.
	 */
	public function prepare_create_import_data( $import_data = array(), $fields = array() ) {
		$import_data['count'] = $this->get_contacts_count();

		return $import_data;
	}

	/**
	 * Prepare the data for importing orders.
	 *
	 * This method fetches orders to import based on specified criteria and stores them in the raw_data property.
	 */
	public function populate_contact_data() {
		$args = array( 'limit' => 25, 'offset' => $this->get_offset() );

		/** Same fixed window as the count, and it turns on the deterministic newest-first ordering */
		$snapshot = $this->get_import_meta( 'import_snapshot_date' );
		if ( ! empty( $snapshot ) ) {
			$args['after_date_created'] = $snapshot;
		}

		return self::get_wc_orders( $args );
	}

	/**
	 * Retrieves the count of orders.
	 *
	 * This function efficiently counts orders that meet the specified criteria using a direct query.
	 * The criteria include the order type, status, and date created.
	 *
	 * @return int The count of orders.
	 */
	public function get_contacts_count() {
		/** Fixed snapshot when the import has one; live 'now' only for previews and legacy imports */
		$snapshot     = $this->get_import_meta( 'import_snapshot_date' );
		$date_created = ! empty( $snapshot ) ? $snapshot : gmdate( 'Y-m-d H:i:s', time() );

		$count = self::get_wc_orders( array( 'only_count' => true, 'after_date_created' => $date_created ) );

		return ! empty( $count ) ? absint( $count ) : 0;
	}

	/**
	 * Process an item for import.
	 *
	 * This method is responsible for processing an item for import, which includes creating or updating a contact based on the provided order ID.
	 *
	 * @param int|null $order_id The ID of the order to process.
	 *
	 * @return array|void|WP_Error An array containing the ID of the contact and a flag indicating if the contact was updated, or a WP_Error object if there was an error.
	 */
	public function process_item( $order_id = null ) {
		if ( empty( absint( $order_id ) ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'bwfcrm_invalid_order_data', __( 'Order data is not valid.', 'wp-marketing-automations' ), array(
				'contact_email' => '',
				'original_id'   => $order_id,
			) );
		}

		$this->schedule_previous_owner_reindex( $this->prepare_order_markers( $order ) );

		if ( ! $order->has_status( wc_get_is_paid_statuses() ) ) {
			return $this->process_unpaid_order_contact( $order, $order_id );
		}

		WooFunnels_DB_Updater::$indexing = true;

		try {
			$cid = bwf_create_update_contact( $order_id, array(), 0, true );
		} catch ( Exception $e ) {
			$error_msg = $e->getMessage();
			BWFAN_Core()->logger->log( 'order id #' . $order_id . ' parsing broke with error message: ' . $error_msg, 'import_wc_contacts' );
		}

		WooFunnels_DB_Updater::$indexing = null;

		if ( empty( $cid ) ) {
			// Only load order if we need email for error reporting
			$email = '';
			if ( ! empty( $error_msg ) ) {
				$order = wc_get_order( $order_id );
				$email = $order instanceof WC_Order ? $order->get_billing_email() : '';
			}
			
			$error_msg  = empty( $error_msg ) ? __( 'Order data is not valid.', 'wp-marketing-automations' ) : $error_msg;
			$error_data = array(
				'contact_email' => $email,
				'original_id'   => $order_id,
			);

			return new WP_Error( 'bwfcrm_invalid_order_data', $error_msg, $error_data );
		}

		$contact         = new \WooFunnels_Contact( '', '', '', $cid );
		$update_existing = $this->get_import_meta( 'update_existing' );

		return array(
			'id'      => $contact->get_id(),
			'updated' => $contact->get_id() > 0 && $update_existing,
		);
	}

	/**
	 * Resolve the identity an order maps to, the same way bwf_create_update_contact() does.
	 *
	 * A WooCommerce account owns the contact: the billing email is only consulted for guest
	 * orders. Editing billing on an order that has a customer account therefore changes
	 * nothing, which is deliberate.
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return array{wp_id:int, wp_email:string, email:string}
	 */
	protected function get_order_identity( $order ) {
		$wp_id    = $order->get_customer_id();
		$wp_email = '';
		if ( $wp_id > 0 ) {
			$wp_user  = get_user_by( 'id', $wp_id );
			$wp_email = ( $wp_user instanceof \WP_User ) ? $wp_user->user_email : '';
		}

		return array(
			'wp_id'    => $wp_id,
			'wp_email' => $wp_email,
			'email'    => empty( $wp_email ) ? $order->get_billing_email() : $wp_email,
		);
	}

	/**
	 * Does this contact still hold aggregated purchase data?
	 *
	 * @param int $cid Contact id.
	 *
	 * @return bool
	 */
	protected function customer_row_exists( $cid ) {
		global $wpdb;

		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}bwf_wc_customers WHERE cid = %d LIMIT 1", absint( $cid ) ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Make `_woofunnel_custid` trustworthy before the order is indexed.
	 *
	 * That meta is the "purchase data already counted" marker: bwf_create_update_customer()
	 * skips both the order count and the order value while it is set, and the paid-status
	 * handler treats it as "already indexed". Two things invalidate it, and neither used to be
	 * detected — the importer is incremental and simply trusted whatever was on the order:
	 *
	 * - the contact it names is gone (contacts deleted, CRM tables reset) while the order meta
	 *   survived, so the marker vouches for aggregates that no longer exist;
	 * - the order has since been reassigned (its WooCommerce customer changed, or a guest
	 *   order's billing email changed), so the marker names the previous owner.
	 *
	 * In both cases the marker is dropped so the current owner actually indexes the order. A
	 * marker that still names the current owner and is backed by a customer row is left alone,
	 * so re-importing a healthy contact stays idempotent instead of counting its orders twice.
	 *
	 * @param WC_Order $order The order being imported.
	 *
	 * @return int cid of the previous owner that must now drop this order, 0 when there is none.
	 */
	protected function prepare_order_markers( $order ) {
		$custid = absint( BWF_WC_Compatibility::get_order_meta( $order, '_woofunnel_custid' ) );
		if ( $custid < 1 ) {
			return 0;
		}

		$identity = $this->get_order_identity( $order );
		$owner    = 0;
		if ( $identity['wp_id'] > 0 || ! empty( $identity['email'] ) ) {
			$contact = bwf_get_contact( $identity['wp_id'], $identity['email'] );
			$owner   = ( $contact instanceof \WooFunnels_Contact ) ? absint( $contact->get_id() ) : 0;
		}

		/** Marker names the current owner and is backed by real aggregates: trust it, skip the order. */
		if ( $owner > 0 && $owner === $custid && $this->customer_row_exists( $custid ) ) {
			return 0;
		}

		$order->delete_meta_data( '_woofunnel_custid' );
		$order->save_meta_data();

		/**
		 * The order moved. Its previous owner still counts it and nothing in the import path
		 * ever subtracts, so that contact has to be rebuilt from its own orders.
		 */
		return ( $owner !== $custid && $this->customer_row_exists( $custid ) ) ? $custid : 0;
	}

	/**
	 * Queue a full reindex for a contact that just lost an order.
	 *
	 * Reuses the recurring action the manual "Resync Orders" button drives, which rebuilds the
	 * contact's `bwf_wc_customers` row from zero. Deduplicated per run and against anything
	 * already scheduled, so a large reassigning import cannot flood Action Scheduler.
	 *
	 * @param int $cid Contact id, or 0 for nothing to do.
	 *
	 * @return void
	 */
	protected function schedule_previous_owner_reindex( $cid ) {
		$cid = absint( $cid );
		if ( $cid < 1 || in_array( $cid, $this->reindex_queued, true ) ) {
			return;
		}

		/**
		 * This import started from an empty customer table, so every row in it was built by
		 * this run and cannot be carrying an order it no longer owns. The marker only looked
		 * stale because the previous owner had already been rebuilt earlier in the same run.
		 */
		if ( ! empty( $this->get_import_meta( 'customers_reset' ) ) ) {
			return;
		}

		$this->reindex_queued[] = $cid;

		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		/** String cid to match how the resync endpoint and schedule_order_reindex_action() encode args. */
		$args = array( 'cid' => (string) $cid );
		if ( as_has_scheduled_action( 'bwf_reindex_contact_orders', $args, 'funnelkit' ) ) {
			return;
		}

		as_schedule_recurring_action( time(), 60, 'bwf_reindex_contact_orders', $args, 'funnelkit' );
	}

	/**
	 * Create/update the contact for a not-yet-paid order WITHOUT indexing customer
	 * purchase data. Mirrors the checkout-time flow (contact + _woofunnel_cid only).
	 *
	 * _woofunnel_custid is deliberately NOT stamped: the order-status-change handler
	 * treats its absence as "not indexed" and runs the full customer indexing when
	 * the order later reaches a paid status.
	 *
	 * @param WC_Order $order    The order object.
	 * @param int      $order_id The order ID.
	 *
	 * @return array|WP_Error
	 */
	protected function process_unpaid_order_contact( $order, $order_id ) {
		$identity = $this->get_order_identity( $order );
		$wp_id    = $identity['wp_id'];
		$wp_email = $identity['wp_email'];
		$email    = $identity['email'];

		if ( empty( $email ) ) {
			return new WP_Error( 'bwfcrm_invalid_order_data', __( 'Order data is not valid.', 'wp-marketing-automations' ), array(
				'contact_email' => '',
				'original_id'   => $order_id,
			) );
		}

		WooFunnels_DB_Updater::$indexing = true;

		$cid = 0;
		try {
			$bwf_contact = bwf_get_contact( $wp_id, $email );

			$bwf_email = isset( $bwf_contact->db_contact->email ) ? $bwf_contact->db_contact->email : '';
			$bwf_wpid  = isset( $bwf_contact->db_contact->wpid ) ? $bwf_contact->db_contact->wpid : 0;

			if ( $wp_id > 0 && ( $bwf_wpid !== $wp_id ) ) {
				$bwf_contact->set_wpid( $wp_id );
			}

			if ( ( empty( $bwf_email ) && ! empty( $email ) ) || ( ! empty( $wp_email ) && ( $bwf_email !== $email ) ) ) {
				$bwf_contact->set_email( $email );
			}

			$bwf_contact = bwf_create_update_contact_object( $bwf_contact, $order );
			bwf_contact_maybe_update_creation_date( $bwf_contact, $order );

			do_action( 'bwf_normalize_contact_meta_before_save', $bwf_contact, $order_id, $order );
			$bwf_contact->save();
			do_action( 'bwf_normalize_contact_meta_after_save', $bwf_contact, $order_id, $order );

			$cid = $bwf_contact->get_id();
		} catch ( Exception $e ) {
			BWFAN_Core()->logger->log( 'order id #' . $order_id . ' parsing broke with error message: ' . $e->getMessage(), 'import_wc_contacts' );
		} finally {
			WooFunnels_DB_Updater::$indexing = null;
		}

		if ( empty( $cid ) || absint( $cid ) < 1 ) {
			return new WP_Error( 'bwfcrm_invalid_order_data', __( 'Order data is not valid.', 'wp-marketing-automations' ), array(
				'contact_email' => $email,
				'original_id'   => $order_id,
			) );
		}

		$order->update_meta_data( '_woofunnel_cid', $cid );
		$order->save_meta_data();

		$update_existing = $this->get_import_meta( 'update_existing' );

		return array(
			'id'      => $cid,
			'updated' => $update_existing ? true : false,
		);
	}

	/**
	 * Updates contact data after import.
	 *
	 * This function is responsible for updating the contact data after importing it.
	 * It sets the contact tags and contact lists to empty arrays.
	 *
	 * @param object $contact The contact object.
	 *
	 * @return void
	 */
	public function update_contact_data_after_import( $contact, $order_id, $order ) {
		if ( ! $contact instanceof \WooFunnels_Contact ) {
			return;
		}

		$to_be_assigned_tags  = $this->get_new_tags();
		$to_be_assigned_lists = $this->get_new_lists();
		$contact              = $this->set_contact_tags( $contact, $to_be_assigned_tags );
		$contact              = $this->set_contact_lists( $contact, $to_be_assigned_lists );

		if ( ! empty( $to_be_assigned_tags ) || ! empty( $to_be_assigned_lists ) ) {
			$contact->save();
		}

		$update_existing = $this->get_import_meta( 'update_existing' );
		if ( ! $contact instanceof BWFCRM_Contact || empty( $update_existing ) ) {
			return;
		}

		$disable_events = $this->get_import_meta( 'disable_events' );
		$import_status  = $this->get_import_meta( 'imported_contact_status' );
		switch ( intval( $import_status ) ) {
			case 0:
				$contact->unverify();
				break;
			case 1:
				$contact->resubscribe( $disable_events );
				break;
			case 2:
				$contact->mark_as_bounced();
				break;
			case 3:
				if ( $order instanceof WC_Order ) {
					$order->delete_meta_data( 'marketing_status' );
					$order->save();
				}
				$contact->contact->is_subscribed = false;
				$contact->set_unsubscribe_context( 0, 0, BWFCRM_Contact::UNSUBSCRIBE_SOURCE_IMPORTER );
				$contact->unsubscribe( $disable_events );
				break;
			case 4:
				$contact->mark_as_soft_bounced();
				break;
			case 5:
				$contact->mark_as_complaint();
				break;
		}
	}

	/**
	 * Get the log headers for this importer.
	 *
	 * @return array
	 */
	protected function get_log_headers() {
		return [ 'ID', 'Order ID', 'Status' ];
	}

	/**
	 * Prepare log data for a single item.
	 *
	 * @param mixed $data
	 * @param mixed $result
	 *
	 * @return array
	 */
	protected function prepare_log_data( $data, $result ) {
		return [
			'ID'       => is_wp_error( $result ) ? 0 : $result['id'],
			'Order ID' => $data ?? 0,
			'Status'   => is_wp_error( $result ) ? __( 'Failed', 'wp-marketing-automations' ) : ( isset( $result['skipped'] ) && $result['skipped'] ? __( 'Skipped', 'wp-marketing-automations' ) : __( 'Success', 'wp-marketing-automations' ) ),
		];
	}

	/**
	 * Order statuses the importer pulls from. Every real status is imported — the
	 * paid/unpaid split only decides whether purchase data is indexed, not whether
	 * a contact is created.
	 *
	 * Two transient statuses are excluded because they do not represent an order
	 * anyone placed:
	 * - 'wc-checkout-draft': WooCommerce Blocks creates a draft order for every
	 *   Store API checkout pageview and deletes them daily — importing them would
	 *   manufacture contacts (and marketing status) for visitors who never placed
	 *   an order.
	 * - 'wc-wfocu-pri-order' (Primary Order Accepted): Upstroke's short-lived
	 *   mid-upsell state; such orders finalize to a real status within minutes and
	 *   are then imported normally.
	 *
	 * @return array status_key => label
	 */
	public static function get_importable_order_statuses() {
		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : [];
		unset( $statuses['wc-checkout-draft'], $statuses['wc-wfocu-pri-order'] );

		return $statuses;
	}

	/**
	 * Get the second step fields for wc importer
	 *
	 * @return array
	 *
	 */
	public function get_second_step_fields() {
		$count       = $this->get_contacts_count();
		$this->count = $count;

		if ( $count < 1 ) {
			$message = __( 'There are no order to import', 'wp-marketing-automations' );
			$type    = 'error';
		} else {
			/** Read the paid list live — plugins extend it through 'woocommerce_order_is_paid_statuses' */
			$status_labels = wc_get_order_statuses();
			/** Emphasis is added here, not in the translated string, so translators never handle markup */
			$paid_labels = array_map( function ( $status ) use ( $status_labels ) {
				return '<strong>' . esc_html( $status_labels[ 'wc-' . $status ] ?? $status ) . '</strong>';
			}, wc_get_is_paid_statuses() );

			/* translators: 1: Order count */
			$intro = sprintf( __( '%d orders will be imported. Orders that share an email address become a single contact, so your contact count will be lower than this.', 'wp-marketing-automations' ), $count );

			/* translators: 1: Comma separated list of paid order status labels */
			$detail = sprintf( __( 'Every order creates a contact, including orders that were never paid. Purchase history (order count, total spent and products bought) is recorded only from paid orders: %s. If an unpaid order is paid later, its purchase history is added then.', 'wp-marketing-automations' ), implode( ', ', $paid_labels ) );

			/* translators: 1: Opening anchor tag, 2: Closing anchor tag */
			$learn_more = sprintf( __( 'Do you have other order statuses you want to capture purchase history from? %1$sLearn more%2$s', 'wp-marketing-automations' ), '<a href="' . esc_url( \BWFAN_Common::get_fk_site_links( 'paidorderstatus' ) ) . '" target="_blank" rel="noopener noreferrer" style="color:#0073aa">', '</a>' );

			$message = $intro . '<br /><br />' . $detail . '<br /><br />' . $learn_more;
			$type    = 'info';
		}

		return [
			[
				'id'          => 'wc_notice',
				'type'        => 'notice',
				'noticeLabel' => __( 'Orders', 'wp-marketing-automations' ),
				'nType'       => $type,
				'isHtml'      => true,
				'text'        => '<span class="bwf-heading8-new">' . __( 'Information', 'wp-marketing-automations' ) . ':</span> ' . $message,
				'desc'        => '',
			],
		];
	}

	/**
	 * Validate the count of contacts to be imported.
	 *
	 * @return bool
	 */
	public function validate_second_step() {
		return intval( $this->count ) > 0;
	}

	/**
	 * Reset the aggregated purchase data, then recompute the stored total count.
	 *
	 * The count written at create time uses a live 'now' because
	 * import_snapshot_date is only persisted on the update/start call. Orders
	 * placed between those two moments sit inside the batch window but are missing
	 * from the count, so processed/count would hit 100% while batches remain and
	 * the tail would be dropped. The runtime self-heal cannot catch it either — it
	 * skips drift under 10%.
	 *
	 * Runs once per import: start_import() is the only caller, so a resumed batch does not
	 * re-enter it, and maybe_reset_customer_index() guards the re-submitted-start case.
	 */
	public function before_start_import() {
		$this->maybe_reset_customer_index();

		$count       = $this->get_contacts_count();
		$this->count = $count;

		\BWFAN_Model_Export_Import::update( array( 'count' => $count ), array( 'id' => $this->get_import_id() ) );
	}

	/**
	 * Clear aggregated purchase data once, so this import rebuilds it from the orders
	 * themselves rather than patching whatever was there before.
	 *
	 * Ports the behaviour Pro's WC importer had in reset_bwf_wc_customers(), which ran on every
	 * WC import until the API consolidation deleted the controller that called it.
	 *
	 * Two things are dropped: the aggregates themselves, and the `_woofunnel_custid` markers
	 * that would otherwise make orders look already counted. `_woofunnel_cid` is kept, which is
	 * where this differs from Pro — Pro deleted the links too, unlinking every order on the
	 * store from its contact, including child/upsell orders this importer never revisits, so
	 * those stayed unlinked afterwards. Pro also did it in one unbounded statement per table.
	 *
	 * Guarded by import meta because update_import() has no in-progress check: a re-submitted
	 * start would otherwise wipe everything already imported while the offset stayed put,
	 * leaving every order processed so far permanently uncounted.
	 *
	 * @return void
	 */
	protected function maybe_reset_customer_index() {
		if ( ! empty( $this->get_import_meta( 'customers_reset' ) ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bwf_wc_customers';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "TRUNCATE TABLE `$table`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table from $wpdb->prefix; no dynamic values
		}

		/**
		 * Emptying the table is not enough on its own. The first order of a contact rebuilds
		 * that contact's row, and every later order of the same contact still carries its old
		 * `_woofunnel_custid` — which now points at a contact that exists again and matches the
		 * order, so it reads as valid and the order is skipped. Only the first order per contact
		 * would count. The markers have to go with the aggregates.
		 *
		 * `_woofunnel_cid` is deliberately kept: it is the contact link, not a counted flag, and
		 * every processed order rewrites it anyway. Keeping it means contact profiles still list
		 * their orders while the import runs, and child/upsell orders this importer never
		 * revisits do not end up permanently unlinked.
		 */
		foreach ( array( $wpdb->postmeta, $wpdb->prefix . 'wc_orders_meta' ) as $meta_table ) {
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $meta_table ) ) ) { //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				continue;
			}
			/** Batched: a single unbounded DELETE over every order on a large store is a lock and timeout risk. */
			do {
				$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM `$meta_table` WHERE meta_key = %s LIMIT 5000", '_woofunnel_custid' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table from $wpdb core props; meta_key via %s
			} while ( $deleted > 0 );
		}

		$this->update_import_meta( array( 'customers_reset' => current_time( 'mysql', 1 ) ) );

		BWFAN_Core()->logger->log( 'Customer purchase data reset at the start of WC import #' . $this->get_import_id(), 'import_wc_contacts' );
	}

	/**
	 * Stamp the fixed fetch window for this import.
	 *
	 * @param array $data Submitted step data.
	 *
	 * @return array
	 */
	public function handle_update( $data ) {
		/**
		 * Stamped once and then kept. update_import() has no in-progress guard, so a
		 * re-submit (double-clicked start, a retried request) lands here again; moving
		 * the window forward mid-import would pull newer orders into the front of the
		 * newest-first result set and push that many already-passed orders beyond the
		 * current offset, skipping them.
		 */
		$snapshot = $this->get_import_meta( 'import_snapshot_date' );

		return array(
			/**
			 * Fixed fetch window. Count and batch queries are both bound to this
			 * moment so orders created mid-import cannot drift the two apart;
			 * new orders are handled by the live tracking pipeline instead.
			 */
			'import_snapshot_date' => ! empty( $snapshot ) ? $snapshot : gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Needed only status and disable mebent field
	 *
	 * @return array
	 */
	public function contact_profile_fields() {
		return [
			'marketing_status',
			'disable_events',
			'update_existing'
		];
	}

	/**
	 * Get WooCommerce orders
	 *
	 * Fetches WooCommerce order IDs using direct SQL queries for better performance.
	 * Supports both HPOS (High-Performance Order Storage) and legacy post-based storage.
	 *
	 * @param array $args {
	 *     Optional. Arguments for querying orders.
	 *
	 *     @type string $after_date_created Optional. MySQL datetime string. Fetches orders created before this date.
	 *                                      Format: 'Y-m-d H:i:s' (e.g., '2024-01-01 00:00:00').
	 *     @type int    $limit              Optional. Number of orders to fetch. Default: no limit.
	 *     @type int    $offset             Optional. Number of orders to skip. Default: 0.
	 *     @type bool   $only_count         Optional. If true, returns count instead of order IDs. Default: false.
	 * }
	 *
	 * @return array|int Array of order IDs, or integer count if $only_count is true.
	 *                   Returns empty array if WooCommerce resolves no statuses or no orders are found.
	 */
	public static function get_wc_orders( $args = [] ) {

		global $wpdb;
		/** Every real status: a contact is created for each order, paid or not */
		$statuses = array_keys( self::get_importable_order_statuses() );
		if ( empty( $statuses ) ) {
			return [];
		}

		$placeholder        = array_fill( 0, count( $statuses ), '%s' );
		$placeholder        = implode( ", ", $placeholder );
		$order_by           = '';
		$after_date_created = '';
		$limit_query        = '';
		$prepare_args        = $statuses;
		if ( BWF_WC_Compatibility::is_hpos_enabled() ) {
			$query       = "SELECT `id` FROM {$wpdb->prefix}wc_orders  WHERE `status` IN ($placeholder) AND `type` = 'shop_order' AND ( `parent_order_id` = 0 OR `parent_order_id` IS NULL ) ";
			$count_query = "SELECT COUNT(`id`) FROM {$wpdb->prefix}wc_orders  WHERE `status` IN ($placeholder) AND `type` = 'shop_order' AND (`parent_order_id` = 0 OR `parent_order_id` IS NULL )";

			if ( ! empty( $args['after_date_created'] ) ) {
				/**
				 * Use '<' operator to fetch orders created BEFORE the specified date.
				 * This is correct for importing historical orders and matches the
				 * behavior of wc_get_orders() with 'date_created' => '<' parameter.
				 */
				$after_date_created = " AND `date_created_gmt` < %s ";
				/** id tiebreaker: same-second orders need a total order for OFFSET paging to be deterministic */
				$order_by           = " ORDER BY date_created_gmt DESC, id DESC";
				$prepare_args[]     = $args['after_date_created'];
			}

		} else {
			$query       = "SELECT `ID` AS id FROM {$wpdb->posts} WHERE `post_parent` = 0 AND `post_type` = 'shop_order' AND post_status IN($placeholder)";
			$count_query = "SELECT COUNT(`ID`) FROM {$wpdb->posts} WHERE `post_parent` = 0  AND `post_type` = 'shop_order' AND post_status IN($placeholder)";

			if ( ! empty( $args['after_date_created'] ) ) {
				/**
				 * Use '<' operator to fetch orders created BEFORE the specified date.
				 * This is correct for importing historical orders and matches the
				 * behavior of wc_get_orders() with 'date_created' => '<' parameter.
				 */
				$after_date_created = " AND `post_date_gmt` < %s ";
				/** id tiebreaker: same-second orders need a total order for OFFSET paging to be deterministic */
				$order_by           = " ORDER BY post_date_gmt DESC, ID DESC";
				$prepare_args[]     = $args['after_date_created'];
			}

		}
		if ( ! empty( $args['only_count'] ) ) {
			$query = $wpdb->prepare( "$count_query $after_date_created", ...$prepare_args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- hardcoded fragments; values bound via prepare; limit via %d
			$count = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded fragments; values bound via prepare; limit via %d

			return ! empty( $count ) ? intval( $count ) : 0;
		}
		$query = $wpdb->prepare( "$query $after_date_created $order_by", ...$prepare_args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- hardcoded fragments; values bound via prepare; limit via %d
		if ( ! empty( $args['limit'] ) ) {
			$limit_query = " LIMIT %d OFFSET %d";
			$limit_query = $wpdb->prepare( $limit_query, $args['limit'], isset( $args['offset'] ) ? $args['offset'] : 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- hardcoded fragments; values bound via prepare; limit via %d
		}
		$orders = $wpdb->get_col( "$query $limit_query " ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- hardcoded fragments; values bound via prepare; limit via %d

		return ! empty( $orders ) ? $orders : [];
	}
}

if ( function_exists( 'bwfan_is_woocommerce_active' ) && bwfan_is_woocommerce_active() ) {
	BWFAN_Core()->importer->register( 'wc', 'BWFAN\Importers\WC_Importer' );
}