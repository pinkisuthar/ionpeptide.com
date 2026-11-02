<?php

namespace WPForms\Emails\Summaries;

/**
 * Which blocks went out in the last few summaries, per slot.
 *
 * Written only after a successful send, so "the previous two summaries" means the two the
 * recipient actually got, whatever the calendar did in between.
 *
 * @since 2.0.3
 */
class ShownBlocks {

	/**
	 * Option: slot => [ [ 'week' => Y-m-d, 'key' => block key ], ... ], oldest first.
	 *
	 * @since 2.0.3
	 */
	public const OPTION = 'wpforms_email_summaries_shown_blocks';

	/**
	 * Records kept per exclusive slot.
	 *
	 * @since 2.0.3
	 */
	public const KEEP = 10;

	/**
	 * Record that a block went out in a slot.
	 *
	 * @since 2.0.3
	 *
	 * @param string $slot Slot.
	 * @param string $key  Block key.
	 * @param Week   $week Reported week.
	 */
	public function remember( string $slot, string $key, Week $week ): void {

		$shown = $this->get_all();

		$shown[ $slot ]   = array_slice( (array) ( $shown[ $slot ] ?? [] ), 1 - self::KEEP );
		$shown[ $slot ][] = [
			'week' => $week->get_end_date(),
			'key'  => $key,
		];

		update_option( self::OPTION, $shown, false );
	}

	/**
	 * Keys of the blocks that went out in a slot during the last N summaries, most recent last.
	 *
	 * @since 2.0.3
	 *
	 * @param string $slot  Slot.
	 * @param int    $count Number of summaries to look back.
	 *
	 * @return string[]
	 */
	public function last( string $slot, int $count ): array {

		// -0 is 0, which would slice the whole history instead of nothing.
		if ( $count < 1 ) {
			return [];
		}

		$records = array_slice( (array) ( $this->get_all()[ $slot ] ?? [] ), -$count );

		return array_map( 'strval', array_column( $records, 'key' ) );
	}

	/**
	 * One block's records (`week`, `key`) in a slot, oldest first.
	 *
	 * The slot history mixes the keys of every block it showed; the prefix narrows it down to one block.
	 *
	 * @since 2.0.3
	 *
	 * @param string $slot   Slot.
	 * @param string $prefix Block key prefix.
	 *
	 * @return array
	 */
	public function recent( string $slot, string $prefix ): array {

		$shown   = $this->get_all();
		$records = array_filter(
			(array) ( $shown['rotations'][ $slot ][ $prefix ] ?? $shown[ $slot ] ?? [] ),
			static function ( $record ) use ( $prefix ) {

				return strpos( (string) ( $record['key'] ?? '' ), $prefix ) === 0;
			}
		);

		return array_values( $records );
	}

	/**
	 * Record a successful summary, including an empty slot, retaining the cooldown window.
	 *
	 * @since 2.0.3
	 *
	 * @param string $slot Slot.
	 * @param array  $keys Sent block keys.
	 * @param Week   $week Reported week.
	 */
	public function remember_summary( string $slot, array $keys, Week $week ): void {

		$shown     = $this->get_all();
		$summaries = $this->get_summaries( $slot );
		$records   = [];

		foreach ( $keys as $key ) {
			$records[] = [
				'week' => $week->get_end_date(),
				'key'  => $key,
			];
		}

		$summaries[]                 = $records;
		$shown['summaries'][ $slot ] = array_slice( $summaries, -Headlines::REPEAT_WEEKS );
		$shown[ $slot ]              = array_merge( ...$shown['summaries'][ $slot ] );

		update_option( self::OPTION, $shown, false );
	}

	/**
	 * Keys sent in the last two successful summaries, restricted to one block.
	 *
	 * @since 2.0.3
	 *
	 * @param string $slot   Slot.
	 * @param string $prefix Block key prefix.
	 *
	 * @return string[]
	 */
	public function summary_keys( string $slot, string $prefix ): array {

		$records = array_merge( [], ...array_slice( $this->get_summaries( $slot ), -Headlines::REPEAT_WEEKS ) );

		return array_values(
			array_filter(
				array_column( $records, 'key' ),
				static function ( $key ) use ( $prefix ): bool {

					return strpos( (string) $key, $prefix ) === 0;
				}
			)
		);
	}

	/**
	 * Retain one block's rotation independently, bounded by its current eligible pool.
	 *
	 * @since 2.0.3
	 *
	 * @param string $slot     Slot.
	 * @param string $prefix   Block key prefix.
	 * @param string $key      Sent block key.
	 * @param Week   $week     Reported week.
	 * @param array  $eligible Eligible block keys.
	 */
	public function remember_rotation( string $slot, string $prefix, string $key, Week $week, array $eligible ): void {

		$shown   = $this->get_all();
		$records = [];

		foreach ( $this->recent( $slot, $prefix ) as $record ) {
			if ( in_array( $record['key'], $eligible, true ) ) {
				unset( $records[ $record['key'] ] );
				$records[ $record['key'] ] = $record;
			}
		}

		unset( $records[ $key ] );
		$records[ $key ] = [
			'week' => $week->get_end_date(),
			'key'  => $key,
		];

		$shown['rotations'][ $slot ][ $prefix ] = array_slice( array_values( $records ), -max( count( $eligible ) - 1, Headlines::REPEAT_WEEKS ) );

		update_option( self::OPTION, $shown, false );
	}

	/**
	 * Summary snapshots, grouping the earlier flat records by their reported week when necessary.
	 *
	 * @since 2.0.3
	 *
	 * @param string $slot Slot.
	 *
	 * @return array
	 */
	private function get_summaries( string $slot ): array {

		$shown = $this->get_all();

		if ( isset( $shown['summaries'][ $slot ] ) ) {
			return $shown['summaries'][ $slot ];
		}

		$summaries = [];

		foreach ( (array) ( $shown[ $slot ] ?? [] ) as $record ) {
			$summaries[ $record['week'] ][] = $record;
		}

		return array_values( $summaries );
	}

	/**
	 * The whole store.
	 *
	 * @since 2.0.3
	 *
	 * @return array
	 */
	private function get_all(): array {

		return (array) get_option( self::OPTION, [] );
	}
}
