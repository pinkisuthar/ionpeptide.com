<?php
defined( 'ABSPATH' ) || exit;
// phpcs:disable Generic.PHP.DisallowAlternativePHPTags -- <% %> below are Underscore.js template delimiters inside HTML output, not PHP tags (asp_tags was removed in PHP 7.0)

/**
 * Author PhpStorm.
 */
#[\AllowDynamicProperties]
class BWFAN_Dynamic_Option_Base extends BWFAN_Rule_Base {
    public $rule_type = '';

    /**
     *   Construct
     *
     * @since 1.0.0
     */
    public function __construct( $name ) {
        parent::__construct( $name );
        $this->rule_type = $name;

        if ( isset( $_POST['ruletype'] ) && ( strtolower( $name ) === $_POST['ruletype'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- only selects the select2 callback; handler bwfan_select2ajax() verifies the nonce
            add_filter( 'bwfan_select2_ajax_callable', array( $this, 'select2_ajax_callback' ), 10, 2 );
        }
    }

    /**
     * Select2 Ajax Callback
     *
     * @since 1.0.0
     */
    public function select2_ajax_callback( $callback, $posted ) {
        if ( isset( $posted['type'] ) && $this->get_search_type_name() === $posted['type'] ) {
            return array( $this, 'get_search_results' );
        }

        return $callback;
    }

    /**
     * Get Search Type Name
     *
     * @since 1.0.0
     */
    public function get_search_type_name() {
        return '';
    }

    /**
     * Get Search Results
     *
     * @since 1.0.0
     */
    public function get_search_results( $term ) {
        $array = [];
        wp_send_json( array(
            'results' => $array,
        ) );
    }

    /**
     * Conditions View
     *
     * @since 1.0.0
     */
    public function conditions_view() {
        $condition_input_type = $this->get_condition_input_type();
        $values               = $this->get_possible_rule_values();
        $value_args           = array(
            'input'       => $condition_input_type,
            'name'        => 'bwfan_rule[<%= groupId %>][<%= ruleId %>][condition]',
            'choices'     => $values,
            'ajax'        => true,
            'search_type' => $this->get_search_type_name(),
            'rule_type'   => $this->rule_type,
        );
        bwfan_Input_Builder::create_input_field( $value_args );
    }

    /**
     * Get Condition Input Type
     *
     * @since 1.0.0
     */
    public function get_condition_input_type() {
        return 'Chosen_Select';
    }

    /**
     * Get Possible Rule Values
     *
     * @since 1.0.0
     */
    public function get_possible_rule_values() {
        return [];
    }

    /**
     * Get Default Rule Value
     *
     * @since 1.0.0
     */
    public function get_default_rule_value() {
        return '';
    }
}

if ( class_exists( 'WooCommerce' ) ) {
    /**
     * Bwfan Rule Products
     *
     * @since 1.0.0
     */
    #[\AllowDynamicProperties]
    class BWFAN_Rule_Products extends BWFAN_Dynamic_Option_Base {

        /**
         * Get Condition Values Nice Names
         *
         * @since 1.0.0
         */
        public function get_condition_values_nice_names( $values ) {
            $return = [];

            if ( ! is_array( $values ) || count( $values ) === 0 ) {
                return $return;
            }

            foreach ( $values as $coupon_id ) {
                $product = wc_get_product( $coupon_id );
                if ( ! $product instanceof WC_Product ) {
                    continue;
                }
                $return[ $coupon_id ] = rawurldecode( BWFAN_Common::get_formatted_product_name( $product ) );
            }

            return $return;
        }

        /**
         * Get Search Results
         *
         * @since 1.0.0
         */
        public function get_search_results( $term, $v2 = false ) {
            $this->set_product_types_arr();
            $array = BWFAN_Common::product_search( $term, true, true );

            if ( $v2 ) {
                $return = array();
                foreach ( $array as $product ) {
                    $return[ $product['id'] ] = $product['text'];
                }

                return $return;
            }

            wp_send_json( array(
                'results' => $array,
            ) );
        }

        /**
         * Get Search Type Name
         *
         * @since 1.0.0
         */
        public function get_search_type_name() {
            return 'product_search';
        }

        /** v2 Methods: Start */

        public function is_match_v2( $automation_data, $rule_data ) {
            if ( ! isset( $automation_data['global'] ) || ! is_array( $automation_data['global'] ) ) {
                return $this->return_is_match( false, $rule_data );
            }

            $operator = $rule_data['rule'];
            $data     = $rule_data['data'];
            if ( ! is_array( $data ) && empty( $data ) ) {
                return $this->return_is_match( false, $rule_data );
            }

            $saved_products = array_map( function ( $product ) {
                return $product['key'];
            }, $data );
            $found_products = $this->get_products( $automation_data );
            $result         = $this->validate_set( $saved_products, $found_products, $operator );

            return $this->return_is_match( $result, $rule_data );
        }

        /** v2 Methods: END */

		public function is_match( $rule_data ) {
			$found_products = $this->get_products();
			$result         = $this->validate_set( $rule_data['condition'] ?? [], $found_products, $rule_data['operator'] ?? '' );

            return $this->return_is_match( $result, $rule_data );
        }

        /**
         * Validate Set
         *
         * @since 1.0.0
         */
        public function validate_set( $products, $found_products, $operator ) {
            $result = false;

            /** Get product ids with parent */
            switch ( $operator ) {
                case 'any':
                    $result = count( array_intersect( $products, $found_products ) ) > 0;
                    break;
                case 'all':
                    $products = $this->get_product_with_parent( $products, $found_products );
                    $result   = BWFAN_Common::array_equal( $products, $found_products );
                    break;
                case 'none':
                    $result = count( array_intersect( $products, $found_products ) ) === 0;
                    break;
            }

            return $result;
        }

        /**
         * Ui View
         *
         * @since 1.0.0
         */
        public function ui_view() {
            esc_html_e( 'Orders Items', 'wp-marketing-automations' );
            ?>
            <% var ops = JSON.parse('<?php echo wp_json_encode( $this->get_possible_rule_operators() ); ?>'); %>

            <%= ops[operator] %> <% var chosen = []; %>
            <% _.each(condition, function( value, key ){ %>
            <%
            if(_.has(uiData, value)) {
            chosen.push(uiData[value]);
            }
            %>

            <% }); %>
            <%= chosen.join("/ ") %>
            <?php
        }

        /**
         * Get Possible Rule Operators
         *
         * @since 1.0.0
         */
        public function get_possible_rule_operators() {
            return array(
                'any'  => __( 'matches any of', 'wp-marketing-automations' ),
                'all'  => __( 'matches all of ', 'wp-marketing-automations' ),
                'none' => __( 'matches none of ', 'wp-marketing-automations' ),
            );
        }

        /**
         * Set Product Types Arr
         *
         * @since 1.0.0
         */
        public function set_product_types_arr() {
            BWFAN_Common::$offer_product_types = [
                'simple',
                'variable',
                'variation',
                'subscription',
                'variable-subscription',
                'subscription_variation'
            ];
        }

        /**
         * Add parent id in product array if product has parent product
         *
         * @param $products
         * @param $found_products
         *
         * @return array
         */
        public function get_product_with_parent( $products, $found_products ) {
            if ( ! is_array( $products ) || empty( $products ) ) {
                return [];
            }
            $ids = $products;
            foreach ( $products as $id ) {
                $product_parent = get_post_parent( $id );
                if ( $product_parent instanceof WP_Post ) {
                    $ids[] = $product_parent->ID;
                    continue;
                }
                /** If product is parent and child product is in the order */
                $product = wc_get_product( $id );
                if ( ! $product instanceof WC_Product || empty( $product->get_children() ) ) {
                    continue;
                }
                $child_product = array_intersect( $product->get_children(), $found_products );
                $ids           = array_merge( $ids, $child_product );
            }
            $ids = array_unique( $ids );
            sort( $ids );

			return $ids;
		}

		/**
		 * Get products
		 * @param array $automation_data
		 * @return array
		 */
		public function get_products( $automation_data = [] ) {
			return [];
		}
	}

    /**
     * Bwfan Rule Term Taxonomy
     *
     * @since 1.0.0
     */
    #[\AllowDynamicProperties]
    class BWFAN_Rule_Term_Taxonomy extends BWFAN_Dynamic_Option_Base {

        public $taxonomy_name = 'product_cat';

        /**
         * Get Possible Rule Values
         *
         * @since 1.0.0
         */
        public function get_possible_rule_values() {
            $result = array();
            $terms  = get_terms( array(
                'taxonomy'   => $this->taxonomy_name,
                'hide_empty' => false,
            ) );

            if ( $terms && ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $result[ $term->term_id ] = BWFAN_Common::decode_term_name( $term->name );
                }
            }

            return $result;
        }

        /**
         * Get Condition Values Nice Names
         *
         * @since 1.0.0
         */
        public function get_condition_values_nice_names( $values ) {
            $return = [];
            if ( count( $values ) > 0 ) {
                foreach ( $values as $coupon_id ) {
                    $term                 = get_term_by( 'id', $coupon_id, $this->taxonomy_name );
                    $return[ $coupon_id ] = isset( $term->name ) ? BWFAN_Common::decode_term_name( $term->name ) : '';
                }
            }

            return $return;
        }

        /**
         * Get Search Results
         *
         * @since 1.0.0
         */
        public function get_search_results( $term, $v2 = false ) {
            $array = [];
            $terms = BWFAN_Common::search_terms_by_name( $this->taxonomy_name, $term, 10 );

            if ( $v2 ) {
                if ( count( $terms ) > 0 ) {
                    foreach ( $terms as $term ) {
                        $parent_info = '';
                        if ( $term->parent > 0 ) {
                            $parent_term = get_term( $term->parent, $this->taxonomy_name );
                            if ( $parent_term && ! is_wp_error( $parent_term ) ) {
                                $parent_info = ' (' . BWFAN_Common::decode_term_name( $parent_term->name ) . ' #' . $parent_term->term_id . ')';
                            }
                        }

                        $display_text            = BWFAN_Common::decode_term_name( $term->name ) . ' (#' . $term->term_id . ')' . $parent_info;
                        $array[ $term->term_id ] = $display_text;
                    }
                }

                return $array;
            }

            if ( count( $terms ) > 0 ) {
                foreach ( $terms as $term ) {
                    $array[] = array(
                        'id'   => $term->term_id,
                        'text' => BWFAN_Common::decode_term_name( $term->name ),
                    );
                }
            }

            wp_send_json( array(
                'results' => $array,
            ) );
        }

        /**
         * Get Search Type Name
         *
         * @since 1.0.0
         */
        public function get_search_type_name() {
            return $this->taxonomy_name . '_search';
        }

        /**
         * Is Match V2
         *
         * @since 1.0.0
         */
        public function is_match_v2( $automation_data, $rule_data ) {
            if ( ! isset( $automation_data['global'] ) || ! is_array( $automation_data['global'] ) ) {
                return $this->return_is_match( false, $rule_data );
            }

            $type = $rule_data['rule'];
            $data = $rule_data['data'];
            if ( ! is_array( $data ) && empty( $data ) ) {
                return $this->return_is_match( false, $rule_data );
            }

            $saved_terms = array_map( function ( $term ) {
                return $term['key'];
            }, $data );

            $all_terms = $this->get_term_ids( $automation_data );
            $all_terms = array_filter( $all_terms );

            $result = false;
            if ( empty( $all_terms ) ) {
                $result = ( 'none' === $type ) ? true : false;

                return $this->return_is_match( $result, $rule_data );
            }

            // Get translated term ID.
            if ( defined( 'ICL_SITEPRESS_VERSION' ) && class_exists( 'BWFAN_Compatibility_With_WPML' ) && method_exists( 'BWFAN_Compatibility_With_WPML', 'get_translated_term_ids' ) ) {
                $saved_terms = BWFAN_Compatibility_With_WPML::get_translated_term_ids( $saved_terms, $this->taxonomy_name, $automation_data );
            }

            switch ( $type ) {
                case 'all':
                    if ( is_array( $saved_terms ) && is_array( $all_terms ) ) {
                        $result = count( array_intersect( $saved_terms, $all_terms ) ) === count( $saved_terms );
                    }
                    break;
                case 'any':
                    if ( is_array( $saved_terms ) && is_array( $all_terms ) ) {
                        $result = count( array_intersect( $saved_terms, $all_terms ) ) >= 1;
                    }
                    break;
                case 'none':
                    if ( is_array( $saved_terms ) && is_array( $all_terms ) ) {
                        $result = count( array_intersect( $saved_terms, $all_terms ) ) === 0;
                    }
                    break;
            }

            return $this->return_is_match( $result, $rule_data );
        }

        /**
         * Is Match
         *
         * @since 1.0.0
         */
        public function is_match( $rule_data ) {
            $type      = $rule_data['operator'];
            $all_terms = $this->get_term_ids();
            $all_terms = array_filter( $all_terms );
            $result    = false;

            if ( empty( $all_terms ) ) {
                $result = ( 'none' === $type ) ? true : false;

                return $this->return_is_match( $result, $rule_data );
            }

            switch ( $type ) {
                case 'all':
                    if ( is_array( $rule_data['condition'] ) && is_array( $all_terms ) ) {
                        $result = count( array_intersect( $rule_data['condition'], $all_terms ) ) === count( $rule_data['condition'] );
                    }
                    break;
                case 'any':
                    if ( is_array( $rule_data['condition'] ) && is_array( $all_terms ) ) {
                        $result = count( array_intersect( $rule_data['condition'], $all_terms ) ) >= 1;
                    }
                    break;
                case 'none':
                    if ( is_array( $rule_data['condition'] ) && is_array( $all_terms ) ) {
                        $result = count( array_intersect( $rule_data['condition'], $all_terms ) ) === 0;
                    }
                    break;
            }

            return $this->return_is_match( $result, $rule_data );
        }

        /**
         * Get Product Terms
         *
         * @since 1.0.0
         */
        public function get_product_terms( $all_terms, $order, $cart_item ) {
            $product = BWFAN_WooCommerce_Compatibility::get_product_from_item( $order, $cart_item );
            if ( ! $product instanceof WC_Product ) {
                return $all_terms;
            }

            $product_id = $product->get_id();
            $product_id = ( $product->get_parent_id() ) ? $product->get_parent_id() : $product_id;
            $terms      = wp_get_object_terms( $product_id, $this->taxonomy_name, array(
                'fields' => 'ids',
            ) );

            return array_merge( $all_terms, $terms );
        }

        /**
         * Ui View
         *
         * @since 1.0.0
         */
        public function ui_view() {
            esc_html_e( 'Order Items Taxonomy', 'wp-marketing-automations' );
            ?>
            <% var ops = JSON.parse('<?php echo wp_json_encode( $this->get_possible_rule_operators() ); ?>'); %>

            <%= ops[operator] %><% var chosen = []; %>
            <% _.each(condition, function( value, key ){ %>
            <% chosen.push(uiData[value]); %>

            <% }); %>
            <%= chosen.join("/ ") %>
            <?php
        }

        /**
         * Get Possible Rule Operators
         *
         * @since 1.0.0
         */
        public function get_possible_rule_operators() {
            return array(
                'any' => __( 'matches any of', 'wp-marketing-automations' ),
                'all' => __( 'matches all of ', 'wp-marketing-automations' ),
            );
        }
    }
}

/**
 * Bwfan Rule Country
 *
 * @since 1.0.0
 */
#[\AllowDynamicProperties]
class BWFAN_Rule_Country extends BWFAN_Rule_Base {

