<?php

namespace WPForms\Emails\Summaries\Slot;

use WPForms\Emails\Summaries\ShownBlocks;
use WPForms\Emails\Summaries\Week;

/**
 * Everything a block needs to decide and render: the week, its metrics, and the preview state.
 *
 * @since 2.0.3
 */
class Context {

	/**
	 * Reported week.
	 *
	 * @since 2.0.3
	 *
	 * @var Week
	 */
	private $week;

	/**
	 * Metrics from Data::for_week().
	 *
	 * @since 2.0.3
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Block id forced to show in the preview, empty for none.
	 *
	 * @since 2.0.3
	 *
	 * @var string
	 */
	private $forced_block_id;

	/**
	 * Variant the preview asks the forced block to render, empty for the block's own choice.
	 *
	 * @since 2.0.3
	 *
	 * @var string
	 */
	private $forced_variant;

	/**
	 * Shown-blocks history shared by the pipeline and the blocks.
	 *
	 * @since 2.0.3
	 *
	 * @var ShownBlocks|null
	 */
	private $shown_blocks;

	/**
	 * Constructor.
	 *
	 * @since 2.0.3
	 *
	 * @param Week   $week            Reported week.
	 * @param array  $data            Metrics from Data::for_week().
	 * @param bool   $is_preview      Whether the email is being previewed.
	 * @param string $forced_block_id Block id forced to show in the preview.
	 * @param string $forced_variant  Variant the forced block should render.
	 */
	public function __construct( Week $week, array $data, bool $is_preview = false, string $forced_block_id = '', string $forced_variant = '' ) {

		$this->week            = $week;
		$this->data            = $data;
		$this->forced_block_id = $is_preview ? $forced_block_id : '';
		$this->forced_variant  = $is_preview ? $forced_variant : '';
	}

	/**
	 * Reported week.
	 *
	 * @since 2.0.3
	 *
	 * @return Week
	 */
	public function get_week(): Week {

		return $this->week;
	}

	/**
	 * All metrics.
	 *
	 * @since 2.0.3
	 *
	 * @return array
	 */
	public function get_data(): array {

		return $this->data;
	}

	/**
	 * One metric.
	 *
	 * @since 2.0.3
	 *
	 * @param string $key      Metric key.
	 * @param mixed  $fallback Value when the metric is absent.
	 *
	 * @return mixed
	 */
	public function get( string $key, $fallback = null ) {

		return $this->data[ $key ] ?? $fallback;
	}

	/**
	 * Whether the reported week collected any entry.
	 *
	 * @since 2.0.3
	 *
	 * @return bool
	 */
	public function has_entries(): bool {

		return (int) $this->get( 'entries', 0 ) > 0;
	}

	/**
	 * Shown-blocks history, one instance for the whole summary.
	 *
	 * @since 2.0.3
	 *
	 * @return ShownBlocks
	 */
	public function get_shown_blocks(): ShownBlocks {

		if ( $this->shown_blocks === null ) {
			$this->shown_blocks = new ShownBlocks();
		}

		return $this->shown_blocks;
	}

	/**
	 * Whether the Pro edition is installed.
	 *
	 * @since 2.0.3
	 *
	 * @return bool
	 */
	public function is_pro(): bool {

		return wpforms()->is_pro();
	}

	/**
	 * Whether the preview forces a given block to show.
	 *
	 * @since 2.0.3
	 *
	 * @param string $block_id Block id.
	 *
	 * @return bool
	 */
	public function is_forced( string $block_id ): bool {

		return $this->forced_block_id !== '' && $this->forced_block_id === $block_id;
	}

	/**
	 * Variant the preview asks the forced block to render, empty for the block's own choice.
	 *
	 * @since 2.0.3
	 *
	 * @return string
	 */
	public function get_forced_variant(): string {

		return $this->forced_variant;
	}
}
