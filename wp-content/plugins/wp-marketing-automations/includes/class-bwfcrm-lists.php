<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BWFCRM_Lists' ) && BWFAN_Common::is_pro_3_0() ) {
	#[\AllowDynamicProperties]
	class BWFCRM_Lists extends BWFCRM_Term {
		public function get_description() {
			if ( is_array( $this->_data ) && isset( $this->_data['description'] ) ) {
				return $this->_data['description'];
			}

			return '';
		}

		public function set_description( $description ) {
			if ( ! is_array( $this->_data ) ) {
				$this->_data = array();
			}

			$this->_data['description'] = $description;
		}

		public function __construct( $data = false ) {
			parent::__construct( $data, BWFCRM_Term_Type::$LIST );
		}

		public static function get_lists( $ids = array(), $search = '', $offset = 0, $limit = 0, $return = ARRAY_A, $use_cache = false ) {
			return parent::get_terms( 2, $ids, $search, $offset, $limit, $return, '', $use_cache );
		}

		public static function get_contact( $list_ids, $offset, $limit ) {
			if ( is_array( $list_ids ) ) {
				$list_ids = implode( ',', $list_ids );
			}
			$filter   = array( 'lists_any' => array( $list_ids ) );
			$contacts = BWFCRM_Contact::get_contacts( '', $offset, $limit, $filter, array(), OBJECT );
			if ( empty( $contacts['contacts'] ) ) {
				return array();
			}
			$list_contacts = array();

			/** @var BWFCRM_Contact $contact */
			foreach ( $contacts['contacts'] as $contact ) {
				if ( ! $contact->is_contact_exists() ) {
					continue;
				}
				$contact_data = $contact->get_basic_array( 'terms' );
				$lists        = $contact_data['lists'];
				unset( $contact_data['lists'] );
				unset( $contact_data['tags'] );
				foreach ( $lists as $list_id ) {
					if ( isset( $list_contacts[ $list_id ] ) && count( $list_contacts[ $list_id ] ) >= 5 ) {
						continue;
					}
					$list_contacts[ $list_id ][] = $contact_data;
				}
			}

			return $list_contacts;
		}

		/**
		 * @param $id
		 *
		 * @return int
		 */
		public static function count_all_contact( $id ) {
			$filter   = array( 'lists_any' => array( $id ) );
			$contacts = BWFCRM_Contact::get_contacts( '', 0, 0, $filter, array(), OBJECT );

			if ( empty( $contacts['contacts'] ) ) {
				return 0;
			}
			$contact_count = 0;
			foreach ( $contacts['contacts'] as $contact ) {

				if ( ! $contact->is_contact_exists() ) {
					continue;
				}
				$contact_count ++;
			}

			return $contact_count;
		}

		/**
		 * @param $id
		 *
		 * @return bool
		 */
		public static function delete_list( $id ) {
			return BWFAN_Model_Terms::delete_term( $id );
		}

		public function get_array() {
			$array = parent::get_array();
			if ( isset( $array['data']['description'] ) ) {
				unset( $array['data']['description'] );
			}
			if ( isset( $array['data'] ) && empty( $array['data'] ) ) {
				unset( $array['data'] );
			}

			$description = $this->get_description();
			if ( ! empty( $description ) ) {
				$array['description'] = $description;
			}

			return $array;
		}

	}
}