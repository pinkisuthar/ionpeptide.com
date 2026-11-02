<?php
/**
 * Rewrite simulation (v1.31): every way a customer could type one address,
 * pushed through the real matcher, so a merchant can see — before the next
 * order — whether a ban holds against the spellings a fraudster will try.
 *
 * The generator takes the address as it stands (house number, directional,
 * street name, type, unit, city, state, ZIP, lines) and varies each
 * dimension the way people and fraudsters actually do: "NW" / "N.W." /
 * "Northwest"; "66th" / "66" / "66 th" / "Sixty-Sixth"; "St" / "Street" /
 * "Str" / dropped; the unit as Apt / Ste / # / glued / repeated / on the
 * other line / first / bare / a different box number; the street on line 2
 * behind a name or a unit; the destination typed into the line; the city
 * renamed, capitalised, misspelled or blank; the ZIP rotated, extended,
 * blanked or invented; the state spelled out; Cyrillic lookalikes,
 * full-width digits, zero-width characters, glued and doubled spacing.
 * Every single-dimension change, the pairs that matter, and a seeded
 * random sample of full combinations, deduplicated on the exact address.
 *
 * simulate() runs them through FS_Blocklist::match() twice: first on the
 * typed text alone (what the locks catch with no outside help), then — for
 * whatever got through — with the geocoder, exactly as checkout would. The
 * result is a count and, above all, the list of what got THROUGH, spelled
 * out, so the gap is a thing to look at rather than a feeling.
 *
 * @package FraudShield
 */

defined( 'ABSPATH' ) || exit;

class FS_Rewrites {

	/**
	 * Default number of variants simulate() runs.
	 *
	 * @var int
	 */
	const DEFAULT_LIMIT = 240;

	/**
	 * How many typed-text misses are sent to the geocoder in one run (each
	 * is a request unless cached).
	 *
	 * @var int
	 */
	const GEOCODE_CAP = 40;

	/**
	 * Spellings of each directional in key form.
	 *
	 * @var array
	 */
	const DIRECTIONAL_FORMS = array(
		'n'  => array( 'N', 'N.', 'North', 'north', 'n' ),
		's'  => array( 'S', 'S.', 'South', 'south', 's' ),
		'e'  => array( 'E', 'E.', 'East', 'east', 'e' ),
		'w'  => array( 'W', 'W.', 'West', 'west', 'w' ),
		'nw' => array( 'NW', 'N.W.', 'N W', 'Northwest', 'North West', 'nw', 'N.W', 'NorthWest' ),
		'ne' => array( 'NE', 'N.E.', 'N E', 'Northeast', 'North East', 'ne' ),
		'sw' => array( 'SW', 'S.W.', 'S W', 'Southwest', 'South West', 'sw' ),
		'se' => array( 'SE', 'S.E.', 'S E', 'Southeast', 'South East', 'se' ),
	);

	/**
	 * Spellings of each street type in key form (the first is the standard).
	 *
	 * @var array
	 */
	const TYPE_FORMS = array(
		'st'   => array( 'St', 'Street', 'ST', 'St.', 'Str', 'Strt', 'street', 'STREET' ),
		'ave'  => array( 'Ave', 'Avenue', 'AVE', 'Av', 'Ave.', 'Aven', 'avenue' ),
		'blvd' => array( 'Blvd', 'Boulevard', 'BLVD', 'Blvd.', 'Boul', 'Blv' ),
		'dr'   => array( 'Dr', 'Drive', 'DR', 'Dr.', 'Drv' ),
		'rd'   => array( 'Rd', 'Road', 'RD', 'Rd.' ),
		'ln'   => array( 'Ln', 'Lane', 'LN', 'Ln.' ),
		'ct'   => array( 'Ct', 'Court', 'CT', 'Crt' ),
		'pl'   => array( 'Pl', 'Place', 'PL', 'Plc' ),
		'ter'  => array( 'Ter', 'Terrace', 'TER', 'Terr' ),
		'cir'  => array( 'Cir', 'Circle', 'CIR', 'Circ' ),
		'hwy'  => array( 'Hwy', 'Highway', 'HWY', 'Hiway' ),
		'pkwy' => array( 'Pkwy', 'Parkway', 'PKWY', 'Pky' ),
		'way'  => array( 'Way', 'WAY', 'Wy' ),
		'trl'  => array( 'Trl', 'Trail', 'TRL' ),
	);

