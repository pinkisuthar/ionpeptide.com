<?php

namespace WPForms\Emails\Summaries\Slot\Blocks;

use WPForms\Emails\Summaries\Slot\AbstractBlock;
use WPForms\Emails\Summaries\Slot\BlockState;
use WPForms\Emails\Summaries\Slot\Context;
use WPForms\Emails\Summaries\Slot\Pipeline;
use WPForms\Integrations\LiteConnect\LiteConnect;

/**
 * Lite Connect prompt: enable backups, or upgrade to keep them. Lite edition only.
 *
 * @since 2.0.3
 */
class LiteConnectPrompt extends AbstractBlock {

	/**
	 * Block id.
	 *
	 * @since 2.0.3
	 */
	public const ID = 'lite_connect';

	/**
	 * Slot.
	 *
	 * @since 2.0.3
	 */
	public const SLOT = Pipeline::SLOT_MIDDLE;

	/**
	 * Priority.
	 *
	 * @since 2.0.3
	 */
	public const PRIORITY = 20;

	/**
	 * Visible on Lite; the variant says whether Lite Connect is on.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 *
	 * @return BlockState
	 */
	public function get_state( Context $context ): BlockState {

		return new BlockState( ! $context->is_pro(), LiteConnect::is_enabled() ? 'enabled' : 'disabled' );
	}

	/**
	 * Copy and links for the variant.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 * @param string  $variant Either `enabled` or `disabled`.
	 *
	 * @return array
	 */
	public function get_args( Context $context, string $variant ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		$icons = WPFORMS_PLUGIN_URL . 'assets/images/email/';
		$base  = [
			'layout'      => 'bar',
			'icon'        => $icons . 'icon-cloud-arrow-up.png',
			'icon_width'  => 28,
			'icon_height' => 22,
			// The dark design inverts the circle, which CSS cannot do to an image, so it ships whole.
			'icon_dark'   => $icons . 'icon-cloud-arrow-up-dark.png',
		];

		if ( $variant === 'enabled' ) {
			$upgrade_url = self::utm_link( 'https://wpforms.com/pricing-lite/', 'Upgrade - With LC' );

			return $base + [
				'title'   => __( 'Your Entries Are Being Backed Up, but Only for One Year', 'wpforms-lite' ),
				'content' => sprintf(
					/* translators: %s - Upgrade to Pro URL. */
					__( 'Lite Connect is saving your entries securely in the cloud. <a href="%s" target="_blank" rel="noopener noreferrer">Upgrade to Pro</a> to keep them permanently and manage them right inside WordPress.', 'wpforms-lite' ),
					esc_url( $upgrade_url )
				),
			];
		}

		$enable_url  = self::utm_link( 'https://wpforms.com/docs/how-to-use-lite-connect-for-wpforms/', 'Documentation#backup-with-lite-connect' );
		$upgrade_url = self::utm_link( 'https://wpforms.com/pricing-lite/', 'Upgrade - Without LC' );

		return $base + [
			'title'   => __( 'Your Entries Aren’t Being Backed Up', 'wpforms-lite' ),
			'content' => sprintf(
				/* translators: %1$s - Enable Lite Connect URL, %2$s - Upgrade to Pro URL. */
				__( '<a href="%1$s" target="_blank" rel="noopener noreferrer">Enable Lite Connect</a> to start storing entries. When you’re ready to manage them inside WordPress, <a href="%2$s" target="_blank" rel="noopener noreferrer">Upgrade to Pro</a> and we’ll import them in seconds!', 'wpforms-lite' ),
				esc_url( $enable_url ),
				esc_url( $upgrade_url )
			),
		];
	}
}
