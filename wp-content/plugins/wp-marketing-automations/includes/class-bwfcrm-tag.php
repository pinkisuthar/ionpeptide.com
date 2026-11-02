<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BWFCRM_Tag' ) && BWFAN_Common::is_pro_3_0() ) {
	#[\AllowDynamicProperties]
	class BWFCRM_Tag extends BWFCRM_Term {
		public function __construct( $data = false ) {
			parent::__construct( $data, BWFCRM_Term_Type::$TAG );
		}

		public static function get_tags( $ids = array(), $search = '', $offset = 0, $limit = 0, $return = ARRAY_A, $use_cache = false ) {
			return parent::get_terms( 1, $ids, $search, $offset, $limit, $return, '', $use_cache );
		}

		public static function get_contact( $tag_ids, $offset, $limit ) {
			if ( is_array( $tag_ids ) ) {
				$tag_ids = implode( ',', $tag_ids );
			}
			$filter   = [ 'tags_any' => [ $tag_ids ] ];
			$contacts = BWFCRM_Contact::get_contacts( '', $offset, $limit, $filter, [], OBJECT );

			if ( empty( $contacts['contacts'] ) ) {
				return array();
			}
			$tag_contacts = [];

			/** @var BWFCRM_Contact $contact */
			foreach ( $contacts['contacts'] as $contact ) {
				if ( ! $contact->is_contact_exists() ) {
					continue;
				}

				$contact_data = $contact->get_basic_array( 'terms' );
				$tags         = $contact_data['tags'];
				unset( $contact_data['lists'] );
				unset( $contact_data['tags'] );
				foreach ( $tags as $tag_id ) {
					if ( isset( $tag_contacts[ $tag_id ] ) && count( $tag_contacts[ $tag_id ] ) >= 5 ) {
						continue;
					}
					$tag_contacts[ $tag_id ][] = $contact_data;
				}
			}

			return $tag_contacts;
		}

		public static function count_all_contact( $id ) {
			$filter   = [ 'tags_any' => [ $id ] ];
			$contacts = BWFCRM_Contact::get_contacts( '', 0, 0, $filter, [], OBJECT );

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

		public static function delete_tag( $id ) {
			return BWFAN_Model_Terms::delete_term( $id );
		}
	}
}