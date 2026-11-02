<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checkbox field.
 *
 * @since 1.0.0
 */
class WPForms_Field_Checkbox extends WPForms_Field {

	/**
	 * Primary class constructor.
	 *
	 * @since 1.0.0
	 */
	public function init() {

		// Define field type information.
		$this->name     = esc_html__( 'Checkboxes', 'wpforms-lite' );
		$this->keywords = esc_html__( 'choice', 'wpforms-lite' );
		$this->type     = 'checkbox';
		$this->icon     = 'fa-check-square-o';
		$this->order    = 110;
		$this->defaults = [
			1 => [
				'label'      => esc_html__( 'First Choice', 'wpforms-lite' ),
				'value'      => '',
				'image'      => '',
				'icon'       => '',
				'icon_style' => '',
				'default'    => '',
			],
			2 => [
				'label'      => esc_html__( 'Second Choice', 'wpforms-lite' ),
				'value'      => '',
				'image'      => '',
				'icon'       => '',
				'icon_style' => '',
				'default'    => '',
			],
			3 => [
				'label'      => esc_html__( 'Third Choice', 'wpforms-lite' ),
				'value'      => '',
				'image'      => '',
				'icon'       => '',
				'icon_style' => '',
				'default'    => '',
			],
		];

		$this->default_settings = [
			'choices' => $this->defaults,
		];

		$this->hooks();
	}

	/**
	 * Hooks.
	 *
	 * @since 1.8.1
	 */
	private function hooks() {

		// Customize HTML field values.
		add_filter( 'wpforms_html_field_value', [ $this, 'field_html_value' ], 10, 4 );
		add_filter( "wpforms_{$this->type}_field_html_value_images", [ $this, 'field_html_value_images' ], 10, 3 );

		// Define additional field properties.
		add_filter( 'wpforms_field_properties_checkbox', [ $this, 'field_properties' ], 5, 3 );

		// This field requires fieldset+legend instead of the field label.
		add_filter( "wpforms_frontend_modern_is_field_requires_fieldset_{$this->type}", '__return_true', PHP_INT_MAX, 2 );

		// Handle export of the Other choice.
		add_filter( 'wpforms_pro_admin_entries_export_ajax_get_entry_fields_data_field', [ $this, 'prepare_field_for_export' ], 10, 1 );
	}

	/**
	 * Adjust builder preview container classes.
	 *
	 * Adds size-{small|medium|large} to the Checkbox field container in the Builder
	 * when the "Add Other Choice" option is enabled.
	 *
	 * @since 2.0.3
	 *
	 * @param string $css   Existing class string.
	 * @param array  $field Field data and settings.
	 *
	 * @return string
	 */
	public function preview_field_class( $css, $field ): string {

		$css = (string) $css;
		$css = parent::preview_field_class( $css, $field );

		if ( $field['type'] !== $this->type ) {
			return $css;
		}

		// Apply a size class to the field container when Other Choice is enabled.
		if ( $this->has_other_choice( $field ) ) {
			$size = ! empty( $field['other_size'] ) ? sanitize_html_class( $field['other_size'] ) : 'medium';
			$css .= ' size-' . $size;
		}

		return $css;
	}

