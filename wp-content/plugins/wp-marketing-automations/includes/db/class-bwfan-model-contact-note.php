<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BWFAN_Model_Contact_Note' ) && BWFAN_Common::is_pro_3_0() ) {

	#[\AllowDynamicProperties]
	class BWFAN_Model_Contact_Note extends BWFAN_Model {

		/**
		 * @param $contact_id
		 * @param int $offset
		 * @param int $limit
		 *
		 * @return array
		 */
		public static function get_contact_notes( $contact_id, $offset = 0, $limit = 0 ) {
			global $wpdb;
			if ( empty( $offset ) && empty( $limit ) ) {
				$query = $wpdb->prepare( "SELECT * FROM {table_name} WHERE `cid` = %d ORDER BY `created_date` DESC", $contact_id );
			} else {
				$query = $wpdb->prepare( "SELECT * FROM {table_name} WHERE `cid` = %d ORDER BY `created_date` DESC LIMIT %d,%d", $contact_id, $offset, $limit );
			}

			return self::get_results( $query );
		}

		/**
		 * Update contact note
		 *
		 * @param $contact_id
		 * @param $notes
		 * @param $note_id
		 *
		 * @return bool|int|mysqli_result|resource|null
		 */
		public static function update_contact_note( $contact_id, $notes, $note_id ) {
			$data = $notes;

			$where = array(
				'id'  => $note_id,
				'cid' => $contact_id,
			);

			return self::update( $data, $where );
		}
	}
}