	/**
	 * Unit designators to try (the value is appended), plus the bare value.
	 *
	 * @var array
	 */
	const UNIT_FORMS = array( 'Apt %s', 'Ste %s', 'Suite %s', '#%s', '# %s', 'Unit %s', 'STE%s', 'Apt %s Apt %s', 'PMB %s', 'Box %s', 'MB %s', 'Mailbox %s', 'Rm %s', '%s', 'Ste. %s', 'Apt. #%s' );

	/**
	 * Words to digits for spelling out an ordinal ("66th" → "Sixty-Sixth").
	 *
	 * @var array
	 */
	const ONES = array( '', 'First', 'Second', 'Third', 'Fourth', 'Fifth', 'Sixth', 'Seventh', 'Eighth', 'Ninth' );
	const TEENS = array( 'Tenth', 'Eleventh', 'Twelfth', 'Thirteenth', 'Fourteenth', 'Fifteenth', 'Sixteenth', 'Seventeenth', 'Eighteenth', 'Nineteenth' );
	const TENS = array( '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety' );

	/**
	 * Break an address into the pieces the generator varies.
	 *
	 * @param array $parts Address parts.
	 * @return array|null Null when there is no house number + street to vary.
	 */
	public static function decompose( $parts ) {
		$number = FS_Normalizer::house( $parts );
		$street = FS_Normalizer::street( $parts );
		if ( '' === $number || '' === $street ) {
			return null;
		}
		$tokens      = explode( ' ', $street );
		$directional = '';
		if ( in_array( $tokens[0], FS_Normalizer::DIRECTIONALS, true ) && count( $tokens ) > 1 ) {
			$directional = array_shift( $tokens );
		}
		$type = '';
		if ( count( $tokens ) > 1 && isset( self::TYPE_FORMS[ end( $tokens ) ] ) ) {
			$type = array_pop( $tokens );
		}
		// The unit value as typed, from either line, after a designator or "#".
		$unit = '';
		foreach ( array( (string) ( $parts['address_1'] ?? '' ), (string) ( $parts['address_2'] ?? '' ) ) as $line ) {
			if ( preg_match( '/(?:\b(?:apt|apartment|unit|ste|suite|rm|room|pmb|box|mailbox|mb|lot|bldg|dept|#)\.?\s*#?\s*)([A-Za-z]?\d{1,8}[A-Za-z]{0,2})\b/i', $line, $m ) ) {
				$unit = $m[1];
				break;
			}
		}
		return array(
			'number'      => $number,
			'directional' => $directional,
			'name'        => $tokens, // e.g. ['66'] or ['main'] or ['martin', 'luther', 'king', 'jr'].
			'type'        => $type,
			'unit'        => $unit,
			'city'        => (string) ( $parts['city'] ?? '' ),
			'state'       => (string) ( $parts['state'] ?? '' ),
			'postcode'    => (string) ( $parts['postcode'] ?? '' ),
			'country'     => (string) ( $parts['country'] ?? '' ),
		);
	}

	/**
	 * An ordinal number spelled out: 66 → "Sixty-Sixth".
	 *
	 * @param int $n 1–99.
	 * @return string '' outside the range.
	 */
	private static function spell_ordinal( $n ) {
		$n = (int) $n;
		if ( $n < 1 || $n > 99 ) {
			return '';
		}
		if ( $n < 10 ) {
			return self::ONES[ $n ];
		}
		if ( $n < 20 ) {
			return self::TEENS[ $n - 10 ];
		}
		$tens = self::TENS[ intdiv( $n, 10 ) ];
		$unit = $n % 10;
		if ( 0 === $unit ) {
			return rtrim( $tens, 'y' ) . 'ieth';
		}
		return $tens . '-' . self::ONES[ $unit ];
	}