	/**
	 * Define additional field properties.
	 *
	 * @since 1.4.5
	 *
	 * @param array $properties Field properties.
	 * @param array $field      Field settings.
	 * @param array $form_data  Form data and settings.
	 *
	 * @return array
	 */
	public function field_properties( $properties, $field, $form_data ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.MaxExceeded

		// Define data.
		$form_id  = absint( $form_data['id'] );
		$field_id = wpforms_validate_field_id( $field['id'] );
		$choices  = $field['choices'];
		$dynamic  = wpforms_get_field_dynamic_choices( $field, $form_id, $form_data );

		if ( $dynamic !== false ) {
			$choices              = $dynamic;
			$field['show_values'] = true;
		}

		// Remove primary input, unset for attribute for label.
		unset( $properties['inputs']['primary'], $properties['label']['attr']['for'] );

		// Set input container (ul) properties.
		$properties['input_container'] = [
			'class' => [ ! empty( $field['random'] ) ? 'wpforms-randomize' : '' ],
			'data'  => [],
			'attr'  => [],
			'id'    => "wpforms-{$form_id}-field_{$field_id}",
		];

		$is_choice_limit_set = ! empty( $field['choice_limit'] ) && (int) $field['choice_limit'] > 0;

		if ( $is_choice_limit_set ) {
			$properties['input_container']['data']['choice-limit'] = $field['choice_limit'];
		}

		// Set input properties.
		foreach ( $choices as $key => $choice ) {

			// Used for dynamic choices.
			$depth = isset( $choice['depth'] ) ? absint( $choice['depth'] ) : 1;
			$label = isset( $choice['label'] ) ? $choice['label'] : '';

			// Choice labels should not be left blank, but if they are we
			// provide a basic value.
			$value = isset( $field['show_values'] ) ? $choice['value'] : $label;

			if ( '' === $value ) {
				if ( 1 === count( $choices ) ) {
					$value = esc_html__( 'Checked', 'wpforms-lite' );
				} else {
					/* translators: %s - choice number. */
					$value = sprintf( esc_html__( 'Choice %s', 'wpforms-lite' ), $key );
				}
			}

			// Check if this is the "Other" choice.
			$is_other_choice = isset( $choice['other'] ) && (bool) $choice['other'] === true;

			$container_classes = [ "choice-{$key}", "depth-{$depth}" ];

			if ( $is_other_choice ) {
				$container_classes[] = 'wpforms-other-choice';
			}

			$properties['inputs'][ $key ] = [
				'container'  => [
					'attr'  => [],
					'class' => $container_classes,
					'data'  => [],
					'id'    => '',
				],
				'label'      => [
					'attr'  => [
						'for' => "wpforms-{$form_id}-field_{$field_id}_{$key}",
					],
					'class' => [ 'wpforms-field-label-inline' ],
					'data'  => [],
					'id'    => '',
					'text'  => $label,
				],
				'attr'       => [
					'name'  => "wpforms[fields][{$field_id}][]",
					'value' => $value,
				],
				'class'      => [],
				'data'       => $is_other_choice ? [ 'other-choice' => 'true' ] : [],
				'id'         => "wpforms-{$form_id}-field_{$field_id}_{$key}",
				'icon'       => isset( $choice['icon'] ) ? $choice['icon'] : '',
				'icon_style' => isset( $choice['icon_style'] ) ? $choice['icon_style'] : '',
				'image'      => isset( $choice['image'] ) ? $choice['image'] : '',
				'required'   => ! empty( $field['required'] ) ? 'required' : '',
				'default'    => isset( $choice['default'] ),
			];

			// Rule for validator only if needed.
			if ( $is_choice_limit_set ) {
				$properties['inputs'][ $key ]['data']['rule-check-limit'] = 'true';
			}
		}

		// Required class for pagebreak validation.
		if ( ! empty( $field['required'] ) ) {
			$properties['input_container']['class'][] = 'wpforms-field-required';
		}

		// Custom properties if image choices is enabled.
		if ( ! $dynamic && ! empty( $field['choices_images'] ) ) {

			$properties['input_container']['class'][] = 'wpforms-image-choices';
			$properties['input_container']['class'][] = 'wpforms-image-choices-' . sanitize_html_class( $field['choices_images_style'] );

			foreach ( $properties['inputs'] as $key => $inputs ) {
				$properties['inputs'][ $key ]['container']['class'][] = 'wpforms-image-choices-item';

				if ( in_array( $field['choices_images_style'], [ 'modern', 'classic' ], true ) ) {
					$properties['inputs'][ $key ]['class'][] = 'wpforms-screen-reader-element';
				}
			}
		} elseif ( ! $dynamic && ! empty( $field['choices_icons'] ) ) {
			$properties = wpforms()->obj( 'icon_choices' )->field_properties( $properties, $field );
		}

		// Custom properties for disclaimer format display.
		if ( ! empty( $field['disclaimer_format'] ) ) {

			$properties['description']['class'][] = 'wpforms-disclaimer-description';
			$properties['description']['value']   = nl2br( $properties['description']['value'] );
		}

		// Add selected class for choices with defaults.
		foreach ( $properties['inputs'] as $key => $inputs ) {
			if ( ! empty( $inputs['default'] ) ) {
				$properties['inputs'][ $key ]['container']['class'][] = 'wpforms-selected';
			}
		}

		return $properties;
	}

