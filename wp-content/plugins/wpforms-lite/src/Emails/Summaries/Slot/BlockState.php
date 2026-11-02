<?php

namespace WPForms\Emails\Summaries\Slot;

/**
 * Block state value object: whether it renders, and which variant.
 *
 * @since 2.0.3
 */
class BlockState {

	/**
	 * Whether the block renders.
	 *
	 * @since 2.0.3
	 *
	 * @var bool
	 */
	private $visible;

	/**
	 * Template variant.
	 *
	 * @since 2.0.3
	 *
	 * @var string
	 */
	private $variant;

	/**
	 * Constructor.
	 *
	 * @since 2.0.3
	 *
	 * @param bool   $visible Whether the block renders.
	 * @param string $variant Template variant.
	 */
	public function __construct( bool $visible, string $variant = '' ) {

		$this->visible = $visible;
		$this->variant = $variant;
	}

	/**
	 * Whether the block renders.
	 *
	 * @since 2.0.3
	 *
	 * @return bool
	 */
	public function is_visible(): bool {

		return $this->visible;
	}

	/**
	 * Template variant.
	 *
	 * @since 2.0.3
	 *
	 * @return string
	 */
	public function get_variant(): string {

		return $this->variant;
	}
}
