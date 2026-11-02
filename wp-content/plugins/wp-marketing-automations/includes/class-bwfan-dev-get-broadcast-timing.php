<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'BWFAN_DEV_Get_Broadcast_Timing' ) ) {
	/**
	 * Dev report: wp-admin/?admin_broadcast_id=ID[&limit=N][&apage=P]
	 *
	 * Reads the flow off the broadcast row and reports accordingly.
	 *
	 * v1 creates each engagement at the moment it is sent, so every row's created_at is a send
	 * attempt. v2 creates every engagement first (up to an hour before the send) and re-stamps
	 * created_at on the rows it actually sends, so:
	 *   - c_status 2 rows carry their send time, and are the only rows that measure sending
	 *   - c_status 1 (draft) and 3 (error) rows still carry their creation time
	 * Counting or timing across all statuses reports the audience as "sent" and the creation
	 * phase as sending, which is what the previous version of this report did.
	 *
	 * All times are UTC, as stored.
	 */
	#[\AllowDynamicProperties]
	final class BWFAN_DEV_Get_Broadcast_Timing {
		private static $ins = null;

		/** Seconds of silence that separate two worker calls in the per-call table */
		const CALL_GAP = 3;

		public function __construct() {
			add_action( 'admin_head', [ $this, 'broadcast_details' ] );
		}

		public static function get_instance() {
			if ( null === self::$ins ) {
				self::$ins = new self();
			}

			return self::$ins;
		}

		public function broadcast_details() {
			$bid = isset( $_GET['admin_broadcast_id'] ) ? absint( $_GET['admin_broadcast_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( empty( $bid ) || ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$this->output_css();
			echo '<div class="broadcast_data_wrapper">'; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$this->render( $bid );
			echo '</div>'; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		public function render( $bid ) {
			global $wpdb;

			$broadcast = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bwfan_broadcast WHERE `id` = %d", $bid ), ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( empty( $broadcast ) ) {
				echo '<h3>No Broadcast details found.</h3>';

				return;
			}

			$data  = ! empty( $broadcast['data'] ) ? json_decode( $broadcast['data'], true ) : array();
			$data  = is_array( $data ) ? $data : array();
			$is_v2 = ! empty( $data['v2'] );

			$by_status = $this->get_status_summary( $bid );
			$sent      = $by_status[2] ?? array( 'n' => 0, 'first' => '', 'last' => '' );
			$draft     = $by_status[1] ?? array( 'n' => 0, 'first' => '', 'last' => '' );
			$error     = $by_status[3] ?? array( 'n' => 0, 'first' => '', 'last' => '' );
			$rows      = array_sum( wp_list_pluck( $by_status, 'n' ) );

			$status_labels = array( 1 => 'draft', 2 => 'scheduled', 3 => 'running', 4 => 'completed', 5 => 'halted', 6 => 'paused', 7 => 'cancelled' );
			$status        = absint( $broadcast['status'] );

			echo '<div class="broadcast_data">';
			echo '<h3>Broadcast #' . absint( $bid ) . ' — ' . esc_html( $broadcast['title'] ) . '</h3>';
			echo 'Flow: <b>' . ( $is_v2 ? 'v2 (create engagements, then send)' : 'v1 (create and send per contact)' ) . '</b><br>';
			echo 'Status: <b>' . esc_html( $status_labels[ $status ] ?? $status ) . '</b>';
			echo ' &nbsp; audience (count): ' . absint( $broadcast['count'] ) . ' &nbsp; processed: ' . absint( $broadcast['processed'] );
			if ( $is_v2 ) {
				echo ' &nbsp; engagements created (e_processed): ' . absint( $broadcast['e_processed'] );
			}
			echo '<br>';
			echo 'Execution time: ' . esc_html( $broadcast['execution_time'] ) . ' UTC';
			if ( $is_v2 && ! empty( $data['prepared_for'] ) ) {
				echo ' &nbsp; prepared for: ' . esc_html( $data['prepared_for'] ) . ' UTC';
			}
			echo '<br>';
			echo 'Engagement rows: ' . absint( $rows ) . ' &nbsp; sent: <b>' . absint( $sent['n'] ) . '</b> &nbsp; draft (unsent): ' . absint( $draft['n'] ) . ' &nbsp; error: ' . absint( $error['n'] ) . '<br>';

			if ( empty( $rows ) ) {
				echo '<h3>Broadcast not started yet.</h3></div>';

				return;
			}

			/* ---- creation phase: only measurable on v2, and only from rows not yet sent ---- */
			if ( $is_v2 ) {
				echo '<h3>Creation phase</h3>';
				$unsent = $this->get_unsent_creation_window( $bid );
				/**
				 * Only rows not yet sent still carry their creation stamp, so the window is complete
				 * before sending starts and shrinks to the tail of creation as rows go out. The rate
				 * is printed only while (nearly) all rows are still unsent - after that it would be
				 * the rate of whatever is left, not of the creation phase.
				 */
				$share = $rows > 0 ? $unsent['n'] / $rows : 0;
				if ( empty( $unsent['n'] ) ) {
					echo 'Not measurable any more: every row has been sent, and a sent row has its created_at re-stamped with the send time.<br>';
				} else {
					$secs = max( 1, strtotime( $unsent['last'] ) - strtotime( $unsent['first'] ) + 1 );
					printf( '%d of %d rows (%d%%) still carry their creation stamp, spanning %s → %s UTC (%d s).', absint( $unsent['n'] ), absint( $rows ), intval( 100 * $share ), esc_html( $unsent['first'] ), esc_html( $unsent['last'] ), $secs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numeric %d / %.1f placeholders only
					if ( $share >= 0.9 ) {
						printf( ' Creation rate: <b>%.1f rows/s</b>.', $unsent['n'] / $secs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numeric %d / %.1f placeholders only
					} else {
						echo ' The rest were created earlier and re-stamped when sent, so only the tail of the creation phase is visible now.';
					}
					echo '<br>';
				}
			}

			/* ---- sending ---- */
			echo '<h3>Sending</h3>';
			if ( empty( $sent['n'] ) ) {
				echo 'No emails sent yet.</div>';

				return;
			}

			$seconds = $this->get_sent_per_second( $bid );
			$calls   = $this->group_into_calls( $seconds );
			$wall    = max( 1, strtotime( $sent['last'] ) - strtotime( $sent['first'] ) + 1 );
			$in_call = 0;
			foreach ( $calls as $call ) {
				$in_call += $call['dur'];
			}
			$in_call = max( 1, $in_call );
			$idle    = max( 0, $wall - $in_call );

			echo 'First send: ' . esc_html( $sent['first'] ) . ' UTC<br>';
			echo 'Last send: ' . esc_html( $sent['last'] ) . ' UTC' . ( 4 === $status ? '' : ' (still running)' ) . '<br>';
			echo 'Total sent: <b>' . absint( $sent['n'] ) . '</b><br>';
			printf( 'Wall clock: %d s%s<br>', $wall, $wall > 60 ? ' (' . intval( $wall / 60 ) . ' min)' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numeric %d / %.1f placeholders only
			printf( 'Inside worker calls: %d s in %d calls &nbsp; idle between calls: %d s (%d%%)<br>', $in_call, count( $calls ), $idle, $wall > 0 ? intval( 100 * $idle / $wall ) : 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numeric %d / %.1f placeholders only
			printf( 'Emails per second while sending: <b>%.1f</b> &nbsp; over the wall clock: <b>%.1f</b><br>', $sent['n'] / $in_call, $sent['n'] / $wall ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numeric %d / %.1f placeholders only
			echo '</div>';

			/* ---- per worker call ---- */
			echo '<div class="broadcast_data"><h3>Per worker call</h3>';
			echo '<table class="broadcast_table_idel"><tbody>';
			echo '<tr><td><b>#</b></td><td><b>Start (UTC)</b></td><td><b>Duration</b></td><td><b>Mails</b></td><td><b>Per sec</b></td><td><b>Peak sec</b></td><td><b>Idle before</b></td></tr>';
			$prev_end = null;
			foreach ( $calls as $i => $call ) {
				$gap = null === $prev_end ? 0 : $call['start'] - $prev_end;
				printf( '<tr><td>%d</td><td>%s</td><td>%d s</td><td>%d</td><td>%.1f</td><td>%d</td><td>%d s</td></tr>', $i + 1, gmdate( 'Y-m-d H:i:s', $call['start'] ), $call['dur'], $call['n'], $call['n'] / max( 1, $call['dur'] ), $call['peak'], $gap ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numeric %d / %.1f placeholders only
				$prev_end = $call['end'];
			}
			echo '</tbody></table></div>';

			/* ---- per second, newest first, paged ---- */
			$limit  = isset( $_GET['limit'] ) ? absint( $_GET['limit'] ) : 100; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$page   = isset( $_GET['apage'] ) ? absint( $_GET['apage'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$offset = $page > 0 ? ( $page - 1 ) * $limit : 0;
			$slice  = array_slice( array_reverse( $seconds ), $offset, $limit ?: null );

			echo '<div class="broadcast_table"><h3>Sent per second (newest first)</h3>';
			echo '<div><div>Date Time (UTC)</div><div>Sent</div></div>';
			foreach ( $slice as $row ) {
				echo '<div><div>' . esc_html( gmdate( 'Y-m-d H:i:s', $row['t'] ) ) . '</div><div>' . absint( $row['n'] ) . '</div></div>';
			}
			echo '</div>';
		}

		/** Row count and created_at range per c_status */
		public function get_status_summary( $oid ) {
			global $wpdb;

			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT `c_status`, COUNT(*) AS n, MIN(`created_at`) AS first, MAX(`created_at`) AS last FROM {$wpdb->prefix}bwfan_engagement_tracking WHERE `oid` = %d AND `type` = %d GROUP BY `c_status`", $oid, 2 ), ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			$out = array();
			foreach ( (array) $rows as $row ) {
				$out[ absint( $row['c_status'] ) ] = array( 'n' => absint( $row['n'] ), 'first' => $row['first'], 'last' => $row['last'] );
			}

			return $out;
		}

		/** Rows that still carry their creation stamp: drafts and errors (v2 never re-stamps those) */
		public function get_unsent_creation_window( $oid ) {
			global $wpdb;

			return $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, MIN(`created_at`) AS first, MAX(`created_at`) AS last FROM {$wpdb->prefix}bwfan_engagement_tracking WHERE `oid` = %d AND `type` = %d AND `c_status` IN (1, 3)", $oid, 2 ), ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		/** Sent rows per second, oldest first: [ [ t => unix, n => count ], ... ] */
		public function get_sent_per_second( $oid ) {
			global $wpdb;

			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT `created_at`, COUNT(*) AS n FROM {$wpdb->prefix}bwfan_engagement_tracking WHERE `oid` = %d AND `type` = %d AND `c_status` = %d GROUP BY `created_at` ORDER BY `created_at` ASC", $oid, 2, 2 ), ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			$out = array();
			foreach ( (array) $rows as $row ) {
				/** created_at is written with current_time( 'mysql', 1 ), so it is UTC */
				$out[] = array( 't' => strtotime( $row['created_at'] . ' UTC' ), 'n' => absint( $row['n'] ) );
			}

			return $out;
		}

		/** Split the per-second series into worker calls wherever sending pauses for more than CALL_GAP seconds */
		public function group_into_calls( $seconds ) {
			$calls = array();
			$cur   = null;
			$prev  = null;

			foreach ( $seconds as $row ) {
				if ( null === $prev || ( $row['t'] - $prev ) > self::CALL_GAP ) {
					if ( null !== $cur ) {
						$calls[] = $cur;
					}
					$cur = array( 'start' => $row['t'], 'end' => $row['t'], 'n' => 0, 'peak' => 0 );
				}
				$cur['end']  = $row['t'];
				$cur['n']    += $row['n'];
				$cur['peak'] = max( $cur['peak'], $row['n'] );
				$prev        = $row['t'];
			}
			if ( null !== $cur ) {
				$calls[] = $cur;
			}

			foreach ( $calls as &$call ) {
				$call['dur'] = $call['end'] - $call['start'] + 1;
			}

			return $calls;
		}

		public function output_css() {
			?>
            <style>
                h3 {
                    margin: 20px 0 10px;
                }

                .broadcast_data_wrapper {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 20px;
                    padding: 20px;
                }

                .broadcast_data {
                    font-size: 15px;
                    line-height: 2;
                    min-width: 420px;
                }

                .broadcast_table {
                    width: 420px;
                    line-height: 2;
                }

                .broadcast_table > div {
                    display: grid;
                    grid-template-columns: repeat(2, 1fr);
                }

                table.broadcast_table_idel {
                    border: 1px solid #ccc;
                    border-collapse: collapse;
                    font-size: 14px;
                    line-height: 1;
                    height: fit-content;
                }

                table.broadcast_table_idel td {
                    border-bottom: 1px solid #ccc;
                    padding: 6px 10px;
                    line-height: 1;
                    white-space: nowrap;
                }
            </style>
			<?php
		}
	}

	BWFAN_DEV_Get_Broadcast_Timing::get_instance();
}
