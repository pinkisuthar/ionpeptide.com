<?php
defined( 'ABSPATH' ) || exit;

#[\AllowDynamicProperties]
class BWFAN_Manage_Profile {
    private static $ins = null;
    protected $settings = null;
    protected $recipient = '';
    protected $uid = '';
    protected $profile_lists = [];
    protected $profile_tags = [];
    protected $profile_fields = [];
    /** @var WooFunnels_Contact */
    protected $contact = '';
    /** @var BWFCRM_Contact */
    protected $crm_contact = '';

    protected $common_shortcodes = null;

    public function __construct() {
        // ajax hooks
        add_action( 'wp_ajax_bwfan_manage_profile', array( $this, 'bwfan_manage_profile' ) );
        add_action( 'wp_ajax_nopriv_bwfan_manage_profile', array( $this, 'bwfan_manage_profile' ) );
        // class code run
        add_action( 'init', array( $this, 'load_manage_profile_page' ), 1 );
    }

    /**
     * get the instance of the current class
     * @return self|null
     */
    public static function get_instance() {
        if ( null === self::$ins ) {
            self::$ins = new self();
        }

        return self::$ins;
    }

    /**
     * Load hooks for manage profile page
     *
     * @return void
     */
    public function load_manage_profile_page() {
        $global_settings = $this->get_global_settings();

        $this->common_shortcodes = BWFAN_Common_Shortcodes::get_instance();

        if ( is_admin() || ! isset( $global_settings['bwfan_profile_page'] ) || empty( $global_settings['bwfan_profile_page'] ) ) {
            add_shortcode( 'fka_contact_profile_form', '__return_empty_string' );

            return;
        }

        // For no distraction mode
        add_action( 'wp', array( $this, 'bwfan_process_manage_profile_page' ) );

        // Shortcode
        add_shortcode( 'fka_contact_profile_form', array( $this, 'bwfan_manage_profile_page' ) );
    }

    /**
     * Check for distraction-free mode
     *
     * @param array $setting
     *
     * @return bool
     */
    public function is_distraction_free_mode( $setting = [] ) {
        $isPreview = filter_input( INPUT_GET, 'bwf-preview' );
        switch ( $isPreview ) {
            case 'prebuild':
                return true;
            case 'custom':
                return false;
            default:
                return ! empty( $setting['bwfan_profile_page_type'] ) && 'prebuild' === $setting['bwfan_profile_page_type'];
        }
    }

    /**
     * process the profile page
     * for rendering content
     *
     * @return false|void
     */
    public function bwfan_process_manage_profile_page() {
        $setting = $this->get_global_settings();

        /** Check current page is manage profile */
        $manage_profile_page_check = false;
        if ( isset( $setting['bwfan_profile_page'] ) && ! empty( $setting['bwfan_profile_page'] ) ) {
            $manage_profile_page_check = is_page( absint( $setting['bwfan_profile_page'] ) );
        }
        if ( ! $manage_profile_page_check ) {
            return;
        }
        $this->set_data();

        /** Check if distraction free mode enabled */
        if ( ! $this->is_distraction_free_mode( $setting ) ) {
            return;
        }

        // Remove emoji detection script and styles
        remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
        remove_action( 'wp_print_styles', 'print_emoji_styles' );
        // Remove all actions from widgets_init
        remove_all_actions( 'widgets_init' );

        // Enqueue the styles and scripts early
        BWFAN_Public::get_instance()->enqueue_assets( $setting );

        $page_title = ! empty( $setting['bwfan_profile_page_title'] ) ? $setting['bwfan_profile_page_title'] : __( 'Manage Your Profile', 'wp-marketing-automations' );

        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>"/>
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
            <meta name="robots" content="noindex, nofollow">
            <title><?php echo esc_html( $page_title ); ?></title>
<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone page prints its own head without wp_head(), so an enqueued style would never print ?>
            <link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet">
            <?php
            wp_print_styles();
            ?>
        </head>
        <body class="bwfan-manage-profile-page-body <?php echo is_rtl() ? 'is-rtl' : ''; ?>">
        <div class="bwfan-manage-profile-page-wrapper">
            <?php
            // Output profile page content
            echo $this->bwfan_manage_profile_page();  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            wp_print_scripts();
            ?>
        </div>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * create a simple profile page
     * including a shortcode
     * @return void
     */
    public function create_profile_sample_page() {
        /** Fetch fresh data from DB */
        $global_settings = BWFAN_Common::get_global_settings();
        if ( isset( $global_settings['bwfan_profile_page'] ) && intval( $global_settings['bwfan_profile_page'] ) > 0 ) {
            return;
        }

        $content  = sprintf( __( "Hi %s \n\n your email is %s. \n\n%s", 'wp-marketing-automations' ), '[fka_contact_name]', '[fka_contact_email]', '[fka_contact_profile_form]' );// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment, WordPress.WP.I18n.UnorderedPlaceholdersText
        $new_page = array(
            'post_title'   => __( 'Manage Profile', 'wp-marketing-automations' ),
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => 'page',
        );
        $post_id  = wp_insert_post( $new_page );

        $global_settings['bwfan_profile_page'] = strval( $post_id );
        update_option( 'bwfan_global_settings', $global_settings, true );
    }

    /**
     * Handles the management of a profile.
     *
     * This function is responsible for verifying the nonce, setting contact data, and handling profile fields, lists, and tags.
     *
     * @return void
     */
    public function bwfan_manage_profile() {
        BWFAN_Common::nocache_headers();

        // Security check: nonce must be bound to the submitted uid (NF-2).
        $nonce         = filter_input( INPUT_POST, '_nonce' );
        $submitted_uid = sanitize_key( (string) filter_input( INPUT_POST, 'uid' ) );
        $nonce_action  = ! empty( $submitted_uid ) ? 'bwfan-manage_profile-nonce-' . $submitted_uid : 'bwfan-manage_profile-nonce';
        if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
            $this->return_message( 7 );
        }

        $this->set_data();

        if ( empty( $this->contact ) ) {
            $this->return_message();
        }

        if ( $this->is_profile_lists() ) {
            $this->handle_profile_lists();
        }

        // Handle profile tags
        if ( $this->is_profile_tags() ) {
            $this->handle_profile_tags();
        }

        // Handle profile fields and update contact
        if ( $this->is_profile_fields() ) {
            $this->handle_profile_fields();
        }
        $this->crm_contact->save();

        // Return success message
        $this->return_message( 3 );
    }

