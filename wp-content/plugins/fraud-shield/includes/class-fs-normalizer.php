<?php
/**
 * Normalization + hashing of identity signals.
 *
 * The whole detection engine depends on collapsing "the same thing typed
 * slightly differently" into one canonical key. e.g. gmail dot/plus aliases,
 * phone formatting, "123 Main St" vs "123 Main Street, Apt 2".
 *
 * @package FraudShield
 */

defined( 'ABSPATH' ) || exit;

class FS_Normalizer {

	/**
	 * Normalize an email. Lowercases, strips +tags, and collapses gmail dots.
	 *
	 * @param string $email Raw email.
	 * @return string
	 */
	public static function email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email || strpos( $email, '@' ) === false ) {
			return $email;
		}

		list( $local, $domain ) = explode( '@', $email, 2 );
		$domain                 = trim( $domain );

		// gmail / googlemail: dots are ignored and +tags are ignored.
		if ( in_array( $domain, array( 'gmail.com', 'googlemail.com' ), true ) ) {
			$local  = str_replace( '.', '', $local );
			$domain = 'gmail.com';
		}

		$plus = strpos( $local, '+' );
		if ( false !== $plus ) {
			$local = substr( $local, 0, $plus );
		}

		return $local . '@' . $domain;
	}

	/**
	 * Extract the email domain after normalization.
	 *
	 * @param string $email Raw email.
	 * @return string
	 */
	public static function email_domain( $email ) {
		$norm = self::email( $email );
		$at   = strpos( $norm, '@' );
		return ( false === $at ) ? '' : substr( $norm, $at + 1 );
	}

	/**
	 * Normalize a phone number to digits only (US: strip leading country code 1).
	 *
	 * @param string $phone Raw phone.
	 * @return string
	 */
	public static function phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( null === $digits ) {
			return '';
		}
		if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
			$digits = substr( $digits, 1 );
		}
		return $digits;
	}

	/**
	 * Build a canonical, normalized full shipping-address string.
	 *
	 * @param array $a Associative parts: address_1, address_2, city, state, postcode, country.
	 * @return string
	 */
	public static function address( $a ) {
		$parts = array(
			$a['address_1'] ?? '',
			$a['address_2'] ?? '',
			$a['city'] ?? '',
			$a['state'] ?? '',
			$a['postcode'] ?? '',
			$a['country'] ?? '',
		);
		return self::canonicalize( implode( ' ', $parts ) );
	}

	/**
	 * Build a "loose" street-level key (address_1 + postcode only).
	 * Catches fraudsters who keep the same drop house but vary unit/apt numbers.
	 *
	 * @param array $a Associative parts.
	 * @return string
	 */
	public static function address_loose( $a ) {
		return self::canonicalize( ( $a['address_1'] ?? '' ) . ' ' . ( $a['postcode'] ?? '' ) );
	}

	/**
	 * Lowercase, strip punctuation, standardize common abbreviations, collapse whitespace.
	 *
	 * @param string $s Input.
	 * @return string
	 */
	public static function canonicalize( $s ) {
		$s = strtolower( trim( (string) $s ) );
		$s = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $s ); // drop punctuation.
		$s = preg_replace( '/\s+/', ' ', $s );

		$map = array(
			'street'    => 'st',
			'avenue'    => 'ave',
			'av'        => 'ave',
			'boulevard' => 'blvd',
			'drive'     => 'dr',
			'road'      => 'rd',
			'lane'      => 'ln',
			'court'     => 'ct',
			'place'     => 'pl',
			'terrace'   => 'ter',
			'circle'    => 'cir',
			'highway'   => 'hwy',
			'parkway'   => 'pkwy',
			'suite'     => 'ste',
			'apartment' => 'apt',
			'apt'       => 'apt',
			'unit'      => 'apt',
			'number'    => '',
			'north'     => 'n',
			'south'     => 's',
			'east'      => 'e',
			'west'      => 'w',
			'first'     => '1',
			'second'    => '2',
			'third'     => '3',
		);

		$tokens = explode( ' ', $s );
		$out    = array();
		foreach ( $tokens as $t ) {
			if ( '' === $t ) {
				continue;
			}
			if ( isset( $map[ $t ] ) ) {
				$t = $map[ $t ];
			}
			if ( '' !== $t ) {
				$out[] = $t;
			}
		}

		return trim( implode( ' ', $out ) );
	}

	/**
	 * Normalize a city name for EXACT destination matching. Deliberately does
	 * NOT apply canonicalize()'s street-abbreviation map — that would mangle real
	 * city names ("North Miami" → "n miami", "West Palm Beach" → "w palm beach").
	 * Lowercases, strips punctuation (keeps letters/numbers/spaces, unicode-aware),
	 * collapses whitespace, trims.
	 *
	 * @param string $s Raw city.
	 * @return string
	 */
	public static function city( $s ) {
		$s = strtolower( trim( (string) $s ) );
		$s = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $s );
		$s = preg_replace( '/\s+/', ' ', (string) $s );
		return trim( (string) $s );
	}

	/**
	 * Normalize a region/state. WooCommerce supplies state CODES (e.g. "FL") for
	 * countries with defined states and free text otherwise; both normalize fine
	 * as an uppercased, city-normalized string.
	 *
	 * @param string $s Raw state/region.
	 * @return string
	 */
	public static function region( $s ) {
		$c = self::city( $s );
		if ( isset( self::REGION_NAMES[ $c ] ) ) {
			return self::REGION_NAMES[ $c ];
		}
		return strtoupper( $c );
	}

	/**
	 * Normalize a postcode: uppercase, strip ALL whitespace ("SW1A 1AA" →
	 * "SW1A1AA"), trim, cap at 16 chars. ZIP-prefix matching operates on this form.
	 *
	 * @param string $s Raw postcode.
	 * @return string
	 */
	public static function postcode( $s ) {
		$s = strtoupper( trim( (string) $s ) );
		$s = preg_replace( '/\s+/', '', (string) $s );
		return mb_substr( (string) $s, 0, 16 );
	}

	/**
	 * Secondary-unit designators (USPS Publication 28, plus the long forms and
	 * the "No. 4" habit), in the shape canonicalize() leaves them in. Anything
	 * from one of these onwards is the unit, not the street.
	 *
	 * @var array
	 */
	const UNIT_DESIGNATORS = array(
		'apt', 'ste', 'bldg', 'building', 'dept', 'department', 'fl', 'floor', 'frnt', 'front',
		'hngr', 'hangar', 'key', 'lbby', 'lobby', 'lot', 'lowr', 'lower', 'ofc', 'office', 'ph',
		'penthouse', 'pier', 'rear', 'rm', 'room', 'side', 'slip', 'spc', 'space', 'stop', 'trlr',
		'trailer', 'uppr', 'upper', 'bsmt', 'basement', 'no', 'num', 'box', 'pmb', 'mailbox',
		// The forwarder's and the office building's vocabulary (v1.31).
		'mb', 'mbx', 'bx', 'locker', 'lkr', 'cubicle', 'desk', 'level', 'lvl', 'flr', 'wing',
		'bay', 'berth', 'cabin', 'gate', 'kiosk', 'cage', 'shop', 'store',
	);

	/**
	 * The street types the destination-tail cut recognises (see parse_line):
	 * canonicalize()'s own targets and their commonest spellings — never the
	 * aliased NAME words ("lake", "view", "mountain"), which sit mid-name as
	 * often as they end one.
	 *
	 * @var array
	 */
	const CUT_TYPES = array(
		'st', 'ave', 'blvd', 'dr', 'rd', 'ln', 'ct', 'pl', 'ter', 'cir', 'hwy', 'pkwy', 'way', 'trl',
		'str', 'strt', 'aven', 'boul', 'terr', 'circ', 'sq', 'expy', 'fwy', 'tpke', 'ext',
	);

	/**
	 * Street types that LEAD a name ("Rue St-Jacques", "Avenue of the
	 * Americas", "Boulevard St-Laurent"). A line that opens with one is
	 * name-after-type style, where a type followed by words is the name
	 * itself — the destination-tail cut stands down.
	 *
	 * @var array
	 */
	const LEADING_TYPES = array( 'rue', 'ave', 'avenue', 'blvd', 'boulevard', 'chemin', 'route', 'rte', 'place', 'pl', 'allee', 'montee', 'cote', 'rang', 'calle', 'via', 'camino', 'paseo' );

	/**
	 * US state and territory names, Canadian provinces and Australian states
	 * → the codes WooCommerce stores. region() folds a spelled-out name to
	 * its code so "Florida" and "FL" are one state in every key.
	 *
	 * @var array
	 */
	const REGION_NAMES = array(
		'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR', 'california' => 'CA', 'colorado' => 'CO',
		'connecticut' => 'CT', 'delaware' => 'DE', 'district of columbia' => 'DC', 'washington dc' => 'DC', 'florida' => 'FL',
		'georgia' => 'GA', 'hawaii' => 'HI', 'idaho' => 'ID', 'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS',
		'kentucky' => 'KY', 'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD', 'massachusetts' => 'MA', 'michigan' => 'MI',
		'minnesota' => 'MN', 'mississippi' => 'MS', 'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE', 'nevada' => 'NV',
		'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM', 'new york' => 'NY', 'north carolina' => 'NC',
		'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK', 'oregon' => 'OR', 'pennsylvania' => 'PA', 'rhode island' => 'RI',
		'south carolina' => 'SC', 'south dakota' => 'SD', 'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT', 'vermont' => 'VT',
		'virginia' => 'VA', 'washington' => 'WA', 'west virginia' => 'WV', 'wisconsin' => 'WI', 'wyoming' => 'WY',
		'puerto rico' => 'PR', 'guam' => 'GU', 'us virgin islands' => 'VI', 'american samoa' => 'AS', 'northern mariana islands' => 'MP',
		'alberta' => 'AB', 'british columbia' => 'BC', 'manitoba' => 'MB', 'new brunswick' => 'NB', 'newfoundland and labrador' => 'NL',
		'nova scotia' => 'NS', 'ontario' => 'ON', 'prince edward island' => 'PE', 'quebec' => 'QC', 'saskatchewan' => 'SK',
		'northwest territories' => 'NT', 'nunavut' => 'NU', 'yukon' => 'YT',
		'new south wales' => 'NSW', 'victoria' => 'VIC', 'queensland' => 'QLD', 'south australia' => 'SA', 'western australia' => 'WA',
		'tasmania' => 'TAS', 'australian capital territory' => 'ACT', 'northern territory' => 'NT',
	);

	/**
	 * Street-type spellings the shared canonicalize() map does not cover,
	 * folded to the USPS Publication 28 standard abbreviation. canonicalize()
	 * itself is frozen — the exact and loose hashes of every indexed order
	 * depend on it — so this second map applies in the street parser only.
	 * That is why "66th Str", "66th Strt" and "66th St" are one street (one
	 * street key, one door key) while remaining three distinct exact hashes.
	 *
	 * Every value maps to itself or is absent from the keys, so applying the
	 * map to its own output changes nothing (align_street() relies on that).
	 *
	 * @var array
	 */
	const STREET_TYPE_ALIASES = array(
		'str'        => 'st',
		'strt'       => 'st',
		'stret'      => 'st',
		'streat'     => 'st',
		'aven'       => 'ave',
		'avn'        => 'ave',
		'avnue'      => 'ave',
		'avenu'      => 'ave',
		'blv'        => 'blvd',
		'boul'       => 'blvd',
		'boulv'      => 'blvd',
		'bvd'        => 'blvd',
		'blvrd'      => 'blvd',
		'drv'        => 'dr',
		'driv'       => 'dr',
		'crt'        => 'ct',
		'cour'       => 'ct',
		'lan'        => 'ln',
		'lne'        => 'ln',
		'plc'        => 'pl',
		'terr'       => 'ter',
		'circ'       => 'cir',
		'crcl'       => 'cir',
		'cirl'       => 'cir',
		'pky'        => 'pkwy',
		'pkw'        => 'pkwy',
		'parkwy'     => 'pkwy',
		'pkway'      => 'pkwy',
		'hiway'      => 'hwy',
		'hway'       => 'hwy',
		'highwy'     => 'hwy',
		'trail'      => 'trl',
		'trails'     => 'trl',
		'wy'         => 'way',
		'square'     => 'sq',
		'sqr'        => 'sq',
		'expressway' => 'expy',
		'expwy'      => 'expy',
		'expw'       => 'expy',
		'freeway'    => 'fwy',
		'frwy'       => 'fwy',
		'turnpike'   => 'tpke',
		'tpk'        => 'tpke',
		'trnpk'      => 'tpke',
		'alley'      => 'aly',
		'ally'       => 'aly',
		'crescent'   => 'cres',
		'cresent'    => 'cres',
		'plaza'      => 'plz',
		'plza'       => 'plz',
		'point'      => 'pt',
		'pnt'        => 'pt',
		'ridge'      => 'rdg',
		'rdge'       => 'rdg',
		'heights'    => 'hts',
		'hgts'       => 'hts',
		'junction'   => 'jct',
		'jctn'       => 'jct',
		'mount'      => 'mt',
		'mountain'   => 'mtn',
		'mntn'       => 'mtn',
		'route'      => 'rte',
		'rt'         => 'rte',
		'center'     => 'ctr',
		'centre'     => 'ctr',
		'cntr'       => 'ctr',
		'crossing'   => 'xing',
		'xng'        => 'xing',
		'extension'  => 'ext',
		'grove'      => 'grv',
		'hill'       => 'hl',
		'hills'      => 'hls',
		'lake'       => 'lk',
		'lakes'      => 'lks',
		'river'      => 'riv',
		'valley'     => 'vly',
		'vlly'       => 'vly',
		'view'       => 'vw',
		'village'    => 'vlg',
		'villg'      => 'vlg',
		'creek'      => 'crk',
		'cove'       => 'cv',
		'bend'       => 'bnd',
		'bridge'     => 'brg',
		'brook'      => 'brk',
		'canyon'     => 'cyn',
		'causeway'   => 'cswy',
		'garden'     => 'gdn',
		'gardens'    => 'gdns',
		'glen'       => 'gln',
		'green'      => 'grn',
		'harbor'     => 'hbr',
		'harbour'    => 'hbr',
		'haven'      => 'hvn',
		'island'     => 'is',
		'knoll'      => 'knl',
		'landing'    => 'lndg',
		'manor'      => 'mnr',
		'meadow'     => 'mdw',
		'meadows'    => 'mdws',
		'mill'       => 'ml',
		'orchard'    => 'orch',
		'pines'      => 'pnes',
		'plain'      => 'pln',
		'plains'     => 'plns',
		'shore'      => 'shr',
		'shores'     => 'shrs',
		'spring'     => 'spg',
		'springs'    => 'spgs',
		'station'    => 'sta',
		'summit'     => 'smt',
		'trace'      => 'trce',
		'vista'      => 'vis',
	);

	/**
	 * Street types in the shape the parser leaves them (canonicalize()'s own
	 * targets plus the aliases above plus the bare words). Used to recognise
	 * whether a parsed street ENDS in a type at all — see door_alternates().
	 *
	 * @var array
	 */
	const STREET_TYPES = array(
		'st', 'ave', 'blvd', 'dr', 'rd', 'ln', 'ct', 'pl', 'ter', 'cir', 'hwy', 'pkwy', 'way',
		'trl', 'sq', 'expy', 'fwy', 'tpke', 'aly', 'cres', 'plz', 'pt', 'rdg', 'hts', 'jct',
		'mt', 'mtn', 'rte', 'ctr', 'xing', 'ext', 'grv', 'hl', 'hls', 'lk', 'lks', 'riv', 'vly',
		'vw', 'vlg', 'crk', 'cv', 'bnd', 'brg', 'brk', 'cyn', 'cswy', 'gdn', 'gdns', 'gln',
		'grn', 'hbr', 'hvn', 'is', 'knl', 'lndg', 'mnr', 'mdw', 'mdws', 'ml', 'orch', 'pnes',
		'pln', 'plns', 'shr', 'shrs', 'spg', 'spgs', 'sta', 'smt', 'trce', 'vis', 'loop',
		'run', 'row', 'path', 'pass', 'pike', 'park', 'walk', 'mall',
	);

	/**
	 * The street types an INCOMING address missing its type is also tried
	 * with — "8020 NW 66th" is checked as "8020 NW 66th St", "… Ave", and so
	 * on. Only the common ones: this is a net for a dropped word, not a
	 * dictionary. See door_alternates().
	 *
	 * @var array
	 */
	const COMMON_STREET_TYPES = array( 'st', 'ave', 'rd', 'dr', 'blvd', 'ln', 'ct', 'pl', 'ter', 'cir', 'way', 'hwy', 'pkwy', 'trl' );

	/**
	 * Directionals in key form. canonicalize() turns north/south/east/west
	 * into n/s/e/w; the compound words and the split two-letter forms ("N W",
	 * "N.W.") are aligned to these in the parser.
	 *
	 * @var array
	 */
	const DIRECTIONALS = array( 'n', 's', 'e', 'w', 'ne', 'nw', 'se', 'sw' );

	/**
	 * @var array Compound directional words → key form.
	 */
	const DIRECTIONAL_ALIASES = array(
		'northwest' => 'nw',
		'northeast' => 'ne',
		'southwest' => 'sw',
		'southeast' => 'se',
	);

	/**
	 * Word ordinals beyond the three canonicalize() has always mapped
	 * (first/second/third → 1/2/3). Parser-only, same reason as the aliases.
	 *
	 * @var array
	 */
	const WORD_ORDINALS = array(
		'first'       => '1',
		'second'      => '2',
		'third'       => '3',
		'fourth'      => '4',
		'fifth'       => '5',
		'sixth'       => '6',
		'seventh'     => '7',
		'eighth'      => '8',
		'ninth'       => '9',
		'tenth'       => '10',
		'eleventh'    => '11',
		'twelfth'     => '12',
		'thirteenth'  => '13',
		'fourteenth'  => '14',
		'fifteenth'   => '15',
		'sixteenth'   => '16',
		'seventeenth' => '17',
		'eighteenth'  => '18',
		'nineteenth'  => '19',
		'twentieth'   => '20',
		'thirtieth'   => '30',
		'fortieth'    => '40',
		'fiftieth'    => '50',
		'sixtieth'    => '60',
		'seventieth'  => '70',
		'eightieth'   => '80',
		'ninetieth'   => '90',
		'hundredth'   => '100',
	);

	/**
	 * Tens words that compound with a unit ordinal: "sixty sixth" → 66,
	 * "twenty first" → 21 (canonicalize() has already made "first" a "1").
	 *
	 * @var array
	 */
	const WORD_TENS = array(
		'twenty'  => 20,
		'thirty'  => 30,
		'forty'   => 40,
		'fifty'   => 50,
		'sixty'   => 60,
		'seventy' => 70,
		'eighty'  => 80,
		'ninety'  => 90,
	);

	/**
	 * Countries whose "state" is a fixed WooCommerce dropdown AND whose
	 * carriers deliver on house number + street, correcting a wrong postcode
	 * in transit. There the door key anchors to the state, because the
	 * postcode is something a reshipper can rotate at no cost to delivery
	 * (the 8020 NW 66th St incident: one door, four ZIPs typed). Everywhere
	 * else the postcode is the anchor — a UK or Dutch postcode IS the address.
	 *
	 * @var array
	 */
	const DOOR_REGION_COUNTRIES = array( 'US', 'CA', 'AU' );

	/**
	 * Characters that look like Latin letters but are not — the Cyrillic and
	 * Greek lookalikes a string-matching blocklist is classically evaded
	 * with ("8020 NW 66th Ѕt") — mapped to the Latin letter they imitate.
	 * Applied by fold() before the street parser runs; a carrier's OCR reads
	 * these as the Latin letter, so the key must too.
	 *
	 * @var array
	 */
	const HOMOGLYPHS = array(
		'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'у' => 'y', 'х' => 'x', 'і' => 'i',
		'ј' => 'j', 'ѕ' => 's', 'һ' => 'h', 'ԁ' => 'd', 'ԛ' => 'q', 'ԝ' => 'w', 'ѵ' => 'v', 'ӏ' => 'l',
		'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H', 'О' => 'O', 'Р' => 'P',
		'С' => 'C', 'Т' => 'T', 'Х' => 'X', 'І' => 'I', 'Ј' => 'J', 'Ѕ' => 'S', 'Ԛ' => 'Q', 'Ԝ' => 'W',
		'Ѵ' => 'V', 'Ԁ' => 'D', 'Ӏ' => 'I',
		'α' => 'a', 'ο' => 'o', 'ν' => 'v', 'ρ' => 'p', 'τ' => 't', 'υ' => 'u', 'ι' => 'i', 'κ' => 'k',
		'χ' => 'x', 'Α' => 'A', 'Β' => 'B', 'Ε' => 'E', 'Ζ' => 'Z', 'Η' => 'H', 'Ι' => 'I', 'Κ' => 'K',
		'Μ' => 'M', 'Ν' => 'N', 'Ο' => 'O', 'Ρ' => 'P', 'Τ' => 'T', 'Υ' => 'Y', 'Χ' => 'X',
		'⁄' => '/',
	);

	/**
	 * Fold the Unicode tricks out of a typed address before parsing: strip
	 * invisible characters (zero-width spaces and joiners, the word joiner,
	 * the BOM, soft hyphens), decompose compatibility forms so full-width
	 * digits and letters, ligatures and "№" become plain ASCII and accented
	 * letters lose their marks (café → cafe), and replace Cyrillic/Greek
	 * lookalikes with the Latin letter they imitate.
	 *
	 * Parser-only: canonicalize() stays as it was so historical exact and
	 * loose hashes keep matching their own orders.
	 *
	 * @param string $s Raw text.
	 * @return string
	 */
	public static function fold( $s ) {
		$s = (string) $s;
		if ( '' === $s ) {
			return '';
		}
		$s = (string) preg_replace( '/[\x{200B}-\x{200F}\x{2060}\x{FEFF}\x{00AD}]/u', '', $s );
		if ( class_exists( 'Normalizer' ) ) {
			$n = Normalizer::normalize( $s, Normalizer::FORM_KD );
			if ( is_string( $n ) && '' !== $n ) {
				$s = (string) preg_replace( '/\p{Mn}+/u', '', $n );
			}
		}
		return strtr( $s, self::HOMOGLYPHS );
	}

	/**
	 * Extract the normalized street NAME from an address, US/Canada "number
	 * first" style: "123 N. Main Street, Apt 4" → "n main st".
	 *
	 * This is what a street lock keys on and what the door key is built from,
	 * so two addresses on one street must collapse to one string no matter
	 * how the house number, the unit, or the street itself was typed:
	 *
	 *  - the leading house number goes, including a letter suffix ("123A"), a
	 *    Queens-style or range hyphen ("37-12", "123-125"), a hyphenated unit
	 *    letter ("123-B") and a fraction ("123 1/2"); a long box number typed
	 *    beside a short house number ("152978 8020 Main St", "8020 152978
	 *    Main St") is read as the unit, not the house;
	 *  - a lone unit letter left behind by "123 B Main St" goes too, but only
	 *    when a real street name remains — "123 B St" keeps "b st";
	 *  - "#4" is read as a unit, and the first unit designator that is followed
	 *    by a unit-shaped value (or ends the line) cuts the rest off: "Apt 4",
	 *    "Ste 200B", "Ste 152978", "STE152978", "Unit C", "Fl 3", "Rear". A
	 *    designator that happens to START the street name ("Pier 39", "Key
	 *    Largo Dr", "Lower River Rd") or is followed by a word ("Front St",
	 *    "Upper Mountain Rd") is left alone;
	 *  - a unit written first ("Apt 4 123 Main St") is stripped before the
	 *    house number is looked for;
	 *  - the destination typed into the line (", Miami FL 33166", "USA") is
	 *    dropped from the end when the address array is supplied;
	 *  - every numeric ordinal collapses ("66th" → "66", "1st" → "1") and the
	 *    word ordinals up to twelfth do too, so "NW 66th St", "NW 66 St" and
	 *    "NW 66 Street" are one street;
	 *  - directionals are aligned ("N.W." / "N W" / "Northwest" → "nw"; "NW66th"
	 *    is split) and a trailing post-directional is moved to the front
	 *    ("Main St NW" → "nw main st"), since both orders name one street;
	 *  - street-type spellings are folded ("Str" → "st", "Boul" → "blvd");
	 *  - then canonicalize() has applied the shared abbreviation map, so
	 *    "North Main Street" and "N Main St" agree;
	 *  - Unicode lookalikes and invisible characters are folded first.
	 *
	 * Directionals are deliberately KEPT: "N Main St" and "S Main St" are
	 * different streets with different address ranges, and collapsing them
	 * would block the wrong side of town. The street TYPE is kept for the
	 * same reason — in a grid city "NW 66th St" and "NW 66th Ave" are two
	 * streets.
	 *
	 * When given the address array, the second line is consulted when the
	 * first yields no house number + street — a forwarder's "Ste 152978" on
	 * line 1 with the street on line 2, or "c/o Someone" on line 1.
	 *
	 * Returns '' for a PO box, rural-route or general-delivery line (no
	 * street), for a line that is only a number or only a unit, and for
	 * anything shorter than two characters, so a caller can never build a
	 * lock key from nothing.
	 *
	 * Takes RAW address text. The output is not a valid input: the house-
	 * number strip cannot tell "1 Ave" (house 1 on "Ave") from what "First
	 * Ave" canonicalizes to, so re-running this on its own result can shorten
	 * it. Segments that are already normalized go through align_street(),
	 * which is idempotent — see place_target().
	 *
	 * @param string|array $a Raw first address line, or the address parts
	 *                        (address_1, address_2, city, state, postcode, country).
	 * @return string Normalized street name, or ''.
	 */
	public static function street( $a ) {
		return self::parse_parts( $a )['street'];
	}

	/**
	 * The normalized house number of an address — digits only, leading zeros
	 * dropped, letter suffix dropped — or '' when the address has none (a PO
	 * box, a unit-only line, a name-first European line). The same number the
	 * door key and a building ban are built on.
	 *
	 * @param string|array $a Raw first address line, or the address parts.
	 * @return string
	 */
	public static function house( $a ) {
		return self::house_number( self::parse_parts( $a )['number'] );
	}

	/**
	 * Re-align an ALREADY-normalized street name (the output of street(), or
	 * a street segment from a stored place key) to the current parser's
	 * form: ordinals collapsed, directionals merged and moved to the front,
	 * type aliases folded. Idempotent, and never strips a house number, so
	 * unlike street() it is safe to run on its own output.
	 *
	 * This is what lets a street lock stored as "street:US|FL|miami|nw 66th st"
	 * keep matching orders the parser now reduces to "nw 66 st": both sides
	 * go through here (place_target() / place_keys()), and the v1.31 upgrade
	 * rewrites the stored keys themselves.
	 *
	 * @param string $street Normalized street name.
	 * @return string
	 */
	public static function align_street( $street ) {
		$s = self::canonicalize( $street );
		if ( '' === $s ) {
			return '';
		}
		return mb_substr( trim( implode( ' ', self::align_tokens( explode( ' ', $s ) ) ) ), 0, 80 );
	}

	/**
	 * The door key: one hash for one street door, blind to everything a
	 * fraudster can cheaply vary.
	 *
	 * house number + street name + country + ANCHOR — and nothing else, where
	 * the anchor is the STATE for the countries in DOOR_REGION_COUNTRIES and
	 * the postcode elsewhere (a US ZIP+4 reduces to its five digits, a UK
	 * postcode to its outward code, a Canadian one to its FSA, when they are
	 * the anchor at all).
	 *
	 * This exists because of two real losses. The first (v1.25.1): a blocked
	 * customer re-registered and re-entered their blocked address with the
	 * unit written differently and the case changed — the exact and loose
	 * hashes both moved, and the order went through; so the key dropped the
	 * unit. The second (v1.31): the same door, 8020 NW 66th St in Miami, was
	 * entered under FOUR different ZIP codes (33166-3900, 33167, 33195,
	 * 33195-3900) and two unit spellings, and every ZIP minted a fresh door
	 * key, because the key contained the postcode. Carriers correct a wrong
	 * ZIP in transit — it costs a reshipper nothing to rotate it, and a
	 * Miami-Dade address has several "acceptable" city names (Miami, Doral,
	 * Medley…) — so neither the postcode nor the city can be part of a key
	 * that is meant to survive rewriting. What a fraudster CANNOT change
	 * without losing the parcel is the house number, the street, and (in
	 * practice) the state; that is the whole key now:
	 *
	 *  - case, punctuation, spacing, abbreviations, Unicode lookalikes →
	 *    fold() + canonicalize();
	 *  - the unit, however written ("Apt 4", "#4", "Ste 152978", "STE152978",
	 *    "4th floor") and whichever line it is typed on → the parser strips
	 *    it; the street is read from line 2 when line 1 has none;
	 *  - "66th" vs "66", "N.W." vs "NW" vs "Northwest", "Str" vs "St",
	 *    "Main St NW" vs "NW Main St" → alignment in the parser;
	 *  - the ZIP, the ZIP+4, the city → not consulted (US/CA/AU);
	 *  - a leading zero on the house number ("08020") → dropped; a letter
	 *    suffix ("123B") too, as before: "123-B Main St", "123 B Main St"
	 *    and "Apt B, 123 Main St" are one door written three ways, and
	 *    merging a building's lettered units errs toward catching the
	 *    fraudster (the exact and loose hashes still tell units apart).
	 *
	 * The cost of the state-level anchor is that "8020 NW 66th St" in two
	 * Florida cities shares one key. That is a deliberate trade: the
	 * population at risk is "another building in the same state with the
	 * same number on a street of the same name", the consequence is a
	 * checkout refusal that names the match, and the remedy is removing the
	 * one door-key entry from the Blocklist page — against a fraudster who
	 * rotates ZIPs for free.
	 *
	 * Returns '' when the house number, the street, or the anchor is missing
	 * — a door key must never be vague enough to block someone else's door.
	 *
	 * @param array $a Address parts: address_1, address_2, city, state, postcode, country.
	 * @return string SHA-256 hash of the door key, or ''.
	 */
	public static function door( $a ) {
		if ( ! is_array( $a ) ) {
			return '';
		}
		$parts = self::parse_parts( $a );
		if ( '' === $parts['street'] ) {
			return '';
		}
		$number = self::house_number( $parts['number'] );
		if ( '' === $number ) {
			return '';
		}
		$cc     = strtoupper( trim( (string) ( $a['country'] ?? '' ) ) );
		$anchor = self::door_anchor( $cc, $a['state'] ?? '', $a['postcode'] ?? '' );
		if ( '' === $anchor ) {
			return '';
		}
		return self::hash( 'door2|' . $number . '|' . $parts['street'] . '|' . $cc . '|' . $anchor );
	}

	/**
	 * Extra door keys an INCOMING address should be checked against, on top
	 * of door(): when the typed street carries no recognised street type
	 * ("8020 NW 66th", "8020 nw 66 miami"), the same door with each common
	 * type appended — so dropping the word "St" does not walk past a block
	 * on "8020 NW 66th St".
	 *
	 * Expansion happens on the checking side only. Stored keys stay precise
	 * (a block on "… 66th St" never becomes a block on "… 66th Ave"); the
	 * only address that meets the wider net is one typed without a type,
	 * which is the fraudster's omission, not the neighbour's.
	 *
	 * @param array $a Address parts.
	 * @return array SHA-256 hashes, possibly empty. Never includes door() itself.
	 */
	public static function door_alternates( $a ) {
		if ( ! is_array( $a ) ) {
			return array();
		}
		$parts = self::parse_parts( $a );
		if ( '' === $parts['street'] ) {
			return array();
		}
		$tokens = explode( ' ', $parts['street'] );
		if ( self::is_street_type( end( $tokens ) ) ) {
			return array();
		}
		$number = self::house_number( $parts['number'] );
		$cc     = strtoupper( trim( (string) ( $a['country'] ?? '' ) ) );
		$anchor = self::door_anchor( $cc, $a['state'] ?? '', $a['postcode'] ?? '' );
		if ( '' === $number || '' === $anchor ) {
			return array();
		}
		$out = array();
		foreach ( self::COMMON_STREET_TYPES as $type ) {
			$out[] = self::hash( 'door2|' . $number . '|' . $parts['street'] . ' ' . $type . '|' . $cc . '|' . $anchor );
		}
		return $out;
	}

	/**
	 * The door key's geographic anchor — see door().
	 *
	 * @param string $cc       Upper-cased country code.
	 * @param string $state    Raw state/region.
	 * @param string $postcode Raw postcode.
	 * @return string 'r:<STATE>' or 'p:<POSTCODE PREFIX>', or '' when neither is usable.
	 */
	private static function door_anchor( $cc, $state, $postcode ) {
		$st = self::region( $state );
		if ( '' !== $st && in_array( $cc, self::DOOR_REGION_COUNTRIES, true ) ) {
			return 'r:' . $st;
		}
		$zp = self::postcode( $postcode );
		if ( '' === $zp ) {
			return '';
		}
		if ( 'US' === $cc && preg_match( '/^(\d{5})-?\d{4}$/', $zp, $m ) ) {
			$zp = $m[1];
		} elseif ( 'GB' === $cc && strlen( $zp ) >= 5 && preg_match( '/^([A-Z]{1,2}\d[A-Z\d]?)\d[A-Z]{2}$/', $zp, $m ) ) {
			$zp = $m[1]; // Outward code: the district, not the handful of doors the inward code names.
		} elseif ( 'CA' === $cc && strlen( $zp ) >= 6 ) {
			$zp = substr( $zp, 0, 3 ); // FSA.
		}
		return 'p:' . $zp;
	}

	/**
	 * The house number as the door key spells it: digits only, leading zeros
	 * dropped ("08020" and "8020" are one door), letter suffix dropped.
	 *
	 * @param string $token Number token from the parser.
	 * @return string Digits, or ''.
	 */
	private static function house_number( $token ) {
		if ( ! preg_match( '/^(\d+)/', (string) $token, $m ) ) {
			return '';
		}
		$n = ltrim( $m[1], '0' );
		return '' === $n ? '0' : $n;
	}

	/**
	 * Shared parser behind street(), door() and door_alternates(): pick the
	 * address line that carries the street and split it into the house
	 * number token and the normalized street name.
	 *
	 * Given a bare string, that line is parsed on its own. Given the address
	 * array, line 1 is preferred; line 2 is used when line 1 yields no house
	 * number + street (a forwarder's "Ste 152978" on line 1 with the street
	 * on line 2, "c/o Someone" on line 1); and the destination fields supply
	 * the trailer tokens the parser strips from the end of the line.
	 *
	 * @param string|array $a Raw first address line, or the address parts.
	 * @return array{number:string,street:string} Either segment '' when absent.
	 */
	private static function parse_parts( $a ) {
		if ( ! is_array( $a ) ) {
			return self::parse_line( (string) $a );
		}
		$trailer = self::trailer_tokens( $a );
		$first   = self::parse_line( $a['address_1'] ?? '', $trailer );
		if ( '' !== $first['number'] && '' !== $first['street'] ) {
			return $first;
		}
		$second = self::parse_line( $a['address_2'] ?? '', $trailer );
		if ( '' !== $second['number'] && '' !== $second['street'] ) {
			return $second;
		}
		return '' !== $first['street'] ? $first : $second;
	}

	/**
	 * The tokens that may be dropped from the END of an address line because
	 * they only repeat the destination fields: the order's own city, state
	 * and postcode as canonicalize() would spell them, the country code, and
	 * for the US the usual "USA" forms. '__zip5' flags that any trailing
	 * five-digit token is a ZIP (typed into the line, possibly a different
	 * one from the postcode field — which is the rotation case itself).
	 *
	 * @param array $a Address parts.
	 * @return array Set: token => true.
	 */
	private static function trailer_tokens( $a ) {
		$set = array();
		foreach ( array( 'city', 'state', 'postcode' ) as $k ) {
			foreach ( explode( ' ', self::canonicalize( self::fold( $a[ $k ] ?? '' ) ) ) as $t ) {
				if ( '' !== $t ) {
					$set[ $t ] = true;
				}
			}
		}
		$cc = strtolower( trim( (string) ( $a['country'] ?? '' ) ) );
		if ( '' !== $cc ) {
			$set[ $cc ] = true;
			if ( 'us' === $cc ) {
				foreach ( array( 'usa', 'united', 'states', 'america' ) as $t ) {
					$set[ $t ] = true;
				}
				$set['__zip5'] = true;
			}
		}
		return $set;
	}

	/**
	 * Parse one raw address line into the house number token and the
	 * normalized street name. All the behaviour documented on street() lives
	 * here and in align_tokens().
	 *
	 * @param string $address_1 Raw address line.
	 * @param array  $trailer   Optional trailer set from trailer_tokens().
	 * @return array{number:string,street:string} Either segment '' when absent.
	 */
	private static function parse_line( $address_1, $trailer = array() ) {
		$none = array(
			'number' => '',
			'street' => '',
		);
		$raw  = trim( self::fold( $address_1 ) );
		if ( '' === $raw ) {
			return $none;
		}

		// "#4" / "# 4" is a unit, not punctuation to discard. "Apt #4" is the
		// same unit written twice over, so there the "#" is simply dropped.
		$raw = preg_replace( '/\b(apt|apartment|unit|ste|suite|no|num|rm|room|bldg|fl|floor|lot|spc|space|pmb|box)\.?\s*#\s*/i', '$1 ', $raw );
		$raw = preg_replace( '/#\s*/', ' apt ', $raw );

		// Glue a hyphen/slash-joined house number into one token ("37-12" →
		// "3712", "123-B" → "123B") and drop a trailing fraction ("123 1/2" →
		// "123"), so the number strip below removes exactly one token. Only
		// number-or-letter pieces are glued: "123 - Main St" keeps its street.
		$raw = preg_replace_callback(
			'/^(\d+[A-Za-z]?)((?:\s*[-\/]\s*(?:\d+[A-Za-z]?|[A-Za-z]))+)(?=\s|$)/',
			function ( $m ) {
				return $m[1] . preg_replace( '/[^A-Za-z0-9]+/', '', $m[2] );
			},
			$raw
		);
		$raw = preg_replace( '/^(\d+[A-Za-z]?)\s+\d+\/\d+(?=\s|$)/', '$1', $raw );

		$starts_numeric = (bool) preg_match( '/^\d/', $raw );

		$s = self::canonicalize( $raw );
		if ( '' === $s ) {
			return $none;
		}

		// No street to speak of: PO boxes, rural routes, general delivery. A
		// box number FOLLOWED by a street ("Box 152978 8020 NW 66th St" — a
		// forwarder's box typed first) is a unit written first, and the
		// street behind it is parsed; the box line itself goes.
		if ( preg_match( '/^(?:general delivery|rr|rural route|hc|highway contract)\b/', $s ) ) {
			return $none;
		}
		if ( preg_match( '/^(?:p ?o ?box|pob|post office box|box) (\d+[a-z]?)(?: (.+))?$/', $s, $pm ) ) {
			if ( empty( $pm[2] ) ) {
				return $none;
			}
			$s              = $pm[2];
			$starts_numeric = (bool) preg_match( '/^\d/', $s );
		}

		$tokens = self::split_glued( explode( ' ', $s ) );

		// "No. 8020 NW 66th St": a NUMBER marker on the house number, not a
		// unit — when what follows the number is not itself a number.
		if ( in_array( $tokens[0], array( 'no', 'num' ), true ) && isset( $tokens[1], $tokens[2] ) && preg_match( '/^\d+[a-z]?$/', $tokens[1] ) && ! preg_match( '/^\d/', $tokens[2] ) ) {
			array_shift( $tokens );
			$starts_numeric = true;
		}

		// Unit written first: "apt 4 123 main st". A line that is ONLY a unit
		// ("Apt 4", "Box 12") has no street in it at all.
		$allow_number = $starts_numeric;
		while ( self::is_unit_designator( $tokens[0] ) && ( 1 === count( $tokens ) || self::is_unit_value( $tokens[1] ) ) ) {
			if ( count( $tokens ) <= 2 ) {
				return $none;
			}
			array_splice( $tokens, 0, 2 );
			$allow_number = true;
		}

		// House number. Only when the line actually started with a digit:
		// "First Ave" canonicalizes to "1 ave", and that leading "1" is the
		// street, not a house number.
		$number          = '';
		$stripped_number = false;
		if ( $allow_number && preg_match( '/^\d+[a-z]?$/', $tokens[0] ) ) {
			// Two bare numbers up front ("152978 8020 Main St", "8020 152978
			// Main St"): a forwarder's box number typed beside the house
			// number, with no designator. The LONGER run of digits is the box
			// — house numbers are short, mailbox numbers are not. Only when
			// both are at least three digits and the longer is at least five:
			// "12345 8 Mile Rd" and "5000 1000 Islands Pkwy" are house numbers
			// on numbered streets, and Queens "37 12" keeps its first number.
			if ( count( $tokens ) >= 4 && preg_match( '/^\d{3,}$/', $tokens[0] ) && preg_match( '/^\d{3,}$/', $tokens[1] ) && strlen( $tokens[0] ) !== strlen( $tokens[1] ) && max( strlen( $tokens[0] ), strlen( $tokens[1] ) ) >= 5 ) {
				if ( strlen( $tokens[0] ) > strlen( $tokens[1] ) ) {
					array_shift( $tokens );
				} else {
					array_splice( $tokens, 1, 1 );
				}
			}
			$number          = array_shift( $tokens );
			$stripped_number = true;
			if ( empty( $tokens ) ) {
				return $none;
			}
		}

		// "123 B Main St": a unit letter the number left behind. Kept when it IS
		// the street ("123 B St"), and never a directional — "123 N Main St"
		// is North Main Street, not unit N.
		if ( $stripped_number && count( $tokens ) >= 3 && preg_match( '/^[a-df-mo-rt-vx-z]$/', $tokens[0] ) ) {
			array_shift( $tokens );
		}

		// First unit designator (never the first token — that is the street)
		// followed by a unit-shaped value, or ending the line, cuts the rest.
		// "2nd Floor" / "3rd Fl" cuts from the ordinal.
		$count = count( $tokens );
		for ( $i = 1; $i < $count; $i++ ) {
			if ( self::is_unit_designator( $tokens[ $i ] ) && ( $i === $count - 1 || self::is_unit_value( $tokens[ $i + 1 ] ) ) ) {
				$cut = $i;
				if ( in_array( $tokens[ $i ], array( 'fl', 'floor' ), true ) && $i >= 2 && preg_match( '/^\d+(?:st|nd|rd|th)$/', $tokens[ $i - 1 ] ) ) {
					$cut = $i - 1;
				}
				$tokens = array_slice( $tokens, 0, $cut );
				break;
			}
		}

		// A line that was nothing but a unit ("Apt #4", "Box #12").
		if ( 1 === count( $tokens ) && self::is_unit_designator( $tokens[0] ) ) {
			return $none;
		}

		// The destination typed into the line (", Miami FL 33166", "USA"):
		// popped from the end while the token names the order's own city,
		// state, postcode or country — never a street type or a directional,
		// and never the last token standing.
		if ( ! empty( $trailer ) ) {
			$zip5 = ! empty( $trailer['__zip5'] );
			while ( count( $tokens ) > 1 ) {
				$last = $tokens[ count( $tokens ) - 1 ];
				if ( self::is_street_type( $last ) || in_array( $last, self::DIRECTIONALS, true ) ) {
					break;
				}
				if ( ! isset( $trailer[ $last ] ) && ! ( $zip5 && preg_match( '/^\d{5}$/', $last ) ) ) {
					break;
				}
				array_pop( $tokens );
			}
		}

		// Words still standing after the LAST common street type are a
		// destination typed into the line that the trailer set did not know
		// ("… 66th St Miami" with "Doral" in the city field, "… Collins Ave
		// Miami Beach") — cut them. Not when the line leads with a
		// name-after-type word ("Rue St-Jacques", "Avenue of the Americas"),
		// never a type at the first position, and a lone post-directional
		// ("Park Ave S") belongs to the street.
		if ( count( $tokens ) > 2 && ! in_array( $tokens[0], self::LEADING_TYPES, true ) ) {
			$last_type = -1;
			foreach ( $tokens as $k => $tt ) {
				// Seen as the aligner will spell it: "Extension" is "ext".
				if ( $k >= 1 && in_array( self::STREET_TYPE_ALIASES[ $tt ] ?? $tt, self::CUT_TYPES, true ) ) {
					$last_type = $k;
				}
			}
			if ( $last_type >= 1 && $last_type < count( $tokens ) - 1 ) {
				$after = array_slice( $tokens, $last_type + 1 );
				// "Park Ave S": a lone post-directional belongs to the street.
				if ( ! ( 1 === count( $after ) && in_array( $after[0], self::DIRECTIONALS, true ) ) ) {
					$tokens = array_slice( $tokens, 0, $last_type + 1 );
				}
			}
		}

		// A long bare number after the street type is a box or unit number
		// typed without its designator ("… 66th St 152978"): five or more
		// digits — road numbers ("FM 1960", "Route 66") never run that long.
		$n = count( $tokens );
		if ( $n >= 3 && preg_match( '/^\d{5,}[a-z]{0,2}$/', $tokens[ $n - 1 ] ) && in_array( $tokens[ $n - 2 ], self::CUT_TYPES, true ) ) {
			array_pop( $tokens );
		}

		// Ordinals, directionals, type aliases — AFTER the unit cut, whose
		// "3rd fl" backtrack needs the ordinal suffix intact.
		$tokens = self::align_tokens( $tokens );

		$street = trim( implode( ' ', $tokens ) );
		if ( mb_strlen( $street ) < 2 ) {
			return $none;
		}
		return array(
			'number' => $number,
			'street' => mb_substr( $street, 0, 80 ),
		);
	}

	/**
	 * Split tokens that were typed without a space where the key needs one:
	 * a directional glued to its number ("nw66th" → "nw" "66th", "n66th") and
	 * a unit designator glued to its value ("ste152978" → "ste" "152978",
	 * "apt4b"). Two-letter directionals split before any number; a single
	 * letter only when the number carries an ordinal suffix, so a stray
	 * "e55" is left alone.
	 *
	 * @param array $tokens Canonicalized tokens.
	 * @return array
	 */
	private static function split_glued( $tokens ) {
		$out = array();
		foreach ( $tokens as $t ) {
			// "8020nw66th" / "8020nw": the house number glued to a two-letter
			// directional (a single letter after a number is a unit suffix,
			// "8020N", and stays).
			if ( preg_match( '/^(\d+)(nw|ne|sw|se)(\d+[a-z]*)?$/', $t, $m ) ) {
				$out[] = $m[1];
				$out[] = $m[2];
				if ( isset( $m[3] ) && '' !== $m[3] ) {
					$out[] = $m[3];
				}
				continue;
			}
			// "nwsixty" — a directional glued to a spelled-out ordinal. Only when
			// the remainder IS a number word: "Newark" and "Seward" stay whole.
			if ( preg_match( '/^(nw|ne|sw|se)([a-z]{4,})$/', $t, $m ) && ( isset( self::WORD_TENS[ $m[2] ] ) || isset( self::WORD_ORDINALS[ $m[2] ] ) ) ) {
				$out[] = $m[1];
				$out[] = $m[2];
				continue;
			}
			if ( preg_match( '/^(nw|ne|sw|se)(\d+[a-z]*)$/', $t, $m )
				|| preg_match( '/^([nsew])(\d+(?:st|nd|rd|th))$/', $t, $m )
				|| preg_match( '/^(apt|ste|unit|rm|bldg|fl|lot|pmb|box|spc|num|no|mb)(\d+[a-z]{0,2})$/', $t, $m ) ) {
				$out[] = $m[1];
				$out[] = $m[2];
				continue;
			}
			$out[] = $t;
		}
		return $out;
	}

	/**
	 * Token-level alignment shared by the parser and align_street(): every
	 * numeric ordinal to its number ("66th" → "66"), word ordinals to digits,
	 * compound directionals to key form, split two-letter directionals merged
	 * ("n" "w" → "nw"), street-type aliases folded, and a trailing
	 * post-directional moved to the front ("main st nw" → "nw main st").
	 * Idempotent.
	 *
	 * @param array $tokens Canonicalized tokens.
	 * @return array
	 */
	private static function align_tokens( $tokens ) {
		$tokens = array_values( array_filter( array_map( 'strval', (array) $tokens ), 'strlen' ) );
		$count  = count( $tokens );
		$out    = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$t = $tokens[ $i ];
			if ( preg_match( '/^(\d+)(?:st|nd|rd|th)$/', $t, $m ) ) {
				$t = $m[1];
			} elseif ( isset( self::WORD_TENS[ $t ] ) && isset( $tokens[ $i + 1 ] ) && ( isset( self::WORD_ORDINALS[ $tokens[ $i + 1 ] ] ) || preg_match( '/^[1-9]$/', $tokens[ $i + 1 ] ) ) ) {
				// "sixty sixth" → 66; "twenty first" (canonicalize made it "twenty 1") → 21.
				$unit = isset( self::WORD_ORDINALS[ $tokens[ $i + 1 ] ] ) ? (int) self::WORD_ORDINALS[ $tokens[ $i + 1 ] ] : (int) $tokens[ $i + 1 ];
				if ( $unit >= 1 && $unit <= 9 ) {
					$t = (string) ( self::WORD_TENS[ $t ] + $unit );
					$i++;
				}
			} elseif ( isset( self::WORD_ORDINALS[ $t ] ) ) {
				$t = self::WORD_ORDINALS[ $t ];
			} elseif ( isset( self::DIRECTIONAL_ALIASES[ $t ] ) ) {
				$t = self::DIRECTIONAL_ALIASES[ $t ];
			} elseif ( isset( self::STREET_TYPE_ALIASES[ $t ] ) ) {
				$t = self::STREET_TYPE_ALIASES[ $t ];
			}
			$n = count( $out );
			// An ordinal suffix typed as its own word after the number: "66 th"
			// → 66. "th" and "nd" are never anything else; "st" and "rd" are also
			// Street and Road, so those fold only when another street type
			// follows ("71 st St" → 71 St; "66 St" keeps its St).
			if ( $n > 0 && preg_match( '/^\d+$/', $out[ $n - 1 ] ) && ( in_array( $t, array( 'th', 'nd' ), true )
				|| ( in_array( $t, array( 'st', 'rd' ), true ) && isset( $tokens[ $i + 1 ] ) && self::is_street_type( self::STREET_TYPE_ALIASES[ $tokens[ $i + 1 ] ] ?? $tokens[ $i + 1 ] ) ) ) ) {
				continue;
			}
			if ( $n > 0 && in_array( $out[ $n - 1 ], array( 'n', 's' ), true ) && in_array( $t, array( 'e', 'w' ), true ) ) {
				$out[ $n - 1 ] .= $t;
				continue;
			}
			$out[] = $t;
		}
		$n = count( $out );
		if ( $n >= 3 && in_array( $out[ $n - 1 ], self::DIRECTIONALS, true ) && ! in_array( $out[0], self::DIRECTIONALS, true ) ) {
			array_unshift( $out, array_pop( $out ) );
		}
		return $out;
	}

	/**
	 * Whether a canonicalized token is a secondary-unit designator.
	 *
	 * @param string $t Token.
	 * @return bool
	 */
	private static function is_unit_designator( $t ) {
		return in_array( (string) $t, self::UNIT_DESIGNATORS, true );
	}

	/**
	 * Whether a canonicalized token looks like a unit VALUE: a single letter,
	 * or an alphanumeric with a digit in it ("4", "200", "4b", "b12",
	 * "152978" — forwarder mailbox numbers run long). Words ("mountain",
	 * "st") are not, which is what protects street names that contain a
	 * designator word.
	 *
	 * @param string $t Token.
	 * @return bool
	 */
	private static function is_unit_value( $t ) {
		return (bool) preg_match( '/^(?:[a-z]|[a-z]?\d{1,8}[a-z]{0,2})$/', (string) $t );
	}

	/**
	 * Whether a token (in parser form) is a street type.
	 *
	 * @param string $t Token.
	 * @return bool
	 */
	private static function is_street_type( $t ) {
		return in_array( (string) $t, self::STREET_TYPES, true );
	}

	/**
	 * Build the canonical, scope-prefixed place key used as both the blocklist
	 * value and the block-action target. Returns '' when a required segment is
	 * empty (which makes add()/add_and_link() reject it).
	 *
	 * Formats: city → "city:US|FL|miami"; state → "state:US|FL"; zip → "zip:US|331";
	 * street → "street:US|FL|miami|n main st" (a street within one city name);
	 * streetzip → "streetzip:US|33166|n main st" (a street within one ZIP);
	 * streetcounty → "streetcounty:US|FL|miami dade|n main st" (a street within
	 * one county — only an address-validation result supplies a county, so a
	 * lock on this key is reached through validation alone);
	 * building → "building:US|FL|8020|n main st" (one house number on one
	 * street, anywhere in the state — every unit, box and recipient in it).
	 * "|" is a safe delimiter because every segment is punctuation-stripped.
	 *
	 * The street segment is expected to be the output of street() (or an
	 * already-normalized key segment); it is passed through align_street(),
	 * which is idempotent, never through street() again.
	 *
	 * @param string $scope   'city' | 'state' | 'zip' | 'street' | 'streetzip' | 'streetcounty' | 'building'.
	 * @param string $country Raw country code.
	 * @param string $a       State/region (city, state, street, streetcounty, building) or postcode (zip, streetzip).
	 * @param string $b       City (city, street), county (streetcounty) or house number (building).
	 * @param string $c       Normalized street name (street, streetzip, streetcounty, building).
	 * @return string
	 */
	public static function place_target( $scope, $country, $a, $b = '', $c = '' ) {
		// An area ban has no country: a point and a radius are the whole key.
		if ( 'geo' === $scope ) {
			return self::geo_key( $a, $b, $c );
		}
		$cc = strtoupper( trim( (string) $country ) );
		if ( '' === $cc ) {
			return '';
		}
		switch ( $scope ) {
			case 'state':
				$st = self::region( $a );
				return '' === $st ? '' : 'state:' . $cc . '|' . $st;
			case 'city':
				$st = self::region( $a );
				$ct = self::city( $b );
				return ( '' === $st || '' === $ct ) ? '' : 'city:' . $cc . '|' . $st . '|' . $ct;
			case 'zip':
				$zp = self::postcode( $a );
				return '' === $zp ? '' : 'zip:' . $cc . '|' . $zp;
			case 'street':
				$st = self::region( $a );
				$ct = self::city( $b );
				$sn = self::align_street( $c );
				return ( '' === $st || '' === $ct || '' === $sn ) ? '' : 'street:' . $cc . '|' . $st . '|' . $ct . '|' . $sn;
			case 'streetzip':
				$zp = self::zip_base( $cc, $a );
				$sn = self::align_street( $c );
				return ( '' === $zp || '' === $sn ) ? '' : 'streetzip:' . $cc . '|' . $zp . '|' . $sn;
			case 'streetcounty':
				$st = self::region( $a );
				$co = self::county( $b );
				$sn = self::align_street( $c );
				return ( '' === $st || '' === $co || '' === $sn ) ? '' : 'streetcounty:' . $cc . '|' . $st . '|' . $co . '|' . $sn;
			case 'building':
				$st = self::region( $a );
				$no = self::house_number( preg_replace( '/\D+/', '', (string) $b ) );
				$sn = self::align_street( $c );
				return ( '' === $st || '' === $no || '' === $sn ) ? '' : 'building:' . $cc . '|' . $st . '|' . $no . '|' . $sn;
			default:
				return '';
		}
	}

	/**
	 * The key of an AREA ban (v1.31): a point on the map and a radius around
	 * it — "geo:<lat>|<lng>|<metres>", the coordinates to five decimals (a
	 * metre), the radius a whole number of metres from 50 m to 100 miles.
	 * Every address that resolves to a point inside the circle is refused,
	 * whatever was typed: units, names, ZIPs and city names do not move a
	 * building. Returns '' for anything outside those ranges.
	 *
	 * @param float|string $lat      Latitude.
	 * @param float|string $lng      Longitude.
	 * @param int|string   $radius_m Radius in metres.
	 * @return string
	 */
	public static function geo_key( $lat, $lng, $radius_m ) {
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) || ! is_numeric( $radius_m ) ) {
			return '';
		}
		$lat = (float) $lat;
		$lng = (float) $lng;
		$r   = (int) round( (float) $radius_m );
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ( 0.0 === $lat && 0.0 === $lng ) || $r < 50 || $r > 160934 ) {
			return '';
		}
		return 'geo:' . sprintf( '%.5f', $lat ) . '|' . sprintf( '%.5f', $lng ) . '|' . $r;
	}

	/**
	 * Parse an area-ban key back into its parts.
	 *
	 * @param string $key "geo:<lat>|<lng>|<metres>".
	 * @return array|null { lat:float, lng:float, radius_m:int } or null.
	 */
	public static function geo_parts( $key ) {
		if ( 0 !== strpos( (string) $key, 'geo:' ) ) {
			return null;
		}
		$segs = explode( '|', substr( (string) $key, 4 ) );
		if ( 3 !== count( $segs ) || '' === self::geo_key( $segs[0], $segs[1], $segs[2] ) ) {
			return null;
		}
		return array(
			'lat'      => (float) $segs[0],
			'lng'      => (float) $segs[1],
			'radius_m' => (int) $segs[2],
		);
	}

	/**
	 * Great-circle distance between two points, in metres (haversine on a
	 * 6,371 km sphere — accurate to a fraction of a percent, which at the
	 * scale of an area ban is a few metres).
	 *
	 * @param float $lat1 Latitude 1.
	 * @param float $lng1 Longitude 1.
	 * @param float $lat2 Latitude 2.
	 * @param float $lng2 Longitude 2.
	 * @return float
	 */
	public static function distance_m( $lat1, $lng1, $lat2, $lng2 ) {
		$p1 = deg2rad( (float) $lat1 );
		$p2 = deg2rad( (float) $lat2 );
		$dp = $p2 - $p1;
		$dl = deg2rad( (float) $lng2 - (float) $lng1 );
		$a  = sin( $dp / 2 ) ** 2 + cos( $p1 ) * cos( $p2 ) * sin( $dl / 2 ) ** 2;
		return 6371000.0 * 2 * atan2( sqrt( $a ), sqrt( max( 0.0, 1 - $a ) ) );
	}

	/**
	 * Miles to metres, for the admin forms (merchants think in miles).
	 *
	 * @param float $miles Miles.
	 * @return int Metres.
	 */
	public static function miles_to_m( $miles ) {
		return (int) round( (float) $miles * 1609.344 );
	}

	/**
	 * The postcode as a streetzip key spells it: the normalized postcode, a US
	 * ZIP+4 reduced to its five digits.
	 *
	 * @param string $cc       Upper-cased country code.
	 * @param string $postcode Raw postcode.
	 * @return string
	 */
	public static function zip_base( $cc, $postcode ) {
		$zp = self::postcode( $postcode );
		if ( 'US' === $cc && preg_match( '/^(\d{5})-?\d{4}$/', $zp, $m ) ) {
			return $m[1];
		}
		return $zp;
	}

	/**
	 * Normalize a county name for a streetcounty key: like city(), with the
	 * word "county" dropped so "Miami-Dade County", "MIAMI-DADE" and "Miami
	 * Dade" agree ("miami dade").
	 *
	 * @param string $s Raw county.
	 * @return string
	 */
	public static function county( $s ) {
		$c = self::city( $s );
		$c = trim( (string) preg_replace( '/\b(county|parish|borough)\b/', ' ', $c ) );
		return trim( (string) preg_replace( '/\s+/', ' ', $c ) );
	}

	/**
	 * Every candidate place key a destination could match, so FS_Blocklist::match()
	 * can resolve destination locks with a single indexed IN(...) lookup instead
	 * of a LIKE scan. Returns one street key (country+state+city+street, when a
	 * street is known), one city key (country+state+city), one zip key PER
	 * postcode prefix from length 2 up to the full postcode (max 10 prefixes),
	 * and one state key (country+state). Empty for virtual goods with no address.
	 *
	 * The ORDER is part of the contract: most specific first — building, the
	 * street (within the city, the ZIP, the county), city, ZIP prefixes
	 * longest to shortest, state. When more than one lock covers a
	 * destination, match() reports the earliest, so the narrowest lock's own
	 * checkout message is the one the customer reads.
	 *
	 * @param string $country  Country code.
	 * @param string $state    State/region.
	 * @param string $city     City.
	 * @param string $postcode Postcode.
	 * @param string $street   Normalized street name (output of street()), or ''.
	 * @param string $number   Normalized house number (output of house()), or ''.
	 * @param string $county   County, when an address-validation result supplied one, or ''.
	 * @return array Distinct candidate keys, most specific first.
	 */
	public static function place_keys( $country, $state, $city, $postcode, $street = '', $number = '', $county = '' ) {
		$cc   = strtoupper( trim( (string) $country ) );
		$keys = array();
		if ( '' === $cc ) {
			return $keys;
		}
		$st = self::region( $state );
		$ct = self::city( $city );
		$zp = self::postcode( $postcode );
		$sn = self::align_street( $street );
		$no = self::house_number( preg_replace( '/\D+/', '', (string) $number ) );
		$co = self::county( $county );

		// A street typed WITHOUT its type ("8020 NW 66th") is also tried as
		// each common type — the same net door_alternates() casts for the
		// address hash — so a dropped "St" meets a building or street lock.
		// The typed form comes first (most specific), the alternates after.
		$streets = array();
		if ( '' !== $sn ) {
			$streets[] = $sn;
			$toks      = explode( ' ', $sn );
			if ( ! self::is_street_type( end( $toks ) ) ) {
				foreach ( self::COMMON_STREET_TYPES as $type ) {
					$streets[] = $sn . ' ' . $type;
				}
			}
		}
		foreach ( $streets as $street_name ) {
			if ( '' !== $st && '' !== $no ) {
				$keys[] = 'building:' . $cc . '|' . $st . '|' . $no . '|' . $street_name;
			}
		}
		foreach ( $streets as $street_name ) {
			if ( '' !== $st && '' !== $ct ) {
				$keys[] = 'street:' . $cc . '|' . $st . '|' . $ct . '|' . $street_name;
			}
			if ( '' !== $zp ) {
				$keys[] = 'streetzip:' . $cc . '|' . self::zip_base( $cc, $zp ) . '|' . $street_name;
			}
			if ( '' !== $st && '' !== $co ) {
				$keys[] = 'streetcounty:' . $cc . '|' . $st . '|' . $co . '|' . $street_name;
			}
		}
		if ( '' !== $st && '' !== $ct ) {
			$keys[] = 'city:' . $cc . '|' . $st . '|' . $ct;
		}
		if ( '' !== $zp ) {
			$max = min( strlen( $zp ), 11 ); // Lengths 11..2 → at most 10 prefixes.
			for ( $len = $max; $len >= 2; $len-- ) {
				$keys[] = 'zip:' . $cc . '|' . substr( $zp, 0, $len );
			}
		}
		if ( '' !== $st ) {
			$keys[] = 'state:' . $cc . '|' . $st;
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Stable hash for indexing/privacy.
	 *
	 * @param string $s Input.
	 * @return string SHA-256 hex, or '' for empty input.
	 */
	public static function hash( $s ) {
		$s = (string) $s;
		return '' === $s ? '' : hash( 'sha256', $s );
	}
}
