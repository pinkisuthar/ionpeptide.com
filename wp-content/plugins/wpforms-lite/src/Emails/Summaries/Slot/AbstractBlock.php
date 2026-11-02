<?php

namespace WPForms\Emails\Summaries\Slot;

/**
 * Block defaults: identity from constants, always visible, nothing to record on send.
 *
 * @since 2.0.3
 */
abstract class AbstractBlock implements Block {

	/**
	 * Block id.
	 *
	 * @since 2.0.3
	 */
	public const ID = '';

	/**
	 * Slot.
	 *
	 * @since 2.0.3
	 */
	public const SLOT = Pipeline::SLOT_TOP;

	/**
	 * Priority within the slot.
	 *
	 * @since 2.0.3
	 */
	public const PRIORITY = 100;

	/**
	 * UTM medium every summary email link carries.
	 *
	 * @since 2.0.3
	 */
	public const UTM_MEDIUM = 'Weekly Summary Email';

	/**
	 * UTM campaign the summary email admin links carry.
	 *
	 * @since 2.0.3
	 */
	public const UTM_CAMPAIGN = 'weekly-summary';

	/**
	 * Unique block id.
	 *
	 * @since 2.0.3
	 *
	 * @return string
	 */
	public function get_id(): string {

		return static::ID;
	}

	/**
	 * The id, whatever the variant.
	 *
	 * @since 2.0.3
	 *
	 * @param string $variant Variant rendered.
	 *
	 * @return string
	 */
	public function get_key( string $variant ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		return static::ID;
	}

	/**
	 * Slot the block renders in.
	 *
	 * @since 2.0.3
	 *
	 * @return string
	 */
	public function get_slot(): string {

		return static::SLOT;
	}

	/**
	 * Priority within the slot.
	 *
	 * @since 2.0.3
	 *
	 * @return int
	 */
	public function get_priority(): int {

		return static::PRIORITY;
	}

	/**
	 * Visible by default, single variant.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 *
	 * @return BlockState
	 */
	public function get_state( Context $context ): BlockState { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		return new BlockState( true );
	}

	/**
	 * Whether the block qualifies for display based on entry count and force preview flags.
	 *
	 * Zero-entry weeks suppress promotional blocks unless forced in preview.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 *
	 * @return bool
	 */
	protected function qualifies_for_entries( Context $context ): bool {

		return $context->has_entries() || $context->is_forced( static::ID );
	}

	/**
	 * Nothing to record by default.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 * @param string  $variant Variant that was sent.
	 */
	public function on_sent( Context $context, string $variant ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
	}

	/**
	 * Admin URL carrying the summary email UTM parameters.
	 *
	 * The single builder for every admin link the weekly summary and its re-engagement
	 * alert send out, so the parameter set stays identical across them.
	 *
	 * @since 2.0.3
	 *
	 * @param string $admin_path Path relative to wp-admin, or to network admin when `$network` is true.
	 * @param string $content    UTM content identifier.
	 * @param string $campaign   UTM campaign, for the alerts that report under their own one.
	 * @param bool   $network    Whether the destination is the network admin.
	 *
	 * @return string
	 */
	public static function admin_utm_url( string $admin_path, string $content, string $campaign = self::UTM_CAMPAIGN, bool $network = false ): string {

		$base = $network ? network_admin_url( $admin_path ) : admin_url( $admin_path );

		return add_query_arg(
			[
				'utm_source'   => 'wpforms-plugin',
				'utm_medium'   => 'email',
				'utm_campaign' => $campaign,
				'utm_content'  => $content,
			],
			$base
		);
	}

	/**
	 * Marketing site URL carrying the summary email UTM parameters.
	 *
	 * @since 2.0.3
	 *
	 * @param string $url     Destination URL.
	 * @param string $content UTM content identifier.
	 *
	 * @return string
	 */
	protected static function utm_link( string $url, string $content ): string {

		return wpforms_utm_link( $url, self::UTM_MEDIUM, $content );
	}
}
