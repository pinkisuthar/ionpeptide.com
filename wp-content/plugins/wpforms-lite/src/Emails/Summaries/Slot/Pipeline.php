<?php

namespace WPForms\Emails\Summaries\Slot;

use WPForms\Emails\Summaries\ShownBlocks;
use WPForms\Emails\Summaries\Tracking;

/**
 * Resolves which blocks occupy each slot of the weekly summary.
 *
 * The top and middle slots are exclusive: blocks are walked by priority and the first
 * visible one wins. The bottom slot shows every visible block.
 *
 * @since 2.0.3
 */
class Pipeline {

	/**
	 * Slot above the stat tiles.
	 *
	 * Headlines and milestones replace the intro. Activity dropoff sits between
	 * the intro and the tiles. The license notice sits above the white card.
	 *
	 * @since 2.0.3
	 */
	public const SLOT_TOP = 'top';

	/**
	 * Slot between the stat tiles and the forms table.
	 *
	 * @since 2.0.3
	 */
	public const SLOT_MIDDLE = 'middle';

	/**
	 * Slot below the forms table.
	 *
	 * @since 2.0.3
	 */
	public const SLOT_BOTTOM = 'bottom';

	/**
	 * Slots that show exactly one block.
	 *
	 * @since 2.0.3
	 */
	private const EXCLUSIVE_SLOTS = [ self::SLOT_TOP, self::SLOT_MIDDLE ];

	/**
	 * Registered blocks keyed by id, sorted by priority.
	 *
	 * @since 2.0.3
	 *
	 * @var Block[]
	 */
	private $blocks = [];

	/**
	 * Shown-blocks history, null when the one the context carries is used.
	 *
	 * @since 2.0.3
	 *
	 * @var ShownBlocks|null
	 */
	private $shown;

	/**
	 * Usage tracking counters.
	 *
	 * @since 2.0.3
	 *
	 * @var Tracking
	 */
	private $tracking;

	/**
	 * Constructor.
	 *
	 * @since 2.0.3
	 *
	 * @param Block[]          $blocks   Blocks to register.
	 * @param ShownBlocks|null $shown    Shown-blocks history. Defaults to the one the context carries.
	 * @param Tracking|null    $tracking Usage tracking counters. Defaults to the registered service.
	 */
	public function __construct( array $blocks, ?ShownBlocks $shown = null, ?Tracking $tracking = null ) {

		$this->shown = $shown;

		$this->tracking = $tracking ?? wpforms()->obj( 'emails_summaries_tracking' );

		// The block list comes from a public filter, so anything else third-party code added is dropped.
		$blocks = array_filter(
			$blocks,
			static function ( $block ): bool {

				return $block instanceof Block;
			}
		);

		usort(
			$blocks,
			static function ( Block $a, Block $b ): int {

				return $a->get_priority() <=> $b->get_priority();
			}
		);

		foreach ( $blocks as $block ) {
			$id = $block->get_id();

			if ( isset( $this->blocks[ $id ] ) ) {
				wpforms_log(
					'Weekly summary email',
					sprintf( 'Two blocks are registered with the id "%s", only the last one competes for its slot.', $id ),
					[ 'type' => [ 'error' ] ]
				);
			}

			$this->blocks[ $id ] = $block;
		}
	}

	/**
	 * Resolve every slot.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 *
	 * @return array Exclusive slots hold one resolved block or an empty array, the bottom slot a list.
	 */
	public function resolve_all( Context $context ): array {

		return [
			self::SLOT_TOP    => $this->resolve( self::SLOT_TOP, $context )[0] ?? [],
			self::SLOT_MIDDLE => $this->resolve( self::SLOT_MIDDLE, $context )[0] ?? [],
			self::SLOT_BOTTOM => $this->resolve( self::SLOT_BOTTOM, $context ),
		];
	}