    protected function get_global_settings() {
        if ( is_null( $this->settings ) ) {
            $this->settings = BWFAN_Common::get_global_settings();
        }

        return $this->settings;
    }

    protected function return_message( $type = 1 ) {
        $default_messages = [
            1  => __( 'Sorry! We are unable to update contact data as no contact found.', 'wp-marketing-automations' ),
            2  => __( 'Your profile has been updated successfully.', 'wp-marketing-automations' ),
            7  => __( 'Security check failed', 'wp-marketing-automations' ),
            9  => __( 'Email is not valid', 'wp-marketing-automations' ),
            10 => __( 'Email is already associated with other contact', 'wp-marketing-automations' ),
        ];

        $error_message = apply_filters( 'bwfan_manage_profile_error_messages', $default_messages );
        if ( 1 === absint( $type ) ) {
            wp_send_json( array(
                'success' => 0,
                'message' => ! empty( $error_message[1] ) ? $error_message[1] : $default_messages[1],
            ) );
        }
        if ( in_array( intval( $type ), array( 2, 3, 4, 5, 6 ), true ) ) {
            $global_settings = $this->get_global_settings();
            wp_send_json( array(
                'success' => 1,
                'message' => ! empty( $global_settings['bwfan_profile_message_text'] ) ? $global_settings['bwfan_profile_message_text'] : ( ! empty( $error_message[2] ) ? $error_message[2] : $default_messages[2] ),
            ) );
        }
        if ( 7 === absint( $type ) ) {
            wp_send_json( array(
                'success' => 0,
                'message' => ! empty( $error_message[7] ) ? $error_message[7] : $default_messages[7],
            ) );
        }
        if ( 9 === absint( $type ) ) {
            wp_send_json( array(
                'success' => 9,
                'message' => ! empty( $error_message[9] ) ? $error_message[9] : $default_messages[9],
            ) );
        }
        if ( 10 === absint( $type ) ) {
            wp_send_json( array(
                'success' => 10,
                'message' => ! empty( $error_message[10] ) ? $error_message[10] : $default_messages[10],
            ) );
        }
    }

    /**
     * Handles the management of profile lists.
     *
     * This function is responsible for adding and removing lists from the contact's profile.
     *
     * @return void
     */
    public function handle_profile_lists() {
        $lists_to_add = $this->profile_lists;
        if ( ! is_array( $lists_to_add ) ) {
            return;
        }
        /**
         * Restrict additions to the lists configured as publicly manageable.
         * A holder of a valid profile link must not be able to submit list IDs
         * that were never rendered in the self-service form (scope bypass).
         * Mirrors the visible-scope check already applied on the removal path below.
         */
        $lists_to_add = array_values( array_intersect( $lists_to_add, $this->get_visible_lists() ) );
        sort( $lists_to_add );
        $assigned_list = array_map( function ( $list ) {
            return [
                'id' => $list
            ];
        }, $lists_to_add );
        if ( count( $lists_to_add ) !== count( array_intersect( $lists_to_add, $this->crm_contact->get_lists() ) ) ) {
            $this->crm_contact->add_lists( $assigned_list );
            $this->crm_contact->save();
        }


        $get_lists       = $this->get_visible_lists();
        $lists_to_remove = array_diff( $get_lists, $lists_to_add );
        $lists_to_remove = array_values( $lists_to_remove );

        /** if list is not assigned */
        if ( empty( $lists_to_remove ) || empty( array_intersect( $lists_to_remove, $this->crm_contact->get_lists() ) ) ) {
            return;
        }

        $this->crm_contact->remove_lists( $lists_to_remove );
    }

    /**
     * Handles the management of profile tags.
     *
     * This function is responsible for adding and removing tags from the contact's profile.
     *
     * @return void
     */
    public function handle_profile_tags() {
        $tags_to_add = $this->profile_tags;
        if ( ! is_array( $tags_to_add ) ) {
            return;
        }
        /**
         * Restrict additions to the tags configured as publicly manageable.
         * A holder of a valid profile link must not be able to submit tag IDs
         * that were never rendered in the self-service form (scope bypass).
         * Mirrors the visible-scope check already applied on the removal path below.
         */
        $tags_to_add = array_values( array_intersect( $tags_to_add, $this->get_visible_tags() ) );
        sort( $tags_to_add );
        $assigned_tag = array_map( function ( $tag ) {
            return [
                'id' => $tag
            ];
        }, $tags_to_add );

        if ( count( $tags_to_add ) !== count( array_intersect( $tags_to_add, $this->crm_contact->get_tags() ) ) ) {
            $this->crm_contact->add_tags( $assigned_tag );
            $this->crm_contact->save();
        }

        $get_tags      = $this->get_visible_tags();
        $tag_to_remove = array_diff( $get_tags, $tags_to_add );
        $tag_to_remove = array_values( $tag_to_remove );

        /** if tag is not assigned */
        if ( empty( $tag_to_remove ) || empty( array_intersect( $tag_to_remove, $this->crm_contact->get_tags() ) ) ) {
            return;
        }
        $this->crm_contact->remove_tags( $tag_to_remove );
    }