	/**
	 * The alternatives for each dimension, given the decomposition. Each
	 * alternative is a label and a small override of the "shape" the
	 * assembler turns into address parts.
	 *
	 * @param array $d       decompose() output.
	 * @param array $extra   Optional extra city names and ZIPs to try (a street's boundary, say).
	 * @return array dimension => [ label => override ].
	 */
	public static function dimensions( $d, $extra = array() ) {
		$dims = array();

		// House number.
		$n = $d['number'];
		$dims['number'] = array(
			'as is'                => array( 'number' => $n ),
			'leading zero'         => array( 'number' => '0' . $n ),
			'letter suffix'        => array( 'number' => $n . 'A' ),
			'hyphenated letter'    => array( 'number' => $n . '-A' ),
			'fraction'             => array( 'number' => $n . ' 1/2' ),
			'"No." marker'         => array( 'number' => 'No. ' . $n ),
			'full-width digits'    => array( 'number' => self::fullwidth( $n ) ),
			'zero-width space'     => array( 'number' => mb_substr( $n, 0, 2 ) . "\u{200B}" . mb_substr( $n, 2 ) ),
		);

		// Directional.
		if ( '' !== $d['directional'] ) {
			$forms = self::DIRECTIONAL_FORMS[ $d['directional'] ] ?? array( strtoupper( $d['directional'] ) );
			$dims['directional'] = array();
			foreach ( $forms as $f ) {
				$dims['directional'][ '"' . $f . '"' ] = array( 'directional' => $f );
			}
			$dims['directional']['moved after the type'] = array( 'directional' => strtoupper( $d['directional'] ), 'post_directional' => true );
			$dims['directional']['glued to the number'] = array( 'directional' => strtoupper( $d['directional'] ), 'glue_number' => true );
			$dims['directional']['glued to the street'] = array( 'directional' => strtoupper( $d['directional'] ), 'glue_name' => true );
		}

		// Street name: an ordinal gets its forms, a word gets its casings.
		$name = implode( ' ', $d['name'] );
		if ( preg_match( '/^\d+$/', $name ) ) {
			$num = (int) $name;
			$suf = in_array( $num % 100, array( 11, 12, 13 ), true ) ? 'th' : array( 'th', 'st', 'nd', 'rd' )[ min( $num % 10, 4 ) % 4 ];
			if ( $num % 10 > 3 ) {
				$suf = 'th';
			}
			$dims['name'] = array(
				'ordinal'          => array( 'name' => $num . $suf ),
				'bare number'      => array( 'name' => (string) $num ),
				'ORDINAL'          => array( 'name' => $num . strtoupper( $suf ) ),
				'split suffix'     => array( 'name' => $num . ' ' . $suf ),
				'uppercase suffix' => array( 'name' => $num . 'TH' ),
			);
			$spelled = self::spell_ordinal( $num );
			if ( '' !== $spelled ) {
				$dims['name']['spelled out']       = array( 'name' => $spelled );
				$dims['name']['spelled, no hyphen'] = array( 'name' => str_replace( '-', ' ', strtolower( $spelled ) ) );
			}
		} else {
			$dims['name'] = array(
				'as is'     => array( 'name' => ucwords( $name ) ),
				'UPPERCASE' => array( 'name' => strtoupper( $name ) ),
				'lowercase' => array( 'name' => strtolower( $name ) ),
			);
		}

		// Street type.
		if ( '' !== $d['type'] ) {
			$dims['type'] = array();
			foreach ( self::TYPE_FORMS[ $d['type'] ] ?? array( $d['type'] ) as $f ) {
				$dims['type'][ '"' . $f . '"' ] = array( 'type' => $f );
			}
			$dims['type']['dropped']            = array( 'type' => '' );
			$dims['type']['Cyrillic lookalike'] = array( 'type' => self::cyrillic( self::TYPE_FORMS[ $d['type'] ][0] ?? $d['type'] ) );
		}

		// Unit.
		$u = '' !== $d['unit'] ? $d['unit'] : '100';
		$dims['unit'] = array( 'none' => array( 'unit' => '' ) );
		foreach ( self::UNIT_FORMS as $f ) {
			$dims['unit'][ sprintf( $f, $u, $u ) ] = array( 'unit' => sprintf( $f, $u, $u ) );
		}
		$dims['unit']['another box number'] = array( 'unit' => 'Ste ' . ( (string) ( (int) preg_replace( '/\D/', '', $u ) + 7 ) ) );
		$dims['unit']['seven-digit box']    = array( 'unit' => 'Ste ' . substr( str_pad( preg_replace( '/\D/', '', $u ) . '1', 7, '9' ), 0, 7 ) );

		// Where the pieces go.
		$dims['layout'] = array(
			'unit at the end of line 1'         => array( 'layout' => 'inline' ),
			'unit on line 2'                     => array( 'layout' => 'line2' ),
			'unit first on line 1'               => array( 'layout' => 'unit_first' ),
			'unit on line 1, street on line 2'   => array( 'layout' => 'swap' ),
			'"c/o Name" on line 1, street on 2'  => array( 'layout' => 'care_of' ),
			'business name on line 1'            => array( 'layout' => 'business' ),
			'city, state, ZIP typed into line 1' => array( 'layout' => 'destination' ),
			'city only typed into line 1'        => array( 'layout' => 'city_in_line' ),
			'comma after the number'             => array( 'layout' => 'comma' ),
			'double spaces, trailing period'     => array( 'layout' => 'spacing' ),
		);

		// City.
		$city = $d['city'];
		$dims['city'] = array(
			'as is'      => array( 'city' => $city ),
			'UPPERCASE'  => array( 'city' => strtoupper( $city ) ),
			'lowercase'  => array( 'city' => strtolower( $city ) ),
			'blank'      => array( 'city' => '' ),
			'misspelled' => array( 'city' => self::misspell( $city ) ),
		);
		foreach ( (array) ( $extra['cities'] ?? array() ) as $c ) {
			if ( '' !== trim( (string) $c ) && FS_Normalizer::city( $c ) !== FS_Normalizer::city( $city ) ) {
				$dims['city'][ '"' . ucwords( (string) $c ) . '"' ] = array( 'city' => ucwords( (string) $c ) );
			}
		}

		// ZIP.
		$zip  = FS_Normalizer::zip_base( strtoupper( $d['country'] ), $d['postcode'] );
		$dims['zip'] = array(
			'as is'   => array( 'postcode' => $d['postcode'] ),
			'blank'   => array( 'postcode' => '' ),
			'invalid' => array( 'postcode' => '99999' ),
			'spaced'  => array( 'postcode' => ' ' . $zip . ' ' ),
		);
		if ( 'US' === strtoupper( $d['country'] ) && preg_match( '/^\d{5}$/', $zip ) ) {
			$dims['zip']['ZIP+4']          = array( 'postcode' => $zip . '-3900' );
			$dims['zip']['neighbouring +1'] = array( 'postcode' => str_pad( (string) ( (int) $zip + 1 ), 5, '0', STR_PAD_LEFT ) );
			$dims['zip']['neighbouring -1'] = array( 'postcode' => str_pad( (string) ( (int) $zip - 1 ), 5, '0', STR_PAD_LEFT ) );
		}
		foreach ( (array) ( $extra['zips'] ?? array() ) as $z ) {
			if ( '' !== trim( (string) $z ) && (string) $z !== $zip ) {
				$dims['zip'][ '"' . $z . '"' ] = array( 'postcode' => (string) $z );
			}
		}

		// State.
		$dims['state'] = array(
			'as is'     => array( 'state' => $d['state'] ),
			'lowercase' => array( 'state' => strtolower( $d['state'] ) ),
		);
		$full = array_search( strtoupper( $d['state'] ), FS_Normalizer::REGION_NAMES, true );
		if ( false !== $full ) {
			$dims['state']['spelled out'] = array( 'state' => ucwords( $full ) );
		}

		return $dims;
	}

