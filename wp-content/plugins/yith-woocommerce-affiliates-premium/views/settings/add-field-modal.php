<?php
/**
 * Add new affiliate profile field modal
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Views
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<script type="text/template" id="tmpl-yith-wcaf-add-affiliate-field-modal">
	<div id="add_affiliate_field_modal">
		<# if ( data.reserved ) { #>
		<p>
			<?php echo wp_kses_post( _x( '<b>Note:</b> this field is protected as it is required for the registration form to work correctly. You can change its basic information, but you cannot delete or clone it.', '[ADMIN] Add Affiliate field modal', 'yith-woocommerce-affiliates' ) ); ?>
		</p>
		<# } #>
		<form method="post">
			<# if ( ! data.reserved ) { #>
			<div class="form-row form-row-inline required">
				<label for="name">
					<?php echo esc_html_x( 'Field name', '[ADMIN] Add Affiliate field modal', 'yith-woocommerce-affiliates' ); ?>
				</label>
				<input type="text" name="name" id="name" value="{{data.name}}"/>
			</div>
			<# } #>
			<# if ( ! data.reserved ) { #>
			<div class="form-row form-row-inline required">
				<label for="type">
					<?php echo esc_html_x( 'Field type', '[ADMIN] Add Affiliate field modal', 'yith-woocommerce-affiliates' ); ?>
				</label>
				<select name="type" id="type" class="wc-enhanced-select" data-value="{{data.type}}">
					<?php foreach ( YITH_WCAF_Affiliates_Profile::get_supported_field_types() as $field_value => $field_label ) : ?>
						<option value="<?php echo esc_attr( $field_value ); ?>"><?php echo esc_html( $field_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<# } #>
			<div class="form-row form-row-inline required">
				<label for="label">
					<?php echo esc_html_x( 'Field label', '[ADMIN] Add Affiliate field modal', 'yith-woocommerce-affiliates' ); ?>
				</label>
				<input type="text" name="label" id="label" value="{{data.label}}"/>
			</div>
			<div class="form-row form-row-inline">
				<label for="admin_label">
					<?php echo esc_html_x( 'Admin label', '[ADMIN] Add Affiliate field modal', 'yith-woocommerce-affiliates' ); ?>
				</label>
				<input type="text" name="admin_label" id="admin_label" value="{{data.admin_label}}" data-copy-target="#label"/>
			</div>
			<div class="form-row form-row-inline">
				<label for="error_message">
					<?php echo esc_html_x( 'Error message', '[ADMIN] Add Affiliate field modal', 'yith-woocommerce-affiliates' ); ?>
				</label>
				<input type="text" name="error_message" id="error_message" value="{{data.error_message}}"/>
			</div>
			<div class="form-row form-row-inline">
				<label for="class">
					<?php echo esc_html_x( 'CSS classes', '[ADMIN] Add Affiliate field modal', 'yith-woocommerce-affiliates' ); ?>
				</label>
				<input type="text" name="class" id="class" value="{{data.class}}"/>
			</div>
			<div class="form-row form-row-inline">
				<label for="label_class">
					<?php echo esc_html_x( 'CSS classes label', '[ADMIN] Add Affiliate field modal', 'yith-woocommerce-affiliates' ); ?>
				</label>
				<input type="text" name="label_class" id="label_class" value="{{data.label_class}}"/>
			</div>
			<# if ( ! data.reserved ) { #>
			<div class="form-row form-row-inline required">
				<label for="validation">
					<?php echo esc_html_x( 'Validation', '[ADMIN] Add Affiliate field modal', 'yith-woocommerce-affiliates' ); ?>
				</label>
				<select name="validation" id="validation" class="wc-enhanced-select" data-value="{{data.validation}}">
					<?php foreach ( YITH_WCAF_Affiliates_Profile::get_supported_field_validations() as $field_value => $field_label ) : ?>
						<option value="<?php echo esc_attr( $field_value ); ?>"><?php echo esc_html( $field_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<# } #>
			<# if ( ! data.reserved ) { #>
			<div class="form-row form-row-inline" id="options_table_container">
				<label for="options">
					<?php echo esc_html_x( 'Options', '[ADMIN] Add Affiliate field modal', 'yith-woocommerce-affiliates' ); ?>
				</label>
				<div class="inline-table">
					<table class="form-table" id="options_table" data-dependencies='{"type":["radio","select","multicheck"]}'>
						<thead>
						<tr>
							<th class="column-label">
								<?php echo esc_html_x( 'Label', '[ADMIN] Add Affiliate field modal', 'yith-woocommerce-affiliates' ); ?>
							</th>
							<th class="column-value">
								<?php echo esc_html_x( 'Value', '[ADMIN] Add Affiliate field modal', 'yith-woocommerce-affiliates' ); ?>
							</th>
							<th class="column-actions"></th>
						</tr>
						</thead>
						<tbody>

						</tbody>
					</table>
					<a href="#" role="button" id="add_new_option">
						<?php echo esc_html_x( '+ Add new option', '[ADMIN] Add Affiliate field modal', 'yith-woocommerce-affiliates' ); ?>
					</a>
				</div>
			</div>
			<# } #>
			<# if ( ! data.reserved ) { #>
			<?php foreach ( YITH_WCAF_Affiliates_Profile::get_supported_show_locations() as $location => $location_name ) : ?>
				<div class="form-row form-row-inline">
					<label for="show_in_<?php echo esc_attr( $location ); ?>">
						<?php echo esc_html( $location_name ); ?>
					</label>
					<?php
					yith_plugin_fw_get_field(
						array(
							'id'                => "show_in_$location",
							'name'              => "show_in[$location]",
							'value'             => "{{data.show_in?.$location}}",
							'type'              => 'onoff',
							'custom_attributes' => array(
								'data-value' => "{{data.show_in?.$location}}",
							),
						),
						true
					);
					?>
				</div>
			<?php endforeach; ?>
			<# } #>
			<# if ( ! data.reserved ) { #>
			<div class="form-row form-row-inline">
				<label for="required">
					<?php echo esc_html_x( 'Make it mandatory', '[ADMIN] Add Affiliate field modal', 'yith-woocommerce-affiliates' ); ?>
				</label>
				<?php
				yith_plugin_fw_get_field(
					array(
						'id'                => 'required',
						'name'              => 'required',
						'value'             => '{{data.required}}',
						'type'              => 'onoff',
						'custom_attributes' => array(
							'data-value' => '{{data.required}}',
						),
					),
					true
				);
				?>
			</div>
			<# } #>
			<div class="form-row form-row-wide submit">
				<button class="submit button-primary">
					<# if ( data.name ) { #>
					<?php echo esc_html_x( 'Save form field', '[ADMIN] Add Affiliate field modal', 'yith-woocommerce-affiliates' ); ?>
					<# } else { #>
					<?php echo esc_html_x( 'Add field to the form', '[ADMIN] Add Affiliate field modal', 'yith-woocommerce-affiliates' ); ?>
					<# } #>
				</button>
				<input type="hidden" name="prev_name" value="{{data.name}}"/>
				<input type="hidden" name="enabled" value="{{data.enabled}}" data-value="{{data.enabled}}"/>
			</div>
		</form>
	</div>
</script>

<script type="text/template" id="tmpl-yith-wcaf-add-affiliate-field-option">
	<tr>
		<td class="column-label form-row required">
			<input type="text" name="options[option_{{data.id}}][label]" id="options_{{data.id}}_label" value="{{data.label}}"/>
		</td>
		<td class="column-value form-row required">
			<input type="text" name="options[option_{{data.id}}][value]" id="options_{{data.id}}_value" value="{{data.value}}"/>
		</td>
		<td class="column-actions">
			<span class="drag yith-icon yith-icon-drag"></span>
			<a href="#" role="button" class="delete yith-icon yith-icon-trash"></a>
		</td>
	</tr>
</script>