    /**
     * Handles the management of profile fields.
     *
     * This function is responsible for updating fields from the contact's profile.
     *
     * @return void
     */
    public function handle_profile_fields() {
        $fields_to_update = $this->profile_fields;
        if ( ! is_array( $fields_to_update ) || 0 === count( $fields_to_update ) ) {
            return;
        }

        /**
         * Restrict updates to the fields configured as publicly manageable.
         * A profile-link holder must not be able to write field slugs that were
         * never exposed in the self-service form (scope bypass). DOB part inputs
         * share the 'dob' slug, so they survive when 'dob' is an allowed field.
         */
        $allowed_field_slugs = array_map( 'strval', $this->get_visible_fields() );
        $fields_to_update    = array_values( array_filter( $fields_to_update, function ( $field ) use ( $allowed_field_slugs ) {
            return isset( $field['slug'] ) && in_array( (string) $field['slug'], $allowed_field_slugs, true );
        } ) );
        if ( 0 === count( $fields_to_update ) ) {
            return;
        }

        $default_contact_fields = $this->get_contact_column_fields();

        // Combine DOB parts if present
        $dob_parts = [ 'day' => '', 'month' => '', 'year' => '' ];
        $has_dob_field = false;

        foreach ( $fields_to_update as $key => $value ) {
            $field_slug = $value['slug'];

            // Check for DOB part selects
            if ( 'dob' === $field_slug && isset( $value['dob_part'] ) ) {
                $has_dob_field = true;
                $dob_parts[ $value['dob_part'] ] = $value['value'];
                // Remove individual parts from update array
                unset( $fields_to_update[ $key ] );
            }
        }

        // If we found DOB parts, combine them into a single field
        if ( $has_dob_field ) {
            $combined_dob = '';
            if ( ! empty( $dob_parts['year'] ) && ! empty( $dob_parts['month'] ) && ! empty( $dob_parts['day'] ) ) {
                $combined_dob = sprintf( '%s-%s-%s', $dob_parts['year'], $dob_parts['month'], $dob_parts['day'] );
            }

            $fields_to_update[] = [
                'slug'  => 'dob',
                'value' => $combined_dob
            ];
        }

        foreach ( $fields_to_update as $value ) {
            $field_value = $value['value'];
            $field_slug  = $value['slug'];

            if ( 'email' === $field_slug ) {
                if ( ! empty( $field_value ) ) {
                    // Sanitize before validation
                    $field_value = sanitize_email( $field_value );

                    // Validate email format
                    if ( ! is_email( $field_value ) ) {
                        $this->return_message( 9 );

                        return;
                    }

                    $check_contact = new BWFCRM_Contact( $field_value );
                    /** If email already exists with other contacts */
                    if ( $check_contact->is_contact_exists() && $field_value !== $this->contact->get_email() ) {
                        // return email is already associated with other contact
                        $this->return_message( 10 );

                        return;
                    }
                }
            }

            // Validate DOB field
            if ( 'dob' === $field_slug && ! empty( $field_value ) ) {
                $date_parts = explode( '-', $field_value );
                if ( count( $date_parts ) !== 3 ) {
                    $this->return_message( 0, __( 'Invalid date format. Please use YYYY-MM-DD format.', 'wp-marketing-automations' ) );
                    return;
                }

                $year  = (int) $date_parts[0];
                $month = (int) $date_parts[1];
                $day   = (int) $date_parts[2];

                // Check if date is valid
                if ( ! checkdate( $month, $day, $year ) ) {
                    $this->return_message( 0, __( 'Please enter a valid date of birth.', 'wp-marketing-automations' ) );
                    return;
                }

                // Check if date is not in the future
                $dob_timestamp   = strtotime( $field_value );
                $current_timestamp = current_time( 'timestamp' );
                if ( $dob_timestamp > $current_timestamp ) {
                    $this->return_message( 0, __( 'Date of birth cannot be in the future.', 'wp-marketing-automations' ) );
                    return;
                }
            }

            /**
             * Validate field value before updating
             *
             * @param string $field_slug The field slug being updated
             * @param mixed $field_value The field value
             * @param array $context Additional context (contact, crm_contact)
             *
             * @return string|bool Error message string if validation fails, false/empty otherwise
             */
            $validation_error = apply_filters( 'bwfan_manage_profile_validate_field', false, $field_slug, $field_value, [
                'contact'     => $this->contact,
                'crm_contact' => $this->crm_contact
            ] );

            if ( ! empty( $validation_error ) && is_string( $validation_error ) ) {
                $this->return_message( 0, $validation_error );
                return;
            }

            $this->crm_contact->set_field_by_slug( $field_slug, $field_value );
            if ( array_key_exists( $field_slug, $default_contact_fields ) ) {
                $this->crm_contact->set_data( [ $field_slug => $field_value ] );
            }
        }
        $this->crm_contact->update( $this->crm_contact->fields );

    }

