<?php

namespace WPForms\Emails\Summaries\Slot\Blocks;

use WPForms\Emails\Summaries\Slot\AbstractBlock;
use WPForms\Emails\Summaries\Slot\BlockState;
use WPForms\Emails\Summaries\Slot\Context;
use WPForms\Emails\Summaries\Slot\Pipeline;
use WPForms\Pro\Emails\ReengagementAlert;

/**
 * Activity drop-off and zero-entry re-engagement alert. Pro edition only.
 *
 * The detection rules and copy stay in ReengagementAlert; this block only places them in the top slot.
 *
 * @since 2.0.3
 */
class ActivityDropoff extends AbstractBlock {

	/**
	 * Block id.
	 *
	 * @since 2.0.3
	 */
	public const ID = 'activity_dropoff';

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
	public const PRIORITY = 20;

	/**
	 * Alert arguments resolved by get_state(), so the detection queries run once.
	 *
	 * @since 2.0.3
	 *
	 * @var array|null
	 */
	private $args;

	/**
	 * Visible when a re-engagement state applies; the variant is its slug.
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

		$this->args = ( new ReengagementAlert() )->get_alert_args( $context->get_week() );

		return new BlockState( $this->args !== null, (string) ( $this->args['slug'] ?? '' ) );
	}

	/**
	 * Alert arguments. A forced preview honors `$variant` so tenure copy can be inspected.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 * @param string  $variant Alert slug.
	 *
	 * @return array
	 */
	public function get_args( Context $context, string $variant ): array {

		if ( ! $context->is_pro() ) {
			return [];
		}

		if ( $this->args === null || $context->is_forced( self::ID ) ) {
			$this->args = ( new ReengagementAlert() )->build_variant_args( $variant, $context->get_week() );
		}

		$args         = $this->args;
		$args['main'] = [
			'text' => $args['button_text'] ?? '',
			'url'  => $args['button_url'] ?? '',
		];

		unset( $args['button_text'], $args['button_url'] );

		return $args;
	}
}
