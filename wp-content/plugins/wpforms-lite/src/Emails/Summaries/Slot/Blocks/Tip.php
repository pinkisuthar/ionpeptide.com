<?php

namespace WPForms\Emails\Summaries\Slot\Blocks;

use WPForms\Emails\InfoBlocks;
use WPForms\Emails\Summaries\Slot\AbstractBlock;
use WPForms\Emails\Summaries\Slot\BlockState;
use WPForms\Emails\Summaries\Slot\Context;
use WPForms\Emails\Summaries\Slot\Pipeline;

/**
 * Tip: a random Info Block absent from the last two successful summaries.
 * Shows nothing when that leaves no block, or on a zero-entry week.
 *
 * @since 2.0.3
 */
class Tip extends AbstractBlock {

	/**
	 * Block id.
	 *
	 * @since 2.0.3
	 */
	public const ID = 'tip';

	/**
	 * Slot.
	 *
	 * @since 2.0.3
	 */
	public const SLOT = Pipeline::SLOT_BOTTOM;

	/**
	 * Priority.
	 *
	 * @since 2.0.3
	 */
	public const PRIORITY = 10;

	/**
	 * Feed reader.
	 *
	 * @since 2.0.3
	 *
	 * @var InfoBlocks
	 */
	private $info_blocks;

	/**
	 * Block picked by get_state(), reused by get_args() and on_sent().
	 *
	 * @since 2.0.3
	 *
	 * @var array|null
	 */
	private $block;

	/**
	 * Constructor.
	 *
	 * @since 2.0.3
	 *
	 * @param InfoBlocks|null $info_blocks Feed reader. Defaults to a new instance.
	 */
	public function __construct( ?InfoBlocks $info_blocks = null ) {

		$this->info_blocks = $info_blocks ?? new InfoBlocks();
	}

	/**
	 * Visible when the week had entries and the feed has a block left to show.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 *
	 * @return BlockState
	 */
	public function get_state( Context $context ): BlockState {

		// A zero-entry week reads no feed: the cache can fall through to a remote fetch, on cron.
		if ( ! $this->qualifies_for_entries( $context ) ) {
			return new BlockState( false );
		}

		// Not inlined below: && would short-circuit past the pick on a forced zero-entry week.
		$block = $this->get_block( $context );

		return new BlockState( $context->has_entries() && ! empty( $block ) );
	}

	/**
	 * The feed block's title, content and button.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 * @param string  $variant Unused.
	 *
	 * @return array
	 */
	public function get_args( Context $context, string $variant ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		$block  = $this->get_block( $context );
		$button = (string) ( $block['button'] ?? '' );

		// The feed shouts its button labels, the design uses Title Case.
		if ( $button !== '' && $button === strtoupper( $button ) ) {
			$button = str_replace( 'Wpforms', 'WPForms', ucwords( strtolower( $button ) ) );
		}

		return [
			'title'   => (string) ( $block['title'] ?? '' ),
			'content' => (string) ( $block['content'] ?? '' ),
			'main'    => [
				'text' => $button,
				'url'  => (string) ( $block['url'] ?? '' ),
			],
		];
	}

	/**
	 * The block id prefixed with `tip_`, so the shown history can exclude it next time.
	 *
	 * @since 2.0.3
	 *
	 * @param string $variant Unused.
	 *
	 * @return string
	 */
	public function get_key( string $variant ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		return self::ID . '_' . (int) ( $this->block['id'] ?? 0 );
	}

	/**
	 * Pick once from the license's blocks, excluding tips in the previous two successful summaries.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 *
	 * @return array
	 */
	private function get_block( Context $context ): array {

		if ( $this->block === null ) {
			$recent = array_map(
				static function ( $key ) {

					return (int) substr( (string) $key, strlen( self::ID ) + 1 );
				},
				$context->get_shown_blocks()->summary_keys( self::SLOT, self::ID . '_' )
			);

			$this->block = (array) $this->info_blocks->get_random( $recent );
		}

		return $this->block;
	}
}