    /**
     * Handles the management of profile page.
     *
     * This function is responsible for rendering the profile page fields, lists and tags.
     *
     * @return string|void The HTML content of the profile page.
     */
    public function bwfan_manage_profile_page() {
        $global_settings = $this->get_global_settings();
        $button_label    = ( isset( $global_settings['bwfan_profile_button_text'] ) ) && ! empty( $global_settings['bwfan_profile_button_text'] ) ? $global_settings['bwfan_profile_button_text'] : __( 'Update Details', 'wp-marketing-automations' );
        $this->set_data();
        $header_logo = ( isset( $global_settings['bwfan_setting_business_logo'] ) ) ? $global_settings['bwfan_setting_business_logo'] : '';
        $mode        = $this->is_distraction_free_mode( $global_settings ) ? 'bwf-distraction-free-mode' : '';
        ob_start();

        // Wrapper
        $wrapper_class = $header_logo ? esc_attr( $mode ) : 'bwf-distraction-free-mode no-header';
        echo '<div class="bwfan-manage-profile-wrapper ' . esc_attr( $wrapper_class ) . '">';
        // if no distraction mode enable
        if ( 'bwf-distraction-free-mode' === $mode ) {
            // Manage profile Header
            $this->common_shortcodes->print_header();
        }

        // Contact Detail Section
        $this->get_contact_detail_section( $mode, $global_settings );

        $content_available = '';

        // Manage Profile Form
        echo '<form id="bwfan_manage_profile_fields">';
        do_action( 'bwfan_print_custom_data', $this->contact );

        if ( $this->is_profile_fields() ) {
            $content_available .= $this->print_profile_fields();
        }
        if ( $this->is_profile_lists() ) {
            $content_available .= $this->print_profile_lists();
        }
        if ( $this->is_profile_tags() ) {
            $content_available .= $this->print_profile_tags();
        }

        if ( empty( $content_available ) ) {
            echo '<p>' . esc_html__( 'No content available to manage', 'wp-marketing-automations' ) . '</p>';

            $output = ob_get_clean();

            return $output ? $output : '';
        }

        $uid = filter_input( INPUT_GET, 'uid' );
        $uid = empty( $uid ) ? filter_input( INPUT_POST, 'uid' ) : $uid;
        $uid = sanitize_key( $uid );
        if ( empty( $uid ) && ! empty( $this->uid ) ) {
            $uid = $this->uid;
        }
        if ( ! empty( $uid ) ) {
            echo '<input type="hidden" id="bwfan_form_uid_id" value="' . esc_attr( $uid ) . '" name="uid">';
        }
        echo '<a id="bwfan_manage_profile_update" class="button-primary button" href="#">' . esc_html( $button_label ) . '</a>';

        // Bind the nonce action to the uid so a nonce minted on one contact's page cannot be replayed to overwrite another contact.
        $nonce_action = ! empty( $uid ) ? 'bwfan-manage_profile-nonce-' . $uid : 'bwfan-manage_profile-nonce';
        echo '<input type="hidden" id="bwfan_manage_profile_nonce" value="' . esc_attr( wp_create_nonce( $nonce_action ) ) . '" name="bwfan_manage_profile_nonce">';
        echo '</form></div>';

        $output = ob_get_clean();

        return $output ? $output : '';
    }