	/**
	 * Assemble address parts from a shape.
	 *
	 * @param array $d     decompose() output.
	 * @param array $shape Dimension overrides merged over the base.
	 * @return array Address parts.
	 */
	public static function assemble( $d, $shape ) {
		$number      = (string) ( $shape['number'] ?? $d['number'] );
		$directional = (string) ( $shape['directional'] ?? ( '' !== $d['directional'] ? strtoupper( $d['directional'] ) : '' ) );
		$name        = (string) ( $shape['name'] ?? self::default_name( $d ) );
		$type        = array_key_exists( 'type', $shape ) ? (string) $shape['type'] : ( '' !== $d['type'] ? ( self::TYPE_FORMS[ $d['type'] ][0] ?? ucfirst( $d['type'] ) ) : '' );
		$unit        = (string) ( $shape['unit'] ?? ( '' !== $d['unit'] ? 'Ste ' . $d['unit'] : '' ) );
		$layout      = (string) ( $shape['layout'] ?? 'inline' );
		$city        = array_key_exists( 'city', $shape ) ? (string) $shape['city'] : $d['city'];
		$state       = (string) ( $shape['state'] ?? $d['state'] );
		$postcode    = array_key_exists( 'postcode', $shape ) ? (string) $shape['postcode'] : $d['postcode'];

		$pieces = array();
		if ( ! empty( $shape['glue_number'] ) && '' !== $directional ) {
			$pieces[] = $number . $directional;
		} else {
			$pieces[] = $number;
			if ( '' !== $directional && empty( $shape['post_directional'] ) ) {
				if ( ! empty( $shape['glue_name'] ) ) {
					$name = $directional . $name;
				} else {
					$pieces[] = $directional;
				}
			}
		}
		$pieces[] = $name;
		if ( '' !== $type ) {
			$pieces[] = $type;
		}
		if ( '' !== $directional && ! empty( $shape['post_directional'] ) ) {
			$pieces[] = $directional;
		}
		$street_line = implode( ' ', array_filter( $pieces, 'strlen' ) );

		$a1 = $street_line;
		$a2 = '';
		switch ( $layout ) {
			case 'line2':
				$a2 = $unit;
				break;
			case 'unit_first':
				$a1 = trim( $unit . ' ' . $street_line );
				break;
			case 'swap':
				$a1 = '' !== $unit ? $unit : 'Ste 1';
				$a2 = $street_line;
				break;
			case 'care_of':
				$a1 = 'c/o Edward Rivera';
				$a2 = trim( $street_line . ' ' . $unit );
				break;
			case 'business':
				$a1 = 'Global Forwarding LLC';
				$a2 = trim( $street_line . ' ' . $unit );
				break;
			case 'destination':
				$a1 = trim( $street_line . ( '' !== $unit ? ' ' . $unit : '' ) . ', ' . $d['city'] . ', ' . $state . ' ' . FS_Normalizer::zip_base( strtoupper( $d['country'] ), $d['postcode'] ) );
				break;
			case 'city_in_line':
				$a1 = trim( $street_line . ( '' !== $unit ? ' ' . $unit : '' ) . ', ' . $d['city'] );
				break;
			case 'comma':
				$a1 = preg_replace( '/^(\S+)\s/', '$1,', $street_line ) . ( '' !== $unit ? ' ' . $unit : '' );
				break;
			case 'spacing':
				$a1 = '  ' . str_replace( ' ', '  ', $street_line ) . ( '' !== $unit ? '  ' . $unit : '' ) . '.  ';
				break;
			default:
				$a1 = trim( $street_line . ( '' !== $unit ? ' ' . $unit : '' ) );
		}

		return array(
			'address_1' => $a1,
			'address_2' => $a2,
			'city'      => $city,
			'state'     => $state,
			'postcode'  => $postcode,
			'country'   => $d['country'],
		);
	}

