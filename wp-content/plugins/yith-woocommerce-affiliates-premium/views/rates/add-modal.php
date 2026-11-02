<?php
/**
 * Add new rate rule modal
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Views
 * @version 2.0.0
 */

/**
 * Template variables:
 *
 * @var $this               YITH_WCAF_Rates_Admin_Panel
 * @var $product_categories array
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<script type="text/template" id="tmpl-yith-wcaf-add-rate-rule-modal">
	<div id="add_affiliate_modal">
		<form method="post" action="<?php echo esc_url( YITH_WCAF_Admin_Actions::get_action_url( 'create_rule' ) ); ?>">
			<div class="form-row form-row-wide required">
				<label for="name">
					<?php echo esc_html_x( 'Rule name', '[ADMIN] Add rate rule modal', 'yith-woocommerce-affiliates' ); ?>
				</label>
				<input type="text" name="name" id="name" value="{{data.name}}"/>
			</div>
			<div class="form-row form-row-wide required">
				<label for="type">
					<?php echo esc_html_x( 'Rule type', '[ADMIN] Add rate rule modal', 'yith-woocommerce-affiliates' ); ?>
				</label>
				<select class="wc-enhanced-select" name="type" id="type" style="width: 100%" data-value="{{data.type}}">
					<?php foreach ( YITH_WCAF_Rate_Handler_Premium::get_supported_rule_types() as $field_type => $type_label ) : ?>
						<option value="<?php echo esc_attr( $field_type ); ?>"><?php echo esc_html( $type_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="form-row form-row-wide required">
				<label for="affiliate_ids">
					<?php echo esc_html_x( 'Search affiliate', '[ADMIN] Add rate rule modal', 'yith-woocommerce-affiliates' ); ?>
				</label>
				<select
					class="yith-wcaf-enhanced-select"
					name="affiliate_ids"
					id="affiliate_ids"
					style="width: 100%"
					multiple="multiple"
					data-value="{{data.affiliates}}"
					data-action="yith_wcaf_get_affiliates_ids"
					data-security="<?php echo esc_attr( wp_create_nonce( 'search-affiliates' ) ); ?>"
					data-placeholder="<?php echo esc_attr_x( 'Search affiliate', '[ADMIN] Add rate rule modal', 'yith-woocommerce-affiliates' ); ?>"
					data-dependencies='{"type":["affiliate_ids","affiliate_product_ids"]}'
				></select>
			</div>
			<div class="form-row form-row-wide required">
				<label for="product_ids">
					<?php echo esc_html_x( 'Search products', '[ADMIN] Add Affiliate modal', 'yith-woocommerce-affiliates' ); ?>
				</label>
				<select
					class="wc-product-search"
					name="product_ids"
					id="product_ids"
					style="width: 100%"
					multiple="multiple"
					data-value="{{data.products}}"
					data-placeholder="<?php echo esc_attr_x( 'Search products', '[ADMIN] Add rate rule modal', 'yith-woocommerce-affiliates' ); ?>"
					data-dependencies='{"type":["product_ids","affiliate_product_ids"]}'
				></select>
			</div>
			<div class="form-row form-row-wide required">
				<label for="product_categories">
					<?php echo esc_html_x( 'Search product categories', '[ADMIN] Add rate rule modal', 'yith-woocommerce-affiliates' ); ?>
				</label>
				<select
					class="wc-enhanced-select"
					name="product_categories"
					id="product_categories"
					style="width: 100%"
					multiple="multiple"
					data-value="{{data.product_categories}}"
					data-placeholder="<?php echo esc_attr_x( 'Search categories', '[ADMIN] Add rate rule modal', 'yith-woocommerce-affiliates' ); ?>"
					data-dependencies='{"type":"product_categories"}'
				>
					<?php foreach ( $product_categories as $category_id => $category_name ) : ?>
						<option value="<?php echo esc_attr( $category_id ); ?>"><?php echo esc_html( $category_name ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="form-row form-row-wide required">
				<label for="user_roles">
					<?php echo esc_html_x( 'Search user roles', '[ADMIN] Add rate rule modal', 'yith-woocommerce-affiliates' ); ?>
				</label>
				<select
					class="wc-enhanced-select"
					name="user_roles"
					id="user_roles"
					style="width: 100%"
					multiple="multiple"
					data-value="{{data.user_roles}}"
					data-placeholder="<?php echo esc_attr_x( 'Search roles', '[ADMIN] Add rate rule modal', 'yith-woocommerce-affiliates' ); ?>"
					data-dependencies='{"type":"user_roles"}'
				>
					<?php wp_dropdown_roles(); ?>
				</select>
			</div>
			<div class="form-row form-row-wide required">
				<label for="rate">
					<?php echo esc_html_x( 'Rate', '[ADMIN] Add rate rule modal', 'yith-woocommerce-affiliates' ); ?>
				</label>
				<?php
				/**
				 * APPLY_FILTERS: yith_wcaf_max_rate_value
				 *
				 * Filters the maximum rate value.
				 *
				 * @param int $max_rate_value Maximum rate value.
				 */
				?>
				<input type="number" min="0" max="<?php echo esc_attr( apply_filters( 'yith_wcaf_max_rate_value', 100 ) ); ?>" step="0.01" name="rate" id="rate" value="{{data.rate}}"/><span class="inline-description">%</span>
				<span class="description">
					<?php echo esc_html_x( 'When this rule is applied, commissions will be calculated as this percentage of the item\'s cost.', '[ADMIN] Add rate rule modal', 'yith-woocommerce-affiliates' ); ?>
				</span>
			</div>
			<div class="form-row form-row-wide">
				<label for="priority">
					<?php echo esc_html_x( 'Priority', '[ADMIN] Add rate rule modal', 'yith-woocommerce-affiliates' ); ?>
				</label>
				<input type="number" min="0" step="1" name="priority" id="priority" value="{{data.priority}}"/>
				<span class="description">
					<?php
					echo wp_kses_post(
						sprintf(
							// translators: 1. Url to documentation page about rules.
							_x(
								'Priority is used to determine what rule to apply when the system finds multiple matches for the same item; the smaller the value, the more important the rule.
								<a target="_blank" href="%s">Read the documentation to better understand how rules work ></a>',
								'[ADMIN] Add rate rule modal',
								'yith-woocommerce-affiliates'
							),
							$this->rules_doc
						)
					);
					?>
				</span>
			</div>
			<div class="form-row form-row-wide submit">
				<button class="submit button-primary">
					<# if ( data.id ) { #>
					<?php echo esc_html_x( 'Save rule', '[ADMIN] Add rate rule modal', 'yith-woocommerce-affiliates' ); ?>
					<# } else { #>
					<?php echo esc_html_x( 'Add rule', '[ADMIN] Add rate rule modal', 'yith-woocommerce-affiliates' ); ?>
					<# } #>
				</button>
				<input type="hidden" name="id" value="{{data.id}}"/>
				<input type="hidden" name="enabled" value="{{data.enabled}}" data-value="{{data.enabled}}"/>
			</div>
		</form>
	</div>
</script>