    /**
     * Contact details section
     *
     * @param $mode
     * @param array $global_settings
     *
     * @return void
     */
    public function get_contact_detail_section( $mode = '', $global_settings = [] ) {
        $contact_details = $this->get_contact_details();
        $name            = ! empty( $contact_details['contact_name'] ) ? $contact_details['contact_name'] : '';
        $email           = ! empty( $contact_details['contact_email'] ) ? $contact_details['contact_email'] : '';
        $page_title      = ! empty( $global_settings['bwfan_profile_page_title'] ) ? $global_settings['bwfan_profile_page_title'] : __( 'Manage Your Profile', 'wp-marketing-automations' );
        ?>
        <div class="bwfan-manage-profile-contact-details">
            <div class="bwfan-details">
                <?php
                if ( 'bwf-distraction-free-mode' === $mode ) {
                    echo '<div class="bwf-page-title">' . esc_html( $page_title ) . '</div>';
                }
                ?>
                <?php
                echo '<div class="bwf-contact-info">';
                if ( ! empty( $name ) ) {
                    echo '<span class="bwfan-manage-profile-contact-name">' . esc_html( $name ) . '</span> <span class="bwfan-manage-profile-contact-email">(' . esc_html( $email ) . ')</span>';
                } else {
                    echo '<span class="bwfan-manage-profile-contact-email">' . esc_html( $email ) . '</span>';
                }
                echo '</div>';
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Prints the profile tags html.
     *
     * @return bool
     */
    public function print_profile_tags() {
        /** If admin screen, return */
        if ( is_admin() ) {
            return false;
        }

        $this->set_data();
        $settings = $this->get_global_settings();
        $tags     = isset( $settings['bwfan_profile_tags'] ) ? $settings['bwfan_profile_tags'] : [];
        $heading  = ! empty( $settings['bwfan_profile_tags_text'] ) ? $settings['bwfan_profile_tags_text'] : __( 'Manage Tags', 'wp-marketing-automations' );
        if ( empty( $tags ) || ! is_array( $tags ) ) {
            return false;
        }

        if ( ! $this->contact instanceof WooFunnels_Contact || 0 === $this->contact->get_id() ) {
            return false;
        }

        $tags = BWFCRM_Tag::get_tags( $tags );
        usort( $tags, function ( $l1, $l2 ) {
            return strcmp( strtolower( $l1['name'] ), strtolower( $l2['name'] ) );
        } );
        if ( empty( $tags ) ) {
            return false;
        }
        $this->profile_tags_html( $heading, $tags );

        return true;
    }

    /**
     * Tag section Html
     *
     * @param $heading
     * @param $tags
     *
     * @return void
     */
    public function profile_tags_html( $heading, $tags = array() ) {
        ?>
        <div class="bwfan-profile-tags" id="bwfan-profile-tags">
            <div class="bwf-h2"><?php echo esc_html( $heading ); ?></div>
            <?php
            $subscribed_tags = $this->crm_contact->get_tags();
            $subscribed_tags = array_map( 'intval', $subscribed_tags );
            foreach ( $tags as $tag ) {
                $is_checked = in_array( absint( $tag['ID'] ), $subscribed_tags, true );
                ?>
                <div class="bwfan-profile-single-tag">
                    <div class="bwfan-profile-tag-checkbox">
                        <input
                            id="bwfan-tags-<?php echo esc_attr( $tag['ID'] ); ?>"
                            type="checkbox"
                            value="<?php echo esc_attr( $tag['ID'] ); ?>"
                            <?php echo $is_checked ? 'checked="checked"' : ''; ?>
                        />
                        <label for="bwfan-tags-<?php echo esc_attr( $tag['ID'] ); ?>"><?php echo esc_html( $tag['name'] ); ?></label>
                    </div>
                </div>
                <?php
            }
            ?>
        </div>
        <?php
    }

    /**
     * get the visible tags
     * @return array|mixed
     */
    protected function get_visible_tags() {
        $settings = $this->get_global_settings();

        return ! empty( $settings['bwfan_profile_tags'] ) ? $settings['bwfan_profile_tags'] : [];
    }

    /**
     * Prints the profile lists html.
     *
     * @return bool
     */
    public function print_profile_lists() {
        /** If admin screen, return */
        if ( is_admin() ) {
            return false;
        }
        $this->set_data();
        $settings = $this->get_global_settings();
        $lists    = isset( $settings['bwfan_profile_lists'] ) ? $settings['bwfan_profile_lists'] : [];
        $heading  = ! empty( $settings['bwfan_profile_lists_text'] ) ? $settings['bwfan_profile_lists_text'] : __( 'Manage Lists', 'wp-marketing-automations' );
        if ( empty( $lists ) || ! is_array( $lists ) ) {
            return false;
        }

        if ( ! $this->contact instanceof WooFunnels_Contact || 0 === $this->contact->get_id() ) {
            return false;
        }

        $lists = BWFCRM_Lists::get_lists( $lists );
        usort( $lists, function ( $l1, $l2 ) {
            return strcmp( strtolower( $l1['name'] ), strtolower( $l2['name'] ) );
        } );
        if ( empty( $lists ) ) {
            return false;
        }
        $this->profile_lists_html( $heading, $lists );

        return true;
    }

    /**
     * Profile list section Html
     *
     * @param $heading
     * @param $lists
     *
     * @return void
     */
    public function profile_lists_html( $heading, $lists = array() ) {
        ?>
        <div class="bwfan-profile-lists" id="bwfan-profile-lists">
            <div class="bwf-h2"><?php echo esc_html( $heading ); ?></div>
            <?php

            $settings         = $this->get_global_settings();
            $subscribed_lists = $this->crm_contact->get_lists();
            $subscribed_lists = array_map( 'intval', $subscribed_lists );

            foreach ( $lists as $list ) {
                $is_checked = in_array( absint( $list['ID'] ), $subscribed_lists, true );
                ?>
                <div class="bwfan-profile-single-list">
                    <div class="bwfan-profile-list-checkbox">
                        <input
                            id="bwfan-list-<?php echo esc_attr( $list['ID'] ); ?>"
                            type="checkbox"
                            value="<?php echo esc_attr( $list['ID'] ); ?>"
                            <?php echo $is_checked ? 'checked="checked"' : ''; ?>
                        />
                        <label for="bwfan-list-<?php echo esc_attr( $list['ID'] ); ?>"><?php echo esc_html( $list['name'] ); ?></label>
                    </div>
                    <?php if ( isset( $list['description'] ) && $settings['bwfan_enable_profile_list_description'] ) : ?>
                        <p class="bwfan-profile-list-description"><?php echo wp_kses_post( $list['description'] ); ?></p>
                    <?php endif; ?>
                </div>
                <?php
            }
            ?>
        </div>
        <?php
    }

    protected function get_visible_lists() {
        $settings = $this->get_global_settings();

        return ! empty( $settings['bwfan_profile_lists'] ) ? $settings['bwfan_profile_lists'] : [];
    }

    /**
     * Get the fields configured as publicly manageable on the profile form.
     *
     * @return array|mixed Flat list of allowed field slugs.
     */
    protected function get_visible_fields() {
        $settings = $this->get_global_settings();

        return ! empty( $settings['bwfan_profile_manage_fields'] ) ? $settings['bwfan_profile_manage_fields'] : [];
    }

    /**
     * Prints the profile fields html.
     *
     * @return bool || void
     */
    public function print_profile_fields() {
        /** If admin screen, return */
        if ( is_admin() ) {
            return;
        }
        $this->set_data();
        $settings = $this->get_global_settings();
        $fields   = isset( $settings['bwfan_profile_manage_fields'] ) ? $settings['bwfan_profile_manage_fields'] : [];
        $heading  = ! empty( $settings['bwfan_profile_fields_text'] ) ? $settings['bwfan_profile_fields_text'] : __( 'Manage Fields', 'wp-marketing-automations' );
        if ( empty( $fields ) || ! is_array( $fields ) ) {
            return false;
        }
        $fields = $this->sorted_fields( $fields );

        return $this->profile_fields_html( $heading, $fields );
    }

	/**
	 * Get the contact column fields label
	 *
	 * @return array
	 */
    public function get_contact_column_fields_label() {
		return apply_filters( 'bwfan_manage_profile_contact_column_fields_label', array(
			'company'    => __( 'Company', 'wp-marketing-automations' ),
			'address-1'  => __( 'Address 1', 'wp-marketing-automations' ),
			'address-2'  => __( 'Address 2', 'wp-marketing-automations' ),
			'city'       => __( 'City', 'wp-marketing-automations' ),
			'postcode'   => __( 'Postcode', 'wp-marketing-automations' ),
			'gender'     => __( 'Gender', 'wp-marketing-automations' ),
			'dob'        => __( 'Date of Birth', 'wp-marketing-automations' ),
		) );
	}

    /**
     * Prints the profile fields html.
     *
     * @param $heading
     * @param $fields
     *
     * @return bool
     */
    public function profile_fields_html( $heading, $fields = array() ) {
        if ( empty( $fields ) ) {
            return false;
        }
		$settings = $this->get_global_settings();
        $col_fields  = $this->get_contact_column_fields();
		$col_fields_label = $this->get_contact_column_fields_label();
        $fields_data = array_map( function ( $field ) use ( $col_fields, $col_fields_label ) {
            if ( ! in_array( $field, array_keys( $col_fields ), true ) ) {
                $field = BWFAN_Model_Fields::get_field_by_slug( $field );
                if ( empty( $field ) ) {
                    return false;
                }
                $type     = $field['type'];
                $name     = ! empty( $col_fields_label[ $field['slug'] ] ) ? $col_fields_label[ $field['slug'] ] : $field['name'];
                $slug     = $field['slug'];
                $field_id = $field['ID'];
                $value    = $this->crm_contact instanceof BWFCRM_Contact ? $this->crm_contact->get_field_by_slug( $field['slug'] ) : '';

            } else {
                $type  = $slug = $field_id = $field;
                $value = '';
                if ( $this->contact instanceof WooFunnels_Contact ) {
                    $value = method_exists( $this->contact, "get_$field" ) ? call_user_func( [ $this->contact, "get_$field" ] ) : '';
                }
                $name = $col_fields[ $field ];
            }

            return [
                'type'     => $type,
                'name'     => $name,
                'slug'     => $slug,
                'field_id' => $field_id,
                'value'    => $value,
                'meta'     => $field['meta'] ?? ''
            ];
        }, $fields );

        $fields_data = array_filter( $fields_data );
        if ( empty( $fields_data ) ) {
            return false;
        }
        ?>
        <div class="bwfan-profile-fields" id="bwfan-profile-fields">
            <div class="bwf-h2"><?php echo esc_html( $heading ); ?></div>
            <?php
            foreach ( $fields_data as $field ) {
                $type        = $field['type'];
                $name        = $field['name'];
                $slug        = $field['slug'];
                $field_id    = $field['field_id'];
                $field_value = $field['value'];

				if ( bwfan_is_autonami_pro_active() && ! empty( $settings['bwfan_hide_dob_field'] ) && 'dob' === $slug ) {
					continue;
				}

                ?>
                <div class="bwfan-profile-field">
                    <?php
                    if ( in_array( $type, [ BWFCRM_Fields::$TYPE_RADIO, BWFCRM_Fields::$TYPE_CHECKBOX ] ) ) {
                        echo '<span class="bwf-label">' . esc_html( $name ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    } else {
                        echo '<label for="bwfan-field-' . $field_id . '">' . esc_html( $name ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    }

                    switch ( $type ) {
                        case 'country' :
                            $options = BWFAN_Common::get_countries_data();
                            echo '<select id="' . esc_attr( "bwfan-field-{$field_id}" ) . '" data-slug="' . esc_attr( $slug ) . '">';
                            foreach ( $options as $key => $label ) {
                                $selected = ( $field_value === $key ) ? 'selected' : '';
                                echo '<option value="' . esc_attr( $key ) . '" ' . esc_attr( $selected ) . '>' . esc_html( $label ) . '</option>';
                            }
                            echo '</select>';
                            break;
                        case BWFCRM_Fields::$TYPE_TEXT:
                        case 'timezone' !== $type && in_array( $type, array_keys( $col_fields ), true ):
                            echo '<input type="text" id="' . esc_attr( "bwfan-field-{$field_id}" ) . '" data-slug="' . esc_attr( $slug ) . '" value="' . esc_attr( $field_value ) . '" />';
                            break;
                        case BWFCRM_Fields::$TYPE_NUMBER:
                            echo '<input type="number" id="' . esc_attr( "bwfan-field-{$field_id}" ) . '" data-slug="' . esc_attr( $slug ) . '" value="' . esc_attr( $field_value ) . '" />';
                            break;
                        case BWFCRM_Fields::$TYPE_TEXTAREA:
                            echo '<textarea id="' . esc_attr( "bwfan-field-{$field_id}" ) . '" data-slug="' . esc_attr( $slug ) . '">' . esc_textarea( $field_value ) . '</textarea>';
                            break;
                        case BWFCRM_Fields::$TYPE_SELECT:
                        case 'timezone' === $type :
                            $options = [];
                            if ( 'timezone' === $type ) {
                                $options = BWFAN_Common::get_timezone_list();
                            } else if ( ! empty( $field['meta'] ) ) {
                                $options = json_decode( $field['meta'], true )['options'];
                            }
                            echo '<select id="' . esc_attr( "bwfan-field-{$field_id}" ) . '" data-slug="' . esc_attr( $slug ) . '">';
                            foreach ( $options as $option ) {
                                $value    = preg_replace( '/\(UTC[^\)]+\)\s*/', '', $option );
                                $selected = ( $field_value === $value ) ? 'selected' : '';
                                echo '<option value="' . esc_attr( $value ) . '" ' . esc_attr( $selected ) . '>' . esc_html( $option ) . '</option>';
                            }
                            echo '</select>';
                            break;
                        case BWFCRM_Fields::$TYPE_RADIO:
                            if ( empty( $field['meta'] ) ) {
                                break;
                            }
                            $options = json_decode( $field['meta'], true )['options'];
                            foreach ( $options as $option ) {
                                $checked = ( $field_value === $option ) ? 'checked' : '';
                                echo '<div class="bwfan-wrapper">';
                                echo '<input type="radio" id="' . esc_attr( "bwfan-field-{$field_id}-{$option}" ) . '" value="' . esc_attr( $option ) . '" data-slug="' . esc_attr( $slug ) . '" ' . esc_attr( $checked ) . ' name="' . esc_attr( $slug ) . '">';
                                echo '<label for="' . esc_attr( "bwfan-field-{$field_id}-{$option}" ) . '">' . esc_html( $option ) . '</label>';
                                echo '</div>';
                            }
                            break;
                        case BWFCRM_Fields::$TYPE_CHECKBOX:
                            if ( empty( $field['meta'] ) ) {
                                break;
                            }
                            $options     = json_decode( $field['meta'], true )['options'];
                            $field_value = (array) json_decode( $field_value, true );
                            foreach ( $options as $option ) {
                                $checked = in_array( $option, $field_value, true ) ? 'checked="checked"' : '';
                                echo '<div class="bwfan-wrapper">';
                                echo '<input type="checkbox" id="' . esc_attr( "bwfan-field-{$field_id}-{$option}" ) . '" value="' . esc_attr( $option ) . '" data-slug="' . esc_attr( $slug ) . '" ' . esc_attr( $checked ) . '>';
                                echo '<label for="' . esc_attr( "bwfan-field-{$field_id}-{$option}" ) . '">' . esc_html( $option ) . '</label>';
                                echo '</div>';
                            }
                            break;
                        case BWFCRM_Fields::$TYPE_DATE:
                            // Special handling for DOB field - render as 3 selects
                            if ( 'dob' === $slug ) {
                                $selected_date  = '';
                                $selected_month = '';
                                $selected_year  = '';

                                // Parse existing value
                                if ( ! empty( $field_value ) && '0000-00-00' !== $field_value ) {
                                    $date_parts = explode( '-', $field_value );
                                    if ( count( $date_parts ) === 3 ) {
                                        $selected_year  = $date_parts[0];
                                        $selected_month = $date_parts[1];
                                        $selected_date  = $date_parts[2];
                                    }
                                }

                                echo '<div class="bwfan-dob-wrapper">';

                                // Month select
                                echo '<select id="' . esc_attr( "bwfan-field-{$field_id}-mm" ) . '" data-slug="' . esc_attr( $slug ) . '" data-dob-part="month" class="bwfan-dob-field">';
                                foreach ( $this->get_dob_select_options( 'month' ) as $value => $label ) {
                                    $selected = ( $selected_month === $value ) ? 'selected' : '';
                                    echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected_month, $value ) . '>' . esc_html( $label ) . '</option>';
                                }
                                echo '</select>';

                                // Day select
                                echo '<select id="' . esc_attr( "bwfan-field-{$field_id}-dd" ) . '" data-slug="' . esc_attr( $slug ) . '" data-dob-part="day" class="bwfan-dob-field">';
                                foreach ( $this->get_dob_select_options( 'date' ) as $value => $label ) {
                                    $selected = ( $selected_date === $value ) ? 'selected' : '';
                                    echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected_date, $value ) . '>' . esc_html( $label ) . '</option>';
                                }
                                echo '</select>';

                                // Year select
                                echo '<select id="' . esc_attr( "bwfan-field-{$field_id}-yy" ) . '" data-slug="' . esc_attr( $slug ) . '" data-dob-part="year" class="bwfan-dob-field">';
                                foreach ( $this->get_dob_select_options( 'year' ) as $value => $label ) {
                                    $selected = ( $selected_year === $value ) ? 'selected' : '';
                                    echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected_year, $value ) . '>' . esc_html( $label ) . '</option>';
                                }
                                echo '</select>';

                                echo '</div>';
                            } else {
                                // Standard date input for other date fields
                                $placeholder = '0000-00-00';
                                if ( '0000-00-00' === $field_value ) {
                                    $field_value = '';
                                }
                                echo '<input type="text" id="' . esc_attr( "bwfan-field-{$field_id}" ) . '" data-type="date" placeholder="' . esc_attr( $placeholder ) . '" data-slug="' . esc_attr( $slug ) . '" value="' . esc_attr( $field_value ) . '" />';
                                echo '<p class="bwfan-country-add-notice">' . esc_html__( 'Enter value in Y-m-d format. eg:', 'wp-marketing-automations' ) . ' ' . esc_html( gmdate( 'Y-m-d' ) ) . '</p>';
                            }
                            break;
                        case BWFCRM_Fields::$TYPE_DATETIME:
                            $placeholder = '0000-00-00 00:00:00';
                            if ( '0000-00-00 00:00:00' === $field_value ) {
                                $field_value = '';
                            }
                            echo '<input type="text" id="' . esc_attr( "bwfan-field-{$field_id}" ) . '" data-type="date-time" placeholder="' . esc_attr( $placeholder ) . '" data-slug="' . esc_attr( $slug ) . '" value="' . esc_attr( $field_value ) . '" />';
                            echo '<p class="bwfan-country-add-notice">' . esc_html__( 'Enter value in Y-m-d H:i:s format. eg:', 'wp-marketing-automations' ) . ' ' . esc_html( gmdate( 'Y-m-d H:i:s' ) ) . '</p>';
                            break;
                        case BWFCRM_Fields::$TYPE_TIME:
                            $placeholder = '00:00:00';
                            if ( '00:00:00' === $field_value ) {
                                $field_value = '';
                            }
                            echo '<input type="text" id="' . esc_attr( "bwfan-field-{$field_id}" ) . '" data-type="time" data-slug="' . esc_attr( $slug ) . '" placeholder="' . esc_attr( $placeholder ) . '" value="' . esc_attr( $field_value ) . '" />';
                            echo '<p class="bwfan-country-add-notice">' . esc_html__( 'Enter value in H:i:s (24H) format. eg:', 'wp-marketing-automations' ) . ' ' . esc_html( gmdate( 'H:i:s' ) ) . '</p>';
                            break;
                        default:
                            break;
                    }
                    ?>
                </div>
                <?php
            }
            ?>
        </div>
        <?php

        return true;
    }