	/**
	 * The standard spelling of the street name for the base variant.
	 *
	 * @param array $d decompose() output.
	 * @return string
	 */
	private static function default_name( $d ) {
		$name = implode( ' ', $d['name'] );
		if ( preg_match( '/^\d+$/', $name ) ) {
			$num = (int) $name;
			$suf = 'th';
			if ( ! in_array( $num % 100, array( 11, 12, 13 ), true ) ) {
				$suf = array( 'th', 'st', 'nd', 'rd' )[ $num % 10 <= 3 ? $num % 10 : 0 ];
			}
			return $num . $suf;
		}
		return ucwords( $name );
	}

	/**
	 * Generate the variants: every single-dimension change, the pairs that
	 * matter most, then a seeded random sample of full combinations, up to
	 * the limit, deduplicated on the exact normalized address.
	 *
	 * @param array $parts Base address parts.
	 * @param int   $limit Maximum variants.
	 * @param array $extra Optional extra city names and ZIPs (a street's boundary).
	 * @return array [ [ 'label' => string, 'parts' => array ], … ] or empty when the address cannot be varied.
	 */
	public static function variants( $parts, $limit = self::DEFAULT_LIMIT, $extra = array() ) {
		$d = self::decompose( $parts );
		if ( null === $d ) {
			return array();
		}
		$dims  = self::dimensions( $d, $extra );
		$out   = array();
		$seen  = array();
		$limit = max( 10, (int) $limit );

		$add = function ( $label, $shape ) use ( $d, &$out, &$seen, $limit ) {
			if ( count( $out ) >= $limit ) {
				return;
			}
			$p   = self::assemble( $d, $shape );
			$key = FS_Normalizer::address( $p ) . '|' . strtolower( (string) $p['address_2'] );
			if ( isset( $seen[ $key ] ) ) {
				return;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'label' => $label,
				'parts' => $p,
			);
		};

		// The address exactly as given.
		$add( 'as typed', array() );

		// Every single-dimension change.
		foreach ( $dims as $dim => $alts ) {
			foreach ( $alts as $label => $shape ) {
				$add( $dim . ': ' . $label, $shape );
			}
		}

		// Pairs that matter: destination (city × ZIP), the unit's spelling ×
		// where it goes, the directional × the ordinal, the type × the layout.
		$pairs = array(
			array( 'city', 'zip' ),
			array( 'unit', 'layout' ),
			array( 'directional', 'name' ),
			array( 'type', 'layout' ),
			array( 'number', 'type' ),
		);
		foreach ( $pairs as $pair ) {
			if ( ! isset( $dims[ $pair[0] ], $dims[ $pair[1] ] ) ) {
				continue;
			}
			foreach ( $dims[ $pair[0] ] as $la => $sa ) {
				foreach ( $dims[ $pair[1] ] as $lb => $sb ) {
					$add( $pair[0] . ': ' . $la . ' + ' . $pair[1] . ': ' . $lb, array_merge( $sa, $sb ) );
				}
			}
		}

		// A seeded random sample of full combinations — the same set every
		// run, so a result can be compared with the last one.
		mt_srand( 20261012 );
		$names = array_keys( $dims );
		$tries = 0;
		while ( count( $out ) < $limit && $tries < $limit * 20 ) {
			$tries++;
			$shape  = array();
			$labels = array();
			foreach ( $names as $dim ) {
				$alts = $dims[ $dim ];
				$keys = array_keys( $alts );
				$pick = $keys[ mt_rand( 0, count( $keys ) - 1 ) ];
				$shape    = array_merge( $shape, $alts[ $pick ] );
				$labels[] = $dim . ': ' . $pick;
			}
			$add( 'combo — ' . implode( '; ', $labels ), $shape );
		}
		return $out;
	}

