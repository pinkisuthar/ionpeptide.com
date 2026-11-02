<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

#[AllowDynamicProperties]
class BWFAN_Merge_Tag_Loader {
	public static $included_merge_tags = array();
	/** @var array */
	protected static $data = array(
		'is_preview' => false,
	);
	/** @var array */
	private static $merge_tags_list;
	private static $instance = null;
	private static $localize_tags_with_source = [];
	private static $localize_tags = [];
	/** @var bool */
	public static $merge_tag_loaded = false;
	private static $merge_tag_group = [];

	private static $_registered_entity = array(
		'active' => array(),
	);

	private static $localize_tags_with_group = [];

	/**
	 * Lazy-loading state. A "source" is one registrant's directory + its
	 * declared shortcode => filename map, registered in the exact order the
	 * old glob chain loaded files (Lite first, then each
	 * `bwfan_merge_tags_loaded` listener at its own priority), so full
	 * materialization preserves today's registration order byte-for-byte.
	 */
	private static $lazy_sources = array(); // source => [ 'dir' => path, 'map' => [ shortcode => filename ] ]
	private static $tag_index = array();    // shortcode => source
	/**
	 * Sources whose whole map has already been bulk-included, keyed by source
	 * name. This replaces a single global "everything is loaded" flag: that
	 * flag made materialize_all() a one-shot, so a source registered after the
	 * first full pass — which register_lazy_tags() explicitly supports — was
	 * stubbed and indexed but never bulk-included, leaving its tags renderable
	 * yet missing from get_all_merge_tags() and the localize getters.
	 */
	private static $materialized_sources = array();

	/**
	 * Re-entrancy guard for the loading pass. load_merge_tags() does not set
	 * $merge_tag_loaded until after do_action( 'bwfan_merge_tags_loaded' ), so
	 * an add-on calling a public getter (get_all_merge_tags(), etc.) from
	 * inside that hook would otherwise re-enter the pass and fire the hook
	 * again — WordPress does not block re-entry — recursing until the stack
	 * blows.
	 */
	private static $loading_merge_tags = false;
	private static $failed_tags = array();  // shortcode => true, file loaded but tag never registered (guarded registration)

	/**
	 * Tags whose constructors register WP hooks that must exist before the
	 * tag itself is parsed: bwfan_coupon_created fires during action
	 * execution (before body decode) and bwfan_output_email_style during
	 * template render. Everything else only calls add_shortcode (covered by
	 * the stub) or hooks wp_ajax_bwfan_get_automation_* (covered by
	 * is_automation_ajax_call(), which runs on admin_init — before ajax
	 * dispatch — and materializes everything).
	 */
	private static $lite_eager_tags = array( 'bwfan_create_coupon', 'bwfan_order_customer_details' );

	private $_form_merge_tags = [
		'contact_confirmation_link', // Form-only when Pro registers it; filtered from broadcast. When merged, Pro skips registration.
	];

	private $_fields_merge_tags = [
		/** Contact Columns */
		'email'         => 'contact_email',
		'f_name'        => 'contact_first_name',
		'l_name'        => 'contact_last_name',
		'contact_no'    => 'contact_phone',
		'timezone'      => 'contact_timezone',
		'creation_date' => 'contact_creation_date',
		'status'        => 'contact_marketing_status',
		'address'       => 'contact_address',
		'country'       => 'contact_country',
		'state'         => 'contact_state',

		/** Contact Fields */
		'company'       => 'contact_company',
		'dob'           => 'contact_dob',
		'gender'        => 'contact_gender',
		'address-1'     => 'contact_address_1',
		'address-2'     => 'contact_address_2',
		'city'          => 'contact_city',
		'postcode'      => 'contact_postcode',
	];

	/**
	 * Constructor
	 *
	 * @access public
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'is_automation_ajax_call' ) );

		if ( defined( 'BWFAN_IS_DEV' ) && BWFAN_IS_DEV ) {
			add_action( 'admin_notices', array( $this, 'show_map_drift_notice' ) );
		}
	}

	/**
	 * Check if the current request is an automation ajax call
	 */
	public function is_automation_ajax_call() {
		if ( ! wp_doing_ajax() || empty( $_REQUEST['action'] ) || strpos( sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ), 'bwfan_get_automation_' ) === false ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- request detection only; decides which code loads, changes nothing
			return;
		}