	/**
	 * Resolve one slot.
	 *
	 * A block the preview forces wins its slot outright. The arguments of every resolved block
	 * are normalized, so the partials render what they get.
	 *
	 * @since 2.0.3
	 *
	 * @param string  $slot    Slot to resolve.
	 * @param Context $context Summary context.
	 *
	 * @return array List of resolved blocks: `id`, `key`, `slot`, `variant`, `args`.
	 */
	public function resolve( string $slot, Context $context ): array {

		$resolved  = [];
		$exclusive = in_array( $slot, self::EXCLUSIVE_SLOTS, true );

		foreach ( $this->get_slot_blocks( $slot, $context ) as $block ) {
			$state  = $block->get_state( $context );
			$forced = $context->is_forced( $block->get_id() );

			if ( ! $state->is_visible() && ! $forced ) {
				continue;
			}

			$variant    = $forced && $context->get_forced_variant() !== '' ? $context->get_forced_variant() : $state->get_variant();
			$resolved[] = [
				'id'      => $block->get_id(),
				'key'     => $block->get_key( $variant ),
				'slot'    => $slot,
				'variant' => $variant,
				'args'    => self::normalize_args( (array) $block->get_args( $context, $variant ) ),
			];

			if ( $exclusive ) {
				break;
			}
		}

		return $resolved;
	}

	/**
	 * Record the send: every rendered block goes into the shown history and the tracking counters, then gets its own callback.
	 *
	 * @since 2.0.3
	 *
	 * @param array   $resolved Output of resolve_all().
	 * @param Context $context  Summary context.
	 */
	public function on_sent( array $resolved, Context $context ): void {

		$shown = $this->shown ?? $context->get_shown_blocks();

		foreach ( self::flatten( $resolved ) as $item ) {
			if ( $item['slot'] !== self::SLOT_BOTTOM ) {
				$shown->remember( (string) $item['slot'], (string) $item['key'], $context->get_week() );
			}

			$this->tracking->bump( (string) $item['key'], Tracking::EVENT_SHOWN );

			if ( isset( $this->blocks[ $item['id'] ] ) ) {
				$this->blocks[ $item['id'] ]->on_sent( $context, (string) $item['variant'] );
			}
		}

		$shown->remember_summary( self::SLOT_BOTTOM, array_column( $resolved[ self::SLOT_BOTTOM ] ?? [], 'key' ), $context->get_week() );
	}

	/**
	 * Flatten the output of resolve_all() into one list of resolved blocks.
	 *
	 * @since 2.0.3
	 *
	 * @param array $resolved Output of resolve_all().
	 *
	 * @return array
	 */
	private static function flatten( array $resolved ): array {

		return array_merge(
			array_filter( [ $resolved[ self::SLOT_TOP ] ?? [], $resolved[ self::SLOT_MIDDLE ] ?? [] ] ),
			$resolved[ self::SLOT_BOTTOM ] ?? []
		);
	}

	/**
	 * Drop the secondary link when it repeats the main button, by label or by destination.
	 *
	 * @since 2.0.3
	 *
	 * @param array $args Block template arguments.
	 *
	 * @return array
	 */
	private static function normalize_args( array $args ): array {

		if ( self::is_duplicate_link( (array) ( $args['alt'] ?? [] ), (array) ( $args['main'] ?? [] ) ) ) {
			unset( $args['alt'] );
		}

		return $args;
	}

	/**
	 * Whether a secondary link says the same as the main button.
	 *
	 * @since 2.0.3
	 *
	 * @param array $link Secondary link: `text`, `url`.
	 * @param array $main Main button: `text`, `url`.
	 *
	 * @return bool
	 */
	private static function is_duplicate_link( array $link, array $main ): bool {

		$text        = trim( (string) ( $link['text'] ?? '' ) );
		$url         = (string) ( $link['url'] ?? '' );
		$button_text = trim( (string) ( $main['text'] ?? '' ) );
		$button_url  = (string) ( $main['url'] ?? '' );

		if ( $url === '' || $button_url === '' ) {
			return false;
		}

		if ( $url === $button_url ) {
			return true;
		}

		return $text !== '' && $button_text !== '' && strcasecmp( $text, $button_text ) === 0;
	}

	/**
	 * Blocks registered for a slot, in priority order. A forced block is the only candidate.
	 *
	 * @since 2.0.3
	 *
	 * @param string  $slot    Slot.
	 * @param Context $context Summary context.
	 *
	 * @return Block[]
	 */
	private function get_slot_blocks( string $slot, Context $context ): array {

		$blocks = array_filter(
			$this->blocks,
			static function ( Block $block ) use ( $slot ): bool {

				return $block->get_slot() === $slot;
			}
		);

		foreach ( $blocks as $block ) {
			if ( $context->is_forced( $block->get_id() ) ) {
				return [ $block ];
			}
		}

		return array_values( $blocks );
	}
}
