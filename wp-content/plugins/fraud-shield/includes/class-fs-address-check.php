<?php
/**
 * Address resolution (v1.31): resolve the PHYSICAL destination an order
 * ships to — standardized street, delivery ZIP, city, county, and the
 * point on the map — so a destination lock matches the place and not the
 * text, and an area ban can be drawn around it.
 *
 * A reshipper types a different ZIP, or one of a city's several acceptable
 * names, and the parcel still arrives — the carrier corrects the label. The
 * typed city and ZIP are therefore the weakest part of any geography rule.
 * A geocoder returns what the carrier will actually do: where the address
 * IS, with its corrected components — and, just as important, whether it
 * could be found at all.
 *
 * FS_Blocklist::match() uses the result three ways:
 *
 *  1. CONFIRMED address → street/building lock keys are built from the
 *     validated components as well as the typed ones. "8020 NW 66th St,
 *     Miami 33167" resolves to Doral / 33166 / Miami-Dade, and a street lock
 *     bounded to any of those meets it, whatever was typed.
 *  2. CONFIRMED address with coordinates → tested against every AREA ban
 *     (a point and a radius): inside one, the order is refused. Nothing a
 *     customer types moves the building they are shipping to.
 *  3. UNCONFIRMED address on a road that is locked somewhere in the state →
 *     stopped for correction (type 'review'), with the merchant's message,
 *     instead of being approved on text nobody could confirm. (Switchable.)
 *
 * Providers:
 *
 *  - census    — the US Census Bureau geocoder. Free, no key, no quota for
 *                interactive use, US addresses only. Matches the number and
 *                street to a TIGER address range and returns the corrected
 *                street, city, state and ZIP, the county, and a point
 *                interpolated along the block (tens of metres). The default:
 *                exactly what a US store needs, at no cost.
 *  - google    — Google Address Validation (with USPS CASS for US addresses):
 *                delivery-point confirmation, the USPS city, the county, and
 *                a rooftop geocode. Paid beyond the monthly credit; needs a key.
 *  - nominatim — OpenStreetMap's geocoder. Free and worldwide, but its usage
 *                policy allows one request per second and asks for a contact
 *                in the User-Agent; results carry the OSM house number and
 *                road when the address is known to the map. Suited to a
 *                small store outside the US; the throttle here enforces the
 *                policy.
 *
 * Results are cached per typed address for thirty days — an address does not
 * move — and a failed request for ten minutes, so an outage costs one slow
 * call, not one per attempt. No request is made unless there is something to
 * resolve against (a street-level lock with validation on, or an area ban).
 * On any failure the check reads 'unavailable' and matching proceeds on the
 * typed address exactly as before — an outage must never block a checkout.
 *
 * @package FraudShield
 */

defined( 'ABSPATH' ) || exit;

class FS_Address_Check {

	const GOOGLE_ENDPOINT    = 'https://addressvalidation.googleapis.com/v1:validateAddress';
	const CENSUS_ENDPOINT    = 'https://geocoding.geo.census.gov/geocoder/geographies/address';
	const NOMINATIM_ENDPOINT = 'https://nominatim.openstreetmap.org/search';

	/**
	 * How long a resolved (confirmed or unconfirmed) result is kept.
	 *
	 * @var int
	 */
	const CACHE_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * How long a failed lookup is remembered before the service is asked again.
	 *
	 * @var int
	 */
	const FAIL_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Request budget for one lookup, in seconds. This runs inside a checkout
	 * submission; a slow geocoder must not become a slow checkout.
	 *
	 * @var int
	 */
	const TIMEOUT = 4;

	/**
	 * How long the provider is left alone after a failed request — the
	 * circuit breaker. During an outage one checkout pays the timeout, then
	 * every checkout for the next two minutes skips the request outright
	 * (typed-text matching only, fail open).
	 *
	 * @var int
	 */
	const BREAKER_TTL = 2 * MINUTE_IN_SECONDS;

	/**
	 * Per-request memo: cache key → result.
	 *
	 * @var array
	 */
	private static $memo = array();

	/**
	 * The configured provider: 'census' | 'google' | 'nominatim'.
	 *
	 * @return string
	 */
	public static function provider() {
		$s = FS_Core::instance()->settings();
		$p = (string) ( $s['addr_check_provider'] ?? 'census' );
		return in_array( $p, array( 'census', 'google', 'nominatim' ), true ) ? $p : 'census';
	}

