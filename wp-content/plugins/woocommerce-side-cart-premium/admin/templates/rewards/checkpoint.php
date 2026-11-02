<script type="text/html" id="tmpl-xoo-as-chkpoint">

	<?php $id = $base_id.'[checkpoints][%#]' ?>

	<div class="xoo-wsc-bar-chkpoint xoo-wsc-accordion" data-type="{{data.type}}">

		<div class="xoo-wsc-acc-head"><span class="dashicons dashicons-plus-alt2"></span><span class="dashicons dashicons-minus"></span> <div class="xoo-wsc-chkpoint-title">{{data.title}}</div> <span class="dashicons dashicons-trash xoo-wsc-checkpoint-delete"></span></div>

		<div class="xoo-wsc-acc-cont xoo-wsc-chkpoint-settings">

			<input type="hidden" name="<?php echo $id ?>[type]" value="{{data.type}}">


			<# if ( data.type === "freeshipping" ) { #>
			<div class="xoo-scbhk-ship-title">
				<i>The checkpoint amount is fetched from Free shipping method ( woocommerce shipping settings ).<br> Please make sure you have a free shipping method available for customers' location.<br><a href="https://docs.xootix.com/side-cart-for-woocommerce/#shippingbar" target="__blank">Read more</a></i><br>
			</div>
			<# } #>

			<div class="xoo-wsc-chkpoint-setting">
				<input type="hidden" name="<?php echo $id ?>[enable]" value="no">
				<label><input type="checkbox" value="yes" name="<?php echo $id ?>[enable]" {{ data.enable == 'yes' ? 'checked' : '' }}> Enable</label>
			</div>

			

			<div class="xoo-wsc-chkpoint-setting">
				<label>Title</label>
				<input type="text" value="{{data.title}}" name="<?php echo $id ?>[title]" class="xoo-wsc-chkpoint-title-input">
			</div>

			<div class="xoo-wsc-chkpoint-setting">
				<label>Remaining Text</label>
				<input type="text" value="{{data.remaining}}" name="<?php echo $id ?>[remaining]">
				<span class="xoo-scbhk-desc">[value] is the remaining value to unlock this checkpoint</span>
			</div>

			

			<# if ( data.type !== "freeshipping" ) { #>
			<div class="xoo-wsc-chkpoint-setting">
				<label>Checkpoint Value</label>
				<input type="number" value="{{data.amount}}" step="any" name="<?php echo $id ?>[amount]">
				<span class="xoo-scbhk-desc">Value required to achieve this reward</span>
			</div>
			<# } #>

			

			<# if ( data.type === "gift" ) { #>

			<div class="xoo-wsc-chkpoint-setting xoo-wsc-bar-prodsearch">

				<label>Free Gift Products</label>

				<select class="wc-product-search" multiple="multiple" name="<?php echo $id ?>[gift_ids][]" data-placeholder="<?php esc_attr_e( 'Search for a product&hellip;', 'woocommerce' ); ?>" data-action="woocommerce_json_search_products_and_variations">
				</select>

				<div class="xoo-wsc-barpsearch-defaults">
					<# _.each( data.gift_ids , function(option_value, index) { #>
						<input type="hidden" name="<?php echo $id ?>[gift_ids][]" value="{{option_value}}">
					<# }) #>
				</div>

				<span class="xoo-scbhk-desc">Add gift products</span>

			</div>


			<div class="xoo-wsc-chkpoint-setting">
				<label>Gift Quantity</label>
				<input type="number" value="{{data.gift_qty}}" step="any" name="<?php echo $id ?>[gift_qty]">
			</div>

			<div class="xoo-wsc-chkpoint-setting">
				<input type="hidden" name="<?php echo $id ?>[showcase]" value="no">
				<label><input type="checkbox" value="yes" name="<?php echo $id ?>[showcase]" {{ data.showcase == 'yes' ? 'checked' : '' }}> Showcase Gifts<span class="xoo-scbhk-desc"> (If disabled, products will be kept as a suprise)</span></label>

			</div>


			<# } #>


			<# if ( data.type === "discount" ) { #>
				<div class="xoo-wsc-chkpoint-setting">
					<label>Discount (In %)</label>
					<input type="number" value="{{data.discount}}" step="any" name="<?php echo $id ?>[discount]">
				</div>
			<# } #>
				

			<div class="xoo-wsc-bar-setgroup xoo-wsc-barset-full">

				<div class="xoo-wsc-chkpoint-setting">
					<label>Icon</label>
					<div>
						<input type="text" value="{{data.icon}}" name="<?php echo $id ?>[icon]" class="xoo-wsc-bar-icon">
						<i></i>
					</div>
				</div>

				<div class="xoo-wsc-chkpoint-setting">
					<label>Checkpoint Achieved Icon</label>
					<div>
						<input type="text" value="{{data.iconFilled}}" name="<?php echo $id ?>[iconFilled]" class="xoo-wsc-bar-icon">
						<i></i>
					</div>
				</div>

			</div>

			
		</div>

	</div>

</script>