    /**
     * Default contact fields to get by method
     *
     * @return array
     */
    public function get_contact_column_fields() {
        return array(
            'f_name'     => __( 'First Name', 'wp-marketing-automations' ),
            'l_name'     => __( 'Last Name', 'wp-marketing-automations' ),
            'email'      => __( 'Email', 'wp-marketing-automations' ),
            'contact_no' => __( 'Phone', 'wp-marketing-automations' ),
            'country'    => __( 'Country', 'wp-marketing-automations' ),
            'state'      => __( 'State', 'wp-marketing-automations' ),
            'timezone'   => __( 'Timezone', 'wp-marketing-automations' ),
        );
    }

    /**
     * Sets up the contact data for the current instance.
     *
     * @return void
     */
    protected function set_data() {
        if ( ! empty( $this->contact ) ) {
            return;
        }

        // Get contact from common shortcodes class
        $contact = $this->common_shortcodes->set_data();

        if ( $contact ) {
            $this->contact   = $contact;
            $this->uid       = $contact->get_uid();
            $this->recipient = $contact->get_email();

            if ( class_exists( 'BWFCRM_Contact' ) ) {
                $crm_contact = new BWFCRM_Contact( $contact );
                if ( $crm_contact->is_contact_exists() ) {
                    $this->crm_contact = $crm_contact;
                }
            }
        }

        if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
            return;
        }

