<?php

namespace WPForms\Helpers;

/**
 * Lifetime per-key event counters kept in a non-autoloaded option.
 *
 * The store is `[ key => [ event => count ] ]`, the shape the weekly summary and the
 * builder adoption tooltips report through Usage Tracking.
 *
 * @since 2.0.3
 */
class EventCounter {

	/**
	 * Option holding the counters.
	 *
	 * @since 2.0.3
	 *
	 * @var string
	 */
	private $option;

	/**
	 * Constructor.
	 *
	 * @since 2.0.3
	 *
	 * @param string $option Option holding the counters.
	 */
	public function __construct( string $option ) {

		$this->option = $option;
	}

	/**
	 * All counters.
	 *
	 * @since 2.0.3
	 *
	 * @return array
	 */
	public function get_all(): array {

		return (array) get_option( $this->option, [] );
	}

	/**
	 * Increment one counter.
	 *
	 * @since 2.0.3
	 *
	 * @param string $key   Counter key.
	 * @param string $event Event name.
	 */
	public function bump( string $key, string $event ): void {

		$events = $this->get_all();

		$events[ $key ][ $event ] = (int) ( $events[ $key ][ $event ] ?? 0 ) + 1;

		update_option( $this->option, $events, false );
	}
}
