<?php
defined( 'ABSPATH' ) || exit;
// phpcs:disable Generic.PHP.DisallowAlternativePHPTags -- <% %> below are Underscore.js template delimiters inside HTML output, not PHP tags (asp_tags was removed in PHP 7.0)

if ( ! class_exists( 'BWFAN_Rule_Comment_Count' ) ) {
	#[\AllowDynamicProperties]
	class BWFAN_Rule_Comment_Count extends BWFAN_Rule_Base {
		/**
		 * BWFAN_Rule_Comment_Count constructor.
		 */
		public function __construct() {
			$this->v2 = true;
			parent::__construct( 'comment_count' );
		}

		/**
		 * @return string
		 */
		public function get_rule_type() {
			return 'Number';
		}

		/**
		 * @param $automation_data
		 * @param $rule_data
		 *
		 * @return bool
		 */
		public function is_match_v2( $automation_data, $rule_data ) {
			if ( ! isset( $automation_data['global'] ) || ! is_array( $automation_data['global'] ) ) {
				return $this->return_is_match( false, $rule_data );
			}

			$comment_id = isset( $automation_data['global']['comment_id'] ) ? $automation_data['global']['comment_id'] : 0;
			$rating     = $comment_id > 0 ? get_comment_meta( $comment_id, 'rating', true ) : 0;
			$count      = absint( $rating );

			$operator = $rule_data['rule'];
			$value    = absint( $rule_data['data'] );

			switch ( $operator ) {
				case '==':
					$result = $count === $value;
					break;
				case '!=':
					$result = $count !== $value;
					break;
				case '>':
					$result = $count > $value;
					break;
				case '<':
					$result = $count < $value;
					break;
				case '>=':
					$result = $count >= $value;
					break;
				case '<=':
					$result = $count <= $value;
					break;
				default:
					$result = false;
					break;
			}

			return $this->return_is_match( $result, $rule_data );
		}

		/**
		 * @return string
		 */
		public function get_condition_input_type() {
			return 'Text';
		}

		/**
		 * @param $rule_data
		 *
		 * @return bool
		 */
		public function is_match( $rule_data ) {
			$comment_details      = BWFAN_Core()->rules->getRulesData( 'wc_comment' );
			$comment_rating_count = $comment_details['rating_number'];
			$count                = absint( $comment_rating_count );
			$value                = absint( $rule_data['condition'] );

			switch ( $rule_data['operator'] ) {
				case '==':
					$result = $count === $value;
					break;
				case '!=':
					$result = $count !== $value;
					break;
				case '>':
					$result = $count > $value;
					break;
				case '<':
					$result = $count < $value;
					break;
				case '>=':
					$result = $count >= $value;
					break;
				case '<=':
					$result = $count <= $value;
					break;
				default:
					$result = false;
					break;
			}

			return $this->return_is_match( $result, $rule_data );
		}

		/**
		 * @return void
		 */
		public function ui_view() {
			esc_html_e( 'Review Rating count', 'wp-marketing-automations' );
			?>
            <% var ops = JSON.parse('<?php echo wp_json_encode( $this->get_possible_rule_operators() ); ?>'); %>

            <%= ops[operator] %>
            <%= condition %>
			<?php
		}

		/**
		 * @return array
		 */
		public function get_possible_rule_operators() {
			return $this->get_possible_number_rule_operators();
		}
	}
}

if ( ! class_exists( 'BWFAN_Rule_Comment_Products_Cats' ) ) {
	#[\AllowDynamicProperties]
	class BWFAN_Rule_Comment_Products_Cats extends BWFAN_Rule_Term_Taxonomy {
		/**
		 * BWFAN_Rule_Comment_Products_Cats constructor.
		 */
		public function __construct() {
			$this->v2 = true;
			$this->v1 = false;
			parent::__construct( 'comment_products_cats' );
		}

		/** v2 Methods: START */
		/**
		 * @param $search
		 *
		 * @return array
		 */
		public function get_options( $search = '' ) {
			return $this->get_possible_rule_values( $search );
		}

		/**
		 * @return string
		 */
		public function get_rule_type() {
			return 'Search';
		}

		/**
		 * @param $automation_data
		 * @param $rule_data
		 *
		 * @return bool
		 */
		public function is_match_v2( $automation_data, $rule_data ) {
			if ( ! isset( $automation_data['global'] ) || ! is_array( $automation_data['global'] ) ) {
				return $this->return_is_match( false, $rule_data );
			}
			$type        = $rule_data['rule'];
			$saved_terms = array_column( $rule_data['data'], 'key' );
			$comment_id  = $automation_data['global']['comment_id'];

			if ( ! $comment_id ) {
				return $this->return_is_match( false, $rule_data );
			}

			$comment = get_comment( $comment_id );

			if ( ! $comment || ! is_a( $comment, 'WP_Comment' ) ) {
				return $this->return_is_match( false, $rule_data );
			}

			$product_id = $comment->comment_post_ID;

			if ( ! $product_id ) {
				return $this->return_is_match( false, $rule_data );
			}
			$product_terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
			switch ( $type ) {
				case 'any':
					$result = count( array_intersect( $saved_terms, $product_terms ) ) >= 1;
					break;
				case 'none':
					$result = count( array_intersect( $saved_terms, $product_terms ) ) === 0;
					break;
				default:
					$result = false;
					break;
			}

			return $this->return_is_match( $result, $rule_data );
		}

		/**
		 * @return array
		 */
		public function get_possible_rule_operators() {
			return array(
				'any'  => __( 'matches any of', 'wp-marketing-automations' ),
				'none' => __( 'matches none of', 'wp-marketing-automations' ),
			);
		}

		/**
		 * @param $search
		 *
		 * @return array
		 */
		public function get_possible_rule_values( $search = '' ) {
			$result = array();
			$search = sanitize_text_field( $search );

			if ( empty( $search ) ) {
				$terms = get_terms( array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
				) );
			} else {
				$terms = BWFAN_Common::search_terms_by_name( 'product_cat', $search, 0 );
			}

			if ( is_wp_error( $terms ) ) {
				return $result;
			}

			foreach ( $terms as $term ) {
				$result[ $term->term_id ] = BWFAN_Common::decode_term_name( $term->name );
			}

			return $result;
		}

	}
}