	/**
	 * Whether the configured provider can be called at all (Google needs a key;
	 * the Census and Nominatim geocoders need nothing).
	 *
	 * @return bool
	 */
	public static function provider_ready() {
		if ( 'google' !== self::provider() ) {
			return true;
		}
		$s = FS_Core::instance()->settings();
		return '' !== trim( (string) ( $s['addr_check_google_key'] ?? '' ) );
	}

	/**
	 * Whether the provider covers an address in this country.
	 *
	 * @param string $country Country code.
	 * @return bool
	 */
	public static function covers( $country ) {
		$cc = strtoupper( trim( (string) $country ) );
		if ( '' === $cc ) {
			return false;
		}
		return 'census' === self::provider() ? in_array( $cc, array( 'US', 'PR' ), true ) : true;
	}

	/**
	 * Whether the merchant has switched address VALIDATION on (street and
	 * building locks matched against the resolved address; the correction
	 * stop). Area bans resolve coordinates whether or not this is on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$s = FS_Core::instance()->settings();
		return ! empty( $s['addr_check_enabled'] ) && self::provider_ready();
	}

	/**
	 * Whether an unconfirmed address on a locked road is stopped for
	 * correction (the default once validation is on) or allowed through on
	 * its typed destination.
	 *
	 * @return bool
	 */
	public static function stops_ambiguous() {
		$s = FS_Core::instance()->settings();
		return self::enabled() && 'allow' !== (string) ( $s['addr_check_ambiguous_action'] ?? 'block' );
	}

	/**
	 * The customer-facing correction message for an unverifiable address.
	 *
	 * @return string
	 */
	public static function ambiguous_message() {
		$s   = FS_Core::instance()->settings();
		$msg = trim( (string) ( $s['addr_check_ambiguous_message'] ?? '' ) );
		return '' !== $msg ? $msg : self::default_ambiguous_message();
	}

	/**
	 * Default correction message.
	 *
	 * @return string
	 */
	public static function default_ambiguous_message() {
		return __( 'We could not verify this shipping address against postal records. Please check the street, city, state and ZIP code and try again — or contact us and we will help.', 'fraud-shield' );
	}

	/**
	 * Whether a lookup is worth making for this address at checkout: the
	 * provider is ready and covers the country, the address has a first line,
	 * and there is something to resolve it against — a street-level lock
	 * (with validation switched on) or an area ban (always).
	 *
	 * @param array $parts Address parts (address_1, address_2, city, state, postcode, country).
	 * @return bool
	 */
	public static function should_check( $parts ) {
		if ( ! is_array( $parts ) || ! self::provider_ready() ) {
			return false;
		}
		if ( '' === trim( (string) ( $parts['address_1'] ?? '' ) ) || ! self::covers( $parts['country'] ?? '' ) ) {
			return false;
		}
		// Scoped to the address's own region: a Florida lock never costs a
		// Texan's checkout a request.
		$cc = (string) ( $parts['country'] ?? '' );
		$st = (string) ( $parts['state'] ?? '' );
		if ( FS_Blocklist::has_geofences( $cc, $st ) ) {
			return true;
		}
		return self::enabled() && FS_Blocklist::has_street_level_locks( $cc, $st, (string) ( $parts['postcode'] ?? '' ) );
	}

	/**
	 * Whether the provider is currently being left alone after a failure.
	 *
	 * @return bool
	 */
	public static function breaker_open() {
		return (bool) get_transient( 'fs_addr_check_down' );
	}

	/**
	 * Resolve an address on demand — the admin's "where is this?" (the
	 * address page's location panel, the area-ban form). Ignores the
	 * checkout gate in should_check(); still needs a ready provider that
	 * covers the country.
	 *
	 * @param array $parts Address parts.
	 * @return array|null Result, or null when no provider can be asked.
	 */
	public static function locate( $parts ) {
		if ( ! is_array( $parts ) || ! self::provider_ready() || ! self::covers( $parts['country'] ?? '' ) || '' === trim( (string) ( $parts['address_1'] ?? '' ) ) ) {
			return null;
		}
		return self::check( $parts );
	}

	/**
	 * Cache key for a typed address: the canonical exact-address string,
	 * hashed, so every spelling of one typed address shares one lookup. The
	 * provider is part of it — two providers' answers are two answers.
	 *
	 * @param array $parts Address parts.
	 * @return string
	 */
	public static function cache_key( $parts ) {
		return 'fs_addr_' . substr( FS_Normalizer::hash( self::provider() . '|' . FS_Normalizer::address( $parts ) . '|' . strtoupper( trim( (string) ( $parts['country'] ?? '' ) ) ) ), 0, 40 );
	}

