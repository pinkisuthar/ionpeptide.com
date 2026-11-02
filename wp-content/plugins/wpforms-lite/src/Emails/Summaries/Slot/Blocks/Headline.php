<?php

namespace WPForms\Emails\Summaries\Slot\Blocks;

use WPForms\Emails\Summaries\Data;
use WPForms\Emails\Summaries\Headlines;
use WPForms\Emails\Summaries\Slot\AbstractBlock;
use WPForms\Emails\Summaries\Slot\BlockState;
use WPForms\Emails\Summaries\Slot\Context;
use WPForms\Emails\Summaries\Slot\Pipeline;

/**
 * Data-driven headline: the most notable thing that happened this week.
 *
 * @since 2.0.3
 */
class Headline extends AbstractBlock {

	/**
	 * Block id.
	 *
	 * @since 2.0.3
	 */
	public const ID = 'headline';

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
	public const PRIORITY = 40;

	/**
	 * Tier rules and anti-repeat store.
	 *
	 * @since 2.0.3
	 *
	 * @var Headlines
	 */
	private $headlines;

	/**
	 * Headline resolved by get_state().
	 *
	 * @since 2.0.3
	 *
	 * @var array|null
	 */
	private $headline;

	/**
	 * Constructor.
	 *
	 * @since 2.0.3
	 *
	 * @param Headlines|null $headlines Rules and store. Defaults to a new instance.
	 */
	public function __construct( ?Headlines $headlines = null ) {

		$this->headlines = $headlines ?? new Headlines();
	}

	/**
	 * Visible when a tier qualifies; the variant is the tier key.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 *
	 * @return BlockState
	 */
	public function get_state( Context $context ): BlockState {

		if ( ! $context->has_entries() ) {
			return new BlockState( false );
		}

		$week    = $context->get_week();
		$history = static function () use ( $week ): array {
			return Data::resolve()->get_weekly_by_form( $week, Headlines::HISTORY_WEEKS );
		};

		$this->headline = $this->headlines->resolve( $context->get_data(), $history, $context->is_pro(), self::SLOT );

		return new BlockState( $this->headline !== null, (string) ( $this->headline['key'] ?? '' ) );
	}

	/**
	 * Headline copy; a forced preview renders the requested tier with sample numbers.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 * @param string  $variant Tier key.
	 *
	 * @return array
	 */
	public function get_args( Context $context, string $variant ): array {

		if ( $this->headline !== null && $this->headline['key'] === $variant ) {
			return $this->headline;
		}

		$forms = (array) $context->get( 'forms', [] );
		$first = reset( $forms );

		return $this->headlines->build(
			max( 1, Headlines::tier( $variant ) ),
			[
				'form' => $first['title'] ?? __( 'Contact Form', 'wpforms-lite' ),
				'n'    => max( (int) $context->get( 'entries', 0 ), 12 ),
				'pct'  => 45,
			]
		);
	}

	/**
	 * The tier key, e.g. `headline_tier_3`.
	 *
	 * @since 2.0.3
	 *
	 * @param string $variant Tier key.
	 *
	 * @return string
	 */
	public function get_key( string $variant ): string {

		return $variant !== '' ? $variant : Headlines::key( 1 );
	}
}