    /**
     * Get Condition Input Type
     *
     * @since 1.0.0
     */
    public function get_condition_input_type() {
        return 'Chosen_Select';
    }

    /** v2 Methods: START */

    public function get_options( $term = '' ) {
        $countries = $this->get_possible_rule_values();

        if ( empty( $term ) ) {
            return $countries;
        }

        $array = array_filter( $countries, function ( $country ) use ( $term ) {
            return false !== strpos( strtolower( $country ), strtolower( $term ) );
        } );

        return $array;
    }

    /**
     * Get Possible Rule Values
     *
     * @since 1.0.0
     */
    public function get_possible_rule_values() {
        $countries_data = array();
        /** get countries using get countries data from woofunnels core */
        if ( function_exists( 'bwf_get_countries_data' ) ) {
            $countries_data = bwf_get_countries_data();
        }

        return $countries_data;
    }

    /**
     * Get Rule Type
     *
     * @since 1.0.0
     */
    public function get_rule_type() {
        return 'Search';
    }

    /**
     * Is Match V2
     *
     * @since 1.0.0
     */
    public function is_match_v2( $automation_data, $rule_data ) {
        if ( ! isset( $automation_data['global'] ) || ! is_array( $automation_data['global'] ) ) {
            return $this->return_is_match( false, $rule_data );
        }

        $type = $rule_data['rule'];
        $data = $rule_data['data'];
        if ( ! is_array( $data ) && empty( $data ) ) {
            return $this->return_is_match( false, $rule_data );
        }

        $saved_country = array_map( function ( $country ) {
            return $country['key'];
        }, $data );

        $country = $this->get_objects_country( $automation_data );

        if ( ! $country ) {
            return $this->return_is_match( false, $rule_data );
        }
        $result = false;
        switch ( $type ) {
            case 'any':
                if ( is_array( $saved_country ) && is_array( $country ) ) {
                    $result = count( array_intersect( $saved_country, $country ) ) >= 1;
                }
                break;
            case 'none':
                if ( is_array( $saved_country ) && is_array( $country ) ) {
                    $result = count( array_intersect( $saved_country, $country ) ) === 0;
                }
                break;
        }

        return $this->return_is_match( $result, $rule_data );
    }

