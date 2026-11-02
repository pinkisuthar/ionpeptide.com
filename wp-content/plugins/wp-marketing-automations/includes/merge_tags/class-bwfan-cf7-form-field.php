<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

#[\AllowDynamicProperties]
class BWFAN_CF7_Form_Field extends BWFAN_Merge_Tag {

	private static $instance = null;

	public function __construct() {
		$this->tag_name        = 'cf7_form_field';
		$this->tag_description = __( 'Form Field', 'wp-marketing-automations' );
		add_shortcode( 'bwfan_cf7_form_field', array( $this, 'parse_shortcode' ) );
		add_action( 'wp_ajax_bwfan_get_automation_cf7_form_fields', array( $this, 'bwfan_get_automation_cf7_form_fields' ) );
	}

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Show the html in popup for the merge tag.
	 */
	public function get_view() {
		$this->get_back_button();
		?>
        <label for=""
               class="bwfan-label-title"><?php echo esc_html__( 'Select Field', 'wp-marketing-automations' ); ?></label>
        <select id="" class="bwfan-input-wrapper bwfan-mb-15 bwfan_tag_select bwfan_cf7_form_fields"
                name="field"></select>
		<?php
		if ( $this->support_fallback ) {
			$this->get_fallback();
		}

		$this->get_preview();
		$this->get_copy_button();
	}

	/**
	 * Parse the merge tag and return its value.
	 *
	 * @param $attr
	 *
	 * @return mixed|string|void
	 */
	public function parse_shortcode( $attr ) {
		if ( true === BWFAN_Merge_Tag_Loader::get_data( 'is_preview' ) ) {
			return $this->get_dummy_preview();
		}

		$entry       = BWFAN_Merge_Tag_Loader::get_data( 'fields' );
		$field_value = '';

		if ( isset( $attr['field'] ) && isset( $entry[ $attr['field'] ] ) ) {
			$field_value = $entry[ $attr['field'] ];

			if ( is_array( $field_value ) ) {
				$field_value = implode( ', ', $field_value );
			}
		}

		return $this->parse_shortcode_output( $field_value, $attr );
	}

	/**
	 * Show dummy value of the current merge tag.
	 *
	 * @return string
	 *
	 * @todo:Hard values shouldn't be passed
	 */
	public function get_dummy_preview() {
		return 'Test';
	}

	public function bwfan_get_automation_cf7_form_fields() {
		BWFAN_Common::check_nonce();

		$finalarr     = [];
		$automationId = isset( $_POST['automationId'] ) ? absint( sanitize_text_field( wp_unslash( $_POST['automationId'] ) ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by BWFAN_Common::check_nonce() above

		/** Check Automation */
		$automation_obj = BWFAN_Automation_V2::get_instance( $automationId );

		/** Check for automation exists */
		if ( empty( $automation_obj->error ) ) {
			$automation_meta = $automation_obj->get_automation_meta_data();
			if ( isset( $automation_meta['event_meta'] ) && isset( $automation_meta['event_meta']['bwfan-cf7_form_submit_form_id'] ) ) {
				$form_id = absint( sanitize_text_field( $automation_meta['event_meta']['bwfan-cf7_form_submit_form_id'] ) );
				$fields  = [];
				if ( ! empty( $form_id ) ) {
					$obj    = BWFAN_CF7_Form_Submit::get_instance();
					$fields = $obj->get_form_fields( $form_id );
				}

				/** Handling form fields for the v2 automations */
				foreach ( $fields as $key => $value ) {
					$finalarr[] = [
						'key'   => $key,
						'value' => $value
					];
				}
			}
		}

		wp_send_json( array(
			'results' => $finalarr
		) );
		exit;
	}

	/**
	 * Returns merge tag schema
	 *
	 * @return array[]
	 */
	public function get_setting_schema() {
		return [
			[
				'id'          => 'field',
				'type'        => 'ajax',
				'label'       => __( 'Select Field', 'wp-marketing-automations' ),
				"class"       => 'bwfan-input-wrapper',
				"required"    => true,
				'placeholder' => __( 'Select', 'wp-marketing-automations' ),
				"description" => "",
				"ajax_cb"     => 'bwfan_get_automation_cf7_form_fields',
			]
		];
	}

}

/**
 * Register this merge tag to a group.
 */
if ( bwfan_is_cf7_active() ) {
	BWFAN_Merge_Tag_Loader::register( 'cf7', 'BWFAN_CF7_Form_Field', null, __( 'Contact Form 7', 'wp-marketing-automations' ) );
}
