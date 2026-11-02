<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'BWFAN_Announcements' ) ) {
	/**
	 * Remote announcement feed: fetch, validate, sanitize, cache, plus per-site read state.
	 *
	 * Nothing here runs on a page load and nothing is scheduled in the background: the feed is only
	 * read when the admin app asks for it over REST (once the page is idle, sooner on hover/focus of
	 * the bell). The unread badge is computed in the app from that response.
	 *
	 * Trust model: the feed is remote content and every field in it is untrusted. It is normalised
	 * once at cache time by sanitize_feed(). Site-state checks (pro active, plugin installed) run at
	 * render time through requirements_met() against a fixed predicate list — the feed can name a
	 * predicate, never a PHP callable or class.
	 *
	 * Feed contract (https://funnelkit.com/announcement/{product}/v1/announcement.json):
	 *   recent_id     int|int[]  newest changelog id [0] and newest feature id [1], as the origin
	 *                            sends it (e.g. [87514, 67260]). Stored, not consumed yet.
	 *   last_updated  string
	 *   features[]    { post_id, title, date, content(html), image(url), url, read_more_text, pro('yes'|'no'), upgrade_text, upgrade_url }
	 *   changelog[]   { post_id, title, date, content(html), image(url), url, read_more_text }
	 *   sale_banner[] { content(html), is_lite(bool), is_pro(bool), dependency{ requires[], requires_not[], version{ lite, pro } } }
	 *   banner_count  int
	 *   plugin_data[] product keys from get_products(), e.g. "cart", "stripe" (empty list = all)
	 *
	 * HTML content is reduced to a fixed tag set; <a>, <img> and <iframe> survive only when their URL
	 * host is on the matching allow-list (see sanitize_html()). Inline styles may not reference URLs.
	 */
	class BWFAN_Announcements {
		/** Per-site read state (last seen id, hidden features), keyed by product. */
		const OPTION_KEY = 'fk_announcements';

		/** Cached, sanitized feed. One per product: { version, fetched_at, fetch_failed, data }. Not autoloaded. */
		const CACHE_OPTION = 'bwfan_announcements_cache_';

		/** Bump when sanitize rules change so every site refetches through the new rules. */
		const CACHE_VERSION = 1;

		const CACHE_TTL    = 2 * HOUR_IN_SECONDS;
		const EMPTY_TTL    = 30 * MINUTE_IN_SECONDS; // retry interval after a failed or genuinely empty fetch
		const HTTP_TIMEOUT = 4;
		const MAX_BYTES    = 524288;                 // 512 KB; live feed is ~64 KB
		const MAX_ITEMS    = 50;                     // per list; live feed ships ~30 changelog entries

		/**
		 * Tag allow-list for feed HTML. Mirrored in admin/frontend/src/announcements/purify.js
		 * (ALLOWED_TAGS); bin/check-announcements.php fails if the two lists drift.
		 */
		const ALLOWED_TAGS = [ 'p', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i', 'span', 'h2', 'h3', 'h4', 'a', 'br', 'div', 'img', 'iframe' ];

		/** URL host allow-lists: exact host or any subdomain, https only, no port, no userinfo. */
		const LINK_HOSTS  = [ 'funnelkit.com', 'sublium.com', 'youtube.com', 'youtu.be' ];
		const IMAGE_HOSTS = [ 'funnelkit.com', 'sublium.com' ];
		const EMBED_HOSTS = [ 'www.youtube.com', 'www.youtube-nocookie.com' ];
		const EMBED_PATH  = '/embed/';

		/**
		 * Escapes a browser would normalise away before the EMBED_PATH pin is reached: dot segments,
		 * doubled or back slashes, and their percent-encoded forms.
		 */
		const EMBED_PATH_TRICKS = '#\.\.|//|\\\\|%2e|%2f|%5c#i';

		/** Attributes an iframe may keep; `allow` is then pinned to IFRAME_ALLOW regardless of the feed. */
		const IFRAME_ATTRS = [ 'src', 'width', 'height', 'title', 'allowfullscreen' ];
		const IFRAME_ALLOW = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';

		/**
		 * Inline-style properties that let feed content escape its box (cover wp-admin, fake overlays).
		 * Matched with an optional vendor prefix. The render container also sets `contain: paint`.
		 */
		const BLOCKED_CSS_PROPS = '/^(-[a-z]+-)?(position|z-index|top|right|bottom|left|inset(-[a-z-]+)?|transform|translate|scale|rotate|clip|clip-path|perspective)$/';

		/**
		 * Kill switch. `define( 'BWFAN_DISABLE_ANNOUNCEMENTS', true )`: no remote call is made, the REST
		 * route answers with empty lists, and the admin feature flag hides the bell.
		 *
		 * @return bool
		 */
		public static function is_enabled() {
			return ! ( defined( 'BWFAN_DISABLE_ANNOUNCEMENTS' ) && BWFAN_DISABLE_ANNOUNCEMENTS );
		}

		/* ------------------------------------------------------------------ */
		/* Plugin-owned definitions the feed may reference by name only        */
		/* ------------------------------------------------------------------ */

		/**
		 * Installable products the sidebar may promote. The feed picks keys; slug, init path and
		 * redirect always come from here, so a feed can never point the installer at another plugin.
		 *
		 * @return array<string,array>
		 */
		public static function get_products() {
			return [
				'cart'   => [
					'key'           => 'cart',
					'slug'          => 'cart-for-woocommerce',
					'init'          => 'cart-for-woocommerce/plugin.php',
					'name'          => __( 'Get FunnelKit Cart', 'wp-marketing-automations' ),
					'icon'          => 'logo',
					'description'   => __( 'Installs from WordPress.org', 'wp-marketing-automations' ),
					'action'        => 'install',
					'redirectLink'  => 'admin.php?page=fkcart',
					'successStatus' => 'installed',
					'dependency'    => [ 'requires' => [ 'wc_active' ] ],
				],
				'stripe' => [
					'key'           => 'stripe',
					'slug'          => 'funnelkit-stripe-woo-payment-gateway',
					'init'          => 'funnelkit-stripe-woo-payment-gateway/funnelkit-stripe-woo-payment-gateway.php',
					'name'          => __( 'Get FunnelKit Stripe', 'wp-marketing-automations' ),
					'icon'          => 'stripe',
					'description'   => __( 'Installs from WordPress.org', 'wp-marketing-automations' ),
					'action'        => 'install',
					'successStatus' => 'connected',
					'dependency'    => [ 'requires' => [ 'wc_active' ] ],
				],
				'sublium' => [
					'key'           => 'sublium',
					'slug'          => 'sublium-subscriptions-for-woocommerce',
					'init'          => 'sublium-subscriptions-for-woocommerce/sublium-subscriptions-for-woocommerce.php',
					'name'          => __( 'Get Sublium', 'wp-marketing-automations' ),
					'icon'          => 'sublium',
					'description'   => __( 'Installs from WordPress.org', 'wp-marketing-automations' ),
					'action'        => 'install',
					'redirectLink'  => 'admin.php?page=sublium',
					'successStatus' => 'installed',
					'dependency'    => [ 'requires' => [ 'wc_active' ] ],
				],
			];
		}

		/**
		 * Site-state predicates a feed dependency may reference. Add here, never let the feed name code.
		 *
		 * @return array<string,callable>
		 */
		public static function get_predicates() {
			return [
				'pro_active'    => static function () {
					return function_exists( 'bwfan_is_autonami_pro_active' ) && bwfan_is_autonami_pro_active();
				},
				'wc_active'     => static function () {
					return function_exists( 'bwfan_is_woocommerce_active' ) && bwfan_is_woocommerce_active();
				},
				'stripe_active' => static function () {
					return defined( 'FKWCS_VERSION' );
				},
				'cart_active'   => static function () {
					return defined( 'FKCART_VERSION' );
				},
				'fb_active'     => static function () {
					return defined( 'WFFN_VERSION' );
				},
			];
		}

		/**
		 * Reduce a feed dependency block to the supported shape. Unknown keys (including the legacy
		 * `function` / `class` maps) or unknown predicate names make the whole block invalid.
		 *
		 * @param mixed $dependency
		 *
		 * @return array|null Normalised block, or null when invalid.
		 */
		public static function normalize_dependency( $dependency ) {
			if ( empty( $dependency ) ) {
				return [];
			}
			if ( ! is_array( $dependency ) || array_diff( array_keys( $dependency ), [ 'requires', 'requires_not', 'version' ] ) ) {
				return null;
			}
			$predicates = self::get_predicates();
			$out        = [];
			foreach ( [ 'requires', 'requires_not' ] as $key ) {
				if ( ! isset( $dependency[ $key ] ) ) {
					continue;
				}
				if ( ! is_array( $dependency[ $key ] ) ) {
					return null;
				}
				foreach ( $dependency[ $key ] as $name ) {
					if ( ! is_string( $name ) || ! isset( $predicates[ $name ] ) ) {
						return null;
					}
				}
				$out[ $key ] = array_values( array_unique( $dependency[ $key ] ) );
			}
			if ( isset( $dependency['version'] ) ) {
				if ( ! is_array( $dependency['version'] ) ) {
					return null;
				}
				$out['version'] = [];
				foreach ( [ 'lite', 'pro' ] as $which ) {
					if ( isset( $dependency['version'][ $which ] ) ) {
						$out['version'][ $which ] = sanitize_text_field( (string) $dependency['version'][ $which ] );
					}
				}
			}

			return $out;
		}

		/**
		 * Evaluate `requires` / `requires_not` against the current site. Fails closed on any invalid shape.
		 * Version constraints are the caller's job (see BWFAN_API_Fetch_Announcement::check_version()).
		 *
		 * @param mixed $dependency
		 *
		 * @return bool
		 */
		public static function requirements_met( $dependency ) {
			$dependency = self::normalize_dependency( $dependency );
			if ( null === $dependency ) {
				return false;
			}
			$predicates = self::get_predicates();
			foreach ( [ 'requires' => true, 'requires_not' => false ] as $key => $expected ) {
				if ( empty( $dependency[ $key ] ) ) {
					continue;
				}
				foreach ( $dependency[ $key ] as $name ) {
					if ( (bool) $predicates[ $name ]() !== $expected ) {
						return false;
					}
				}
			}

			return true;
		}

		/* ------------------------------------------------------------------ */
		/* Per-site read state                                                 */
		/* ------------------------------------------------------------------ */

		/**
		 * Last seen id and hidden feature slugs for a product.
		 *
		 * @param string $product
		 *
		 * @return array{last_seen_id:int,hide_features:array}
		 */
		public static function get_announcement_data( $product = 'fka' ) {
			$product = sanitize_key( $product );
			$data    = [
				'last_seen_id'  => 0,
				'hide_features' => [],
			];

			$announcements = get_option( self::OPTION_KEY, [] );

			if ( empty( $announcements ) || ! isset( $announcements[ $product ] ) || ! is_array( $announcements[ $product ] ) ) {
				return $data;
			}

			return [
				'last_seen_id'  => isset( $announcements[ $product ]['last_seen_id'] ) ? absint( $announcements[ $product ]['last_seen_id'] ) : 0,
				'hide_features' => isset( $announcements[ $product ]['hide_features'] ) && is_array( $announcements[ $product ]['hide_features'] ) ? array_values( $announcements[ $product ]['hide_features'] ) : [],
			];
		}

		/**
		 * Record a "last seen" id or hide a promoted product.
		 *
		 * @param string $product
		 * @param string $key  'last_seen' | 'hide_feature'
		 * @param array  $data
		 *
		 * @return array{status:bool,msg:string}
		 */
		public static function update_announcement_data( $product = 'fka', $key = '', $data = '' ) {
			$product = sanitize_key( $product );
			if ( empty( $product ) || empty( $key ) || empty( $data ) || ! is_array( $data ) ) {
				return [
					'status' => false,
					'msg'    => __( 'Product, action and data are required', 'wp-marketing-automations' ),
				];
			}

			$options = get_option( self::OPTION_KEY, [] );
			if ( ! is_array( $options ) ) {
				$options = [];
			}
			if ( ! isset( $options[ $product ] ) || ! is_array( $options[ $product ] ) ) {
				$options[ $product ] = [];
			}

			switch ( $key ) {
				case 'last_seen':
					$last_seen_id = isset( $data['last_seen_id'] ) ? absint( $data['last_seen_id'] ) : 0;
					if ( ! $last_seen_id ) {
						return [
							'status' => false,
							'msg'    => __( 'last_seen_id is required for last_seen action', 'wp-marketing-automations' ),
						];
					}
					$options[ $product ]['last_seen_id'] = $last_seen_id;
					break;
				case 'hide_feature':
					$slug    = isset( $data['slug'] ) ? sanitize_key( $data['slug'] ) : '';
					$allowed = wp_list_pluck( self::get_products(), 'slug' );
					// Only plugin-owned product slugs can be hidden; this also bounds the stored list.
					if ( '' === $slug || ! in_array( $slug, $allowed, true ) ) {
						return [
							'status' => false,
							'msg'    => __( 'Unknown feature slug', 'wp-marketing-automations' ),
						];
					}
					if ( ! isset( $options[ $product ]['hide_features'] ) || ! is_array( $options[ $product ]['hide_features'] ) ) {
						$options[ $product ]['hide_features'] = [];
					}
					if ( ! in_array( $slug, $options[ $product ]['hide_features'], true ) ) {
						$options[ $product ]['hide_features'][] = $slug;
					}
					break;
				default:
					return [
						'status' => false,
						'msg'    => __( 'Invalid action', 'wp-marketing-automations' ),
					];
			}

			update_option( self::OPTION_KEY, $options, false );

			return [
				'status' => true,
				'msg'    => __( 'Announcement data updated successfully', 'wp-marketing-automations' ),
			];
		}

		/* ------------------------------------------------------------------ */
		/* Feed cache                                                          */
		/* ------------------------------------------------------------------ */

		/**
		 * Sanitized feed for a product, served from the option cache and refetched once it expires.
		 *
		 * Fresh within CACHE_TTL, or EMPTY_TTL after a failed or empty fetch. refresh() writes a row
		 * even when the fetch fails, so a broken origin costs one attempt per EMPTY_TTL at most.
		 *
		 * @param string $product
		 *
		 * @return array
		 */
		public static function get_announcement( $product = 'fka' ) {
			if ( ! self::is_enabled() ) {
				return [];
			}
			$product = sanitize_key( $product );
			$cache   = get_option( self::CACHE_OPTION . $product, [] );

			$valid = is_array( $cache )
			         && isset( $cache['version'], $cache['fetched_at'], $cache['data'] )
			         && self::CACHE_VERSION === (int) $cache['version']
			         && is_array( $cache['data'] );

			if ( $valid && time() - (int) $cache['fetched_at'] < self::cache_ttl( $cache ) ) {
				return $cache['data'];
			}

			return self::refresh( $product );
		}

		/**
		 * How long a cache row stays fresh. A failed attempt backs off on the short interval too:
		 * keeping the previous copy must not push the next try out to the full CACHE_TTL.
		 *
		 * @param array $cache Cache row.
		 *
		 * @return int Seconds.
		 */
		public static function cache_ttl( $cache ) {
			$failed = is_array( $cache ) && ! empty( $cache['fetch_failed'] );
			$empty  = ! is_array( $cache ) || empty( $cache['data'] );

			return ( $failed || $empty ) ? self::EMPTY_TTL : self::CACHE_TTL;
		}

		/**
		 * Fetch, sanitize and store the feed.
		 *
		 * The attempt is recorded as failed before the network call and only overwritten once a
		 * response is usable. Every request that arrives while the origin hangs then reads a fresh
		 * row instead of fetching too, and a request that times out, fatals or is killed mid-fetch
		 * still counts, so a broken origin costs one attempt per EMPTY_TTL, not one per page load.
		 *
		 * A failed fetch keeps whatever is already cached, so a timeout or a 502 cannot blank the
		 * panel. Only the origin actually publishing an empty feed clears the cache.
		 *
		 * @param string $product
		 *
		 * @return array Sanitized feed (the previous copy when the fetch failed).
		 */
		public static function refresh( $product = 'fka' ) {
			if ( ! self::is_enabled() ) {
				return [];
			}
			$product = sanitize_key( $product );
			$cache   = get_option( self::CACHE_OPTION . $product, [] );
			$data    = is_array( $cache ) && isset( $cache['version'], $cache['data'] ) && self::CACHE_VERSION === (int) $cache['version'] && is_array( $cache['data'] ) ? $cache['data'] : [];

			// Older installs wrote the read-state row before it carried an autoload flag, and
			// update_option() only reconsiders autoload when the value itself changes — which it
			// never does on a site nobody interacts with.
			if ( function_exists( 'wp_set_option_autoload' ) ) {
				wp_set_option_autoload( self::OPTION_KEY, false );
			}

			self::store_feed( $product, $data, true );

			$raw = self::fetch_latest_announcements( $product );
			if ( null === $raw ) {
				return $data;
			}

			$data = self::sanitize_feed( $raw );
			self::store_feed( $product, $data, false );

			return $data;
		}

		/**
		 * Write the cache row, stamped now.
		 *
		 * @param string $product
		 * @param array  $data   Sanitized feed.
		 * @param bool   $failed Whether this row records a failed (or still running) attempt.
		 */
		private static function store_feed( $product, $data, $failed ) {
			update_option( self::CACHE_OPTION . $product, [
				'version'      => self::CACHE_VERSION,
				'fetched_at'   => time(),
				'fetch_failed' => $failed,
				'data'         => $data,
			], false );
		}

		/**
		 * Plugin deactivation: drop the feed cache. Read state (last seen, hidden products) is kept,
		 * like every other user preference.
		 *
		 * @param string $product
		 */
		public static function deactivate( $product = 'fka' ) {
			delete_option( self::CACHE_OPTION . sanitize_key( $product ) );
		}

		/**
		 * Raw remote fetch. Only a 200 with a JSON object body counts.
		 *
		 * The two failure shapes are kept apart on purpose: null means the fetch did not produce a
		 * usable answer (transport error, non-200, unparseable body) and the caller must not treat it
		 * as content, while an empty array is the origin genuinely publishing nothing.
		 *
		 * @param string $product
		 *
		 * @return array|null Decoded payload, or null when the fetch failed.
		 */
		private static function fetch_latest_announcements( $product = 'fka' ) {
			$url      = 'https://funnelkit.com/announcement/' . rawurlencode( $product ) . '/v1/announcement.json';
			$response = wp_remote_get( $url, [
				'timeout'             => self::HTTP_TIMEOUT,
				'limit_response_size' => self::MAX_BYTES,
				'sslverify'           => true,
			] );

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			return is_array( $data ) ? $data : null;
		}

		/* ------------------------------------------------------------------ */
		/* Sanitization                                                        */
		/* ------------------------------------------------------------------ */

		/**
		 * Normalise a raw feed to the documented contract. Unknown keys are dropped, every string is
		 * sanitized for its context, every URL is host-checked, every HTML blob goes through sanitize_html().
		 *
		 * @param mixed $feed
		 *
		 * @return array
		 */
		public static function sanitize_feed( $feed ) {
			if ( empty( $feed ) || ! is_array( $feed ) ) {
				return [];
			}

			$out = [];

			$recent           = isset( $feed['recent_id'] ) ? (array) $feed['recent_id'] : [];
			$out['recent_id'] = array_values( array_map( 'absint', array_slice( $recent, 0, 2 ) ) );
			if ( isset( $feed['last_updated'] ) ) {
				$out['last_updated'] = sanitize_text_field( (string) $feed['last_updated'] );
			}

			foreach ( [ 'features', 'changelog' ] as $list ) {
				$out[ $list ] = [];
				if ( empty( $feed[ $list ] ) || ! is_array( $feed[ $list ] ) ) {
					continue;
				}
				// Bounded: every item costs two HTML sanitizer passes (cache write, then each API read).
				foreach ( array_slice( $feed[ $list ], 0, self::MAX_ITEMS ) as $item ) {
					$item = self::sanitize_post_item( $item );
					if ( null !== $item ) {
						$out[ $list ][] = $item;
					}
				}
			}

			if ( isset( $feed['sale_banner'] ) && is_array( $feed['sale_banner'] ) ) {
				$out['sale_banner'] = [];
				foreach ( array_slice( $feed['sale_banner'], 0, self::MAX_ITEMS ) as $banner ) {
					$banner = self::sanitize_banner( $banner );
					if ( null !== $banner ) {
						$out['sale_banner'][] = $banner;
					}
				}
			}
			if ( isset( $feed['banner_count'] ) ) {
				$out['banner_count'] = max( 1, (int) $feed['banner_count'] );
			}

			// plugin_data present (even empty) = show the products box; entries pick which products.
			if ( isset( $feed['plugin_data'] ) && is_array( $feed['plugin_data'] ) ) {
				$known = array_keys( self::get_products() );
				$keys  = [];
				foreach ( $feed['plugin_data'] as $entry ) {
					$key = is_array( $entry ) && isset( $entry['key'] ) ? $entry['key'] : $entry;
					$key = is_scalar( $key ) ? sanitize_key( (string) $key ) : '';
					if ( in_array( $key, $known, true ) && ! in_array( $key, $keys, true ) ) {
						$keys[] = $key;
					}
				}
				// Consumers read an empty list as "every product", which is right for a feed that
				// sent `[]` on purpose and badly wrong for one that named only keys we do not know:
				// a typo would promote everything. Asking for nothing recognisable gets nothing.
				if ( ! empty( $feed['plugin_data'] ) && empty( $keys ) ) {
					unset( $out['plugin_data'] );
				} else {
					$out['plugin_data'] = $keys;
				}
			}

			return $out;
		}

		/**
		 * @param mixed $item Feature or changelog entry.
		 *
		 * @return array|null
		 */
		private static function sanitize_post_item( $item ) {
			if ( ! is_array( $item ) || empty( $item['post_id'] ) ) {
				return null;
			}
			$text = static function ( $key ) use ( $item ) {
				return isset( $item[ $key ] ) && is_scalar( $item[ $key ] ) ? sanitize_text_field( (string) $item[ $key ] ) : '';
			};
			$url  = static function ( $key, $group ) use ( $item ) {
				return isset( $item[ $key ] ) && is_string( $item[ $key ] ) && self::is_allowed_url( $item[ $key ], $group ) ? sanitize_url( $item[ $key ] ) : '';
			};

			$title   = $text( 'title' );
			$content = self::sanitize_html( isset( $item['content'] ) && is_string( $item['content'] ) ? $item['content'] : '' );
			// Blank = no title, no visible text, no media. Entries like that are not stored and never count as unread.
			if ( '' === $title && ! self::has_visible_content( $content ) ) {
				return null;
			}

			// No 'date': item dates are hidden for now; the panel shows one again once this returns it.
			return [
				'post_id'        => absint( $item['post_id'] ),
				'title'          => $title,
				'content'        => $content,
				'image'          => $url( 'image', 'image' ),
				'url'            => $url( 'url', 'link' ),
				'read_more_text' => $text( 'read_more_text' ),
				'pro'            => isset( $item['pro'] ) && 'yes' === $item['pro'] ? 'yes' : 'no',
				'upgrade_text'   => $text( 'upgrade_text' ),
				'upgrade_url'    => $url( 'upgrade_url', 'link' ),
			];
		}

		/**
		 * Does sanitized HTML still show anything: visible text (nbsp/whitespace ignored) or an image/embed?
		 *
		 * @param string $html
		 *
		 * @return bool
		 */
		private static function has_visible_content( $html ) {
			$has_media = false !== stripos( $html, '<img' ) || false !== stripos( $html, '<iframe' );
			$has_text  = '' !== preg_replace( '/[\s\x{00A0}]+/u', '', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );

			return $has_media || $has_text;
		}

		/**
		 * @param mixed $banner Sale banner entry.
		 *
		 * @return array|null
		 */
		private static function sanitize_banner( $banner ) {
			if ( ! is_array( $banner ) || empty( $banner['content'] ) || ! is_string( $banner['content'] ) ) {
				return null;
			}
			$content = self::sanitize_html( $banner['content'] );
			// Blank = no visible text and no media (e.g. a wrapper whose only content was a url() background).
			if ( ! self::has_visible_content( $content ) ) {
				return null;
			}
			$dependency = self::normalize_dependency( isset( $banner['dependency'] ) ? $banner['dependency'] : [] );
			if ( null === $dependency ) {
				return null; // fail closed: unsupported dependency shape
			}

			return [
				'content'    => $content,
				'is_lite'    => ! empty( $banner['is_lite'] ),
				'is_pro'     => ! empty( $banner['is_pro'] ),
				'dependency' => $dependency,
			];
		}

		/**
		 * Is this URL acceptable for the given context? https only, exact/sub-domain host match,
		 * no port or userinfo; embeds must also sit under EMBED_PATH.
		 *
		 * @param string $url
		 * @param string $group 'link' | 'image' | 'embed'
		 *
		 * @return bool
		 */
		public static function is_allowed_url( $url, $group = 'link' ) {
			$hosts = [
				'link'  => self::LINK_HOSTS,
				'image' => self::IMAGE_HOSTS,
				'embed' => self::EMBED_HOSTS,
			];
			$url   = trim( (string) $url );
			if ( '' === $url || ! isset( $hosts[ $group ] ) ) {
				return false;
			}

			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				return false;
			}
			if ( ! empty( $parts['port'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
				return false;
			}

			$host    = strtolower( $parts['host'] );
			$matched = false;
			foreach ( $hosts[ $group ] as $allowed ) {
				if ( $host === $allowed || substr( $host, - ( strlen( $allowed ) + 1 ) ) === '.' . $allowed ) {
					$matched = true;
					break;
				}
			}
			if ( ! $matched ) {
				return false;
			}
			if ( 'embed' === $group ) {
				$path = isset( $parts['path'] ) ? $parts['path'] : '';
				// Path pin is checked on the raw string, so refuse anything a browser would normalise
				// away first: dot segments, doubled or back slashes, and their percent-encoded forms.
				if ( 0 !== strpos( $path, self::EMBED_PATH ) || preg_match( self::EMBED_PATH_TRICKS, $path ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Tag/attribute allow-list handed to kses. Everything URL-bearing is re-checked by the DOM pass.
		 *
		 * @return array
		 */
		private static function get_allowed_html() {
			static $allowed = null;
			if ( null !== $allowed ) {
				return $allowed;
			}
			$post    = wp_kses_allowed_html( 'post' );
			$allowed = [];
			foreach ( self::ALLOWED_TAGS as $tag ) {
				if ( isset( $post[ $tag ] ) ) {
					$allowed[ $tag ] = $post[ $tag ];
				}
			}
			$allowed['iframe'] = array_fill_keys( [ 'src', 'width', 'height', 'title', 'allow', 'allowfullscreen', 'loading' ], true );

			return $allowed;
		}

		/**
		 * Inline style filter for feed content: the whole attribute goes when it references url() /
		 * expression() / @import, and positioning or stacking declarations (BLOCKED_CSS_PROPS) are
		 * dropped one by one so colours, spacing and typography still work.
		 *
		 * @param string $style
		 *
		 * @return string Remaining declarations, '' when nothing survives.
		 */
		public static function sanitize_style( $style ) {
			$style = (string) $style;
			if ( '' === trim( $style ) || preg_match( '/url\s*\(|expression\s*\(|@import/i', $style ) ) {
				return '';
			}
			$kept = [];
			foreach ( explode( ';', $style ) as $declaration ) {
				$declaration = trim( $declaration );
				if ( '' === $declaration ) {
					continue;
				}
				$property = strtolower( trim( strstr( $declaration, ':', true ) ) );
				if ( '' === $property || preg_match( self::BLOCKED_CSS_PROPS, $property ) ) {
					continue;
				}
				$kept[] = $declaration;
			}

			return implode( '; ', $kept );
		}

		/**
		 * Two-pass HTML sanitizer for feed content.
		 *
		 *  1. wp_kses with a fixed tag set: kills scripts, event handlers, unknown tags, bad schemes,
		 *     entity-encoded tricks.
		 *  2. DOM pass: host allow-list on <a href>, <img src|srcset>, <iframe src> (+ /embed/ path);
		 *     failing links are unwrapped to their text, failing media is removed; iframe attributes
		 *     are reduced to IFRAME_ATTRS and `allow` is pinned; `rel` is forced on target=_blank;
		 *     inline styles referencing url()/expression()/@import are dropped (kses' safecss strips
		 *     the url() token before its own allow filter runs, so it cannot be used for this) and
		 *     positioning/stacking declarations are stripped (see sanitize_style()).
		 *
		 * @param string $html
		 *
		 * @return string
		 */
		public static function sanitize_html( $html ) {
			$html = wp_kses( (string) $html, self::get_allowed_html() );
			// kses passes an unmatched </div> straight through; left alone it closes the wrapper below
			// and the whole item is dropped. Balance first so a malformed entry loses markup, not content.
			$html = force_balance_tags( $html );
			if ( '' === trim( $html ) ) {
				return '';
			}
			if ( ! class_exists( 'DOMDocument' ) ) {
				// Fallback: without ext-dom the hosts cannot be checked; fail closed by dropping every URL-bearing element.
				return wp_kses( $html, array_diff_key( self::get_allowed_html(), array_flip( [ 'a', 'img', 'iframe' ] ) ) );
			}

			$dom  = new DOMDocument();
			$prev = libxml_use_internal_errors( true );
			$dom->loadHTML( '<?xml encoding="utf-8" ?><div id="bwfan-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );

			$root = $dom->getElementById( 'bwfan-root' );
			if ( ! $root ) {
				return '';
			}
			$xpath  = new DOMXPath( $dom );
			$remove = static function ( DOMNode $node ) {
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			};

			foreach ( iterator_to_array( $xpath->query( './/*[@style]', $root ) ) as $node ) {
				$style = self::sanitize_style( $node->getAttribute( 'style' ) );
				if ( '' === $style ) {
					$node->removeAttribute( 'style' );
				} else {
					$node->setAttribute( 'style', $style );
				}
			}

			foreach ( iterator_to_array( $xpath->query( './/iframe', $root ) ) as $node ) {
				if ( ! self::is_allowed_url( $node->getAttribute( 'src' ), 'embed' ) ) {
					$remove( $node );
					continue;
				}
				foreach ( iterator_to_array( $node->attributes ) as $attr ) {
					if ( ! in_array( $attr->name, self::IFRAME_ATTRS, true ) ) {
						$node->removeAttribute( $attr->name );
					}
				}
				$node->setAttribute( 'allow', self::IFRAME_ALLOW );
				$node->setAttribute( 'loading', 'lazy' );
			}

			foreach ( iterator_to_array( $xpath->query( './/img', $root ) ) as $node ) {
				if ( ! self::is_allowed_url( $node->getAttribute( 'src' ), 'image' ) ) {
					$remove( $node );
					continue;
				}
				if ( $node->hasAttribute( 'srcset' ) ) {
					foreach ( explode( ',', $node->getAttribute( 'srcset' ) ) as $candidate ) {
						$parts = preg_split( '/\s+/', trim( $candidate ) );
						if ( ! self::is_allowed_url( $parts[0], 'image' ) ) {
							$node->removeAttribute( 'srcset' );
							break;
						}
					}
				}
			}

			foreach ( iterator_to_array( $xpath->query( './/a', $root ) ) as $node ) {
				if ( ! self::is_allowed_url( $node->getAttribute( 'href' ), 'link' ) ) {
					// Unwrap: keep the text, lose the link.
					while ( $node->firstChild ) {
						$node->parentNode->insertBefore( $node->firstChild, $node );
					}
					$remove( $node );
					continue;
				}
				// `target` is ASCII case-insensitive, so `_BLANK` opens a tab too and needs the same rel.
				if ( '_blank' === strtolower( $node->getAttribute( 'target' ) ) ) {
					$node->setAttribute( 'rel', 'noopener noreferrer' );
				}
			}

			$out = '';
			foreach ( $root->childNodes as $child ) {
				$out .= $dom->saveHTML( $child );
			}

			return $out;
		}
	}
}