    /**
     * Get Objects Country
     *
     * @since 1.0.0
     */
    public function get_objects_country() {
        if ( ! bwfan_is_woocommerce_active() ) {
            return false;
        }

        $order   = BWFAN_Core()->rules->getRulesData( 'wc_order' );
        $country = BWFAN_WooCommerce_Compatibility::get_billing_country_from_order( $order );

        return empty( $country ) ? false : array( $country );
    }

    /** v2 Methods: END */

    public function is_match( $rule_data ) {
        $type    = $rule_data['operator'];
        $country = $this->get_objects_country();

        if ( ! $country ) {
            return $country;
        }
        $result = false;
        switch ( $type ) {
            case 'any':
                if ( is_array( $rule_data['condition'] ) && is_array( $country ) ) {
                    $result = count( array_intersect( $rule_data['condition'], $country ) ) >= 1;
                }
                break;
            case 'none':
                if ( is_array( $rule_data['condition'] ) && is_array( $country ) ) {
                    $result = count( array_intersect( $rule_data['condition'], $country ) ) === 0;
                }
                break;
        }

        return $this->return_is_match( $result, $rule_data );
    }

    /**
     * Ui View
     *
     * @since 1.0.0
     */
    public function ui_view() {
        esc_html_e( 'Order Shipping Country', 'wp-marketing-automations' );
        ?>
        <% var ops = JSON.parse('<?php echo wp_json_encode( $this->get_possible_rule_operators() ); ?>'); %>

        <%= ops[operator] %>
        <% var chosen = []; %>
        <% _.each(condition, function( value, key ){ %>
        <% chosen.push(uiData[value]); %>

        <% }); %>
        <%= chosen.join("/ ") %>
        <?php
    }

