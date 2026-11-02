<?php

namespace WPForms\Emails\Summaries\Slot\Blocks;

use WPForms\Admin\Splash\SplashCache;
use WPForms\Emails\Summaries\Slot\AbstractBlock;
use WPForms\Emails\Summaries\Slot\BlockState;
use WPForms\Emails\Summaries\Slot\Context;
use WPForms\Emails\Summaries\Slot\Pipeline;

/**
 * Feature Spotlight: a What's New entry from the splash feed that is still new for the installed version.
 *
 * Reads the cache `SplashCache` already maintains, filtered by license there. The cache marks entries
 * older than the installed version as not new, so those never show. The rest rotate: a sent entry sits
 * out the next two summaries, and enough recent keys sit out for a pool of N entries to cycle through
 * all N before repeating. Suppressed on a zero-entry week.
 *
 * @since 2.0.3
 */
class FeatureSpotlight extends AbstractBlock {

	/**
	 * Block id.
	 *
	 * @since 2.0.3
	 */
	public const ID = 'spotlight';

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
	public const PRIORITY = 20;

	/**
	 * UTM content for a feed link that carries none of its own.
	 *
	 * @since 2.0.3
	 */
	private const UTM_CONTENT = 'Feature Spotlight';

	/**
	 * Entry picked by get_state().
	 *
	 * @since 2.0.3
	 *
	 * @var array|null
	 */
	private $entry;

	/**
	 * Eligible keys from the feed used for this summary.
	 *
	 * @since 2.0.3
	 *
	 * @var string[]
	 */
	private $eligible_keys = [];

	/**
	 * Visible on a week with entries when a still-new entry is not sitting out the rotation.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 *
	 * @return BlockState
	 */
	public function get_state( Context $context ): BlockState {

		// A forced preview still needs the pick below, so it passes this guard.
		if ( ! $this->qualifies_for_entries( $context ) ) {
			return new BlockState( false );
		}

		// A corrupted cache can carry scalars, and one would fatal the whole send further down.
		$cache  = wpforms()->obj( 'splash_cache' );
		$blocks = $cache instanceof SplashCache ? array_filter( (array) ( $cache->get()['blocks'] ?? [] ), 'is_array' ) : [];
		$shown  = $context->get_shown_blocks();

		$this->entry = self::select(
			$blocks,
			$shown->summary_keys( self::SLOT, self::ID . '_' ),
			$shown->recent( self::SLOT, self::ID . '_' )
		);

		// A forced preview falls back to the newest entry, new or not, so the card is never empty.
		if ( $this->entry === null && $context->is_forced( self::ID ) ) {
			$this->entry = self::select(
				array_map(
					static function ( $block ) {

						return array_merge( (array) $block, [ 'new' => true ] );
					},
					$blocks
				)
			);
		}

		$this->eligible_keys = [];

		foreach ( $blocks as $block ) {
			if ( ( $block['new'] ?? true ) !== false ) {
				$this->eligible_keys[] = self::key_for( $block );
			}
		}

		return new BlockState( $context->has_entries() && $this->entry !== null );
	}

	/**
	 * Keep the eligible pool's rotation separate from the other bottom-slot blocks.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 * @param string  $variant Unused.
	 */
	public function on_sent( Context $context, string $variant ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		$context->get_shown_blocks()->remember_rotation( self::SLOT, self::ID . '_', $this->get_key( '' ), $context->get_week(), $this->eligible_keys );
	}

	/**
	 * The next entry in the rotation, or null.
	 *
	 * Entries the cache marked as not new for this install never qualify. An entry sent in either of
	 * the previous two successful summaries sits out, and so do the newest `pool - 1` sent keys, so
	 * a pool of N entries cycles through all N before repeating. Among the candidates the highest
	 * version wins.
	 *
	 * @since 2.0.3
	 *
	 * @param array    $blocks   Prepared splash blocks.
	 * @param string[] $cooldown Keys sent in the previous two successful summaries.
	 * @param array    $recent   Rotation history records (`week`, `key`), most recent last.
	 *
	 * @return array|null
	 */
	public static function select( array $blocks, array $cooldown = [], array $recent = [] ): ?array {

		$eligible = array_values(
			array_filter(
				$blocks,
				static function ( $block ) {

					return ( $block['new'] ?? true ) !== false;
				}
			)
		);

		if ( ! $eligible ) {
			return null;
		}

		$sitting_out = self::sitting_out( $cooldown, $recent, count( $eligible ) );
		$best        = null;

		foreach ( $eligible as $block ) {
			if ( in_array( self::key_for( $block ), $sitting_out, true ) ) {
				continue;
			}

			if ( $best === null || version_compare( (string) ( $block['version'] ?? '' ), (string) ( $best['version'] ?? '' ), '>' ) ) {
				$best = $block;
			}
		}

		return $best;
	}