	/**
	 * Field options panel inside the builder.
	 *
	 * @since 1.0.0
	 *
	 * @param array $field Field settings.
	 */
	public function field_options( $field ) {
		/*
		 * Basic field options.
		 */

		// Options open markup.
		$this->field_option(
			'basic-options',
			$field,
			[
				'markup' => 'open',
			]
		);

		// Label.
		$this->field_option( 'label', $field );

		// Choices.
		$this->field_option( 'choices', $field );

		// AI Feature.
		$this->field_option(
			'ai_modal_button',
			$field,
			[
				'value' => esc_html__( 'Generate Choices', 'wpforms-lite' ),
				'type'  => 'choices',
			]
		);

		// Add Other Choice.
		$this->field_option( 'choices_other', $field );

		// Other Field Size for "Other" input.
		$this->field_option( 'other_size', $field );

		// Other Placeholder for "Other" input.
		$this->field_option( 'other_placeholder', $field );

		// Choices Images.
		$this->field_option( 'choices_images', $field );

		// Hide Choices Images.
		$this->field_option( 'choices_images_hide', $field );

		// Choices Images Style (theme).
		$this->field_option( 'choices_images_style', $field );

		// Choices Icons.
		$this->field_option( 'choices_icons', $field );

		// Choices Icons Color.
		$this->field_option( 'choices_icons_color', $field );

		// Choices Icons Size.
		$this->field_option( 'choices_icons_size', $field );

		// Choices Icons Style.
		$this->field_option( 'choices_icons_style', $field );

		// Description.
		$this->field_option( 'description', $field );

		// Required toggle.
		$this->field_option( 'required', $field );

		// Options close markup.
		$this->field_option(
			'basic-options',
			$field,
			[
				'markup' => 'close',
			]
		);

		/*
		 * Advanced field options
		 */

		// Options open markup.
		$this->field_option(
			'advanced-options',
			$field,
			[
				'markup' => 'open',
			]
		);

		// Randomize order of choices.
		$this->field_element(
			'row',
			$field,
			[
				'slug'    => 'random',
				'content' => $this->field_element(
					'toggle',
					$field,
					[
						'slug'    => 'random',
						'value'   => isset( $field['random'] ) ? '1' : '0',
						'desc'    => esc_html__( 'Randomize Choices', 'wpforms-lite' ),
						'tooltip' => esc_html__( 'Check this option to randomize the order of the choices.', 'wpforms-lite' ),
					],
					false
				),
			]
		);

		// Show Values toggle option. This option will only show if already used
		// or if manually enabled by a filter.
		if ( ! empty( $field['show_values'] ) || wpforms_show_fields_options_setting() ) {
			$this->field_element(
				'row',
				$field,
				[
					'slug'    => 'show_values',
					'content' => $this->field_element(
						'toggle',
						$field,
						[
							'slug'    => 'show_values',
							'value'   => isset( $field['show_values'] ) ? $field['show_values'] : '0',
							'desc'    => esc_html__( 'Show Values', 'wpforms-lite' ),
							'tooltip' => esc_html__( 'Check this option to manually set form field values.', 'wpforms-lite' ),
						],
						false
					),
				]
			);
		}

		// Display format.
		$this->field_option( 'input_columns', $field );

		// Choice Limit.
		$this->field_option( 'choice_limit', $field );

		// Dynamic choice auto-populating toggle.
		$this->field_option( 'dynamic_choices', $field );

		// Dynamic choice source.
		$this->field_option( 'dynamic_choices_source', $field );

		// Custom CSS classes.
		$this->field_option( 'css', $field );

		// Hide label.
		$this->field_option( 'label_hide', $field );

		// Enable Disclaimer formatting.
		$this->field_element(
			'row',
			$field,
			[
				'slug'    => 'disclaimer_format',
				'content' => $this->field_element(
					'toggle',
					$field,
					[
						'slug'    => 'disclaimer_format',
						'value'   => isset( $field['disclaimer_format'] ) ? '1' : '0',
						'desc'    => esc_html__( 'Enable Disclaimer / Terms of Service Display', 'wpforms-lite' ),
						'tooltip' => esc_html__( 'Check this option to adjust the field styling to support Disclaimers and Terms of Service type agreements.', 'wpforms-lite' ),
					],
					false
				),
			]
		);

		// Options close markup.
		$this->field_option(
			'advanced-options',
			$field,
			[
				'markup' => 'close',
			]
		);
	}

	/**
	 * Field preview inside the builder.
	 *
	 * @since 1.0.0
	 *
	 * @param array $field Field settings.
	 */
	public function field_preview( $field ) {

		// Label.
		$this->field_preview_option( 'label', $field );

		// Choices.
		$this->field_preview_option( 'choices', $field );

		// Description.
		$this->field_preview_option(
			'description',
			$field,
			[
				'class' => ! empty( $field['disclaimer_format'] ) ? 'disclaimer nl2br' : false,
			]
		);
	}