    /**
     * Get Possible Rule Operators
     *
     * @since 1.0.0
     */
    public function get_possible_rule_operators() {
        return array(
            'any'  => __( 'matches any of', 'wp-marketing-automations' ),
            'none' => __( 'matches none of ', 'wp-marketing-automations' ),
        );
    }
}

/**
 * Bwfan Rule Custom Field
 *
 * @since 1.0.0
 */
#[\AllowDynamicProperties]
class BWFAN_Rule_Custom_Field extends BWFAN_Rule_Base {

    /**
     * Conditions View
     *
     * @since 1.0.0
     */
    public function conditions_view() {
        $condition_input_type = $this->get_condition_input_type();
        $values               = $this->get_possible_rule_values();
        $value_args           = array(
            'input'       => $condition_input_type,
            'name'        => 'bwfan_rule[<%= groupId %>][<%= ruleId %>][condition][key]',
            'choices'     => $values,
            'class'       => 'bwfan_field_one_half',
            'placeholder' => __( 'Key', 'wp-marketing-automations' ),
        );

        bwfan_Input_Builder::create_input_field( $value_args );
        $condition_input_type = $this->get_condition_input_type();
        $values               = $this->get_possible_rule_values();
        $value_args           = array(
            'input'       => $condition_input_type,
            'name'        => 'bwfan_rule[<%= groupId %>][<%= ruleId %>][condition][value]',
            'choices'     => $values,
            'class'       => 'bwfan_field_one_half',
            'placeholder' => __( 'Value', 'wp-marketing-automations' ),
        );

        bwfan_Input_Builder::create_input_field( $value_args );
    }

