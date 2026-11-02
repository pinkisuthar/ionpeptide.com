<?php

namespace WPForms\Emails\Summaries\Slot;

/**
 * A conditional block competing for a slot in the weekly summary email.
 *
 * The contract the `wpforms_emails_summaries_get_blocks` filter accepts: anything the
 * filter adds has to implement it, which is why the interface exists next to the single
 * AbstractBlock implementation the bundled blocks extend.
 *
 * @since 2.0.3
 */
interface Block {

	/**
	 * Unique block id, also the template partial suffix and the tracking key.
	 *
	 * @since 2.0.3
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Key identifying what went out, for the shown-blocks history and usage tracking.
	 *
	 * The id for most blocks; blocks with meaningful variants fold the variant in.
	 *
	 * @since 2.0.3
	 *
	 * @param string $variant Variant rendered.
	 *
	 * @return string
	 */
	public function get_key( string $variant ): string;

	/**
	 * Slot the block renders in. One of the Pipeline::SLOT_* constants.
	 *
	 * @since 2.0.3
	 *
	 * @return string
	 */
	public function get_slot(): string;

	/**
	 * Priority within the slot. Lower wins.
	 *
	 * @since 2.0.3
	 *
	 * @return int
	 */
	public function get_priority(): int;

	/**
	 * Decide whether the block shows and in which variant.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 *
	 * @return BlockState
	 */
	public function get_state( Context $context ): BlockState;

	/**
	 * Template arguments for the block.
	 *
	 * Every block speaks the same shape, so the templates never guess: `title`, `content` (string or
	 * list of paragraphs), `main` and `alt` buttons as [ `text`, `url` ], optional `icon`,
	 * `icon_width`, `icon_height`, `icon_dark` and `layout`. Extra keys are block-specific.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 * @param string  $variant Variant resolved by get_state(), or the preview default when forced.
	 *
	 * @return array
	 */
	public function get_args( Context $context, string $variant ): array;

	/**
	 * Record that the block went out in a sent email.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 * @param string  $variant Variant that was sent.
	 */
	public function on_sent( Context $context, string $variant ): void;
}
