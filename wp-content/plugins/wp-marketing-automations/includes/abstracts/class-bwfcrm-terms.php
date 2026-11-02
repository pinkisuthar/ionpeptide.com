<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BWFCRM_Term' ) && BWFAN_Common::is_pro_3_0() ) {
	#[\AllowDynamicProperties]
	class BWFCRM_Term {
		protected $_id = null;
		protected $_name = null;
		protected $_type = null;
		protected $_created_at = null;
		protected $_updated_at = null;
		protected $_newly_created = false;
		protected $_data = '';

		public static $tags_by_id = array();

		public function __construct( $data = false, $type = 1, $force_create = false ) {
			if ( empty( $data ) ) {
				$this->_type = $type;

				return;
			}

			/** If data is BWFCRM_Term */
			if ( $data instanceof BWFCRM_Term ) {
				$this->init_term( $data );
			}

			/** Check if the term is already stored in cache */
			$term = self::maybe_get_term( $data );

			if ( false !== $term && $term instanceof BWFCRM_Term ) {
				$this->init_term( $term );
			}

			/** If data is an array */
			if ( is_array( $data ) ) {
				$this->init_term( $data );
			}

			/** If data is an integer */
			if ( is_numeric( $data ) && 0 < absint( $data ) ) {
				$this->init_term( BWFAN_Model_Terms::get( absint( $data ) ) );
			}

			/** If data is a string */
			if ( ! empty( $data ) && is_string( $data ) ) {
				$term_row = BWFAN_Model_Terms::get_term_by_name( $data, $type );
				/** in case of not found then insert */
				if ( empty( $term_row ) && $force_create ) {
					$term_row = self::add_term_to_db( $data, $type );
				}

				if ( ! empty( $term_row ) ) {
					$this->init_term( $term_row );
					$this->_newly_created = true;
				}
			}

			/** Store terms for further use, within current HTTP Request */
			if ( ! empty( $this->get_id() ) ) {
				self::$tags_by_id[ $this->get_id() ] = $this;
			}
		}

		public static function maybe_get_term( $data = false ) {
			if ( is_array( $data ) && isset( $data['ID'] ) && isset( self::$tags_by_id[ absint( $data['ID'] ) ] ) ) {
				return self::$tags_by_id[ absint( $data['ID'] ) ];
			}

			if ( is_numeric( $data ) && absint( $data ) > 0 ) {
				if ( isset( self::$tags_by_id[ absint( $data ) ] ) ) {
					return self::$tags_by_id[ absint( $data ) ];
				}
			}

			return false;
		}

		public function is_exists() {
			return is_numeric( $this->get_id() ) && ! empty( $this->get_id() );
		}

		public function is_newly_created() {
			return $this->_newly_created;
		}

		public static function add_term_to_db( $term_name, $type = 0 ) {
			BWFAN_Model_Terms::insert( array(
				'name'       => $term_name,
				'type'       => $type,
				'created_at' => current_time( 'mysql', 1 ),
			) );

			return BWFAN_Model_Terms::get( BWFAN_Model_Terms::insert_id() );
		}

		private function init_term( $db_term ) {
			if ( $db_term instanceof BWFCRM_Term ) {
				$db_term = array(
					'ID'         => $db_term->get_id(),
					'name'       => $db_term->get_name(),
					'type'       => $db_term->get_type(),
					'created_at' => $db_term->get_created_at(),
				);
			}

			$this->_id = isset( $db_term['ID'] ) ? absint( $db_term['ID'] ) : 0;

			if ( empty( $this->_id ) && isset( $db_term['_id'] ) ) {
				$this->_id = $db_term['_id'];
			}

			$this->_type = isset( $db_term['type'] ) ? $db_term['type'] : - 1;

			if ( empty( $this->_type ) && isset( $db_term['_type'] ) ) {
				$this->_type = $db_term['_type'];
			}

			$this->_name = isset( $db_term['name'] ) ? $db_term['name'] : '';

			if ( empty( $this->_name ) && isset( $db_term['_name'] ) ) {
				$this->_name = $db_term['_name'];
			}

			$this->_created_at = isset( $db_term['created_at'] ) ? $db_term['created_at'] : null;
			if ( empty( $this->_created_at ) && isset( $db_term['_created_at'] ) ) {
				$this->_created_at = $db_term['_created_at'];
			}

			$this->_updated_at = isset( $db_term['updated_at'] ) ? $db_term['updated_at'] : null;
			if ( empty( $this->_updated_at ) && isset( $db_term['_updated_at'] ) ) {
				$this->_updated_at = $db_term['_updated_at'];
			}

			$this->_data = isset( $db_term['data'] ) && ! empty( $db_term['data'] ) ? json_decode( $db_term['data'], true ) : null;
			if ( empty( $this->_data ) && isset( $db_term['_data'] ) && ! empty( $db_term['_data'] ) ) {
				$this->_data = json_decode( $db_term['_data'], true );
			}
		}

		public function save() {
			if ( empty( $this->_name ) || empty( $this->_type ) ) {
				return BWFAN_Common::crm_error( __( 'Required term data is missing.', 'wp-marketing-automations' ) );
			}

			$term = array(
				'name'       => $this->_name,
				'type'       => $this->_type,
				'data'       => empty( $this->_data ) ? '' : wp_json_encode( $this->_data ),
				'updated_at' => current_time( 'mysql', 1 ),
			);

			if ( ! empty( $this->_id ) ) {
				return BWFAN_Model_Terms::update( $term, array( 'ID' => absint( $this->_id ) ) );
			} else {
				$term['created_at'] = $term['updated_at'];
				BWFAN_Model_Terms::insert( $term );

				return BWFAN_Model_Terms::insert_id();
			}
		}

		public function get_data() {
			return $this->_data;
		}

		public function get_id() {
			return absint( $this->_id );
		}

		public function get_name() {
			return $this->_name;
		}

		public function set_name( $name ) {
			$this->_name = $name;
		}

		public function get_type() {
			return $this->_type;
		}

		public function get_created_at() {
			return $this->_created_at;
		}

		public function get_updated_at() {
			return $this->_updated_at;
		}

		public function get_array() {
			return array(
				'ID'         => $this->get_id(),
				'name'       => $this->get_name(),
				'type'       => $this->get_type(),
				'created_at' => $this->get_created_at(),
				'updated_at' => $this->get_updated_at(),
				'data'       => $this->get_data(),
			);
		}

		/**
		 * @param BWFCRM_Term[] $objects
		 * @param string $key
		 *
		 * @return array[]
		 */
		public static function get_collection_array( $objects, $key = '' ) {
			if ( ! is_array( $objects ) || empty( $objects ) ) {
				return array();
			}

			$array = array_map( function ( $object ) use ( $key ) {
				if ( ! $object instanceof BWFCRM_Term ) {
					return false;
				}

				if ( ! empty( $key ) ) {
					$value = call_user_func( array( $object, 'get_' . $key ) );

					return $value;
				}

				return $object->get_array();
			}, $objects );

			return array_filter( $array );
		}

		public static function get_objects_from_db_rows( $data = array() ) {
			if ( ! is_array( $data ) || empty( $data ) ) {
				return array();
			}

			return array_map( function ( $single_data ) {
				if ( $single_data instanceof BWFCRM_Term ) {
					return $single_data;
				}

				return ( BWFCRM_Term_Type::$TAG === absint( $single_data['type'] ) ? ( new BWFCRM_Tag( $single_data ) ) : ( new BWFCRM_Lists( $single_data ) ) );
			}, $data );
		}

		public static function get_array_from_db_rows( $data = array() ) {
			if ( ! is_array( $data ) || empty( $data ) ) {
				return array();
			}

			return array_map( function ( $single_data ) {
				/** To store the db_row in cache i.e.: BWFCRM_Term::$tags_by_id & BWFCRM_Term::$tags_by_slug */
				$term = BWFCRM_Term_Type::$TAG === absint( $single_data['type'] ) ? ( new BWFCRM_Tag( $single_data ) ) : ( new BWFCRM_Lists( $single_data ) );

				return $term->get_array();
			}, $data );
		}

		public static function get_terms( $type = 1, $ids = array(), $search = '', $offset = 0, $limit = 0, $return = ARRAY_A, $search_nature = '', $use_cache = false ) {
			$db_terms = BWFAN_Model_Terms::get_terms( $type, $offset, $limit, $search, $ids, $search_nature, $use_cache );

			return ( ARRAY_A === $return ? self::get_array_from_db_rows( $db_terms ) : self::get_objects_from_db_rows( $db_terms ) );
		}

		public static function get_terms_by_type( $type = 1, $return = ARRAY_A ) {
			$db_terms = BWFAN_Model_Terms::get_specific_rows( 'type', $type );

			return ARRAY_A === $return ? self::get_array_from_db_rows( $db_terms ) : self::get_objects_from_db_rows( $db_terms );
		}

		/**
		 * @param $terms
		 * @param $type
		 *
		 * @return bool
		 */
		public static function add_terms_to_db( $terms, $type ) {
			$terms  = array_map( function ( $term ) use ( $type ) {
				return array(
					'name'       => $term,
					'type'       => $type,
					'created_at' => current_time( 'mysql', 1 ),
				);
			}, $terms );
			$result = BWFAN_Model_Terms::insert_multiple( $terms, array( 'name', 'type', 'created_at' ) );
			if ( false === $result ) {
				return $result;
			}

			return true;
		}


		/**
		 * Terms in Apply Contact Tag will be in this format:
		 *  [
		 *      [ 'id'=> 3, 'name'=>'Product' ],
		 *      [ 'id'=> 1, 'name'=>'Hello' ],
		 *      [ 'id'=> 0, 'name'=>'Hi' ],
		 *      [ 'id'=> 0, 'name'=>'Done' ],
		 *  ]
		 *
		 * So we need to differentiate which terms are new and which are already available in DB.
		 * This method differentiate that
		 *
		 * @param $terms
		 * @param int $type
		 * @param bool $use_cache
		 *
		 * @return array
		 */
		public static function parse_terms_request( $terms, $type = 1, $use_cache = false ) {
			/** Separate new terms from existing terms */
			$existing_terms   = array();
			$new_terms_to_add = array_map( function ( $term ) use ( &$existing_terms ) {
				if ( 0 !== absint( $term['id'] ) ) {
					$existing_terms[] = $term;

					return false;
				}

				return $term['value'];
			}, $terms );
			$new_terms_to_add = array_filter( $new_terms_to_add );

			/** Get terms by IDs */
			$existing_term_ids = array_map( 'absint', array_filter( array_column( $existing_terms, 'id' ) ) );
			$db_existing_terms = array();
			if ( ! empty( $existing_term_ids ) ) {
				$db_existing_terms = BWFAN_Model_Terms::get_terms( $type, 0, count( $existing_term_ids ), '', $existing_term_ids, '', $use_cache );
			}

			/** Check if any term by IDs missing from DB, then add that term name to "need to be created terms" */
			$db_term_ids = array_map( 'absint', array_column( $db_existing_terms, 'ID' ) );
			if ( count( $db_existing_terms ) !== count( $existing_terms ) ) {
				foreach ( $existing_terms as $e_term ) {
					if ( false === array_search( absint( $e_term['id'] ), $db_term_ids ) ) {
						$new_terms_to_add[] = $e_term['value'];
					}
				}
			}

			/** Array Unique => the (To be Created) term names, and run SQL to check if any term exists by their name */
			$new_terms_to_add    = array_unique( $new_terms_to_add );
			$db_new_terms_to_add = array();
			if ( ! empty( $new_terms_to_add ) ) {
				$db_new_terms_to_add = BWFAN_Model_Terms::get_terms( $type, 0, 0, $new_terms_to_add, array(), 'exact', $use_cache );
			}

			/** If any exists, add the term ID to "Need to assigned terms" and remove from "to be created" terms names */
			if ( ! empty( $db_new_terms_to_add ) ) {
				foreach ( $db_new_terms_to_add as $e_term ) {
					if ( false === array_search( $e_term['name'], $new_terms_to_add ) ) {
						continue;
					}

					$new_terms_to_add = array_diff( $new_terms_to_add, array( $e_term['name'] ) );
					if ( false === array_search( $e_term['ID'], $db_term_ids ) ) {
						$db_term_ids[] = $e_term['ID'];
					}
				}
			}

			/** Array Unique => the (To be Assigned) term IDs. (In any case if duplication exists) */
			$db_term_ids = array_unique( $db_term_ids );

			return array(
				'ids'   => $db_term_ids,
				'names' => $new_terms_to_add,
			);
		}

		/**
		 * @param array $terms
		 * @param int $type
		 * @param bool $create_if_not_exists
		 * @param bool $separate_created_terms
		 * @param bool $use_cache
		 *
		 * @return array|array[]|BWFCRM_Lists[]|BWFCRM_Tag[]|BWFCRM_Term[]
		 */
		public static function get_or_create_terms( $terms = array(), $type = 1, $create_if_not_exists = false, $separate_created_terms = false, $use_cache = false ) {
			$terms = self::parse_terms_request( $terms, $type, $use_cache );
			if ( ! isset( $terms['ids'] ) || ! isset( $terms['names'] ) ) {
				return true === $separate_created_terms ? array(
					'created'  => array(),
					'existing' => array(),
				) : array();
			}

			$existing_terms = array();
			if ( ! empty( $terms['ids'] ) ) {
				$existing_terms = self::get_terms( $type, $terms['ids'], '', 0, 0, OBJECT, '', $use_cache );
			}

			if ( false === $create_if_not_exists || empty( $terms['names'] ) ) {
				return true === $separate_created_terms ? array(
					'created'  => array(),
					'existing' => $existing_terms,
				) : $existing_terms;
			}

			/** Create rest of the terms */
			$terms_created = self::add_terms_to_db( $terms['names'], $type );
			$new_terms     = array();
			if ( $terms_created ) {
				$new_terms = self::get_terms( $type, array(), $terms['names'], 0, 0, OBJECT, 'exact', $use_cache );
			}

			/** Get added terms */
			if ( false === $separate_created_terms ) {
				return array_merge( $existing_terms, $new_terms );
			}

			return array(
				'created'  => $new_terms,
				'existing' => $existing_terms,
			);
		}

	}
}
