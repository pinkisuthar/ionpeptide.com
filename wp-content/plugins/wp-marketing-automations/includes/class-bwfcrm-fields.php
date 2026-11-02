<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BWFCRM_Fields' ) && BWFAN_Common::is_pro_3_0() ) {
	#[\AllowDynamicProperties]
	class BWFCRM_Fields {
		public static $TYPE_TEXT = 1;
		public static $TYPE_NUMBER = 2;
		public static $TYPE_TEXTAREA = 3;
		public static $TYPE_SELECT = 4;
		public static $TYPE_RADIO = 5;
		public static $TYPE_CHECKBOX = 6;
		public static $TYPE_DATE = 7;
		public static $TYPE_DATETIME = 8;
		public static $TYPE_TIME = 9;

		public static $contact_columns = array(
			'f_name'        => 'First Name',
			'l_name'        => 'Last Name',
			'contact_no'    => 'Phone',
			'timezone'      => 'Timezone',
			'creation_date' => 'Creation Date',
			'status'        => 'Status',
		);

		public static $contact_fields = array(
			'gender'  => 'Gender',
			'company' => 'Company',
			'dob'     => 'Date of Birth',
		);

		public static $contact_address_columns = array(
			'country' => 'Country',
			'state'   => 'State',
		);

		public static $contact_address_fields = array(
			'address-1' => 'Address 1',
			'address-2' => 'Address 2',
			'city'      => 'City',
			'postcode'  => 'Postal Code',
		);

		public static $extra_columns = array(
			'address' => 'Address',
		);

		public static $reserved_keys = [
			'id',
			'wpid',
			'email',
			'f_name',
			'l_name',
			'contact_no',
			'country',
			'state',
			'timezone',
			'source',
			'points',
			'status',
			'timezone',
			'type',
			'tags',
			'lists',
			'last_modified',
			'creation_date',
		];

		public static $_instance = null;

		public function __construct() {

		}

		public static function get_instance() {
			if ( null === self::$_instance ) {
				self::$_instance = new self();
			}

			return self::$_instance;
		}

		public static function is_field_exists( $field_id ) {
			if ( empty( $field_id ) ) {
				return false;
			}

			$return = true;
			$field  = BWFAN_Model_Fields::get_field_by_id( $field_id );
			if ( empty( $field ) && is_string( $field_id ) ) {
				$defult_fields = self::get_default_fields( true );
				$return        = ! isset( $defult_fields[ $field_id ] ) ? false : true;
			}

			return $return;
		}

		/**
		 * function to return default custom fields
		 **/
		public static function get_default_fields( $show_address_fields = false, $editable_by_user = null ) {
			/** Unset Marketing Status, as it has mode of 2 (Non-Editable) and vmode of 2 (Non-Editable) */
			$contact_columns = self::$contact_columns;
			unset( $contact_columns['status'] );
			if ( true === $editable_by_user ) {
				unset( $contact_columns['creation_date'] );
				// unset( $contact_columns['timezone'] );
			}

			$default_fields = array_replace( $contact_columns, self::$contact_fields );
			if ( true === $show_address_fields ) {
				$default_fields = array_replace( $default_fields, self::get_address_fields() );
			} else {
				$default_fields = array_replace( $default_fields, self::$extra_columns );
			}

			return apply_filters( 'bwfcrm_get_default_fields', $default_fields );
		}

		public static function get_address_fields() {
			return array_replace( self::$contact_address_columns, self::$contact_address_fields );
		}

		public static function get_address_fields_from_db() {
			$db_fields = BWFAN_Model_Fields::get_fields_by_multiple_slugs( array_keys( self::$contact_address_fields ) );
			if ( ! is_array( $db_fields ) || empty( $db_fields ) ) {
				return self::$contact_address_columns;
			}

			$address_fields = array();
			foreach ( $db_fields as $field ) {
				$address_fields[ $field['ID'] ] = array(
					'ID'         => $field['ID'],
					'group_id'   => $field['gid'],
					'name'       => $field['name'],
					'type'       => $field['type'],
					'meta'       => json_decode( $field['meta'], true ),
					'created_at' => $field['created_at'],
					'slug'       => $field['slug'],
				);
			}

			return $address_fields;
		}

		public static function get_contact_fields_from_db( $return_by = 'row' ) {
			$address_keys = array_keys( self::$contact_address_fields );
			$fields_keys  = array_keys( self::$contact_fields );
			$db_fields    = BWFAN_Model_Fields::get_fields_by_multiple_slugs( array_merge( $address_keys, $fields_keys ) );

			$return = array();
			foreach ( $db_fields as $field ) {
				$return[ 'slug' === $return_by ? $field['slug'] : $field['ID'] ] = array(
					'ID'         => absint( $field['ID'] ),
					'group_id'   => $field['gid'],
					'name'       => $field['name'],
					'type'       => $field['type'],
					'meta'       => json_decode( $field['meta'], true ),
					'created_at' => $field['created_at'],
					'slug'       => $field['slug'],
				);
			}

			return $return;
		}

		/**
		 * Get Custom Fields from the BWFCRM_Fields table
		 *
		 * @param null $mode
		 * @param null $vmode
		 * @param null $searchable
		 * @param bool $exclude_sys_fields
		 *
		 * @return array|null
		 */
		public static function get_custom_fields( $mode = null, $vmode = null, $searchable = null, $exclude_sys_fields = false, $viewable = null, $type = null, $limit = 0, $offset = 0 ) {
			$fields = BWFAN_Model_Fields::get_custom_fields( $mode, $vmode, $searchable, $viewable, $type, $limit, $offset );

			$contact_fields = array_keys( self::$contact_fields );
			$address_fields = array_keys( self::$contact_address_fields );
			$system_fields  = array_merge( $contact_fields, $address_fields );

			$field_data = array();
			foreach ( $fields as $field ) {
				$is_system_field = is_array( $field ) && isset( $field['slug'] ) && in_array( $field['slug'], $system_fields );
				if ( $exclude_sys_fields && $is_system_field ) {
					continue;
				}
				if ( bwfan_is_autonami_pro_active() && version_compare( BWFAN_PRO_VERSION, '3.4.0', '<=' ) && in_array( intval( $field['type'] ), [ self::$TYPE_DATETIME, self::$TYPE_TIME ], true ) ) {
					continue;
				}

				$field_data[ $field['ID'] ] = array(
					'group_id'   => $field['gid'],
					'ID'         => $field['ID'],
					'name'       => $field['name'],
					'type'       => $field['type'],
					'search'     => $field['search'],
					'meta'       => json_decode( $field['meta'], true ),
					'created_at' => $field['created_at'],
					'slug'       => $field['slug'],
				);
			}

			return $field_data;
		}

		/**
		 * Get Default and Custom Fields
		 *
		 * @param null $mode
		 * @param null $vmode
		 * @param null $searchable
		 *
		 * @return array|null
		 */
		public static function get_fields( $mode = null, $vmode = null, $searchable = null ) {
			$default_fields = array_replace( self::$contact_columns, self::$contact_address_columns );
			if ( 1 === $vmode ) {
				unset( $default_fields['creation_date'] );
			}

			$custom_fields = self::get_custom_fields( $mode, $vmode, $searchable );

			return array_replace( $default_fields, $custom_fields );
		}

		/**
		 * get all group fields (DB) from the BWFCRM_Fields
		 *
		 * TODO: Rename this function according to usage (Suggested: get_raw_db_group_fields)
		 **/
		public static function get_group_fields( $group_id ) {

			$fields = BWFAN_Model_Fields::get_group_fields( $group_id );

			$field_data = array();
			foreach ( $fields as $field ) {
				$field_data['id']       = $field['gid'];
				$field_data['name']     = $field['group_name'];
				$field_data['fields'][] = array(
					'id'   => $field['field_id'],
					'name' => $field['name'],
					'type' => $field['type'],
					'meta' => json_decode( $field['meta'] ),
				);
			}

			return $field_data;
		}

		/**
		 * get all groups with fields (Sorted Format) from the BWFCRM_Fields
		 *
		 * TODO: Optimise this code & all related functions (sort_format_data, convert_to_pure_array)
		 *
		 * @param false $show_address_fields
		 * @param false $add_email_field
		 * @param null $editable_by_user
		 * @param false $mapping
		 *
		 * @return array
		 */
		public static function get_groups_with_fields( $show_address_fields = false, $add_email_field = false, $editable_by_user = null, $mapping = false ) {
			$vmode          = true === $editable_by_user ? 1 : ( false === $editable_by_user ? 2 : null );
			$fields         = BWFAN_Model_Fields::get_group_fields( 0, null, $vmode );
			$default_fields = self::get_default_fields( $show_address_fields, $editable_by_user );

			$field_data = array();

			/** General Group and Fields */
			$field_data[0]['name'] = __( 'General', 'wp-marketing-automations' );
			$field_data[0]['id']   = 0;

			/** Email Field */
			if ( true === $add_email_field ) {
				$field_data[0]['fields']['email'] = __( 'Email', 'wp-marketing-automations' );
			}

			/** General Group's fields */
			foreach ( $default_fields as $field_key => $field_name ) {
				$field_from_db = BWFAN_Model_Fields::get_field_by_slug( $field_key );
				if ( 'creation_date' === $field_key ) {
					continue;
				}
				if ( empty( $field_from_db ) ) {
					// $val = ( 'creation_date' === $field_key ) ? $field_name . ' (Y-m-d)' : $field_name;
					$val = ( 'country' === $field_key ) ? $field_name . ' (2 digit ISO code)' : $field_name;

					if ( true !== $mapping ) {
						$val = $field_name;
					}
					$field_data[0]['fields'][ $field_key ] = $val;
				} else {
					$field_data[0]['fields'][ $field_from_db['ID'] ] = array(
						'name'       => $field_from_db['name'],
						'slug'       => $field_from_db['slug'],
						'type'       => $field_from_db['type'],
						'meta'       => json_decode( $field_from_db['meta'], true ),
						'mode'       => $field_from_db['mode'],
						'created_at' => isset( $field_from_db['created_at'] ) ? $field_from_db['created_at'] : '',
					);
				}
			}

			/** Rest of the Groups and Fields */
			foreach ( $fields as $field_key => $field ) {
				/** Exclude Address Fields, as they are already present in self::get_default_fields call */
				if ( in_array( $field['slug'], array_keys( self::get_address_fields() ) ) ) {
					continue;
				}

				$group_id   = 0;
				$group_name = __( 'General', 'wp-marketing-automations' );
				if ( ! empty( $field['gid'] ) ) {
					$group_id   = $field['gid'];
					$group_name = $field['group_name'];
				}
				$field_data[ $group_id ]['id']   = $group_id;
				$field_data[ $group_id ]['name'] = $group_name;
				/** Remove default contact field from general group if default contact field is in other groups */
				if ( 0 !== absint( $field['gid'] ) && in_array( $field['slug'], array_keys( self::$contact_fields ), false ) ) {
					unset( $field_data[0]['fields'][ $field['field_id'] ] );
				}
				$field_data[ $group_id ]['fields'][ $field['field_id'] ] = array(
					'name'       => $field['name'],
					'slug'       => $field['slug'],
					'type'       => $field['type'],
					'search'     => $field['search'],
					'meta'       => json_decode( $field['meta'], true ),
					'mode'       => $field['mode'],
					'created_at' => isset( $field['created_at'] ) ? $field['created_at'] : '',
				);

				if ( true === $mapping && self::$TYPE_DATE === intval( $field['type'] ) ) {
					$field_data[ $group_id ]['fields'][ $field['field_id'] ]['name'] = $field['name'] . ' (Y-m-d)';
				}
				if ( true === $mapping && self::$TYPE_NUMBER === intval( $field['type'] ) ) {
					$field_data[ $group_id ]['fields'][ $field['field_id'] ]['name'] = $field['name'] . ' (number)';
				}
				if ( true === $mapping && self::$TYPE_DATETIME === intval( $field['type'] ) ) {
					$field_data[ $group_id ]['fields'][ $field['field_id'] ]['name'] = $field['name'] . ' (Y-m-d H:i:s)';
				}
				if ( true === $mapping && self::$TYPE_TIME === intval( $field['type'] ) ) {
					$field_data[ $group_id ]['fields'][ $field['field_id'] ]['name'] = $field['name'] . ' (H:i)';
				}
			}

			/** Rest of the Groups in which not assign fields */
			$with_fields_groups    = array_keys( $field_data );
			$without_fields_groups = BWFCRM_Group::get_groups( $with_fields_groups );

			foreach ( $without_fields_groups as $group ) {
				$field_data[ $group['id'] ]['id']     = $group['id'];
				$field_data[ $group['id'] ]['name']   = $group['name'];
				$field_data[ $group['id'] ]['fields'] = array();
			}

			$sort_format = get_option( 'bwf_crm_field_sort', array() );
			if ( ! empty( $sort_format ) ) {
				$field_data = self::sort_format_data( $field_data, $sort_format, $show_address_fields, $mapping );
			}

			/** 'Creation Date' Field */
			if ( true === $mapping ) {
				$field_data[0]['fields']['creation_date'] = __( 'Creation Date (Y-m-d)', 'wp-marketing-automations' );
			}

			return self::convert_to_pure_array( $field_data );
		}

		public static function sort_format_data( $field_data, $sort_format, $show_address_fields, $mapping ) {
			$field_sort = array();
			foreach ( $sort_format as $group ) {
				$group_id = $group['group_id'];
				if ( isset( $field_data[ $group_id ]['name'] ) && 'email' === $field_data[ $group_id ]['name'] ) {
					continue;
				}
				if ( ! isset( $field_data[ $group_id ] ) ) {
					$group_id = 0;
				}
				$field_sort[ $group_id ]['id']   = $group_id;
				$field_sort[ $group_id ]['name'] = $field_data[ $group_id ]['name'];
				if ( ! isset( $field_sort[ $group_id ]['fields'] ) ) {
					$field_sort[ $group_id ]['fields'] = array();
				}
				if ( empty( $group['fields'] ) ) {
					continue;
				}

				/** Hard adding Email and Creation date at the top */
				if ( 0 === $group_id ) {
					if ( isset( $field_data[0] ) && isset( $field_data[0]['fields'] ) && isset( $field_data[0]['fields']['email'] ) ) {
						$field_sort[ $group_id ]['fields']['email'] = $field_data[0]['fields']['email'];
					}
					if ( isset( $field_data[0] ) && isset( $field_data[0]['fields'] ) && isset( $field_data[0]['fields']['creation_date'] ) ) {
						$field_sort[ $group_id ]['fields']['creation_date'] = $field_data[0]['fields']['creation_date'];
					}
				}

				foreach ( $group['fields'] as $field_id ) {
					if ( empty( $field_id ) ) {
						continue;
					}
					/** If empty or not exists */
					if ( ! in_array( $field_id, array_keys( self::$extra_columns ) ) ) {
						if ( ! isset( $field_data[ $group_id ]['fields'][ $field_id ] ) || empty( $field_data[ $group_id ]['fields'][ $field_id ] ) ) {
							unset( $field_data[ $group_id ]['fields'][ $field_id ] );
							continue;
						}
					}

					/** For Address Field */
					if ( true === $show_address_fields && 0 === absint( $group_id ) && 'address' === $field_id ) {
						foreach ( $field_data[ $group_id ]['fields'] as $index => $candidate_field ) {
							if ( is_array( $candidate_field ) && in_array( $candidate_field['slug'], array_keys( self::get_address_fields() ) ) ) {
								$field_sort[ $group_id ]['fields'][ $index ]       = $candidate_field;
								$field_sort[ $group_id ]['fields'][ $index ]['id'] = $index;
								unset( $field_data[ $group_id ]['fields'][ $index ] );
							} elseif ( in_array( $index, array_keys( self::get_address_fields() ) ) ) {
								if ( true === $mapping && 'country' === $index ) {
									$field_sort[ $group_id ]['fields'][ $index ] = $candidate_field;
								} else {
									$field_sort[ $group_id ]['fields'][ $index ] = self::get_address_fields()[ $index ];
								}
								unset( $field_data[ $group_id ]['fields'][ $index ] );
							}
						}
						continue;
					}

					/** For rest of the fields */
					$field_sort[ $group_id ]['fields'][ $field_id ] = $field_data[ $group_id ]['fields'][ $field_id ];
					if ( is_array( $field_sort[ $group_id ]['fields'][ $field_id ] ) ) {
						$field_sort[ $group_id ]['fields'][ $field_id ]['id'] = absint( $field_id );
					}
					unset( $field_data[ $group_id ]['fields'][ $field_id ] );
				}
			}

			/** Add rest of the unsorted groups and fields */
			foreach ( $field_data as $group_id => $group ) {
				$fields = $group['fields'];

				if ( ! isset( $field_sort[ $group_id ] ) ) {
					$field_sort[ $group_id ] = $field_data[ $group_id ];
				}

				foreach ( $fields as $field_id => $field ) {
					/** If empty or not exists */
					if ( ! isset( $field_data[ $group_id ]['fields'][ $field_id ] ) || empty( $field_data[ $group_id ]['fields'][ $field_id ] ) ) {
						continue;
					}

					/** For Address Field */
					if ( true === $show_address_fields && 0 === absint( $group_id ) && 'address' === $field_id ) {
						foreach ( $field_data[ $group_id ]['fields'] as $index => $candidate_field ) {
							if ( is_array( $candidate_field ) && in_array( $candidate_field['slug'], array_keys( self::get_address_fields() ) ) ) {
								$field_sort[ $group_id ]['fields'][ $index ]       = $candidate_field;
								$field_sort[ $group_id ]['fields'][ $index ]['id'] = $index;
								unset( $field_data[ $group_id ]['fields'][ $index ] );
							} elseif ( in_array( $index, array_keys( self::get_address_fields() ) ) ) {
								$field_sort[ $group_id ]['fields'][ $index ] = self::get_address_fields()[ $index ];
								unset( $field_data[ $group_id ]['fields'][ $index ] );
							}
						}
						continue;
					}

					/** For rest of the fields */
					$field_sort[ $group_id ]['fields'][ $field_id ] = $field_data[ $group_id ]['fields'][ $field_id ];
					if ( is_array( $field_sort[ $group_id ]['fields'][ $field_id ] ) ) {
						$field_sort[ $group_id ]['fields'][ $field_id ]['id'] = absint( $field_id );
					}
				}
			}

			return $field_sort;
		}

		public static function convert_to_pure_array( $fields ) {
			$fields_array = array();
			foreach ( $fields as $group ) {
				$new_group           = $group;
				$new_group['fields'] = array();
				if ( ! isset( $group['fields'] ) || empty( $group['fields'] ) ) {
					$fields_array[] = $new_group;
					continue;
				}

				foreach ( $group['fields'] as $field_id => $field ) {
					if ( is_array( $field ) ) {
						$new_group['fields'][] = array_replace( array(
							'id'        => $field_id,
							'merge_tag' => BWFAN_Core()->merge_tags->get_field_tag( $field['slug'] ),
						), $field );
					}

					if ( is_string( $field ) ) {
						$new_group['fields'][] = array(
							'id'        => $field_id,
							'name'      => $field,
							'merge_tag' => BWFAN_Core()->merge_tags->get_field_tag( $field_id ),
						);
					}
				}
				$fields_array[] = $new_group;
			}

			return $fields_array;
		}

		/**
		 * add new fields
		 **/
		public static function add_field( $field_name, $type, $options, $placeholder, $mode, $vmode, $search, $group_id = 0, $view = 1 ) {
			$field = self::get_fieldby_name( $field_name );

			if ( ! empty( $field ) ) {
				return BWFAN_Common::crm_error( __( 'Field already exists', 'wp-marketing-automations' ) );
			}
			$meta = array();
			if ( ! empty( $options ) ) {
				$meta['options'] = $options;
			}
			if ( ! empty( $placeholder ) ) {
				$meta['placeholder'] = $placeholder;
			}
			$field_slug = sanitize_title( $field_name );
			$data       = array(
				'name'       => $field_name,
				'slug'       => $field_slug,
				'type'       => $type,
				'gid'        => $group_id,
				'meta'       => json_encode( $meta ),
				'mode'       => $mode,
				'vmode'      => $vmode,
				'view'       => $view,
				'search'     => $search,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			);

			BWFAN_Model_Fields::insert( $data );

			$field_id = BWFAN_Model_Fields::insert_id();

			$column_added = self::add_contact_fields_column( $field_id, $search );
			/** If column not added */
			if ( true !== $column_added ) {
				BWFAN_Model_Fields::delete( $field_id );

				return [
					'err_msg' => $column_added
				];
			}

			return BWFAN_Model_Fields::get_field_by_id( $field_id );
		}

		/**
		 * update field
		 **/
		public static function update_field( $field_id, $group_id, $field_name, $type, $options, $placeholder, $slug, $mode, $vmode, $search ) {
			global $wpdb;
			$field_slug = sanitize_title( $slug );

			/** Checking slug is already assigned to other fields */
			$query          = $wpdb->prepare( "SELECT ID FROM {table_name} WHERE slug= %s  AND ID != %d", $field_slug, $field_id );
			$already_exists = BWFAN_Model_Fields::get_results( $query );

			if ( empty( $already_exists ) ) {
				$data = array();

				if ( ! empty( $field_name ) ) {
					$data['name'] = $field_name;
				}

				/** Checking mode is 1 then update the slug */
				if ( 1 === absint( $mode ) && ! empty( $field_slug ) ) {
					$data['slug'] = $field_slug;
				}

				if ( ! empty( $type ) ) {
					$data['type'] = $type;
				}

				if ( $group_id !== false ) {
					$data['gid'] = $group_id;
				}
				$meta = array();
				if ( ! empty( $options ) && is_array( $options ) ) {
					$meta['options'] = $options;
				}

				if ( ! empty( $placeholder ) ) {
					$meta['placeholder'] = $placeholder;
				}

				if ( ! empty( $meta ) ) {
					$data['meta'] = wp_json_encode( $meta, JSON_UNESCAPED_UNICODE );
				}

				if ( ! empty( $search ) ) {
					$data['search'] = $search;
				}

				$where = array(
					'ID' => $field_id,
				);

				if ( empty( $data ) ) {
					return array(
						'status' => 200,
					);
				}

				$updated = BWFAN_Model_Fields::update( $data, $where );
				if ( ! empty( $updated ) ) {
					BWF_Model_Contact_Fields::update_contact_field_column_indexing( $field_id, $search );
				}

				return array(
					'status' => 200,
				);

			} else {
				return array(
					'status'  => 404,
					'message' => __( 'Field slug already exists, kindly change', 'wp-marketing-automations' ),
				);
			}

		}

		public static function field_move_to_group( $group_id, $move_group_id ) {

			$group_fields = self::get_group_fields( $group_id );

			$group_fields = isset( $group_fields['fields'] ) ? $group_fields['fields'] : '';

			self::update_sort_format( $group_id, $move_group_id, $group_fields );

			if ( empty( $group_fields ) ) {
				return true;
			}

			foreach ( $group_fields as $field ) {
				$data = array(
					'gid' => $move_group_id,
				);

				$where = array(
					'ID' => $field['id'],
				);

				$moved = BWFAN_Model_Fields::update( $data, $where );
			}

			return $moved;
		}


		public static function update_sort_format( $group_id, $move_group_id, $group_fields ) {
			if ( empty( $group_fields ) ) {
				return;
			}
			$field_ids   = array_keys( $group_fields );
			$sort_format = get_option( 'bwf_crm_field_sort', array() );
			if ( empty( $sort_format ) ) {
				return;
			}
			$new_format   = array();
			$sort_updated = false;
			foreach ( $sort_format as $index => $group ) {
				$group_id             = $group['group_id'];
				$new_format[ $index ] = $group;
				if ( $group_id !== $move_group_id ) {
					continue;
				}
				$sort_updated = true;
				foreach ( $field_ids as $field_id ) {
					$new_format[ $index ]['fields'][] = $field_id;
				}
			}

			if ( ! $sort_updated ) {
				$new_format = $sort_format;
				$new_data   = array(
					'group_id' => $move_group_id,
				);
				foreach ( $field_ids as $field_id ) {
					$new_data['fields'][] = $field_id;
				}
				$new_format[] = $new_data;
			}
			update_option( 'bwf_crm_field_sort', $new_format );
		}

		/**
		 *  function to get the field details by field name
		 */

		public static function get_fieldby_name( $field_name ) {
			$name       = sanitize_title( $field_name );
			$query      = "select * from {table_name} where slug='" . $name . "'";
			$field_data = BWFAN_Model_Fields::get_results( $query );

			return $field_data;
		}

		/**
		 * Add new column in contact_fields table
		 *
		 * @param integer $field_id
		 *
		 * @return boolean
		 */
		public static function add_contact_fields_column( $field_id, $searchable ) {

			/** Check field column exist in contact_fields table */
			$column_exist = BWF_Model_Contact_Fields::column_already_exists( $field_id );
			if ( ! empty( $column_exist ) ) {
				return true;
			}

			return BWF_Model_Contact_Fields::add_column_field( $field_id, $searchable );
		}

		/**
		 * delete field and contact field from db
		 *
		 * @param integer $field_id
		 *
		 * @return boolean
		 */
		public static function delete_field( $field_id ) {

			BWFAN_Model_Fields::delete( $field_id );
			BWF_Model_Contact_Fields::drop_contact_field_column( $field_id );

			return true;
		}

		public static function get_field_id_by_slug( $slug ) {
			$db_row = BWFAN_Model_Fields::get_field_by_slug( $slug );
			if ( ! is_array( $db_row ) || ! isset( $db_row['ID'] ) ) {
				return false;
			}

			return absint( $db_row['ID'] );
		}

		public static function delete_unwanted_field_columns() {
			global $wpdb;
			$cols = $wpdb->get_col( "DESC {$wpdb->prefix}bwf_contact_fields", 0 ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$cols = array_diff( $cols, array( 'ID', 'cid' ) );
			if ( empty( $cols ) ) {
				return;
			}

			$cols        = array_map( function ( $col ) {
				return absint( explode( 'f', $col )[1] );
			}, $cols );
			$cols        = array_values( $cols );
			$cols_string = implode( ',', $cols );

			$fields = $wpdb->get_results( "SELECT ID FROM {$wpdb->prefix}bwfan_fields WHERE ID in ($cols_string)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids absint()ed before implode; table from $wpdb->prefix
			if ( empty( $fields ) ) {
				return;
			}

			$fields        = array_map( 'absint', array_column( $fields, 'ID' ) );
			$unwanted_cols = array_values( array_diff( $cols, $fields ) );
			if ( empty( $unwanted_cols ) ) {
				return;
			}

			foreach ( $unwanted_cols as $field_id ) {
				BWF_Model_Contact_Fields::drop_contact_field_column( $field_id );
			}
		}

		public static function get_sorted_fields( $fields ) {
			$sort_format = get_option( 'bwf_crm_field_sort', array() );
			if ( empty( $sort_format ) ) {
				return $fields;
			}
			$data = [];
			foreach ( $sort_format as $sort_data ) {
				if ( ! isset( $sort_data['fields'] ) ) {
					continue;
				}

				foreach ( $sort_data['fields'] as $field_id ) {
					if ( ! isset( $fields[ $field_id ] ) ) {
						continue;
					}

					$data[ $field_id ] = $fields[ $field_id ];
				}
			}

			/** Get newly added fields */
			$field_keys = array_keys( $fields );
			$data_keys  = array_keys( $data );
			$new_fields = array_diff( $field_keys, $data_keys );
			if ( 0 === count( $new_fields ) ) {
				return $data;
			}

			foreach ( $new_fields as $field_id ) {
				$data[ $field_id ] = $fields[ $field_id ];
			}

			return $data;
		}
	}
}