	/**
	 * Checkout-shaped signals for an address — the same set every
	 * enforcement point builds — with an optional identity.
	 *
	 * @param array  $parts Address parts.
	 * @param string $email Email (raw).
	 * @param string $phone Phone (raw).
	 * @param int    $uid   Account id.
	 * @return array
	 */
	public static function signals( $parts, $email = '', $phone = '', $uid = 0 ) {
		$cc = strtoupper( trim( (string) ( $parts['country'] ?? '' ) ) );
		return array(
			'order_id'         => 0,
			'order_date'       => current_time( 'mysql' ),
			'user_id'          => (int) $uid,
			'customer_name'    => '',
			'email_norm'       => FS_Normalizer::email( $email ),
			'email_domain'     => FS_Normalizer::email_domain( $email ),
			'phone_norm'       => FS_Normalizer::phone( $phone ),
			'ip'               => '',
			'ship_hash'        => FS_Normalizer::hash( FS_Normalizer::address( $parts ) ),
			'ship_loose_hash'  => FS_Normalizer::hash( FS_Normalizer::address_loose( $parts ) ),
			'ship_door_hash'   => FS_Normalizer::door( $parts ),
			'ship_door_alts'   => FS_Normalizer::door_alternates( $parts ),
			'ship_number'      => FS_Normalizer::house( $parts ),
			'ship_parts'       => $parts,
			'ship_street'      => FS_Normalizer::street( $parts ),
			'ship_city'        => FS_Normalizer::city( $parts['city'] ?? '' ),
			'ship_state'       => FS_Normalizer::region( $parts['state'] ?? '' ),
			'ship_postcode'    => FS_Normalizer::postcode( $parts['postcode'] ?? '' ),
			'shipping_country' => $cc,
			'billing_country'  => $cc,
			'ship_text'        => trim( preg_replace( '/\s+/', ' ', (string) ( $parts['address_1'] ?? '' ) . ' ' . (string) ( $parts['address_2'] ?? '' ) . ', ' . (string) ( $parts['city'] ?? '' ) . ' ' . (string) ( $parts['state'] ?? '' ) . ' ' . (string) ( $parts['postcode'] ?? '' ) . ' ' . $cc ), " ," ),
		);
	}

