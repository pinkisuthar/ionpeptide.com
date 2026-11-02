<?php

namespace WPForms\Emails\Summaries\Slot\Blocks;

use WPForms\Emails\Summaries\Slot\AbstractBlock;
use WPForms\Emails\Summaries\Slot\BlockState;
use WPForms\Emails\Summaries\Slot\Context;
use WPForms\Emails\Summaries\Slot\Pipeline;
use WPForms\Pro\Emails\LicenseBanner;

/**
 * License notice: expiring, expired or invalid license. Pro edition only.
 *
 * The rules and copy stay in LicenseBanner; this block only places them in the top slot.
 *
 * @since 2.0.3
 */
class LicenseNotice extends AbstractBlock {

	/**
	 * Block id.
	 *
	 * @since 2.0.3
	 */
	public const ID = 'license_notice';

	/**
	 * Slot.
	 *
	 * @since 2.0.3
	 */
	public const SLOT = Pipeline::SLOT_TOP;

	/**
	 * Priority.
	 *
	 * @since 2.0.3
	 */
	public const PRIORITY = 10;

	/**
	 * Variant shown when the preview forces the block.
	 *
	 * @since 2.0.3
	 */
	private const PREVIEW_VARIANT = 'expire_soon';

	/**
	 * Visible when the license needs attention; the variant is the license status.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 *
	 * @return BlockState
	 */
	public function get_state( Context $context ): BlockState {

		if ( ! $context->is_pro() ) {
			return new BlockState( false );
		}

		$banner = new LicenseBanner();

		if ( ! $banner->has_license() ) {
			return new BlockState( false );
		}

		$status = $banner->get_license_status();

		return new BlockState( $status !== '', $status );
	}

	/**
	 * Banner arguments.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 * @param string  $variant License status.
	 *
	 * @return array
	 */
	public function get_args( Context $context, string $variant ): array {

		if ( ! $context->is_pro() ) {
			return [];
		}

		$args = ( new LicenseBanner() )->generate_license_banner_args( $variant !== '' ? $variant : self::PREVIEW_VARIANT );

		// The help link is already inside the content, with a better label.
		return [
			'title'   => $args['title'] ?? '',
			'content' => $args['content'] ?? [],
			'main'    => [
				'text' => $args['cta']['text'] ?? '',
				'url'  => $args['cta']['url'] ?? '',
			],
		];
	}
}
