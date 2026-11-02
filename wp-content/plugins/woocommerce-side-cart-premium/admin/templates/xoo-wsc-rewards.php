<div class="xoo-wsc-rewards-cont">

	<span class="xoo-wsc-rwdhead">Progress Bars & Rewards</span>

	<div class="xoo-wsc-rwenb-cont">
		<div class="xoo-as-field" bis_skin_checked="1">
			<label class="xoo-as-switch">
				<input type="hidden" name="xoo-wsc-rewards-options[scbar-en]" value="no">
				<input name="xoo-wsc-rewards-options[scbar-en]" type="checkbox" value="yes" <?php echo xoo_wsc_helper()->get_rewards_option('scbar-en') === "yes" ? 'checked' : ''; ?>><span class="xoo-as-slider"></span>
			</label>
		</div>
	</div>

	<button type="button" class="button button-primary xoo-wsc-add-bar">+ Add a new progress bar</button>

	<div class="xoo-wsc-bars"></div>

</div>