	/**
	 * Field display on the form front-end and admin entry edit page.
	 *
	 * @since 1.0.0
	 *
	 * @param array $field      Field settings.
	 * @param array $deprecated Deprecated array.
	 * @param array $form_data  Form data and settings.
	 */
	public function field_display( $field, $deprecated, $form_data ) {

		$using_image_choices = $this->is_image_choices( $field );
		$using_icon_choices  = $this->is_icon_choices( $field );

		// Define data.
		$container = $field['properties']['input_container'];
		$choices   = $field['properties']['inputs'];

		// Do not display the field with empty choices on the frontend.
		if ( ! $choices && ! is_admin() ) {
			return;
		}

		// Display a warning message on Entry Edit page.
		if ( ! $choices && is_admin() ) {
			$this->display_empty_dynamic_choices_message( $field );

			return;
		}

		$amp_state_id = '';

		if ( wpforms_is_amp() && ( $using_image_choices || $using_icon_choices ) ) {
			$amp_state_id = str_replace( '-', '_', sanitize_key( $container['id'] ) ) . '_state';
			$state        = [];

			foreach ( $choices as $key => $choice ) {
				$state[ $choice['id'] ] = ! empty( $choice['default'] );
			}
			printf(
				'<amp-state id="%s"><script type="application/json">%s</script></amp-state>',
				esc_attr( $amp_state_id ),
				wp_json_encode( $state )
			);
		}

		printf(
			'<ul %s>',
			wpforms_html_attributes( $container['id'], $container['class'], $container['data'], $container['attr'] )
		);

		$other_input_html = '';

			foreach ( $choices as $key => $choice ) {
				$label = $this->get_choices_label( $choice['label']['text'] ?? '', $key, $field );

				if ( wpforms_is_amp() && ( $using_image_choices || $using_icon_choices ) ) {
					$choice['container']['attr']['[class]'] = sprintf(
						'%s + ( %s[%s] ? " wpforms-selected" : "")',
						wp_json_encode( wpforms_sanitize_classes( $choice['container']['class'], is_array( $choice['container']['class'] ) ) ),
						$amp_state_id,
						wp_json_encode( $choice['id'] )
					);
				}

				// If the field is required, has the label hidden, and has
				// disclaimer mode enabled, so the required status in choice
				// label.
				$required = '';

				if ( ! empty( $field['disclaimer_format'] ) && ! empty( $choice['required'] ) && ! empty( $field['label_hide'] ) ) {
					$required = wpforms_get_field_required_label();
				}

				printf(
					'<li %s>',
					wpforms_html_attributes( $choice['container']['id'], $choice['container']['class'], $choice['container']['data'], $choice['container']['attr'] )
				);

					// The required constraint in HTML5 form validation does not work with checkbox groups, so omit in AMP.
					$required_attr = wpforms_is_amp() && count( $choices ) > 1 ? '' : $choice['required'];

					if ( $using_image_choices ) {

						// Make sure the image choices are keyboard-accessible.
						$choice['label']['attr']['tabindex'] = 0;

						if ( wpforms_is_amp() ) {
							$choice['label']['attr']['on']   = sprintf(
								'tap:AMP.setState({ %s: { %s: ! %s[%s] } })',
								wp_json_encode( $amp_state_id ),
								wp_json_encode( $choice['id'] ),
								$amp_state_id,
								wp_json_encode( $choice['id'] )
							);
							$choice['label']['attr']['role'] = 'button';
						}
						if ( is_array( $choice['label']['class'] ) && wpforms_is_empty_string( $label ) ) {
							$choice['label']['class'][] = 'wpforms-field-label-inline-empty';
						}

						// Image choices.
						printf(
							'<label %s>',
							wpforms_html_attributes( $choice['label']['id'], $choice['label']['class'], $choice['label']['data'], $choice['label']['attr'] )
						);

							echo '<span class="wpforms-image-choices-image">';

							if ( ! empty( $choice['image'] ) ) {
								printf(
									'<img src="%s" alt="%s"%s>',
									esc_url( $choice['image'] ),
									esc_attr( $label ),
									! empty( $label ) ? ' title="' . esc_attr( $label ) . '"' : ''
								);
							}

							echo '</span>';

							if ( $field['choices_images_style'] === 'none' ) {
								echo '<br>';
							}

							$choice['attr']['tabindex'] = '-1';

							if ( wpforms_is_amp() ) {
								$choice['attr']['[checked]'] = sprintf(
									'%s[%s]',
									$amp_state_id,
									wp_json_encode( $choice['id'] )
								);
							}

							printf(
								'<input type="checkbox" %s %s %s>',
								wpforms_html_attributes( $choice['id'], $choice['class'], $choice['data'], $choice['attr'] ),
								esc_attr( $required_attr ),
								checked( '1', $choice['default'], false )
							);

							echo '<span class="wpforms-image-choices-label">' . wp_kses_post( $label ) . '</span>';

						echo '</label>';

					} elseif ( $using_icon_choices ) {

						if ( wpforms_is_amp() ) {
							$choice['label']['attr']['on']   = sprintf(
								'tap:AMP.setState({ %s: { %s: ! %s[%s] } })',
								wp_json_encode( $amp_state_id ),
								wp_json_encode( $choice['id'] ),
								$amp_state_id,
								wp_json_encode( $choice['id'] )
							);
							$choice['label']['attr']['role'] = 'button';
						}

						// Icon Choices.
						wpforms()->obj( 'icon_choices' )->field_display( $field, $choice, 'checkbox' );

					} else {

						// Normal display.
						printf(
							'<input type="checkbox" %s %s %s>',
							wpforms_html_attributes( $choice['id'], $choice['class'], $choice['data'], $choice['attr'] ),
							esc_attr( $required_attr ),
							checked( '1', $choice['default'], false )
						);

						printf(
							'<label %s>%s%s</label>',
							wpforms_html_attributes( $choice['label']['id'], $choice['label']['class'], $choice['label']['data'], $choice['label']['attr'] ),
							wp_kses_post( $label ),
							wp_kses(
								$required,
								[
									'span' => [
										'class' => true,
									],
								]
							)
						);
					}

					// Capture the text field for "Other" choice to render separately below the list.
					$other_input_html = $this->render_other_input( $field, $choice, (string) $key, $label, $form_data );

				echo '</li>';
			}

		echo '</ul>';

		// Render the captured "Other" input separately, under the list of options.
		if ( ! empty( $other_input_html ) ) {
			echo $other_input_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Validate field on form submit.
	 *
	 * @since 1.5.2
	 *
	 * @param int   $field_id     Field ID.
	 * @param array $field_submit Submitted field value (raw data).
	 * @param array $form_data    Form data.
	 */
	public function validate( $field_id, $field_submit, $form_data ) {

		$field_id = (int) $field_id;
		$field    = $form_data['fields'][ $field_id ];

		// Skip validation if field is dynamic and choices are empty.
		if ( $this->is_dynamic_choices_empty( $field, $form_data ) ) {
			return;
		}

		if ( ! is_array( $field_submit ) ) {
			$field_submit = wpforms_is_empty_string( $field_submit ) ? [] : (array) $field_submit;
		}

		// The standalone Other text input is not a selection and must not count toward the limit.
		$choice_submit = $field_submit;

		unset( $choice_submit['other'] );

		$this->validate_field_choice_limit( $field_id, $choice_submit, $form_data );

		// Basic required check - If field is marked as required, check for entry data.
		if (
			! empty( $form_data['fields'][ $field_id ]['required'] ) &&
			(
				empty( $field_submit ) ||
				(
					count( $field_submit ) === 1 &&
					( ! isset( $field_submit[0] ) || (string) $field_submit[0] === '' )
				)
			)
		) {
			$error = wpforms_get_required_label();
		}

		if ( ! empty( $error ) ) {
			wpforms()->obj( 'process' )->errors[ $form_data['id'] ][ $field_id ] = $error;
		}

		$this->validate_other_choice_value( $field_id, $field_submit, $form_data );
		$this->validate_choices_allowlist( $field_id, $field_submit, $form_data );
	}

	/**
	 * Render the "Other" text input field.
	 *
	 * Generates HTML for the text input that allows users to enter custom text
	 * when the "Other" choice is selected.
	 *
	 * @since 2.0.3
	 *
	 * @param array  $field     Field data and settings.
	 * @param array  $choice    Current choice data.
	 * @param string $key       Choice key.
	 * @param string $label     Choice label.
	 * @param array  $form_data Form data and settings.
	 *
	 * @return string HTML for the Other input field, or empty string if not applicable.
	 */
	private function render_other_input( array $field, array $choice, string $key, string $label, array $form_data ): string {

		if ( empty( $choice['data']['other-choice'] ) ) {
			return '';
		}

		$default_value = '';
		$size          = ! empty( $field['other_size'] ) ? sanitize_html_class( $field['other_size'] ) : 'medium';
		$size_class    = 'wpforms-field-' . $size;

		// Do not hide the Other input if this choice is set as default.
		$hidden_class = ! empty( $choice['default'] ) ? '' : ' wpforms-hidden';

		if ( isset( $field['choices'][ $key ]['value'] ) && $field['choices'][ $key ]['value'] !== '' ) {
			$default_value = $field['choices'][ $key ]['value'];
		}

		/**
		 * Filters the default value of the Other choice input for checkbox fields.
		 *
		 * This filter allows modifying what value should be prefilled for the Other choice input.
		 *
		 * @since 2.0.3
		 *
		 * @param string $default_value Default value for the "Other" choice input.
		 * @param array  $field         Field data and settings.
		 * @param string $label         Choice label.
		 */
		$default_value = apply_filters( 'wpforms_field_checkbox_other_choice_default_value', $default_value, $field, $label );

		$other_atts = [
			'name'  => "wpforms[fields][{$field['id']}][other]",
			'value' => $default_value,
		];

		if ( empty( $choice['default'] ) ) {
			$other_atts['disabled'] = 'disabled';
		}

		if ( ! empty( $field['other_placeholder'] ) ) {
			$other_atts['placeholder'] = $field['other_placeholder'];
		}

		// A read-only field strips the required flag on save; mirror that on the Other input.
		$is_required = empty( $field['read_only'] );

		return sprintf(
			'<input type="text" %s%s>',
			wpforms_html_attributes(
				"wpforms-{$form_data['id']}-field_{$field['id']}_other",
				[ 'wpforms-other-input', $is_required ? 'wpforms-field-required' : '', $size_class, $hidden_class ],
				[],
				$other_atts
			),
			$is_required ? ' required' : ''
		);
	}

	/**
	 * Format the "Other" option data for storage.
	 *
	 * Processes the custom text entered for the Other choice and adds it to the
	 * field data with proper formatting.
	 *
	 * @since 2.0.3
	 *
	 * @param array  &$data               Field data array (passed by reference).
	 * @param array  $field               Field settings.
	 * @param string $other_submitted_raw Raw submitted Other value.
	 */
	private function format_other_option( array &$data, array $field, string $other_submitted_raw ): void {

		$other_choice = $this->find_other_choice( $field );

		if ( $other_choice === null || wpforms_is_empty_string( $other_submitted_raw ) ) {
			return;
		}

		$other_value = sanitize_text_field( $other_submitted_raw );
		$other_label = $other_choice['label'];

		$data['is_other']    = true;
		$data['other_label'] = $other_label;
		$data['other_value'] = $other_value;

		$data['value'] = $this->build_value_with_other( $data['value'], $other_label, $other_value );
	}

	/**
	 * Build the final value string including the formatted Other choice.
	 *
	 * @since 2.0.3
	 *
	 * @param string $current_value Current value string.
	 * @param string $other_label   Other choice label.
	 * @param string $other_value   Submitted Other value.
	 *
	 * @return string
	 */
	private function build_value_with_other( string $current_value, string $other_label, string $other_value ): string {

		$formatted_other = $this->format_other_label_value( $other_label, $other_value );

		if ( wpforms_is_empty_string( $current_value ) ) {
			return $formatted_other;
		}

		$value_parts = array_filter(
			explode( "\n", $current_value ),
			static function ( $part ) use ( $other_label ) {

				return trim( $part ) !== $other_label;
			}
		);

		$value_parts[] = $formatted_other;

		return implode( "\n", $value_parts );
	}

	/**
	 * Format the Other label and value into a single string.
	 *
	 * @since 2.0.3
	 *
	 * @param string $label Other choice label.
	 * @param string $value Submitted value.
	 *
	 * @return string
	 */
	private function format_other_label_value( string $label, string $value ): string {

		if ( wpforms_is_empty_string( $label ) ) {
			return $value;
		}

		if ( wpforms_is_empty_string( $value ) ) {
			return '';
		}

		return sprintf( '%s: %s', $label, $value );
	}

	/**
	 * Prepare field data for export with proper handling of the Other choice.
	 *
	 * This method normalizes checkbox field data when the Other choice is selected
	 * to ensure proper export functionality with multiple columns. It stores both
	 * the normalized value for matching and the full formatted value for display.
	 *
	 * @since 2.0.3
	 *
	 * @param array|mixed $field Entry field data.
	 *
	 * @return array Modified field data.
	 */
	public function prepare_field_for_export( $field ): array {

		$field = (array) $field;

		// Only process checkbox fields with the Other choice selected.
		if (
			empty( $field['type'] ) ||
			$field['type'] !== $this->type ||
			empty( $field['is_other'] ) ||
			empty( $field['other_label'] )
		) {
			return $field;
		}

		$other_label  = trim( $field['other_label'] );
		$other_prefix = $other_label . ': ';

		// Get all values from the field.
		$values = explode( "\n", $field['value'] );

		// Store the mapping of normalized values to display values.
		$field['export_value_map'] = [];

		// Process each value.
		foreach ( $values as $index => $value ) {
			$value = trim( $value );

			// Check if this value is the "Other" choice (formatted as "Other: custom value").
			if ( strpos( $value, $other_prefix ) === 0 ) {
				// Store the full formatted value in the map for later use.
				$field['export_value_map'][ $other_label ] = $value;

				// Replace the formatted value with just the label for matching.
				$values[ $index ] = $other_label;
			}
		}

		// Update the value field with normalized values.
		$field['value'] = implode( "\n", $values );

		return $field;
	}

	/**
	 * Find the Other choice in the field choices.
	 *
	 * @since 2.0.3
	 *
	 * @param array $field Field settings.
	 *
	 * @return array|null Array with 'key' and 'label', or null if not found.
	 */
	private function find_other_choice( array $field ): ?array {

		if ( empty( $field['choices'] ) || ! is_array( $field['choices'] ) ) {
			return null;
		}

		foreach ( $field['choices'] as $key => $choice ) {
			if ( ! empty( $choice['other'] ) ) {
				return [
					'key'   => $key,
					'label' => $choice['label'] ?? '',
				];
			}
		}

		return null;
	}

	/**
	 * Return choice value, including the typed Other text if applicable.
	 *
	 * It overrides the parent method because with Show Values enabled the parent
	 * returns raw values, where the Other line holds the static configured value
	 * and silently drops the typed custom text.
	 *
	 * @since 2.0.3
	 *
	 * @param array $field     Field settings.
	 * @param array $form_data Form data.
	 *
	 * @return string
	 */
	protected function get_choices_value( array $field, array $form_data ): string {

		$value = parent::get_choices_value( $field, $form_data );

		if (
			empty( $field['is_other'] ) ||
			! isset( $field['other_value'] ) ||
			empty( $form_data['fields'][ $field['id'] ]['show_values'] )
		) {
			return $value;
		}

		return $this->replace_other_raw_value( $value, $field, $form_data );
	}

	/**
	 * Replace the Other choice's static value line with the formatted typed text.
	 *
	 * @since 2.0.3
	 *
	 * @param string $value     Raw values string, one value per line.
	 * @param array  $field     Entry field data.
	 * @param array  $form_data Form data.
	 *
	 * @return string
	 */
	private function replace_other_raw_value( string $value, array $field, array $form_data ): string {

		$field_config = $form_data['fields'][ $field['id'] ] ?? [];
		$other_choice = $this->find_other_choice( $field_config );

		if ( $other_choice === null ) {
			return $value;
		}

		$other_raw = (string) ( $field_config['choices'][ $other_choice['key'] ]['value'] ?? '' );
		$formatted = $this->format_other_label_value( $field['other_label'] ?? '', (string) $field['other_value'] );

		if ( wpforms_is_empty_string( $other_raw ) || wpforms_is_empty_string( $formatted ) ) {
			return $value;
		}

		$value_parts = explode( "\n", $value );

		foreach ( $value_parts as $key => $part ) {
			if ( trim( $part ) === $other_raw ) {
				$value_parts[ $key ] = $formatted;

				break;
			}
		}

		return implode( "\n", $value_parts );
	}

	/**
	 * Format and sanitize field.
	 *
	 * @since 1.0.2
	 *
	 * @param int   $field_id     Field ID.
	 * @param array $field_submit Submitted form data.
	 * @param array $form_data    Form data and settings.
	 */
	public function format( $field_id, $field_submit, $form_data ) {

		$field_submit = (array) $field_submit;
		$field        = $form_data['fields'][ $field_id ];
		$field_submit = (array) $this->sanitize_choices_submission( $field_submit, $field, $form_data );
		$dynamic      = ! empty( $field['dynamic_choices'] ) ? $field['dynamic_choices'] : false;
		$name         = sanitize_text_field( $field['label'] );
		// A non-scalar Other value can only come from a hand-crafted request; treat it as absent.
		$other_submitted_raw = isset( $field_submit['other'] ) && is_scalar( $field_submit['other'] ) ? (string) wp_unslash( $field_submit['other'] ) : '';

		// Remove the standalone Other input from the main submitted values before combining.
		unset( $field_submit['other'] );

		$combined  = wpforms_sanitize_array_combine( $field_submit );
		$value_raw = is_string( $combined ) ? $combined : '';

		$data = [
			'name'      => $name,
			'value'     => '',
			'value_raw' => $value_raw,
			'id'        => wpforms_validate_field_id( $field_id ),
			'type'      => $this->type,
		];

		if ( 'post_type' === $dynamic && ! empty( $field['dynamic_post_type'] ) ) {

			// Dynamic population is enabled using post type.
			$value_raw                 = implode( ',', array_map( 'absint', $field_submit ) );
			$data['value_raw']         = $value_raw;
			$data['dynamic']           = 'post_type';
			$data['dynamic_items']     = $value_raw;
			$data['dynamic_post_type'] = $field['dynamic_post_type'];
			$posts                     = [];

			foreach ( $field_submit as $id ) {
				$post = get_post( $id );

				if ( ! is_wp_error( $post ) && ! empty( $post ) && $data['dynamic_post_type'] === $post->post_type ) {
					$posts[] = esc_html( wpforms_get_post_title( $post ) );
				}
			}

			$data['value'] = ! empty( $posts ) ? wpforms_sanitize_array_combine( $posts ) : '';

		}
		elseif ( 'taxonomy' === $dynamic && ! empty( $field['dynamic_taxonomy'] ) ) {

			// Dynamic population is enabled using taxonomy.
			$value_raw                = implode( ',', array_map( 'absint', $field_submit ) );
			$data['value_raw']        = $value_raw;
			$data['dynamic']          = 'taxonomy';
			$data['dynamic_items']    = $value_raw;
			$data['dynamic_taxonomy'] = $field['dynamic_taxonomy'];
			$terms                    = [];

			foreach ( $field_submit as $id ) {
				$term = get_term( $id, $field['dynamic_taxonomy'] );

				if ( ! is_wp_error( $term ) && ! empty( $term ) ) {
					$terms[] = esc_html( wpforms_get_term_name( $term ) );
				}
			}

			$data['value'] = ! empty( $terms ) ? wpforms_sanitize_array_combine( $terms ) : '';

		} else {

			// Normal processing, dynamic population is off.
			$choice_keys = [];

			// If show_values is true, that means values posted are the raw values
			// and not the labels. So we need to set label values. Also store
			// the choice keys.
			if ( ! empty( $field['show_values'] ) && (int) $field['show_values'] === 1 ) {

				foreach ( $field_submit as $item ) {
					foreach ( $field['choices'] as $key => $choice ) {
						// Check if the submitted value is the same as the choice value or if the value is empty and the key matches.
						// Skip if the submitted value is empty.
						if ( ( ! empty( $item ) && $item === $choice['value'] ) || ( empty( $choice['value'] ) && (int) str_replace( 'Choice ', '', $item ) === $key ) ) {
							$label = $this->get_choices_label( $choice['label'] ?? '', $key, $field );

							// Icon and Image choices resolve an empty label to an empty string: skip it, so the combined value never contains empty lines.
							if ( ! wpforms_is_empty_string( $label ) ) {
								$value[] = $label;
							}

							$choice_keys[] = $key;

							break;
						}
					}
				}

				$data['value'] = ! empty( $value ) ? wpforms_sanitize_array_combine( $value ) : '';

			} else {

				$data['value'] = $value_raw;

				// Determine choices keys, this is needed for image choices.
				foreach ( $field_submit as $item ) {
					foreach ( $field['choices'] as $key => $choice ) {
						$label = isset( $choice['label'] ) ? $choice['label'] : '';

						/* translators: %s - choice number. */
						if ( $item === $label || $item === sprintf( esc_html__( 'Choice %s', 'wpforms-lite' ), $key ) ) {
							$choice_keys[] = $key;

							break;
						}
					}
				}
			}

			// Images choices are enabled, lookup and store image URLs.
			if ( ! empty( $choice_keys ) && ! empty( $field['choices_images'] ) ) {

				$data['images'] = [];

				foreach ( $choice_keys as $key ) {
					$data['images'][] = ! empty( $field['choices'][ $key ]['image'] ) ? esc_url_raw( $field['choices'][ $key ]['image'] ) : '';
				}
			}
		}

		// Handle the Other option when it is enabled and submitted.
		$this->format_other_option( $data, $field, $other_submitted_raw );

		// Push field details to be saved.
		wpforms()->obj( 'process' )->fields[ $field_id ] = $data;
	}
}

new WPForms_Field_Checkbox();