        // AJAX-specific data processing
        $lists = filter_input( INPUT_POST, 'profile_lists' );
        if ( ! empty( $lists ) ) {
            $lists = stripslashes_deep( $lists );
            $lists = json_decode( $lists, true );
        }

        if ( ! empty( $lists ) ) {
            $lists = array_map( 'sanitize_text_field', $lists );
            $lists = array_map( 'strval', $lists );
        }

        $this->profile_lists = $lists;

        $tags = filter_input( INPUT_POST, 'profile_tags' );
        if ( ! empty( $tags ) ) {
            $tags = stripslashes_deep( $tags );
            $tags = json_decode( $tags, true );
        }

        if ( ! empty( $tags ) ) {
            $tags = array_map( 'sanitize_text_field', $tags );
            $tags = array_map( 'strval', $tags );
        }
        $this->profile_tags = $tags;

        $fields = filter_input( INPUT_POST, 'profile_fields' );

        if ( ! empty( $fields ) ) {
            $fields = stripslashes_deep( $fields );
            $fields = json_decode( $fields, true );
        }

        if ( ! empty( $fields ) ) {
            foreach ( $fields as &$field ) {
                $field['slug']  = sanitize_text_field( $field['slug'] );
                $field['value'] = sanitize_text_field( $field['value'] );
            }
        }
        $this->profile_fields = $fields;
    }

    /**
     * checking setting enable or not
     * @return bool
     */
    public function is_profile_tags() {
        $settings         = $this->get_global_settings();
        $enabled_tags     = isset( $settings['bwfan_enable_profile_tags'] ) ? $settings['bwfan_enable_profile_tags'] : false;
        $is_tags_selected = isset( $settings['bwfan_profile_tags'] ) ? $settings['bwfan_profile_tags'] : false;

        return ! empty( $enabled_tags ) && ! empty( $is_tags_selected );
    }

    /**
     * checking setting enable or not
     * @return bool
     */
    public function is_profile_lists() {
        $settings          = $this->get_global_settings();
        $enabled_list      = isset( $settings['bwfan_enable_profile_lists'] ) ? $settings['bwfan_enable_profile_lists'] : false;
        $is_lists_selected = isset( $settings['bwfan_profile_lists'] ) ? $settings['bwfan_profile_lists'] : false;

        return ! empty( $enabled_list ) && ! empty( $is_lists_selected );
    }

    /**
     * checking setting enable or not
     * @return bool
     */
    public function is_profile_fields() {
        $settings = $this->get_global_settings();

        return ! empty( $settings['bwfan_profile_manage_fields'] );
    }

    /**
     * Get subscriber details using uid
     *
     * @return array|false
     */
    protected function get_contact_details() {
        $contact_details = $this->common_shortcodes->get_contact_details();

        if ( empty( $contact_details ) ) {
            return false;
        }

        return array(
            'contact_email'     => $contact_details['email'],
            'contact_name'      => $contact_details['name'],
            'contact_firstname' => $contact_details['firstname'],
            'contact_lastname'  => $contact_details['lastname']
        );
    }

    /**
     * @param $selected_fields
     *
     * @return array
     */
    public function sorted_fields( $selected_fields ) {
        $sorted_fields    = [
            'email',
            'f_name',
            'l_name',
            'company',
            'address-1',
            'address-2',
            'city',
            'postcode',
            'country',
            'state',
        ];
        $sorted_fields    = apply_filters( 'bwfan_manage_profile_sorted_fields', $sorted_fields, $selected_fields );
        $formatted_fields = [];
        foreach ( $sorted_fields as $field ) {
            $index = array_search( $field, $selected_fields, true );
            if ( false === $index ) {
                continue;
            }
            $formatted_fields[] = $field;
            unset( $selected_fields[ $index ] );
        }

        return array_merge( $formatted_fields, $selected_fields );
    }

    /**
     * Get date select options for DOB field
     *
     * @param string $type Type of options: 'date', 'month', or 'year'
     *
     * @return array
     */
    protected function get_dob_select_options( $type = 'date' ) {
        $options = [];

        switch ( $type ) {
            case 'date':
                $options = array( '' => __( 'Select Day', 'wp-marketing-automations' ) );
                for ( $i = 1; $i <= 31; $i ++ ) {
                    $key             = sprintf( "%02d", $i );
                    $options[ $key ] = $i;
                }
                break;

            case 'month':
                $options = array( '' => __( 'Select Month', 'wp-marketing-automations' ) );
                for ( $i = 1; $i <= 12; $i ++ ) {
                    $key             = sprintf( "%02d", $i );
                    $options[ $key ] = date_i18n( 'F', mktime( 0, 0, 0, $i, 1 ) );
                }
                break;

            case 'year':
                $options      = array( '' => __( 'Select Year', 'wp-marketing-automations' ) );
                $current_year = (int) current_time( 'Y' );
                $years_back   = apply_filters( 'bwfan_birthday_min_years', 100 );
                $years_back   = max( 1, intval( $years_back ) );
                $min_year     = $current_year - $years_back;

                for ( $i = $current_year; $i >= $min_year; $i -- ) {
                    $options[ (string) $i ] = (string) $i;
                }
                break;
        }

        return $options;
    }
}

BWFAN_Manage_Profile::get_instance();