    /**
     * Get Condition Input Type
     *
     * @since 1.0.0
     */
    public function get_condition_input_type() {
        return 'Text';
    }

    /**
     * Get Possible Rule Values
     *
     * @since 1.0.0
     */
    public function get_possible_rule_values() {
        return null;
    }

    /**
     * Is Match V2
     *
     * @since 1.0.0
     */
    public function is_match_v2( $automation_data, $rule_data ) {
        if ( ! isset( $automation_data['global'] ) || ! is_array( $automation_data['global'] ) ) {
            return $this->return_is_match( false, $rule_data );
        }

        $type = $rule_data['rule'];
        $data = $rule_data['data'];
        if ( ! is_array( $data ) || empty( $data ) ) {
            return $this->return_is_match( false, $rule_data );
        }
        $key         = $data[0] ?? '';
        $saved_value = strtolower( trim( $data[1] ?? '' ) );
        $value       = strtolower( trim( $this->get_possible_value( $key, $automation_data ) ) );

        if ( empty( $value ) && ! in_array( $type, [ 'is_blank', 'is_not_blank' ] ) ) {
            return $this->return_is_match( false, $rule_data );
        }
        $result = false;
        switch ( $type ) {
            case 'is':
                $result = ( $value === $saved_value );
                break;
            case 'isnot':
            case 'is_not':
                $result = ( $value !== $saved_value );
                break;
            case '>':
                $result = ( $value >= $saved_value );
                break;
            case '<':
                $result = ( $value <= $saved_value );
                break;
            case 'contains':
                $result = strpos( $value, $saved_value ) !== false;
                break;
            case 'not_contains':
                $result = strpos( $value, $saved_value ) === false;
                break;
            case 'starts_with':
                $result = strpos( $value, $saved_value ) === 0;
                break;
            case 'ends_with':
                $result = substr( $value, - strlen( $saved_value ) ) === $saved_value;
                break;
            case 'is_blank':
                $result = empty( $value );
                break;
            case 'is_not_blank':
                $result = ! empty( $value );
                break;
        }

        return $this->return_is_match( $result, $rule_data );
    }