	/**
	 * Run the variants through the real matcher. Phase one is the typed
	 * text alone — the locks with no outside help. Phase two sends what got
	 * through to the geocoder (capped), exactly as checkout would.
	 *
	 * @param array $parts   Base address parts.
	 * @param array $options { limit:int, extra:array, email:string, phone:string, geocode:bool, geocode_cap:int }.
	 * @return array {
	 *   total:int, refused_typed:int, refused_resolved:int, review:int, through:int, geocode_skipped:int,
	 *   through_list:array, refused_sample:array, variants:array (every variant with its outcome)
	 * }
	 */
	public static function simulate( $parts, $options = array() ) {
		$limit    = (int) ( $options['limit'] ?? self::DEFAULT_LIMIT );
		$variants = self::variants( $parts, $limit, (array) ( $options['extra'] ?? array() ) );
		$email    = (string) ( $options['email'] ?? '' );
		$phone    = (string) ( $options['phone'] ?? '' );
		$geocode  = ! isset( $options['geocode'] ) || (bool) $options['geocode'];
		$cap      = isset( $options['geocode_cap'] ) ? max( 0, (int) $options['geocode_cap'] ) : self::GEOCODE_CAP;

		$result = array(
			'total'            => count( $variants ),
			'refused_typed'    => 0,
			'refused_resolved' => 0,
			'review'           => 0,
			'through'          => 0,
			'geocode_skipped'  => 0,
			'through_list'     => array(),
			'refused_sample'   => array(),
			'variants'         => array(),
		);

		$misses = array();
		foreach ( $variants as $i => $v ) {
			$s = self::signals( $v['parts'], $email, $phone );
			unset( $s['ship_parts'] ); // Typed text only.
			$m = FS_Blocklist::match( $s );
			if ( ! empty( $m ) ) {
				$result['refused_typed']++;
				$how                     = FS_Blocklist::describe_match( $m[0], self::signals( $v['parts'], $email, $phone ), FS_Blocklist::provenance( $m )[ (int) ( $m[0]['id'] ?? 0 ) ]['cards'] ?? array() )['label'];
				$result['variants'][ $i ] = array_merge( $v, array( 'outcome' => 'refused', 'phase' => 'typed', 'how' => $how, 'type' => (string) $m[0]['type'], 'key' => (string) $m[0]['value'] ) );
				if ( count( $result['refused_sample'] ) < 12 ) {
					$result['refused_sample'][] = $result['variants'][ $i ];
				}
				continue;
			}
			$misses[ $i ] = $v;
		}

		$sent = 0;
		foreach ( $misses as $i => $v ) {
			$outcome = array( 'outcome' => 'through', 'phase' => 'typed', 'how' => __( 'no lock matched the typed text', 'fraud-shield' ), 'type' => '', 'key' => '' );
			if ( $geocode && class_exists( 'FS_Address_Check' ) && FS_Address_Check::should_check( $v['parts'] ) ) {
				if ( $sent >= $cap && null === FS_Address_Check::cached( $v['parts'] ) ) {
					$result['geocode_skipped']++;
					$outcome['how'] = __( 'no lock matched the typed text; not sent to the geocoder (run cap)', 'fraud-shield' );
				} else {
					$sent++;
					$s = self::signals( $v['parts'], $email, $phone );
					$m = FS_Blocklist::match( $s );
					if ( ! empty( $m ) ) {
						$how     = FS_Blocklist::describe_match( $m[0], $s, FS_Blocklist::provenance( $m )[ (int) ( $m[0]['id'] ?? 0 ) ]['cards'] ?? array() )['label'];
						$outcome = array( 'outcome' => 'review' === (string) $m[0]['type'] ? 'review' : 'refused', 'phase' => 'resolved', 'how' => $how, 'type' => (string) $m[0]['type'], 'key' => (string) $m[0]['value'] );
					} else {
						$c = FS_Address_Check::cached( $v['parts'] );
						$outcome['how'] = is_array( $c ) && 'confirmed' === $c['status']
							? sprintf( /* translators: %s: resolved address */ __( 'resolved to %s — no lock covers it', 'fraud-shield' ), (string) $c['formatted'] )
							: ( is_array( $c ) && 'unconfirmed' === $c['status'] ? __( 'the geocoder could not find it, and no locked road is named', 'fraud-shield' ) : __( 'the geocoder did not answer', 'fraud-shield' ) );
					}
				}
			}
			$result['variants'][ $i ] = array_merge( $v, $outcome );
			if ( 'refused' === $outcome['outcome'] ) {
				$result['refused_resolved']++;
			} elseif ( 'review' === $outcome['outcome'] ) {
				$result['review']++;
			} else {
				$result['through']++;
				$result['through_list'][] = $result['variants'][ $i ];
			}
		}
		ksort( $result['variants'] );
		$result['variants'] = array_values( $result['variants'] );
		return $result;
	}