		/** Ajax handlers live in tag constructors — every file must be present before wp_ajax_* dispatch */
		self::materialize_all();
	}

	/**
	 * Ensures only one instance of the class is loaded or can be loaded.
	 *
	 * @return BWFAN_Merge_Tag_Loader|null
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function include_merge_tag_files() {
		$this->load_merge_tags();
		$this->register_classes();
	}

	/**
	 * Function to get all the data set
	 *
	 * @param string $key
	 *
	 * @return array|mixed
	 */
	public static function get_data( $key = '' ) {
		if ( empty( $key ) ) {
			return self::$data;
		}
		if ( isset( self::$data[ $key ] ) ) {
			return self::$data[ $key ];
		}

		return array();
	}

	/**
	 * Set merge tag data
	 *
	 * @param $data
	 */
	public static function set_data( $data ) {
		if ( ! is_array( $data ) ) {
			return;
		}
		if ( ! self::$merge_tag_loaded ) {
			self::$instance->include_merge_tag_files();
		}
		foreach ( $data as $key => $value ) {
			self::$data[ $key ] = $value;
		}
	}

	/**
	 * Reset the data
	 */
	public static function reset_data() {
		self::$data = array(
			'is_preview' => false,
		);
	}

	/**
	 * Loading pass: fires `bwfan_merge_tags_loaded` (old-version Pro /
	 * Connectors and add-ons glob their files right here, exactly as
	 * before), then indexes every lazily-declared map and registers one
	 * shared stub shortcode per mapped tag. No mapped file is included —
	 * a file loads the moment its shortcode is first parsed
	 * (dispatch_lazy_tag), or all together via materialize_all().
	 */
	public static function load_merge_tags() {

		if ( self::$merge_tag_loaded || self::$loading_merge_tags ) {
			return;
		}
		self::$loading_merge_tags = true;

		self::register_lazy_tags( 'lite', __DIR__ . '/merge_tags', self::lite_merge_tag_map() );

		/**
		 * Index Lite before the hook fires, not after.
		 *
		 * Old-version Pro and add-ons decide whether to register their own
		 * copy of a duplicated tag by probing `shortcode_exists()` inside
		 * `bwfan_merge_tags_loaded` — a probe that answered "yes, Lite has
		 * it" only because Lite called add_shortcode() before firing the
		 * hook. Stubbing Lite's tags here keeps that probe answering the
		 * same way it did pre-lazy-loading. index_source() skips any tag
		 * already registered, so nothing is stomped, and build_index()
		 * below is idempotent for a source already on the index.
		 */
		if ( isset( self::$lazy_sources['lite'] ) ) { // absent when an empty map sent register_lazy_tags() down the eager-glob branch
			self::index_source( 'lite' );
		}

		do_action( 'bwfan_merge_tags_loaded' );

		self::build_index();
		self::$merge_tag_loaded = true;

		foreach ( self::$lite_eager_tags as $tag ) {
			self::materialize_tag( $tag );
		}

		self::$loading_merge_tags = false;
	}

	/**
	 * Declare a directory of merge-tag files as lazily loadable.
	 *
	 * Call it from your `bwfan_merge_tags_loaded` listener — the same hook,
	 * the same priority where the files were previously required — so the
	 * source order (and therefore full-materialization order) matches the
	 * old include order. Guard the call with
	 * `method_exists( 'BWFAN_Merge_Tag_Loader', 'register_lazy_tags' )` and
	 * keep the eager glob as the else-branch for old Lite versions.
	 *
	 * An empty/invalid map falls back to an eager glob of $dir — identical
	 * to the old behaviour, nothing breaks, nothing gets faster.
	 *
	 * @param string $source unique key, e.g. 'pro/merge_tags'
	 * @param string $dir    absolute path to the directory
	 * @param array  $map    [ shortcode name => file basename ], filename order
	 */
	public static function register_lazy_tags( $source, $dir, $map ) {
		if ( empty( $map ) || ! is_array( $map ) ) {
			foreach ( glob( trailingslashit( $dir ) . 'class-*.php' ) as $_field_filename ) {
				if ( ! is_readable( $_field_filename ) ) { // survive update file-swap / partial deploy
					continue;
				}
				require_once( $_field_filename );
			}

			return;
		}

		self::$lazy_sources[ $source ] = array(
			'dir' => untrailingslashit( $dir ),
			'map' => $map,
		);

		/** Late registrant (after the loading pass) — index it immediately */
		if ( self::$merge_tag_loaded ) {
			self::index_source( $source );
		}
	}

	/**
	 * Index all registered sources (loading-pass path).
	 */
	private static function build_index() {
		foreach ( array_keys( self::$lazy_sources ) as $source ) {
			self::index_source( $source );
		}
	}

	/**
	 * Put one source's tags on the index and stub their shortcodes.
	 *
	 * An already-registered shortcode is never stubbed over: an old-version
	 * plugin or an add-on that eagerly registered the real handler during
	 * `bwfan_merge_tags_loaded` keeps it.
	 */
	private static function index_source( $source ) {
		foreach ( self::$lazy_sources[ $source ]['map'] as $tag => $filename ) {
			if ( isset( self::$tag_index[ $tag ] ) ) {
				continue;
			}
			self::$tag_index[ $tag ] = $source;
			if ( ! shortcode_exists( $tag ) ) {
				add_shortcode( $tag, array( __CLASS__, 'dispatch_lazy_tag' ) );
			}
		}
	}

	/**
	 * Stub handler shared by every mapped-but-not-yet-loaded tag: include
	 * the file, then hand off to the real handler its constructor just
	 * registered. A file whose registration guard failed (integration
	 * plugin inactive) leaves the stub in place — the reconstructed raw
	 * shortcode text is returned, which is what WP itself yields for an
	 * unregistered shortcode, so downstream escaping behaves as before.
	 *
	 * @param array|string $atts
	 * @param string|null  $content
	 * @param string       $tag
	 *
	 * @return string
	 */
	public static function dispatch_lazy_tag( $atts = array(), $content = null, $tag = '' ) {
		if ( empty( $tag ) || isset( self::$failed_tags[ $tag ] ) ) {
			return self::raw_shortcode_text( $tag, $atts, $content );
		}

		$loaded = self::materialize_tag( $tag );

		$callback = isset( $GLOBALS['shortcode_tags'][ $tag ] ) ? $GLOBALS['shortcode_tags'][ $tag ] : null;
		if ( ! empty( $callback ) && $callback !== array( __CLASS__, 'dispatch_lazy_tag' ) ) {
			return call_user_func( $callback, $atts, $content, $tag );
		}

		/**
		 * Latch a genuine registration refusal only — the file loaded and
		 * declined to register (its integration plugin is inactive), which
		 * cannot change for the life of the request.
		 *
		 * An unreadable file is transient, not permanent: materialize_tag()
		 * returns false while a plugin update swaps files, and the
		 * is_readable() guard there exists precisely to survive that. Latching
		 * it would keep this tag rendering as literal text for every later
		 * task in the same worker batch — long after the file is readable
		 * again, since the check at the top of this method short-circuits
		 * before materialize_tag() is ever retried. Those emails still send
		 * and still get marked complete, so nothing surfaces the breakage.
		 */
		if ( $loaded ) {
			self::$failed_tags[ $tag ] = true;
		}

		return self::raw_shortcode_text( $tag, $atts, $content );
	}

	/**
	 * Include the single file that registers a tag. Idempotent.
	 *
	 * @param string $tag shortcode name
	 *
	 * @return bool file was includable
	 */
	public static function materialize_tag( $tag ) {
		if ( ! isset( self::$tag_index[ $tag ] ) ) {
			return false;
		}

		$source = self::$lazy_sources[ self::$tag_index[ $tag ] ];
		$file   = $source['dir'] . '/' . $source['map'][ $tag ];

		if ( ! is_readable( $file ) ) { // survive update file-swap / partial deploy
			return false;
		}

		require_once( $file );

		return true;
	}

	/**
	 * Include every mapped file, source by source in registration order —
	 * the same order the old glob chain produced. Needed wherever the full
	 * catalogue matters: builder localize data, v1 automation ajax, admin
	 * REST responses.
	 */
	public static function materialize_all() {
		self::load_merge_tags();

		$pending = array_diff_key( self::$lazy_sources, self::$materialized_sources );
		if ( empty( $pending ) ) {
			return;
		}

		foreach ( $pending as $source_key => $source ) {
			/** Mark before including — a file that re-enters must not restart this source. */
			self::$materialized_sources[ $source_key ] = true;

			foreach ( $source['map'] as $filename ) {
				$file = $source['dir'] . '/' . $filename;
				if ( ! is_readable( $file ) ) { // survive update file-swap / partial deploy
					continue;
				}
				require_once( $file );
			}
		}

		if ( null !== self::$instance ) {
			self::$instance->register_classes();
		}

		if ( defined( 'BWFAN_IS_DEV' ) && BWFAN_IS_DEV ) {
			self::dev_check_maps();
		}
	}

	/**
	 * Register the merge tag when the merge tag file is included
	 *
	 * @param $shortName
	 * @param $class
	 * @param $overrides
	 * @param $group_label
	 *
	 * @return void
	 */
	public static function register( $shortName, $class, $overrides = null, $group_label = '' ) {
		if ( ! class_exists( $class ) || ! method_exists( $class, 'get_instance' ) ) {
			return;
		}

		/**
		 * @var BWFAN_Merge_Tag $instance 
		 */
		$instance                                               = $class::get_instance();
		$slug                                                   = $instance->get_slug();
		self::$localize_tags_with_source[ $shortName ][ $slug ] = $instance->get_localize_data();
		self::$localize_tags[ $slug ]                           = self::$localize_tags_with_source[ $shortName ][ $slug ];
		self::$_registered_entity[ $shortName ][ $slug ]        = $instance;
		if ( ! empty( $group_label ) && ! isset( self::$merge_tag_group[ $shortName ] ) ) {
			self::$merge_tag_group[ $shortName ] = $group_label;
		}

		if ( true === $instance->is_crm_broadcast() ) {
			$group_label                                             = ! empty( $group_label ) ? $group_label : 'General';
			self::$localize_tags_with_group[ $group_label ][ $slug ] = $instance;
		}
	}

	/**
	 * Return all the actions with group and their integrations
	 *
	 * @return array
	 */
	public static function get_all_merge_tags() {
		self::materialize_all();
		if ( null !== self::$instance ) {
			self::$instance->register_classes();
		}

		return self::$included_merge_tags;
	}

	/**
	 * Registers every integration as a system integration
	 */
	public function register_classes() {
		$load_classes = self::get_registered_entities();

		if ( is_array( $load_classes ) && count( $load_classes ) > 0 ) {
			self::$included_merge_tags = $load_classes;
		}
	}

	/**
	 * Raw registered-entity read, no load trigger — internal.
	 *
	 * @return array
	 */
	private static function get_registered_entities() {
		return self::$_registered_entity;
	}

	/**
	 * Return the registered integrations
	 *$instance->get_localize_data()
	 * @return mixed
	 */
	public static function get_registered_merge_tags() {
		self::materialize_all();

		return self::$_registered_entity;
	}

	/**
	 * Return associated merge tag localize data(Parent child based)
	 * @return array
	 */
	public function get_localize_tags_with_source() {
		self::materialize_all();

		return self::$localize_tags_with_source;
	}

	/**
	 * Return merge tag localize data
	 * @return array
	 */
	public function get_localize_tags() {
		return self::$localize_tags_with_source;
	}

	/**
	 * Returns merge tag group array
	 *
	 * @return array
	 */
	public function get_merge_tag_groups() {
		return self::$merge_tag_group;
	}


	/**
	 * Get crm broadcast merge tags
	 *
	 * @param $form_tags
	 *
	 * @return array
	 */
	public function get_localize_tags_with_group( $form_tags = false, $extra_group = [] ) {
		self::materialize_all();
		$group_localize_tags = self::$localize_tags_with_group;

		foreach ( $group_localize_tags as $key => $value ) {
			foreach ( $value as $k => $v ) {
				if ( false === $form_tags && in_array( $k, $this->_form_merge_tags ) ) {
					unset( $group_localize_tags[ $key ][ $k ] );
				}
			}
		}

		if ( empty( $extra_group ) ) {
			return $group_localize_tags;
		}

		foreach ( $extra_group as $group_key ) {
			if ( ! isset( self::$included_merge_tags[ $group_key ] ) ) {
				continue;
			}
			$group_localize_tags[ self::$merge_tag_group[ $group_key ] ] = self::$included_merge_tags[ $group_key ];
		}

		return $group_localize_tags;
	}

	public function get_field_tag( $field_slug ) {
		if ( empty( $field_slug ) ) {
			return '';
		}

		if ( isset( $this->_fields_merge_tags[ $field_slug ] ) ) {
			return "{{{$this->_fields_merge_tags[ $field_slug ]}}}";
		}

		return "{{contact_field key=\"{$field_slug}\"}}";
	}

	/**
	 * Rebuild the shortcode text an unresolvable tag was parsed from — the
	 * output WP produces for an unregistered shortcode, so the nested-parse
	 * escaping in BWFAN_Merge_Tag then treats it exactly as before.
	 *
	 * @param string       $tag
	 * @param array|string $atts
	 * @param string|null  $content
	 *
	 * @return string
	 */
	private static function raw_shortcode_text( $tag, $atts, $content ) {
		$text = '[' . $tag;
		if ( is_array( $atts ) ) {
			foreach ( $atts as $key => $value ) {
				$text .= is_int( $key ) ? ' ' . $value : ' ' . $key . '="' . $value . '"';
			}
		}
		$text .= ']';
		if ( is_string( $content ) && '' !== $content ) {
			$text .= $content . '[/' . $tag . ']';
		}

		return $text;
	}

	/**
	 * BWFAN_IS_DEV insurance for hand-declared maps: every file on disk must
	 * be mapped, every mapped file must exist, map order must match
	 * filename order (registration order = builder order). Findings go to
	 * error_log AND to a transient shown as an admin notice — drift found
	 * during a REST/ajax/worker request would otherwise never be seen.
	 */
	private static function dev_check_maps() {
		$findings = array();
		foreach ( self::$lazy_sources as $key => $source ) {
			$files = array_map( 'basename', (array) glob( $source['dir'] . '/class-*.php' ) );
			foreach ( array_diff( $files, $source['map'] ) as $unmapped ) {
				$findings[] = sprintf( '%s: file not in map: %s', $key, $unmapped );
			}
			foreach ( array_diff( $source['map'], $files ) as $stale ) {
				$findings[] = sprintf( '%s: mapped file missing: %s', $key, $stale );
			}
			$mapped_order = array_values( array_unique( array_values( $source['map'] ) ) );
			if ( $mapped_order !== array_values( array_intersect( $files, $mapped_order ) ) ) {
				$findings[] = sprintf( '%s: map not in filename order - builder order will drift', $key );
			}
		}

		if ( empty( $findings ) ) {
			delete_transient( 'bwfan_merge_tag_map_drift' );

			return;
		}

		foreach ( $findings as $finding ) {
			error_log( '[BWFAN merge_tag_map] ' . $finding ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- developer diagnostic, only runs when BWFAN_IS_DEV is defined
		}
		set_transient( 'bwfan_merge_tag_map_drift', $findings, DAY_IN_SECONDS );
	}

	/**
	 * BWFAN_IS_DEV only: surface map drift loudly in wp-admin.
	 */
	public function show_map_drift_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$findings = get_transient( 'bwfan_merge_tag_map_drift' );
		if ( empty( $findings ) || ! is_array( $findings ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>FunnelKit Automations dev check — merge-tag map drift.</strong> Regenerate with <code>php bin/generate-merge-tag-maps.php</code>:</p><ul style="list-style:disc;margin-left:20px;">';
		foreach ( $findings as $finding ) {
			echo '<li>' . esc_html( $finding ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Lite's own map. Regenerate with bin/generate-merge-tag-maps.php after
	 * adding/removing a tag file (BWFAN_IS_DEV logs any drift).
	 *
	 * @return array
	 */
	private static function lite_merge_tag_map() {
		return array(
			'bwfan_admin_email' => 'class-bwfan-admin-email.php',
			'bwfan_business_address' => 'class-bwfan-business-address.php',
			'bwfan_business_name' => 'class-bwfan-business-name.php',
			'bwfan_cf7_form_field' => 'class-bwfan-cf7-form-field.php',
			'bwfan_cf7_form_id' => 'class-bwfan-cf7-form-id.php',
			'bwfan_cf7_form_title' => 'class-bwfan-cf7-form-title.php',
			'bwfan_contact_address' => 'class-bwfan-contact-address.php',
			'bwfan_contact_city' => 'class-bwfan-contact-city.php',
			'bwfan_customer_city' => 'class-bwfan-contact-city.php',
			'bwfan_contact_company' => 'class-bwfan-contact-company.php',
			'bwfan_contact_country' => 'class-bwfan-contact-country.php',
			'bwfan_customer_country' => 'class-bwfan-contact-country.php',
			'bwfan_contact_creation_date' => 'class-bwfan-contact-creation-date.php',
			'bwfan_contact_dob' => 'class-bwfan-contact-dob.php',
			'bwfan_contact_email' => 'class-bwfan-contact-email.php',
			'bwfan_customer_email' => 'class-bwfan-contact-email.php',
			'bwfan_contact_field' => 'class-bwfan-contact-field.php',
			'bwfan_contact_first_name' => 'class-bwfan-contact-firstname.php',
			'bwfan_contact_firstname' => 'class-bwfan-contact-firstname.php',
			'bwfan_customer_first_name' => 'class-bwfan-contact-firstname.php',
			'bwfan_contact_full_name' => 'class-bwfan-contact-full-name.php',
			'bwfan_customer_full_name' => 'class-bwfan-contact-full-name.php',
			'bwfan_contact_gender' => 'class-bwfan-contact-gender.php',
			'bwfan_contact_id' => 'class-bwfan-contact-id.php',
			'bwfan_contact_lastname' => 'class-bwfan-contact-lastname.php',
			'bwfan_contact_last_name' => 'class-bwfan-contact-lastname.php',
			'bwfan_customer_last_name' => 'class-bwfan-contact-lastname.php',
			'bwfan_contact_password_setup_link' => 'class-bwfan-contact-password-setup-link.php',
			'bwfan_contact_phone' => 'class-bwfan-contact-phone.php',
			'bwfan_customer_phone' => 'class-bwfan-contact-phone.php',
			'bwfan_customer_postcode' => 'class-bwfan-contact-postcode.php',
			'bwfan_contact_postcode' => 'class-bwfan-contact-postcode.php',
			'bwfan_contact_state' => 'class-bwfan-contact-state.php',
			'bwfan_customer_state' => 'class-bwfan-contact-state.php',
			'bwfan_contact_status' => 'class-bwfan-contact-status.php',
			'bwfan_contact_timezone' => 'class-bwfan-contact-timezone.php',
			'bwfan_contact_hash_uid' => 'class-bwfan-contact-uid.php',
			'bwfan_customer_username' => 'class-bwfan-contact-username.php',
			'bwfan_contact_username' => 'class-bwfan-contact-username.php',
			'bwfan_contact_user_id' => 'class-bwfan-contact-wpid.php',
			'bwfan_customer_user_id' => 'class-bwfan-contact-wpid.php',
			'bwfan_current_datetime' => 'class-bwfan-current-datetime.php',
			'bwfan_mail_footer' => 'class-bwfan-mail-footer.php',
			'bwfan_profile_link' => 'class-bwfan-manage-profile-link.php',
			'bwfan_one_click_unsubscribe_link' => 'class-bwfan-one-click-unsubscribe-link.php',
			'bwfan_site_logo' => 'class-bwfan-site-logo.php',
			'bwfan_site_url' => 'class-bwfan-site-url.php',
			'bwfan_site_link' => 'class-bwfan-site-url.php',
			'bwfan_store_name' => 'class-bwfan-store-name.php',
			'bwfan_contact_subscribe_link' => 'class-bwfan-subscribe-link.php',
			'bwfan_contact_confirmation_link' => 'class-bwfan-subscribe-link.php',
			'bwfan_unsubscribe_link' => 'class-bwfan-unsubscribe-link.php',
			'bwfan_unsubscribe_source' => 'class-bwfan-unsubscribe-source.php',
			'bwfan_user_language' => 'class-bwfan-user-language.php',
			'bwfan_contact_language' => 'class-bwfan-user-language.php',
			'bwfan_cart_abandoned_data' => 'class-bwfan-wc-cart-abandoned-data.php',
			'bwfan_cart_abandoned_date' => 'class-bwfan-wc-cart-abandoned-date.php',
			'bwfan_cart_abandoned_id' => 'class-bwfan-wc-cart-abandoned-id.php',
			'bwfan_cart_billing_address1' => 'class-bwfan-wc-cart-billing-address1.php',
			'bwfan_cart_billing_address2' => 'class-bwfan-wc-cart-billing-address2.php',
			'bwfan_cart_billing_city' => 'class-bwfan-wc-cart-billing-city.php',
			'bwfan_cart_billing_company' => 'class-bwfan-wc-cart-billing-company.php',
			'bwfan_cart_billing_country' => 'class-bwfan-wc-cart-billing-country.php',
			'bwfan_cart_billing_email' => 'class-bwfan-wc-cart-billing-email.php',
			'bwfan_cart_billing_first_name' => 'class-bwfan-wc-cart-billing-first-name.php',
			'bwfan_cart_billing_last_name' => 'class-bwfan-wc-cart-billing-last-name.php',
			'bwfan_cart_billing_phone' => 'class-bwfan-wc-cart-billing-phone.php',
			'bwfan_cart_billing_postcode' => 'class-bwfan-wc-cart-billing-postcode.php',
			'bwfan_cart_billing_state' => 'class-bwfan-wc-cart-billing-state.php',
			'bwfan_cart_currency' => 'class-bwfan-wc-cart-currency.php',
			'bwfan_cart_items' => 'class-bwfan-wc-cart-items.php',
			'bwfan_cart_recovery_link' => 'class-bwfan-wc-cart-recovery-link.php',
			'bwfan_cart_shipping_address1' => 'class-bwfan-wc-cart-shipping-address1.php',
			'bwfan_cart_shipping_address2' => 'class-bwfan-wc-cart-shipping-address2.php',
			'bwfan_cart_shipping_city' => 'class-bwfan-wc-cart-shipping-city.php',
			'bwfan_cart_shipping_company' => 'class-bwfan-wc-cart-shipping-company.php',
			'bwfan_cart_shipping_country' => 'class-bwfan-wc-cart-shipping-country.php',
			'bwfan_cart_shipping_first_name' => 'class-bwfan-wc-cart-shipping-first-name.php',
			'bwfan_cart_shipping_last_name' => 'class-bwfan-wc-cart-shipping-last-name.php',
			'bwfan_cart_shipping_phone' => 'class-bwfan-wc-cart-shipping-phone.php',
			'bwfan_cart_shipping_postcode' => 'class-bwfan-wc-cart-shipping-postcode.php',
			'bwfan_cart_shipping_state' => 'class-bwfan-wc-cart-shipping-state.php',
			'bwfan_cart_total' => 'class-bwfan-wc-cart-total.php',
			'bwfan_create_coupon' => 'class-bwfan-wc-create-coupon-code.php',
			'bwfan_wc_dynamic_coupon' => 'class-bwfan-wc-dynamic-coupon.php',
			'bwfan_item_attribute' => 'class-bwfan-wc-item-attribute.php',
			'bwfan_item_data' => 'class-bwfan-wc-item-data.php',
			'bwfan_item_id' => 'class-bwfan-wc-item-id.php',
			'bwfan_item_image' => 'class-bwfan-wc-item-image.php',
			'bwfan_item_meta' => 'class-bwfan-wc-item-meta.php',
			'bwfan_item_name' => 'class-bwfan-wc-item-name.php',
			'bwfan_item_price' => 'class-bwfan-wc-item-price.php',
			'bwfan_item_quantity' => 'class-bwfan-wc-item-quantity.php',
			'bwfan_item_sku' => 'class-bwfan-wc-item-sku.php',
			'bwfan_item_stock' => 'class-bwfan-wc-item-stock.php',
			'bwfan_item_url' => 'class-bwfan-wc-item-url.php',
			'bwfan_wc_myaccount_page' => 'class-bwfan-wc-myaccount-page.php',
			'bwfan_order_admin_edit_link' => 'class-bwfan-wc-order-admin-edit-link.php',
			'bwfan_order_billing_address' => 'class-bwfan-wc-order-billing-address.php',
			'bwfan_order_billing_city' => 'class-bwfan-wc-order-billing-city.php',
			'bwfan_order_billing_company' => 'class-bwfan-wc-order-billing-company.php',
			'bwfan_order_billing_country' => 'class-bwfan-wc-order-billing-country.php',
			'bwfan_order_billing_email' => 'class-bwfan-wc-order-billing-email.php',
			'bwfan_order_billing_first_name' => 'class-bwfan-wc-order-billing-first-name.php',
			'bwfan_order_billing_last_name' => 'class-bwfan-wc-order-billing-last-name.php',
			'bwfan_order_billing_phone' => 'class-bwfan-wc-order-billing-phone.php',
			'bwfan_order_billing_postcode' => 'class-bwfan-wc-order-billing-postcode.php',
			'bwfan_order_billing_state' => 'class-bwfan-wc-order-billing-state.php',
			'bwfan_order_confirmation_page_link' => 'class-bwfan-wc-order-confirmation-page-link.php',
			'bwfan_order_cross_sells' => 'class-bwfan-wc-order-cross-sells.php',
			'bwfan_order_currency' => 'class-bwfan-wc-order-currency.php',
			'bwfan_order_customer_details' => 'class-bwfan-wc-order-customer-details.php',
			'bwfan_order_customer_note' => 'class-bwfan-wc-order-customer-note.php',
			'bwfan_order_data' => 'class-bwfan-wc-order-data.php',
			'bwfan_order_date' => 'class-bwfan-wc-order-date.php',
			'bwfan_order_id' => 'class-bwfan-wc-order-id.php',
			'bwfan_order_items_sku' => 'class-bwfan-wc-order-items-sku.php',
			'bwfan_order_items_quantitycount' => 'class-bwfan-wc-order-items-total-count.php',
			'bwfan_order_items_total_count' => 'class-bwfan-wc-order-items-total-count.php',
			'bwfan_order_items' => 'class-bwfan-wc-order-items.php',
			'bwfan_order_itemscount' => 'class-bwfan-wc-order-itemscount.php',
			'bwfan_current_order_note' => 'class-bwfan-wc-order-note.php',
			'bwfan_order_number' => 'class-bwfan-wc-order-number.php',
			'bwfan_order_payment_method' => 'class-bwfan-wc-order-payment-method.php',
			'bwfan_order_payment_url' => 'class-bwfan-wc-order-payment-url.php',
			'bwfan_order_refund_amount' => 'class-bwfan-wc-order-refund-amount.php',
			'bwfan_order_refund_reason' => 'class-bwfan-wc-order-refund-reason.php',
			'bwfan_order_refunded_items' => 'class-bwfan-wc-order-refunded-items.php',
			'bwfan_order_related_products' => 'class-bwfan-wc-order-related-products.php',
			'bwfan_order_review' => 'class-bwfan-wc-order-review.php',
			'bwfan_order_shipping_address' => 'class-bwfan-wc-order-shipping-address.php',
			'bwfan_order_shipping_city' => 'class-bwfan-wc-order-shipping-city.php',
			'bwfan_order_shipping_company' => 'class-bwfan-wc-order-shipping-company.php',
			'bwfan_order_shipping_cost' => 'class-bwfan-wc-order-shipping-cost.php',
			'bwfan_order_shipping_country' => 'class-bwfan-wc-order-shipping-country.php',
			'bwfan_order_shipping_email' => 'class-bwfan-wc-order-shipping-email.php',
			'bwfan_order_shipping_first_name' => 'class-bwfan-wc-order-shipping-first-name.php',
			'bwfan_order_shipping_last_name' => 'class-bwfan-wc-order-shipping-last-name.php',
			'bwfan_order_shipping_method' => 'class-bwfan-wc-order-shipping-method.php',
			'bwfan_order_shipping_phone' => 'class-bwfan-wc-order-shipping-phone.php',
			'bwfan_order_shipping_postcode' => 'class-bwfan-wc-order-shipping-postcode.php',
			'bwfan_order_shipping_state' => 'class-bwfan-wc-order-shipping-state.php',
			'bwfan_order_status' => 'class-bwfan-wc-order-status.php',
			'bwfan_order_subtotal' => 'class-bwfan-wc-order-subtotal.php',
			'bwfan_order_summary' => 'class-bwfan-wc-order-summary.php',
			'bwfan_order_tax' => 'class-bwfan-wc-order-tax.php',
			'bwfan_order_total' => 'class-bwfan-wc-order-total.php',
			'bwfan_used_coupon' => 'class-bwfan-wc-order-used-coupons.php',
			'bwfan_order_view_url' => 'class-bwfan-wc-order-view-url.php',
			'bwfan_customer_order_view_link' => 'class-bwfan-wc-order-view-url.php',
			'bwfan_product_add_to_cart_url' => 'class-bwfan-wc-product-add-to-cart-url.php',
			'bwfan_product_current_price' => 'class-bwfan-wc-product-current-price.php',
			'bwfan_product_description' => 'class-bwfan-wc-product-description.php',
			'bwfan_product_featured_image' => 'class-bwfan-wc-product-featured-image.php',
			'bwfan_product_id' => 'class-bwfan-wc-product-id.php',
			'bwfan_product_parent_sku' => 'class-bwfan-wc-product-parent-sku.php',
			'bwfan_product_permalink' => 'class-bwfan-wc-product-permalink.php',
			'bwfan_product_regular_price' => 'class-bwfan-wc-product-regular-price.php',
			'bwfan_product_short_description' => 'class-bwfan-wc-product-short-description.php',
			'bwfan_product_sku' => 'class-bwfan-wc-product-sku.php',
			'bwfan_product_title' => 'class-bwfan-wc-product-title.php',
			'bwfan_review_content' => 'class-bwfan-wc-review-content.php',
			'bwfan_review_rating' => 'class-bwfan-wc-review-rating.php',
		);
	}

}

if ( class_exists( 'BWFAN_Merge_Tag_Loader' ) ) {
	BWFAN_Core::register( 'merge_tags', 'BWFAN_Merge_Tag_Loader' );

}