    /**
     * Get Possible Value
     *
     * @since 1.0.0
     */
    public function get_possible_value( $key ) {
        return __return_empty_string();
    }

    /**
     * Is Match
     *
     * @since 1.0.0
     */
    public function is_match( $rule_data ) {
        $type  = $rule_data['operator'];
        $value = $this->get_possible_value( $rule_data['condition']['key'] );

        $result = false;
        switch ( $type ) {
            case 'is':
                $result = ( strtolower( $value ) === strtolower( $rule_data['condition']['value'] ) );
                break;
            case 'isnot':
            case 'is_not':
                $result = ( strtolower( $value ) !== strtolower( $rule_data['condition']['value'] ) );
                break;
            case '>':
                $result = ( strtolower( $value ) >= strtolower( $rule_data['condition']['value'] ) );
                break;
            case '<':
                $result = ( strtolower( $value ) <= strtolower( $rule_data['condition']['value'] ) );
                break;
        }

        return $this->return_is_match( $result, $rule_data );
    }

    /**
     * Ui View
     *
     * @since 1.0.0
     */
    public function ui_view() {
        ?>
        Order Custom Field
        '<%= condition['key'] %>' <% var ops = JSON.parse('<?php echo wp_json_encode( $this->get_possible_rule_operators() ); ?>'); %>
        <%= ops[operator] %> '<%= condition['value'] %>'
        <?php

        if ( ! defined( "ABSPATH" ) ) {
            exit;
        }

    }

    /**
     * Get Possible Rule Operators
     *
     * @since 1.0.0
     */
    public function get_possible_rule_operators() {
        return array(
            'is'    => __( 'is', 'wp-marketing-automations' ),
            'isnot' => __( 'is not', 'wp-marketing-automations' ),
            '>'     => __( 'greater than', 'wp-marketing-automations' ),
            '<'     => __( 'less than', 'wp-marketing-automations' ),
        );
    }
}