	/**
	 * Digits in full-width form.
	 *
	 * @param string $s ASCII digits.
	 * @return string
	 */
	private static function fullwidth( $s ) {
		$out = '';
		foreach ( str_split( (string) $s ) as $ch ) {
			$out .= ctype_digit( $ch ) ? mb_chr( 0xFF10 + (int) $ch, 'UTF-8' ) : $ch;
		}
		return $out;
	}

	/**
	 * A word with one Latin letter swapped for its Cyrillic lookalike.
	 *
	 * @param string $s Word.
	 * @return string
	 */
	private static function cyrillic( $s ) {
		$map = array( 'S' => 'Ѕ', 's' => 'ѕ', 'a' => 'а', 'e' => 'е', 'o' => 'о', 'A' => 'А', 'E' => 'Е', 'O' => 'О', 'T' => 'Т', 'c' => 'с', 'p' => 'р', 'B' => 'В', 'r' => 'r' );
		foreach ( str_split( (string) $s ) as $i => $ch ) {
			if ( isset( $map[ $ch ] ) && $map[ $ch ] !== $ch ) {
				return substr( $s, 0, $i ) . $map[ $ch ] . substr( $s, $i + 1 );
			}
		}
		return $s;
	}

	/**
	 * A plausible misspelling: two adjacent letters swapped.
	 *
	 * @param string $s Word.
	 * @return string
	 */
	private static function misspell( $s ) {
		$s = (string) $s;
		if ( strlen( $s ) < 4 ) {
			return $s . 'e';
		}
		$i = (int) floor( strlen( $s ) / 2 );
		return substr( $s, 0, $i - 1 ) . $s[ $i ] . $s[ $i - 1 ] . substr( $s, $i + 1 );
	}
}