	/**
	 * Keys sitting out the rotation: everything sent in the previous two successful summaries plus
	 * the newest `pool - 1` sent keys.
	 *
	 * @since 2.0.3
	 *
	 * @param string[] $cooldown Keys sent in the previous two successful summaries.
	 * @param array    $recent   Rotation history records (`week`, `key`), most recent last.
	 * @param int      $pool     Eligible entries count.
	 *
	 * When the rotation history is empty but the cooldown carries keys, the slice is empty and the
	 * cooldown keys still apply. For a pool of one the cooldown is the whole exclusion, so the
	 * entry waits out the two successful summaries.
	 *
	 * @return string[]
	 */
	private static function sitting_out( array $cooldown, array $recent, int $pool ): array {

		$keys        = array_map( 'strval', array_column( $recent, 'key' ) );
		$sitting_out = $pool > 1 ? array_slice( $keys, 1 - $pool ) : [];

		return array_values( array_unique( array_merge( $sitting_out, $cooldown ) ) );
	}

	/**
	 * Entry fields the template needs, with the buttons re-attributed to email.
	 *
	 * @since 2.0.3
	 *
	 * @param Context $context Summary context.
	 * @param string  $variant Unused.
	 *
	 * @return array
	 */
	public function get_args( Context $context, string $variant ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		$entry = $this->entry ?? [];
		$args  = [
			'title'   => (string) ( $entry['title'] ?? '' ),
			'content' => (string) ( $entry['content'] ?? '' ),
		];

		foreach ( [ 'main', 'alt' ] as $name ) {
			$button = $entry['buttons'][ $name ] ?? [];

			if ( ! empty( $button['url'] ) && ! empty( $button['text'] ) ) {
				$args[ $name ] = [
					'url'  => $this->re_attribute( (string) $button['url'] ),
					'text' => (string) $button['text'],
				];
			}
		}

		return $args;
	}

	/**
	 * Swap the splash campaign on a feed link for the summary email one, keeping the entry's own content value.
	 *
	 * @since 2.0.3
	 *
	 * @param string $url Button URL as the splash feed wrote it.
	 *
	 * @return string
	 */
	private function re_attribute( string $url ): string {

		// The feed's update.php nonce was minted on cron with no user, so from an email it can only
		// yield "link expired". Send the reader to the Plugins screen instead.
		if ( strpos( $url, 'action=upgrade-plugin' ) !== false ) {
			return self::admin_utm_url( 'plugins.php', 'Update Now', self::UTM_CAMPAIGN, self::is_network_admin_url( $url ) );
		}

		$query = [];

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return self::utm_link( $url, $query['utm_content'] ?? self::UTM_CONTENT );
	}

	/**
	 * Whether a splash URL points at the network admin, so the Plugins fallback stays on that screen.
	 *
	 * @since 2.0.3
	 *
	 * @param string $url Button URL as the splash feed wrote it.
	 *
	 * @return bool
	 */
	private static function is_network_admin_url( string $url ): bool {

		if ( strpos( $url, '/wp-admin/network/' ) !== false ) {
			return true;
		}

		$network_url = network_admin_url();

		return $network_url !== '' && strpos( $url, $network_url ) === 0;
	}

	/**
	 * The entry key: the block id plus the title slug, readable in the tracking payload, until the feed carries an id.
	 *
	 * @since 2.0.3
	 *
	 * @param string $variant Unused.
	 *
	 * @return string
	 */
	public function get_key( string $variant ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		return self::key_for( $this->entry ?? [] );
	}

	/**
	 * Key of a feed entry.
	 *
	 * @since 2.0.3
	 *
	 * @param array $entry Feed entry.
	 *
	 * @return string
	 */
	private static function key_for( array $entry ): string {

		return self::ID . '_' . sanitize_title( (string) ( $entry['title'] ?? '' ) );
	}
}