	/**
	 * A result already in hand for this address — memo or transient — or
	 * null. Never makes a request; what describe_match() reads so the staff
	 * view can say what an address resolved to without a second lookup.
	 *
	 * @param array $parts Address parts.
	 * @return array|null
	 */
	public static function cached( $parts ) {
		if ( ! is_array( $parts ) ) {
			return null;
		}
		$key = self::cache_key( $parts );
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}
		$t = get_transient( $key );
		return is_array( $t ) ? $t : null;
	}

	/**
	 * Resolve an address: memo → transient → the provider. Always returns a
	 * result array; status 'unavailable' when the provider could not be asked
	 * or did not answer usably.
	 *
	 * @param array $parts Address parts.
	 * @return array {
	 *   status:'confirmed'|'unconfirmed'|'unavailable', provider, country, state, city, city_usps, postcode,
	 *   county, number, street, lat:?float, lng:?float, precision, replaced:string[], unconfirmed:string[],
	 *   dpv, granularity, formatted, error, at:int
	 * }
	 */
	public static function check( $parts ) {
		$cached = self::cached( $parts );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$key = self::cache_key( $parts );

		// Outage: the breaker is open, so no request is made at all.
		if ( self::breaker_open() ) {
			$result             = self::unavailable( $parts, self::provider(), __( 'geocoder paused after a recent failure', 'fraud-shield' ) );
			self::$memo[ $key ] = $result;
			return $result;
		}

		switch ( self::provider() ) {
			case 'google':
				$result = self::via_google( $parts );
				break;
			case 'nominatim':
				$result = self::via_nominatim( $parts );
				break;
			default:
				$result = self::via_census( $parts );
		}

		if ( 'unavailable' === $result['status'] ) {
			set_transient( 'fs_addr_check_down', 1, self::BREAKER_TTL );
		}
		self::$memo[ $key ] = $result;
		set_transient( $key, $result, 'unavailable' === $result['status'] ? self::FAIL_TTL : self::CACHE_TTL );
		return $result;
	}

	/**
	 * Forget a cached result (a merchant re-trying after fixing a key).
	 *
	 * @param array $parts Address parts.
	 */
	public static function forget( $parts ) {
		$key = self::cache_key( $parts );
		unset( self::$memo[ $key ] );
		delete_transient( $key );
	}

	/**
	 * Drop the per-request memo (the transients stay). For long-running
	 * processes and the harnesses, where one PHP process plays many requests.
	 */
	public static function flush() {
		self::$memo = array();
	}

	/**
	 * Whether a result carries a point precise enough to test against an
	 * area ban: a confirmed address located to the building, the block or
	 * the street — never a town or postcode centroid, which can sit miles
	 * from the door.
	 *
	 * @param array $check Result of check().
	 * @return bool
	 */
	public static function has_point( $check ) {
		return is_array( $check )
			&& 'confirmed' === ( $check['status'] ?? '' )
			&& null !== ( $check['lat'] ?? null ) && null !== ( $check['lng'] ?? null )
			&& in_array( (string) ( $check['precision'] ?? '' ), array( 'rooftop', 'range', 'street' ), true );
	}

	/* ===================== Providers ===================== */

	/**
	 * The address line that carries the house number and street — line 1,
	 * unless line 1 is a unit or a name and line 2 is the street.
	 *
	 * @param array $parts Address parts.
	 * @return string
	 */
	private static function street_line( $parts ) {
		$a1 = trim( (string) ( $parts['address_1'] ?? '' ) );
		$a2 = trim( (string) ( $parts['address_2'] ?? '' ) );
		if ( '' !== $a2 && '' === FS_Normalizer::house( array( 'address_1' => $a1 ) ) && '' !== FS_Normalizer::house( array( 'address_1' => $a2 ) ) ) {
			return $a2;
		}
		return $a1;
	}

	/**
	 * US Census Bureau geocoder (structured address endpoint, with county
	 * geography). Free, no key.
	 *
	 * @param array $parts Address parts.
	 * @return array
	 */
	private static function via_census( $parts ) {
		$query = array(
			'street'    => self::street_line( $parts ),
			'benchmark' => 'Public_AR_Current',
			'vintage'   => 'Current_Current',
			'layers'    => 'Counties',
			'format'    => 'json',
		);
		foreach ( array( 'city' => 'city', 'state' => 'state', 'zip' => 'postcode' ) as $q => $part ) {
			if ( '' !== trim( (string) ( $parts[ $part ] ?? '' ) ) ) {
				$query[ $q ] = trim( (string) $parts[ $part ] );
			}
		}
		$response = wp_remote_get(
			add_query_arg( array_map( 'rawurlencode', $query ), self::CENSUS_ENDPOINT ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::unavailable( $parts, 'census', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $json ) || ! isset( $json['result'] ) || ! is_array( $json['result'] ) ) {
			/* translators: %d: HTTP status code */
			return self::unavailable( $parts, 'census', sprintf( __( 'Census geocoder error (HTTP %d).', 'fraud-shield' ), $code ) );
		}

		$cc      = 'US';
		$matches = (array) ( $json['result']['addressMatches'] ?? array() );
		if ( empty( $matches ) ) {
			// Asked and answered: no such address. Unconfirmed, not unavailable.
			return self::shape( 'unconfirmed', 'census', $cc, '', '', '', '', '', array( 'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '', 'postcode' => '', 'country' => $cc ), null, null, '', array(), array( 'street_number', 'route' ), '', '', '' );
		}
		$m    = (array) $matches[0];
		$ac   = (array) ( $m['addressComponents'] ?? array() );
		$geo  = (array) ( $m['geographies']['Counties'][0] ?? array() );
		$line = trim( (string) ( $m['matchedAddress'] ?? '' ) );
		// matchedAddress is "8020 NW 66TH ST, MIAMI, FL, 33166" — the first
		// segment is the standardized street line.
		$street_line = '' !== $line ? trim( (string) strtok( $line, ',' ) ) : '';
		if ( '' === $street_line ) {
			$street_line = trim( implode( ' ', array_filter( array( (string) ( $ac['fromAddress'] ?? '' ), (string) ( $ac['preDirection'] ?? '' ), (string) ( $ac['streetName'] ?? '' ), (string) ( $ac['suffixType'] ?? '' ), (string) ( $ac['suffixDirection'] ?? '' ) ) ) ) );
		}
		$vparts = array(
			'address_1' => $street_line,
			'address_2' => '',
			'city'      => (string) ( $ac['city'] ?? '' ),
			'state'     => (string) ( $ac['state'] ?? '' ),
			'postcode'  => (string) ( $ac['zip'] ?? '' ),
			'country'   => $cc,
		);
		// The typed house number is the one that was matched (the components
		// carry the block's range, not the house) — read it from the typed line.
		$number = FS_Normalizer::house( array( 'address_1' => self::street_line( $parts ) ) );
		if ( '' === $number ) {
			$number = FS_Normalizer::house( $vparts );
		}
		$replaced = array();
		if ( '' !== FS_Normalizer::zip_base( $cc, $parts['postcode'] ?? '' ) && FS_Normalizer::zip_base( $cc, $parts['postcode'] ?? '' ) !== FS_Normalizer::zip_base( $cc, $vparts['postcode'] ) ) {
			$replaced[] = 'postal_code';
		}
		if ( '' !== FS_Normalizer::city( $parts['city'] ?? '' ) && FS_Normalizer::city( $parts['city'] ?? '' ) !== FS_Normalizer::city( $vparts['city'] ) ) {
			$replaced[] = 'locality';
		}
		$lat = isset( $m['coordinates']['y'] ) ? (float) $m['coordinates']['y'] : null;
		$lng = isset( $m['coordinates']['x'] ) ? (float) $m['coordinates']['x'] : null;

		return self::shape( 'confirmed', 'census', $cc, $vparts['state'], $vparts['city'], '', $vparts['postcode'], (string) ( $geo['BASENAME'] ?? ( $geo['NAME'] ?? '' ) ), $vparts, $lat, $lng, 'range', $replaced, array(), '', '', $line, $number );
	}

	/**
	 * Google Address Validation (+ USPS CASS). The key travels in a header,
	 * never in the URL, so it stays out of access logs.
	 *
	 * @param array $parts Address parts.
	 * @return array
	 */
	private static function via_google( $parts ) {
		$s  = FS_Core::instance()->settings();
		$cc = strtoupper( trim( (string) ( $parts['country'] ?? '' ) ) );

		$lines = array( trim( (string) ( $parts['address_1'] ?? '' ) ) );
		if ( '' !== trim( (string) ( $parts['address_2'] ?? '' ) ) ) {
			$lines[] = trim( (string) $parts['address_2'] );
		}
		$address = array(
			'regionCode'   => $cc,
			'addressLines' => $lines,
		);
		foreach ( array( 'administrativeArea' => 'state', 'locality' => 'city', 'postalCode' => 'postcode' ) as $field => $part ) {
			if ( '' !== trim( (string) ( $parts[ $part ] ?? '' ) ) ) {
				$address[ $field ] = trim( (string) $parts[ $part ] );
			}
		}
		$body = array( 'address' => $address );
		if ( in_array( $cc, array( 'US', 'PR' ), true ) ) {
			$body['enableUspsCass'] = true;
		}

		$response = wp_remote_post(
			self::GOOGLE_ENDPOINT,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'Accept'         => 'application/json',
					'X-Goog-Api-Key' => trim( (string) $s['addr_check_google_key'] ),
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::unavailable( $parts, 'google', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $json ) || empty( $json['result'] ) ) {
			/* translators: %d: HTTP status code */
			return self::unavailable( $parts, 'google', sprintf( __( 'Address validation error (HTTP %d).', 'fraud-shield' ), $code ) );
		}

		$r       = (array) $json['result'];
		$verdict = (array) ( $r['verdict'] ?? array() );
		$addr    = (array) ( $r['address'] ?? array() );
		$postal  = (array) ( $addr['postalAddress'] ?? array() );
		$usps    = (array) ( $r['uspsData'] ?? array() );
		$std     = (array) ( $usps['standardizedAddress'] ?? array() );
		$cc      = strtoupper( trim( (string) ( $postal['regionCode'] ?? $cc ) ) );

		$replaced    = array();
		$unconfirmed = array();
		$components  = array();
		foreach ( (array) ( $addr['addressComponents'] ?? array() ) as $c ) {
			$type = (string) ( $c['componentType'] ?? '' );
			$text = (string) ( $c['componentName']['text'] ?? '' );
			if ( '' !== $type ) {
				$components[ $type ] = $text;
				if ( ! empty( $c['replaced'] ) || ! empty( $c['spellCorrected'] ) || ! empty( $c['inferred'] ) ) {
					$replaced[] = $type;
				}
				if ( 'CONFIRMED' !== (string) ( $c['confirmationLevel'] ?? 'CONFIRMED' ) ) {
					$unconfirmed[] = $type;
				}
			}
		}
		foreach ( (array) ( $addr['unconfirmedComponentTypes'] ?? array() ) as $t ) {
			$unconfirmed[] = (string) $t;
		}
		$unconfirmed = array_values( array_unique( $unconfirmed ) );

		$line = trim( (string) ( $std['firstAddressLine'] ?? '' ) );
		if ( '' === $line && ! empty( $postal['addressLines'] ) ) {
			$line = trim( (string) $postal['addressLines'][0] );
		}
		if ( '' === $line && ( isset( $components['street_number'] ) || isset( $components['route'] ) ) ) {
			$line = trim( (string) ( $components['street_number'] ?? '' ) . ' ' . (string) ( $components['route'] ?? '' ) );
		}
		$state    = (string) ( $postal['administrativeArea'] ?? ( $std['state'] ?? ( $components['administrative_area_level_1'] ?? '' ) ) );
		$locality = (string) ( $postal['locality'] ?? ( $components['locality'] ?? '' ) );
		$city_us  = (string) ( $std['city'] ?? '' );
		$zip      = (string) ( $std['zipCode'] ?? ( $postal['postalCode'] ?? ( $components['postal_code'] ?? '' ) ) );
		$county   = (string) ( $usps['county'] ?? ( $components['administrative_area_level_2'] ?? '' ) );
		$vparts   = array(
			'address_1' => $line,
			'address_2' => '',
			'city'      => $locality,
			'state'     => $state,
			'postcode'  => $zip,
			'country'   => $cc,
		);

		$granularity = strtoupper( (string) ( $verdict['validationGranularity'] ?? '' ) );
		$geo_gran    = strtoupper( (string) ( $verdict['geocodeGranularity'] ?? $granularity ) );
		$dpv         = strtoupper( (string) ( $usps['dpvConfirmation'] ?? '' ) );

		// Confirmed at the BUILDING: the delivery point is known (DPV Y, or S/D
		// — the building is confirmed and only the unit is wrong or missing),
		// or Google resolved to the premise with nothing unconfirmed beyond the
		// unit. A forwarder's odd suite number is exactly the kind of thing
		// that stays unconfirmed, and it is the one component these locks do
		// not care about.
		$only_unit = empty( array_diff( $unconfirmed, array( 'subpremise' ) ) );
		$confirmed = in_array( $dpv, array( 'Y', 'S', 'D' ), true )
			|| ( in_array( $granularity, array( 'PREMISE', 'SUB_PREMISE' ), true ) && $only_unit && '' !== FS_Normalizer::street( $vparts ) );

		$lat = isset( $r['geocode']['location']['latitude'] ) ? (float) $r['geocode']['location']['latitude'] : null;
		$lng = isset( $r['geocode']['location']['longitude'] ) ? (float) $r['geocode']['location']['longitude'] : null;
		$precision_map = array(
			'SUB_PREMISE'       => 'rooftop',
			'PREMISE'           => 'rooftop',
			'PREMISE_PROXIMITY' => 'range',
			'BLOCK'             => 'street',
			'ROUTE'             => 'street',
		);
		$precision = $precision_map[ $geo_gran ] ?? ( null !== $lat ? 'area' : '' );

		return self::shape( $confirmed ? 'confirmed' : 'unconfirmed', 'google', $cc, $state, $locality, $city_us, $zip, $county, $vparts, $lat, $lng, $precision, array_values( array_unique( $replaced ) ), $unconfirmed, $dpv, $granularity, (string) ( $addr['formattedAddress'] ?? '' ) );
	}

	/**
	 * OpenStreetMap Nominatim. Free, worldwide, one request per second by
	 * policy — enforced here with a short wait, never a burst.
	 *
	 * @param array $parts Address parts.
	 * @return array
	 */
	private static function via_nominatim( $parts ) {
		$s  = FS_Core::instance()->settings();
		$cc = strtoupper( trim( (string) ( $parts['country'] ?? '' ) ) );

		// Policy: at most one request per second, identified by a contact.
		$last = (float) get_transient( 'fs_nominatim_last' );
		$wait = 1.05 - ( microtime( true ) - $last );
		if ( $last > 0 && $wait > 0 && $wait < 1.2 ) {
			usleep( (int) ( $wait * 1000000 ) );
		}
		set_transient( 'fs_nominatim_last', microtime( true ), MINUTE_IN_SECONDS );

		$query = array(
			'format'         => 'jsonv2',
			'addressdetails' => 1,
			'limit'          => 1,
			'street'         => self::street_line( $parts ),
			'country'        => $cc,
		);
		foreach ( array( 'city' => 'city', 'state' => 'state', 'postalcode' => 'postcode' ) as $q => $part ) {
			if ( '' !== trim( (string) ( $parts[ $part ] ?? '' ) ) ) {
				$query[ $q ] = trim( (string) $parts[ $part ] );
			}
		}
		$contact = trim( (string) ( $s['addr_check_contact'] ?? '' ) );
		if ( '' === $contact ) {
			$contact = (string) get_option( 'admin_email' );
		}
		$ua = 'FraudShield/' . ( defined( 'FS_VERSION' ) ? FS_VERSION : '1' ) . ' (' . home_url( '/' ) . '; ' . $contact . ')';

		$response = wp_remote_get(
			add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), self::NOMINATIM_ENDPOINT ),
			array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => $ua,
				'headers'    => array(
					'Accept'          => 'application/json',
					'Accept-Language' => 'en',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::unavailable( $parts, 'nominatim', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
			/* translators: %d: HTTP status code */
			return self::unavailable( $parts, 'nominatim', sprintf( __( 'Nominatim error (HTTP %d).', 'fraud-shield' ), $code ) );
		}
		if ( empty( $json ) ) {
			return self::shape( 'unconfirmed', 'nominatim', $cc, '', '', '', '', '', array( 'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '', 'postcode' => '', 'country' => $cc ), null, null, '', array(), array( 'street_number', 'route' ), '', '', '' );
		}
		$hit  = (array) $json[0];
		$a    = (array) ( $hit['address'] ?? array() );
		$num  = (string) ( $a['house_number'] ?? '' );
		$road = (string) ( $a['road'] ?? '' );
		$city = '';
		foreach ( array( 'city', 'town', 'village', 'municipality', 'hamlet', 'suburb' ) as $k ) {
			if ( '' !== (string) ( $a[ $k ] ?? '' ) ) {
				$city = (string) $a[ $k ];
				break;
			}
		}
		$vparts = array(
			'address_1' => trim( $num . ' ' . $road ),
			'address_2' => '',
			'city'      => $city,
			'state'     => (string) ( $a['state'] ?? '' ),
			'postcode'  => (string) ( $a['postcode'] ?? '' ),
			'country'   => strtoupper( (string) ( $a['country_code'] ?? $cc ) ),
		);
		$confirmed = '' !== $num && '' !== $road;
		$precision = $confirmed ? 'rooftop' : ( '' !== $road ? 'street' : 'area' );
		$lat       = isset( $hit['lat'] ) ? (float) $hit['lat'] : null;
		$lng       = isset( $hit['lon'] ) ? (float) $hit['lon'] : null;

		return self::shape( $confirmed ? 'confirmed' : 'unconfirmed', 'nominatim', $vparts['country'], $vparts['state'], $city, '', $vparts['postcode'], (string) ( $a['county'] ?? '' ), $vparts, $lat, $lng, $precision, array(), $confirmed ? array() : array( 'street_number' ), '', '', (string) ( $hit['display_name'] ?? '' ) );
	}

	/* ===================== Shapes ===================== */

	/**
	 * The common result shape.
	 *
	 * @param string     $status      confirmed | unconfirmed.
	 * @param string     $provider    Provider id.
	 * @param string     $cc          Country code.
	 * @param string     $state       Raw state.
	 * @param string     $city        Raw locality.
	 * @param string     $city_usps   Raw USPS city ('' when the provider has none).
	 * @param string     $zip         Raw postcode.
	 * @param string     $county      Raw county.
	 * @param array      $vparts      The validated address parts (for the street parser).
	 * @param float|null $lat         Latitude.
	 * @param float|null $lng         Longitude.
	 * @param string     $precision   rooftop | range | street | area | ''.
	 * @param array      $replaced    Component types the provider changed.
	 * @param array      $unconfirmed Component types left unconfirmed.
	 * @param string     $dpv         USPS DPV code, when known.
	 * @param string     $granularity Provider granularity, when known.
	 * @param string     $formatted   The resolved address as text.
	 * @param string     $number      House number override ('' = parse from $vparts).
	 * @return array
	 */
	private static function shape( $status, $provider, $cc, $state, $city, $city_usps, $zip, $county, $vparts, $lat, $lng, $precision, $replaced, $unconfirmed, $dpv, $granularity, $formatted, $number = '' ) {
		return array(
			'status'      => $status,
			'provider'    => $provider,
			'country'     => strtoupper( trim( (string) $cc ) ),
			'state'       => FS_Normalizer::region( $state ),
			'city'        => FS_Normalizer::city( $city ),
			'city_usps'   => FS_Normalizer::city( $city_usps ),
			'postcode'    => FS_Normalizer::postcode( $zip ),
			'county'      => FS_Normalizer::county( $county ),
			'number'      => '' !== $number ? $number : FS_Normalizer::house( $vparts ),
			'street'      => FS_Normalizer::street( $vparts ),
			'lat'         => null === $lat ? null : round( (float) $lat, 6 ),
			'lng'         => null === $lng ? null : round( (float) $lng, 6 ),
			'precision'   => (string) $precision,
			'replaced'    => array_values( (array) $replaced ),
			'unconfirmed' => array_values( (array) $unconfirmed ),
			'dpv'         => (string) $dpv,
			'granularity' => (string) $granularity,
			'formatted'   => (string) $formatted,
			'error'       => '',
			'at'          => time(),
		);
	}

	/**
	 * The result shape for a lookup that could not be made or did not answer.
	 *
	 * @param array  $parts    Typed address.
	 * @param string $provider Provider id.
	 * @param string $error    Why.
	 * @return array
	 */
	private static function unavailable( $parts, $provider, $error = '' ) {
		$r          = self::shape( 'unavailable', $provider, (string) ( $parts['country'] ?? '' ), '', '', '', '', '', array( 'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '', 'postcode' => '', 'country' => (string) ( $parts['country'] ?? '' ) ), null, null, '', array(), array(), '', '', '' );
		$r['error'] = (string) $error;
		return $r;
	}
}
