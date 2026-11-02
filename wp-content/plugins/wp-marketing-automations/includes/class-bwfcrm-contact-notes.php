<?php
/**
 * Contact Controller Class
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BWFCRM_Note' ) && BWFAN_Common::is_pro_3_0() ) {
	/**
	 * Class BWFCRM_Note
	 *
	 */
	#[\AllowDynamicProperties]
	class BWFCRM_Note {
		public $already_exists_in_db = true;

		public $id = 0;
		public $title = '';
		public $body = '';
		public $created_by = '';
		public $created_date = '';
		public $modified_date = '';
		public $date_time = '';
		public $type = 1;
		public $cid = 0;

		public static $NOTE_GENERAL = 'general';
		public static $NOTE_EMAIL = 'email';
		public static $NOTE_CALL = 'call';
		public static $NOTE_MEETING = 'meeting';

		public function __construct( $note_id = 0, $create_if_not_exists = false, $create_note_args = array() ) {
			if ( true === $create_if_not_exists ) {
				$note_id = $this->create_note( $create_note_args );
			}

			if ( is_numeric( $note_id ) && absint( $note_id ) > 0 ) {
				$note = BWFAN_Model_Contact_Note::get( $note_id );
				$this->fill_note_from_db_row( $note );
			}
		}

		public function exists() {
			return absint( $this->id ) > 0;
		}

		public function create_note( $args ) {
			if ( ! is_array( $args ) || ! isset( $args['cid'] ) || ! absint( $args['cid'] ) > 0 ) {
				return false;
			}

			$user_id                     = get_current_user_id();
			$notes_data['cid']           = absint( $args['cid'] );
			$notes_data['title']         = isset( $args['title'] ) ? $args['title'] : '';
			$notes_data['body']          = isset( $args['body'] ) ? $args['body'] : '';
			$notes_data['created_by']    = isset( $args['created_by'] ) ? $args['created_by'] : $user_id;
			$notes_data['created_date']  = current_time( 'mysql', 1 );
			$notes_data['modified_date'] = current_time( 'mysql', 1 );
			$notes_data['date_time']     = isset( $args['date_time'] ) ? date( 'Y-m-d H:i:s', strtotime( $args['date_time'] ) ) : current_time( 'mysql', 1 ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Round-trips strtotime($string) in default tz; gmdate would shift the stored note time
			$notes_data['type']          = isset( $args['type'] ) ? $args['type'] : self::$NOTE_GENERAL;

			BWFAN_Model_Contact_Note::insert( $notes_data );
			$contact_note_id = BWFAN_Model_Contact_Note::insert_id();

			if ( empty( $contact_note_id ) ) {
				return false;
			}

			$this->already_exists_in_db = false;

			return $contact_note_id;
		}

		public function fill_note_from_db_row( $db_row ) {
			if ( ! is_array( $db_row ) || ! isset( $db_row['id'] ) ) {
				return;
			}

			$this->id            = absint( $db_row['id'] );
			$this->cid           = absint( $db_row['cid'] );
			$this->title         = $db_row['title'];
			$this->body          = $db_row['body'];
			$this->created_by    = absint( $db_row['created_by'] );
			$this->created_date  = $db_row['created_date'];
			$this->modified_date = $db_row['modified_date'];
			$this->date_time     = $db_row['date_time'];
			$this->type          = absint( $db_row['type'] );
		}

		public function update_note( $args ) {
			$notes_data = array();

			if ( ! empty( $args['title'] ) ) {
				$notes_data['title'] = $args['title'];
			}

			if ( ! empty( $args['created_by'] ) ) {
				$notes_data['created_by'] = absint( $args['created_by'] );
			}

			if ( ! empty( $args['body'] ) ) {
				$notes_data['body'] = $args['body'];
			}

			if ( ! empty( $args['date_time'] ) ) {
				$notes_data['date_time'] = date( 'Y-m-d H:i:s', strtotime( $args['date_time'] ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Round-trips strtotime($string) in default tz; gmdate would shift the stored note time
			}

			if ( ! empty( $args['type'] ) ) {
				$notes_data['type'] = absint( $args['type'] );
			}

			if ( ! empty( $args['cid'] ) ) {
				$notes_data['cid'] = absint( $args['cid'] );
			}

			$notes_data['modified_by']   = $args['modified_by'];
			$notes_data['modified_date'] = current_time( 'mysql', 1 );

			$result = BWFAN_Model_Contact_Note::update_contact_note( $this->cid, $notes_data, $this->id );
			if ( false !== $result ) {
				$note = BWFAN_Model_Contact_Note::get( $this->id );
				$this->fill_note_from_db_row( $note );
			}

			return $result;
		}

		public function get_array() {
			return array(
				'id'            => $this->id,
				'cid'           => $this->cid,
				'title'         => $this->title,
				'body'          => $this->body,
				'created_by'    => $this->created_by,
				'created_date'  => $this->created_date,
				'modified_date' => $this->modified_date,
				'date_time'     => $this->date_time,
				'type'          => $this->type,
			);
		}
	}
}