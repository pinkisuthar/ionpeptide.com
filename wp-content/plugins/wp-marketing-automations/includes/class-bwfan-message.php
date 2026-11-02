<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BWFAN_Message' ) && BWFAN_Common::is_pro_3_0() ) {
	#[AllowDynamicProperties]
	class BWFAN_Message {
		private $_id = 0;
		private $_track_id = 0;
		private $_subject = '';
		private $_body = '';
		private $_date = '';
		private $data = [];

		public function __construct( $m_id = 0 ) {
			if ( empty( absint( $m_id ) ) ) {
				return;
			}

			$message = BWFAN_Model_Message::get( absint( $m_id ) );
			if ( ! is_array( $message ) || empty( $message ) ) {
				return;
			}

			$this->set_message( $message['ID'], $message['track_id'], $message['sub'], $message['body'], $message['date'] );
			if ( isset( $message['data'] ) ) {
				$this->set_data( $message['data'] );
			}
		}

		public function is_message_exists() {
			return $this->get_id() > 0;
		}

		public function set_message( $id, $track_id, $subject, $body, $date = '' ) {
			! empty( absint( $id ) ) && $this->set_id( absint( $id ) );
			! empty( absint( $track_id ) ) && $this->set_track_id( absint( $track_id ) );
			! empty( $subject ) && $this->set_subject( $subject );
			! empty( $body ) && $this->set_body( $body );
			! empty( $date ) && $this->set_date( $date );
		}

		public function get_id() {
			return absint( $this->_id );
		}

		public function get_subject() {
			return $this->_subject;
		}

		public function get_track_id() {
			return $this->_track_id;
		}

		public function get_body() {
			return $this->_body;
		}

		public function get_date() {
			return $this->_date;
		}

		public function set_id( $id ) {
			$this->_id = absint( $id );
		}

		public function set_body( $body ) {
			$this->_body = $body;
		}

		public function set_track_id( $track_id ) {
			$this->_track_id = $track_id;
		}

		public function set_date( $date ) {
			$this->_date = $date;
		}

		public function set_subject( $subject ) {
			$this->_subject = $subject;
		}

		public function set_data( $data ) {
			$this->data = $data;
		}

		public function get_data() {
			return json_decode( $this->data, true );
		}

		/**
		 * @return int
		 */
		public function save() {
			if ( empty( $this->get_track_id() ) && empty( $this->get_body() ) ) {
				return false;
			}
			if ( 0 === $this->get_id() ) {
				$insert_data = array(
					'track_id' => $this->get_track_id(),
					'sub'      => $this->get_subject(),
					'body'     => $this->get_body(),
					'date'     => current_time( 'mysql', 1 ),
				);
				if ( ! empty( $this->data ) ) {
					$insert_data['data'] = wp_json_encode( $this->data );
				}
				BWFAN_Model_Message::insert( $insert_data );

				$this->set_id( BWFAN_Model_Message::insert_id() );
			} else {
				$updated_data = array(
					'sub'  => $this->get_subject(),
					'body' => $this->get_body(),
				);
				if ( ! empty( $this->data ) ) {
					$updated_data['data'] = wp_json_encode( $this->data );
				}
				BWFAN_Model_Message::update( $updated_data, [ 'ID' => $this->get_id() ] );
			}

			return $this->get_id();
		}

		public function get_array() {
			$message = array(
				'ID'       => $this->get_id(),
				'track_id' => $this->get_track_id(),
				'sub'      => $this->get_subject(),
				'body'     => $this->get_body(),
				'date'     => $this->get_date()
			);

			return $message;
		}
	}
}