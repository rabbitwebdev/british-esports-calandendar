<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BEF_Calendar {

    const POST_TYPE = 'bef_event';
    const TAXONOMY = 'bef_event_category';
    const META_DATE = '_bef_event_date';
    const META_END_DATE = '_bef_event_end_date';
    const META_START_TIME = '_bef_event_start_time';
    const META_END_TIME = '_bef_event_end_time';
    const META_LOCATION = '_bef_event_location';
    const META_URL = '_bef_event_url';
    const META_TICKET_URL = '_bef_event_ticket_url';
    const META_TICKET_LABEL = '_bef_event_ticket_label';
    const META_RECURRENCE_FREQUENCY = '_bef_event_recurrence_frequency';
    const META_RECURRENCE_INTERVAL = '_bef_event_recurrence_interval';
    const META_RECURRENCE_UNTIL = '_bef_event_recurrence_until';

    const OPTION_EVENTBRITE = 'bef_calendar_eventbrite_settings';
    const OPTION_BRITISH_ARENA = 'bef_calendar_british_arena_settings';
    const OPTION_BRITISH_ARENA_STATE = 'bef_calendar_british_arena_state';
    const OPTION_GOOGLE_SHEETS = 'bef_calendar_google_sheets_settings';
    const OPTION_GOOGLE_SHEETS_STATE = 'bef_calendar_google_sheets_state';
    const TRANSIENT_EVENTS = 'bef_calendar_eventbrite_events_v171_';
    const TRANSIENT_ORG_ID = 'bef_calendar_eventbrite_org_id';
    const CRON_HOOK_BRITISH_ARENA = 'bef_calendar_sync_british_arena';
    const CRON_HOOK_GOOGLE_SHEETS = 'bef_calendar_sync_google_sheets';
    const META_SOURCE = '_bef_event_source';
    const META_REMOTE_SOURCE = '_bef_event_remote_source';
    const META_REMOTE_ID = '_bef_event_remote_id';
    const META_REMOTE_MODIFIED = '_bef_event_remote_modified';
    const META_REMOTE_IMAGE_URL = '_bef_event_remote_image_url';
    const META_EVENTBRITE_ORGANIZER = '_bef_event_eventbrite_organizer';
    const META_EVENTBRITE_VENUE_ADDRESS = '_bef_event_eventbrite_venue_address';
    const META_EVENTBRITE_SUMMARY = '_bef_event_eventbrite_summary';

    /**
     * Boot the plugin.
     */
    public function run() {
        add_action( 'init', array( $this, 'ensure_event_staff_role' ), 1 );
        add_action( 'init', array( $this, 'register_post_type' ), 5 );
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
        add_action( self::CRON_HOOK_BRITISH_ARENA, array( $this, 'maybe_sync_british_arena' ) );
        add_action( self::CRON_HOOK_GOOGLE_SHEETS, array( $this, 'maybe_sync_google_sheets' ) );
        add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
        add_action( 'save_post', array( $this, 'save_event_meta' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_frontend_assets' ) );
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_bef_calendar_refresh_eventbrite_cache', array( $this, 'handle_refresh_eventbrite_cache' ) );
        add_action( 'admin_post_bef_calendar_sync_british_arena', array( $this, 'handle_sync_british_arena' ) );
        add_action( 'admin_post_bef_calendar_sync_google_sheets', array( $this, 'handle_sync_google_sheets' ) );
        add_action( 'admin_post_bef_calendar_import_uploaded_sheet', array( $this, 'handle_import_uploaded_sheet' ) );
        add_action( 'admin_post_bef_calendar_save_quick_event', array( $this, 'handle_save_quick_event' ) );
        add_action( 'admin_post_bef_calendar_front_submit_event', array( $this, 'handle_frontend_submit_event' ) );
        add_action( 'acf/init', array( $this, 'register_acf_block' ) );
        add_action( 'acf/init', array( $this, 'register_acf_field_group' ) );
        add_shortcode( 'bef_calendar', array( $this, 'render_calendar_shortcode' ) );
        add_shortcode( 'bef_staff_event_portal', array( $this, 'render_frontend_submission_shortcode' ) );
        add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'admin_columns' ) );
        add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_admin_columns' ), 10, 2 );
        add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( $this, 'sortable_columns' ) );
        add_action( 'pre_get_posts', array( $this, 'admin_orderby' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( BEF_CALENDAR_FILE ), array( $this, 'add_plugin_action_links' ) );
        add_filter( 'template_include', array( $this, 'load_event_templates' ) );
        add_action( 'template_redirect', array( $this, 'maybe_handle_event_export' ) );
    }

    /**
     * Activation callback.
     */
    public static function activate() {
        $plugin = new self();
        $plugin->ensure_event_staff_role();
        $plugin->register_post_type();
        $plugin->schedule_british_arena_sync();
        $plugin->schedule_google_sheets_sync();
        flush_rewrite_rules();
    }

    /**
     * Deactivation callback.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK_BRITISH_ARENA );
        wp_clear_scheduled_hook( self::CRON_HOOK_GOOGLE_SHEETS );
        flush_rewrite_rules();
    }

    /**
     * Ensure the staff role and capabilities exist.
     *
     * @return void
     */
    public function ensure_event_staff_role() {
        $caps = $this->get_event_capabilities();

        $role = get_role( 'bef_event_staff' );

        if ( ! $role ) {
            $role = add_role(
                'bef_event_staff',
                __( 'BEF Event Staff', 'bef-calendar' ),
                array_merge(
                    array(
                        'read'         => true,
                        'upload_files' => true,
                    ),
                    $caps
                )
            );
        }

        if ( $role instanceof WP_Role ) {
            $role->add_cap( 'read' );
            $role->add_cap( 'upload_files' );

            foreach ( array_keys( $caps ) as $cap ) {
                $role->add_cap( $cap );
            }
        }

        foreach ( array( 'administrator', 'editor' ) as $role_name ) {
            $existing_role = get_role( $role_name );

            if ( ! $existing_role instanceof WP_Role ) {
                continue;
            }

            foreach ( array_keys( $caps ) as $cap ) {
                $existing_role->add_cap( $cap );
            }
        }
    }

    /**
     * Capability map for the BEF event post type.
     *
     * @return array
     */
    private function get_event_capabilities() {
        return array(
            'edit_bef_event'           => true,
            'read_bef_event'           => true,
            'delete_bef_event'         => true,
            'edit_bef_events'          => true,
            'edit_others_bef_events'   => true,
            'publish_bef_events'       => true,
            'read_private_bef_events'  => true,
            'delete_bef_events'        => true,
            'delete_private_bef_events'=> true,
            'delete_published_bef_events' => true,
            'delete_others_bef_events' => true,
            'edit_private_bef_events'  => true,
            'edit_published_bef_events'=> true,
        );
    }

    /**
     * Load plugin templates for BEF events.
     *
     * @param string $template Template path.
     * @return string
     */
    public function load_event_templates( $template ) {
        if ( is_singular( self::POST_TYPE ) ) {
            $theme_template = locate_template(
                array(
                    'single-' . self::POST_TYPE . '.php',
                    'bef-calendar/single-' . self::POST_TYPE . '.php',
                )
            );

            if ( $theme_template ) {
                return $theme_template;
            }

            $plugin_template = BEF_CALENDAR_PATH . 'templates/single-' . self::POST_TYPE . '.php';

            if ( file_exists( $plugin_template ) ) {
                return $plugin_template;
            }
        }

        if ( is_post_type_archive( self::POST_TYPE ) ) {
            $theme_template = locate_template(
                array(
                    'archive-' . self::POST_TYPE . '.php',
                    'bef-calendar/archive-' . self::POST_TYPE . '.php',
                )
            );

            if ( $theme_template ) {
                return $theme_template;
            }

            $plugin_template = BEF_CALENDAR_PATH . 'templates/archive-' . self::POST_TYPE . '.php';

            if ( file_exists( $plugin_template ) ) {
                return $plugin_template;
            }
        }

        return $template;
    }

    /**
     * Register the event post type.
     */
    public function register_post_type() {
        $labels = array(
            'name'               => __( 'BEF Events', 'bef-calendar' ),
            'singular_name'      => __( 'BEF Event', 'bef-calendar' ),
            'menu_name'          => __( 'BEF Calendar', 'bef-calendar' ),
            'name_admin_bar'     => __( 'BEF Event', 'bef-calendar' ),
            'add_new'            => __( 'Add New', 'bef-calendar' ),
            'add_new_item'       => __( 'Add New Event', 'bef-calendar' ),
            'edit_item'          => __( 'Edit Event', 'bef-calendar' ),
            'new_item'           => __( 'New Event', 'bef-calendar' ),
            'view_item'          => __( 'View Event', 'bef-calendar' ),
            'all_items'          => __( 'All Events', 'bef-calendar' ),
            'search_items'       => __( 'Search Events', 'bef-calendar' ),
            'not_found'          => __( 'No events found.', 'bef-calendar' ),
            'not_found_in_trash' => __( 'No events found in Trash.', 'bef-calendar' ),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'show_in_menu'       => true,
            'menu_icon'          => 'dashicons-calendar-alt',
            'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
            'has_archive'        => true,
            'rewrite'            => array( 'slug' => 'bef-events' ),
            'show_in_rest'       => true,
            'publicly_queryable' => true,
            'taxonomies'         => array( self::TAXONOMY ),
            'capability_type'    => array( 'bef_event', 'bef_events' ),
            'map_meta_cap'       => true,
        );

        register_post_type( self::POST_TYPE, $args );
        $this->register_taxonomy();
        $this->register_rest_meta_fields();
    }

    /**
     * Register the event category taxonomy.
     */
    public function register_rest_meta_fields() {
        $meta_config = array(
            self::META_DATE         => 'sanitize_text_field',
            self::META_END_DATE     => 'sanitize_text_field',
            self::META_START_TIME   => 'sanitize_text_field',
            self::META_END_TIME     => 'sanitize_text_field',
            self::META_LOCATION     => 'sanitize_text_field',
            self::META_URL                  => 'esc_url_raw',
            self::META_TICKET_URL           => 'esc_url_raw',
            self::META_TICKET_LABEL         => 'sanitize_text_field',
            self::META_RECURRENCE_FREQUENCY => 'sanitize_text_field',
            self::META_RECURRENCE_INTERVAL  => 'absint',
            self::META_RECURRENCE_UNTIL     => 'sanitize_text_field',
        );

        foreach ( $meta_config as $meta_key => $sanitize_callback ) {
            register_post_meta(
                self::POST_TYPE,
                $meta_key,
                array(
                    'single'            => true,
                    'type'              => 'string',
                    'show_in_rest'      => true,
                    'auth_callback'     => '__return_true',
                    'sanitize_callback' => $sanitize_callback,
                )
            );
        }
    }

    /**
     * Register the event category taxonomy.
     */
    public function register_taxonomy() {
        $labels = array(
            'name'              => __( 'Event Categories', 'bef-calendar' ),
            'singular_name'     => __( 'Event Category', 'bef-calendar' ),
            'search_items'      => __( 'Search Event Categories', 'bef-calendar' ),
            'all_items'         => __( 'All Event Categories', 'bef-calendar' ),
            'parent_item'       => __( 'Parent Event Category', 'bef-calendar' ),
            'parent_item_colon' => __( 'Parent Event Category:', 'bef-calendar' ),
            'edit_item'         => __( 'Edit Event Category', 'bef-calendar' ),
            'update_item'       => __( 'Update Event Category', 'bef-calendar' ),
            'add_new_item'      => __( 'Add New Event Category', 'bef-calendar' ),
            'new_item_name'     => __( 'New Event Category Name', 'bef-calendar' ),
            'menu_name'         => __( 'Categories', 'bef-calendar' ),
        );

        $args = array(
            'hierarchical'      => true,
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => array( 'slug' => 'bef-event-category' ),
            'show_in_rest'      => true,
            'public'            => true,
        );

        register_taxonomy( self::TAXONOMY, array( self::POST_TYPE ), $args );
    }

    /**
     * Register admin menu entries.
     */
    public function register_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=' . self::POST_TYPE,
            __( 'Quick Add Event', 'bef-calendar' ),
            __( 'Quick Add Event', 'bef-calendar' ),
            'edit_bef_events',
            'bef-calendar-quick-add',
            array( $this, 'render_quick_add_page' )
        );

        add_submenu_page(
            'edit.php?post_type=' . self::POST_TYPE,
            __( 'Eventbrite Settings', 'bef-calendar' ),
            __( 'Eventbrite', 'bef-calendar' ),
            'manage_options',
            'bef-calendar-eventbrite',
            array( $this, 'render_eventbrite_settings_page' )
        );

        add_submenu_page(
            'edit.php?post_type=' . self::POST_TYPE,
            __( 'British Arena Sync', 'bef-calendar' ),
            __( 'British Arena Sync', 'bef-calendar' ),
            'manage_options',
            'bef-calendar-british-arena',
            array( $this, 'render_british_arena_settings_page' )
        );

        add_submenu_page(
            'edit.php?post_type=' . self::POST_TYPE,
            __( 'Google Sheets Sync', 'bef-calendar' ),
            __( 'Google Sheets Sync', 'bef-calendar' ),
            'manage_options',
            'bef-calendar-google-sheets',
            array( $this, 'render_google_sheets_settings_page' )
        );

        add_submenu_page(
            'edit.php?post_type=' . self::POST_TYPE,
            __( 'Sheet Upload Import', 'bef-calendar' ),
            __( 'Sheet Upload', 'bef-calendar' ),
            'edit_bef_events',
            'bef-calendar-sheet-upload',
            array( $this, 'render_sheet_upload_page' )
        );
    }

    /**
     * Register plugin settings.
     */
    public function register_settings() {
        register_setting(
            'bef_calendar_eventbrite',
            self::OPTION_EVENTBRITE,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_eventbrite_settings' ),
                'default'           => $this->get_default_eventbrite_settings(),
            )
        );

        register_setting(
            'bef_calendar_british_arena',
            self::OPTION_BRITISH_ARENA,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_british_arena_settings' ),
                'default'           => $this->get_default_british_arena_settings(),
            )
        );

        register_setting(
            'bef_calendar_google_sheets',
            self::OPTION_GOOGLE_SHEETS,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_google_sheets_settings' ),
                'default'           => $this->get_default_google_sheets_settings(),
            )
        );
    }

    /**
     * Add plugin action links.
     *
     * @param array $links Existing links.
     * @return array
     */
    public function add_plugin_action_links( $links ) {
        $links[] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=bef-calendar-quick-add' ) ) . '">' . esc_html__( 'Quick Add', 'bef-calendar' ) . '</a>';
        $links[] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=bef-calendar-eventbrite' ) ) . '">' . esc_html__( 'Eventbrite', 'bef-calendar' ) . '</a>';
        $links[] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=bef-calendar-british-arena' ) ) . '">' . esc_html__( 'British Arena Sync', 'bef-calendar' ) . '</a>';
        $links[] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=bef-calendar-google-sheets' ) ) . '">' . esc_html__( 'Google Sheets Sync', 'bef-calendar' ) . '</a>';
        $links[] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=bef-calendar-sheet-upload' ) ) . '">' . esc_html__( 'Sheet Upload', 'bef-calendar' ) . '</a>';
        return $links;
    }


    /**
     * Render the uploaded sheet import page.
     *
     * @return void
     */
    public function render_sheet_upload_page() {
        if ( ! current_user_can( 'edit_bef_events' ) ) {
            return;
        }

        $settings   = $this->get_google_sheets_settings();
        $state      = $this->get_google_sheets_state();
        $import_url = admin_url( 'admin-post.php' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Import Events from a Google Sheet Upload', 'bef-calendar' ); ?></h1>
            <p><?php esc_html_e( 'Upload a Google Sheets export in CSV or XLSX format and the plugin will create or update BEF Events from the rows that are marked ready.', 'bef-calendar' ); ?></p>

            <?php if ( isset( $_GET['bef_sheet_uploaded'] ) ) : ?>
                <div class="notice notice-<?php echo 'success' === $state['last_status'] ? 'success' : 'warning'; ?> is-dismissible"><p><?php echo esc_html( $state['message'] ); ?></p></div>
            <?php endif; ?>

            <div style="max-width: 980px; display:grid; grid-template-columns:minmax(0,2fr) minmax(260px,1fr); gap:24px; margin-top:20px;">
                <div style="background:#fff; border:1px solid #dcdcde; border-radius:16px; padding:24px;">
                    <form method="post" action="<?php echo esc_url( $import_url ); ?>" enctype="multipart/form-data">
                        <?php wp_nonce_field( 'bef_calendar_import_uploaded_sheet', 'bef_calendar_import_uploaded_sheet_nonce' ); ?>
                        <input type="hidden" name="action" value="bef_calendar_import_uploaded_sheet">
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="bef_uploaded_sheet_file"><?php esc_html_e( 'Sheet file', 'bef-calendar' ); ?></label></th>
                                    <td>
                                        <input type="file" id="bef_uploaded_sheet_file" name="bef_uploaded_sheet_file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                                        <p class="description"><?php esc_html_e( 'Upload either a CSV export or an XLSX workbook. The importer can recognise the British Esports event planning template even when the sheet title and guide rows sit above the column headings.', 'bef-calendar' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="bef_uploaded_ready_column"><?php esc_html_e( 'Ready column heading', 'bef-calendar' ); ?></label></th>
                                    <td>
                                        <input type="text" class="regular-text" id="bef_uploaded_ready_column" name="bef_uploaded_ready_column" value="<?php echo esc_attr( $settings['ready_column'] ); ?>">
                                        <p class="description"><?php esc_html_e( 'Only rows where this column is TRUE, yes, 1, or checked will be imported. If the uploaded sheet does not include that column, the importer treats filled event rows as ready by default.', 'bef-calendar' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Import categories', 'bef-calendar' ); ?></th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="bef_uploaded_import_categories" value="1" <?php checked( ! empty( $settings['import_categories'] ) ); ?>>
                                            <?php esc_html_e( 'Create and assign event categories from the Categories column', 'bef-calendar' ); ?>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="bef_uploaded_default_status"><?php esc_html_e( 'Default post status', 'bef-calendar' ); ?></label></th>
                                    <td>
                                        <select id="bef_uploaded_default_status" name="bef_uploaded_default_status">
                                            <option value="publish" <?php selected( $settings['default_post_status'], 'publish' ); ?>><?php esc_html_e( 'Publish', 'bef-calendar' ); ?></option>
                                            <option value="draft" <?php selected( $settings['default_post_status'], 'draft' ); ?>><?php esc_html_e( 'Draft', 'bef-calendar' ); ?></option>
                                        </select>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <?php submit_button( __( 'Import Sheet', 'bef-calendar' ) ); ?>
                    </form>
                </div>

                <div style="background:#fff; border:1px solid #dcdcde; border-radius:16px; padding:24px;">
                    <h2 style="margin-top:0;"><?php esc_html_e( 'Recommended columns', 'bef-calendar' ); ?></h2>
                    <p><?php esc_html_e( 'Use these headings so the importer can map them automatically:', 'bef-calendar' ); ?></p>
                    <p><code>Title, Excerpt, Description / Info, Event Date, End Date (optional), Start Time, End Time, Category, Location, Event URL (optional), Ticket / Registration URL (optional), Ticket Button Label (optional), Repeats, Repeat Until (date), Featured Image URL, Ready</code></p>
                    <p><?php esc_html_e( 'Rows without a Title and Date are skipped. If Source ID is present, future uploads update the same event instead of making duplicates.', 'bef-calendar' ); ?></p>
                    <p><?php esc_html_e( 'This uploader understands both CSV exports and the XLSX workbook template, so staff can keep using the planning sheet format you already have.', 'bef-calendar' ); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle a CSV import exported from Google Sheets.
     *
     * @return void
     */
    public function handle_import_uploaded_sheet() {
        if ( ! current_user_can( 'edit_bef_events' ) ) {
            wp_die( esc_html__( 'You do not have permission to import events.', 'bef-calendar' ) );
        }

        check_admin_referer( 'bef_calendar_import_uploaded_sheet', 'bef_calendar_import_uploaded_sheet_nonce' );

        if ( empty( $_FILES['bef_uploaded_sheet_file']['tmp_name'] ) || empty( $_FILES['bef_uploaded_sheet_file']['name'] ) ) {
            wp_die( esc_html__( 'Upload a Google Sheets export file first.', 'bef-calendar' ) );
        }

        $file_name = sanitize_file_name( wp_unslash( $_FILES['bef_uploaded_sheet_file']['name'] ) );
        $extension = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );

        if ( ! in_array( $extension, array( 'csv', 'xlsx' ), true ) ) {
            wp_die( esc_html__( 'Only CSV and XLSX files exported from Google Sheets are supported by this uploader.', 'bef-calendar' ) );
        }

        $settings = array(
            'ready_column'        => isset( $_POST['bef_uploaded_ready_column'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_uploaded_ready_column'] ) ) : 'Ready',
            'import_categories'   => ! empty( $_POST['bef_uploaded_import_categories'] ) ? 1 : 0,
            'default_post_status' => isset( $_POST['bef_uploaded_default_status'] ) && 'draft' === sanitize_key( wp_unslash( $_POST['bef_uploaded_default_status'] ) ) ? 'draft' : 'publish',
        );

        $result = $this->import_uploaded_sheet_file( $_FILES['bef_uploaded_sheet_file']['tmp_name'], $file_name, $settings );

        if ( is_wp_error( $result ) ) {
            $this->update_google_sheets_state(
                array(
                    'last_sync'   => current_time( 'mysql' ),
                    'last_status' => 'warning',
                    'message'     => $result->get_error_message(),
                    'stats'       => array(),
                )
            );
        } else {
            $message = sprintf(
                __( 'Sheet import complete. Created %1$d, updated %2$d, skipped %3$d, failed %4$d.', 'bef-calendar' ),
                (int) $result['created'],
                (int) $result['updated'],
                (int) $result['skipped'],
                (int) $result['failed']
            );

            $this->update_google_sheets_state(
                array(
                    'last_sync'   => current_time( 'mysql' ),
                    'last_status' => 0 === (int) $result['failed'] ? 'success' : 'warning',
                    'message'     => $message,
                    'stats'       => $result,
                )
            );
        }

        $redirect = add_query_arg(
            array(
                'post_type'          => self::POST_TYPE,
                'page'               => 'bef-calendar-sheet-upload',
                'bef_sheet_uploaded' => 1,
            ),
            admin_url( 'edit.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Default Eventbrite settings.
     *
     * @return array
     */
    private function get_default_eventbrite_settings() {
        return array(
            'enabled'               => 0,
            'private_token'         => '',
            'organization_id'       => '',
            'cache_minutes'         => 15,
            'default_show_external' => 1,
        );
    }

    /**
     * Get stored Eventbrite settings merged with defaults.
     *
     * @return array
     */
    private function get_eventbrite_settings() {
        $settings = get_option( self::OPTION_EVENTBRITE, array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        return wp_parse_args( $settings, $this->get_default_eventbrite_settings() );
    }


    /**
     * Default British Arena sync settings.
     *
     * @return array
     */
    private function get_default_british_arena_settings() {
        return array(
            'enabled'              => 0,
            'source_url'           => 'https://britisharena.com',
            'endpoint_path'        => '/wp-json/wp/v2/bef_event',
            'auto_sync'            => 1,
            'sync_interval'        => 'bef_every_fifteen_minutes',
            'import_categories'    => 1,
            'auth_username'        => '',
            'application_password' => '',
        );
    }

    /**
     * Get stored British Arena settings merged with defaults.
     *
     * @return array
     */
    private function get_british_arena_settings() {
        $settings = get_option( self::OPTION_BRITISH_ARENA, array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        return wp_parse_args( $settings, $this->get_default_british_arena_settings() );
    }

    /**
     * Get British Arena sync state.
     *
     * @return array
     */
    private function get_british_arena_state() {
        $state = get_option( self::OPTION_BRITISH_ARENA_STATE, array() );
        if ( ! is_array( $state ) ) {
            $state = array();
        }

        return wp_parse_args(
            $state,
            array(
                'last_sync'   => '',
                'last_status' => '',
                'message'     => '',
                'stats'       => array(),
            )
        );
    }

    /**
     * Persist British Arena sync state.
     *
     * @param array $state Sync state.
     * @return void
     */
    private function update_british_arena_state( $state ) {
        update_option( self::OPTION_BRITISH_ARENA_STATE, $state, false );
    }

    /**
     * Sanitize settings.
     *
     * @param array $input Raw settings.
     * @return array
     */
    public function sanitize_eventbrite_settings( $input ) {
        $defaults = $this->get_default_eventbrite_settings();
        $input    = is_array( $input ) ? $input : array();

        $settings = array(
            'enabled'               => ! empty( $input['enabled'] ) ? 1 : 0,
            'private_token'         => isset( $input['private_token'] ) ? sanitize_text_field( wp_unslash( $input['private_token'] ) ) : $defaults['private_token'],
            'organization_id'       => isset( $input['organization_id'] ) ? sanitize_text_field( wp_unslash( $input['organization_id'] ) ) : $defaults['organization_id'],
            'cache_minutes'         => isset( $input['cache_minutes'] ) ? max( 1, absint( $input['cache_minutes'] ) ) : $defaults['cache_minutes'],
            'default_show_external' => ! empty( $input['default_show_external'] ) ? 1 : 0,
        );

        $this->clear_eventbrite_cache();

        return $settings;
    }


    /**
     * Sanitize British Arena sync settings.
     *
     * @param array $input Raw settings.
     * @return array
     */
    public function sanitize_british_arena_settings( $input ) {
        $defaults = $this->get_default_british_arena_settings();
        $input    = is_array( $input ) ? $input : array();
        $interval = isset( $input['sync_interval'] ) ? sanitize_key( wp_unslash( $input['sync_interval'] ) ) : $defaults['sync_interval'];
        $allowed  = array( 'bef_every_fifteen_minutes', 'hourly', 'twicedaily', 'daily' );

        if ( ! in_array( $interval, $allowed, true ) ) {
            $interval = $defaults['sync_interval'];
        }

        $settings = array(
            'enabled'              => ! empty( $input['enabled'] ) ? 1 : 0,
            'source_url'           => isset( $input['source_url'] ) ? esc_url_raw( trim( wp_unslash( $input['source_url'] ) ) ) : $defaults['source_url'],
            'endpoint_path'        => isset( $input['endpoint_path'] ) ? '/' . ltrim( sanitize_text_field( wp_unslash( $input['endpoint_path'] ) ), '/' ) : $defaults['endpoint_path'],
            'auto_sync'            => ! empty( $input['auto_sync'] ) ? 1 : 0,
            'sync_interval'        => $interval,
            'import_categories'    => ! empty( $input['import_categories'] ) ? 1 : 0,
            'auth_username'        => isset( $input['auth_username'] ) ? sanitize_text_field( wp_unslash( $input['auth_username'] ) ) : '',
            'application_password' => isset( $input['application_password'] ) ? sanitize_text_field( wp_unslash( $input['application_password'] ) ) : '',
        );

        $this->schedule_british_arena_sync( $settings );

        return $settings;
    }

    /**
     * Render the quick-add admin page for staff.
     *
     * @return void
     */
    public function render_quick_add_page() {
        if ( ! current_user_can( 'edit_bef_events' ) ) {
            return;
        }

        wp_enqueue_media();

        $edit_id      = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
        $duplicate_id = isset( $_GET['duplicate_event'] ) ? absint( $_GET['duplicate_event'] ) : 0;
        $source_id    = $edit_id ? $edit_id : $duplicate_id;
        $is_duplicate = $duplicate_id && ! $edit_id;
        $source_post  = $source_id ? get_post( $source_id ) : null;

        if ( $edit_id && ( ! $source_post || self::POST_TYPE !== $source_post->post_type || ! current_user_can( 'edit_post', $edit_id ) ) ) {
            wp_die( esc_html__( 'You do not have permission to edit that event.', 'bef-calendar' ) );
        }

        if ( $duplicate_id && ( ! $source_post || self::POST_TYPE !== $source_post->post_type || ! current_user_can( 'edit_post', $duplicate_id ) ) ) {
            wp_die( esc_html__( 'You do not have permission to duplicate that event.', 'bef-calendar' ) );
        }

        $fields = $this->get_quick_event_form_defaults( $source_post, $is_duplicate );
        $categories = get_terms(
            array(
                'taxonomy'   => self::TAXONOMY,
                'hide_empty' => false,
            )
        );
        $recent_events = new WP_Query(
            array(
                'post_type'      => self::POST_TYPE,
                'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
                'posts_per_page' => 8,
                'meta_key'       => self::META_DATE,
                'orderby'        => 'meta_value',
                'order'          => 'ASC',
                'meta_type'      => 'DATE',
            )
        );
        $action_url = admin_url( 'admin-post.php' );
        $quick_url  = admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=bef-calendar-quick-add' );
        ?>
        <div class="wrap bef-quick-add-wrap">
            <h1><?php echo esc_html( $edit_id ? __( 'Edit Event Quickly', 'bef-calendar' ) : __( 'Quick Add Event', 'bef-calendar' ) ); ?></h1>
            <p><?php esc_html_e( 'This screen keeps event entry simple for staff. Add the essentials here, and use Advanced Options only when you actually need them.', 'bef-calendar' ); ?></p>

            <?php if ( isset( $_GET['bef_event_saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Event saved successfully.', 'bef-calendar' ); ?></p></div>
            <?php endif; ?>

            <?php if ( isset( $_GET['bef_event_updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Event updated successfully.', 'bef-calendar' ); ?></p></div>
            <?php endif; ?>

            <?php if ( $edit_id && 'british_arena' === get_post_meta( $edit_id, self::META_SOURCE, true ) ) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e( 'This event was imported from British Arena. Manual edits can be overwritten the next time the sync runs.', 'bef-calendar' ); ?></p></div>
            <?php endif; ?>

            <?php if ( $is_duplicate && $source_post ) : ?>
                <div class="notice notice-info inline"><p>
                    <?php
                    printf(
                        /* translators: %s: event title. */
                        esc_html__( 'You are creating a copy of %s. Change anything you like before saving.', 'bef-calendar' ),
                        esc_html( get_the_title( $source_post ) )
                    );
                    ?>
                </p></div>
            <?php endif; ?>

            <style>
                .bef-quick-layout { display:grid; grid-template-columns:minmax(0, 2fr) minmax(280px, 1fr); gap:24px; margin-top:20px; }
                .bef-quick-panel { background:#fff; border:1px solid #dcdcde; border-radius:16px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
                .bef-quick-panel h2 { margin-top:0; }
                .bef-quick-grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:16px; }
                .bef-quick-grid .bef-field-full { grid-column:1 / -1; }
                .bef-quick-grid label { display:block; font-weight:600; margin-bottom:6px; }
                .bef-quick-grid input[type="text"],
                .bef-quick-grid input[type="date"],
                .bef-quick-grid input[type="time"],
                .bef-quick-grid input[type="url"],
                .bef-quick-grid input[type="number"],
                .bef-quick-grid textarea,
                .bef-quick-grid select { width:100%; }
                .bef-quick-grid textarea { min-height:110px; }
                .bef-quick-help { color:#50575e; margin-top:6px; }
                .bef-quick-actions { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-top:24px; }
                .bef-quick-side-list { display:grid; gap:12px; }
                .bef-quick-side-card { border:1px solid #e0e0e0; border-radius:14px; padding:14px; background:#fcfcfd; }
                .bef-quick-side-card h3 { margin:0 0 8px; font-size:15px; }
                .bef-quick-side-meta { margin:0 0 10px; color:#50575e; font-size:13px; }
                .bef-quick-side-actions { display:flex; flex-wrap:wrap; gap:8px; }
                .bef-quick-chip { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; background:#eef4ff; color:#1446a0; font-size:12px; font-weight:600; text-decoration:none; }
                .bef-quick-thumb-preview { width:100%; max-width:260px; aspect-ratio:16/9; object-fit:cover; border-radius:12px; border:1px solid #dcdcde; display:block; margin-bottom:10px; background:#f6f7f7; }
                .bef-quick-button-link { display:inline-flex; align-items:center; justify-content:center; text-decoration:none; border-radius:10px; padding:8px 14px; border:1px solid #d0d7de; background:#fff; }
                .bef-quick-advanced { margin-top:20px; border-top:1px solid #e5e7eb; padding-top:20px; }
                .bef-quick-advanced summary { cursor:pointer; font-weight:700; font-size:15px; }
                .bef-quick-category-list { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:10px; margin-top:8px; }
                @media (max-width: 960px) {
                    .bef-quick-layout { grid-template-columns:1fr; }
                }
                @media (max-width: 782px) {
                    .bef-quick-grid,
                    .bef-quick-category-list { grid-template-columns:1fr; }
                }
            </style>

            <div class="bef-quick-layout">
                <div class="bef-quick-panel">
                    <form method="post" action="<?php echo esc_url( $action_url ); ?>">
                        <?php wp_nonce_field( 'bef_calendar_save_quick_event', 'bef_calendar_quick_event_nonce' ); ?>
                        <input type="hidden" name="action" value="bef_calendar_save_quick_event">
                        <input type="hidden" name="bef_quick_event_id" value="<?php echo esc_attr( $edit_id ); ?>">
                        <input type="hidden" name="bef_quick_duplicate_id" value="<?php echo esc_attr( $duplicate_id ); ?>">

                        <div class="bef-quick-grid">
                            <div class="bef-field-full">
                                <label for="bef_quick_title"><?php esc_html_e( 'Event title', 'bef-calendar' ); ?></label>
                                <input type="text" id="bef_quick_title" name="bef_quick_title" value="<?php echo esc_attr( $fields['title'] ); ?>" required>
                            </div>

                            <div>
                                <label for="bef_quick_date"><?php esc_html_e( 'Event date', 'bef-calendar' ); ?></label>
                                <input type="date" id="bef_quick_date" name="bef_quick_date" value="<?php echo esc_attr( $fields['date'] ); ?>" required>
                            </div>

                            <div>
                                <label for="bef_quick_start_time"><?php esc_html_e( 'Start time', 'bef-calendar' ); ?></label>
                                <input type="time" id="bef_quick_start_time" name="bef_quick_start_time" value="<?php echo esc_attr( $fields['start_time'] ); ?>">
                            </div>

                            <div>
                                <label for="bef_quick_end_time"><?php esc_html_e( 'End time', 'bef-calendar' ); ?></label>
                                <input type="time" id="bef_quick_end_time" name="bef_quick_end_time" value="<?php echo esc_attr( $fields['end_time'] ); ?>">
                            </div>

                            <div>
                                <label for="bef_quick_recurrence_frequency"><?php esc_html_e( 'Repeats', 'bef-calendar' ); ?></label>
                                <select id="bef_quick_recurrence_frequency" name="bef_quick_recurrence_frequency">
                                    <option value="none" <?php selected( 'none', $fields['recurrence_frequency'] ); ?>><?php esc_html_e( 'Does not repeat', 'bef-calendar' ); ?></option>
                                    <option value="daily" <?php selected( 'daily', $fields['recurrence_frequency'] ); ?>><?php esc_html_e( 'Every day', 'bef-calendar' ); ?></option>
                                    <option value="weekly" <?php selected( 'weekly', $fields['recurrence_frequency'] ); ?>><?php esc_html_e( 'Every week', 'bef-calendar' ); ?></option>
                                    <option value="monthly" <?php selected( 'monthly', $fields['recurrence_frequency'] ); ?>><?php esc_html_e( 'Every month', 'bef-calendar' ); ?></option>
                                </select>
                            </div>

                            <div>
                                <label for="bef_quick_recurrence_interval"><?php esc_html_e( 'Repeat interval', 'bef-calendar' ); ?></label>
                                <input type="number" min="1" step="1" id="bef_quick_recurrence_interval" name="bef_quick_recurrence_interval" value="<?php echo esc_attr( $fields['recurrence_interval'] ); ?>">
                                <p class="bef-quick-help"><?php esc_html_e( 'Use 2 for every 2 weeks, every 2 months, and so on.', 'bef-calendar' ); ?></p>
                            </div>

                            <div>
                                <label for="bef_quick_recurrence_until"><?php esc_html_e( 'Repeat until', 'bef-calendar' ); ?></label>
                                <input type="date" id="bef_quick_recurrence_until" name="bef_quick_recurrence_until" value="<?php echo esc_attr( $fields['recurrence_until'] ); ?>">
                            </div>

                            <div>
                                <label for="bef_quick_location"><?php esc_html_e( 'Location', 'bef-calendar' ); ?></label>
                                <input type="text" id="bef_quick_location" name="bef_quick_location" value="<?php echo esc_attr( $fields['location'] ); ?>" placeholder="<?php esc_attr_e( 'National Esports Arena, Sunderland', 'bef-calendar' ); ?>">
                            </div>

                            <div>
                                <label for="bef_quick_ticket_url"><?php esc_html_e( 'Ticket or registration link', 'bef-calendar' ); ?></label>
                                <input type="url" id="bef_quick_ticket_url" name="bef_quick_ticket_url" value="<?php echo esc_attr( $fields['ticket_url'] ); ?>" placeholder="https://">
                            </div>

                            <div class="bef-field-full">
                                <label for="bef_quick_excerpt"><?php esc_html_e( 'Short summary', 'bef-calendar' ); ?></label>
                                <textarea id="bef_quick_excerpt" name="bef_quick_excerpt" placeholder="<?php esc_attr_e( 'A short description for archive cards and previews.', 'bef-calendar' ); ?>"><?php echo esc_textarea( $fields['excerpt'] ); ?></textarea>
                            </div>

                            <div class="bef-field-full">
                                <label for="bef_quick_content"><?php esc_html_e( 'Event details', 'bef-calendar' ); ?></label>
                                <textarea id="bef_quick_content" name="bef_quick_content" placeholder="<?php esc_attr_e( 'Add the main event description, format, timings, or anything attendees need to know.', 'bef-calendar' ); ?>"><?php echo esc_textarea( $fields['content'] ); ?></textarea>
                            </div>

                            <div class="bef-field-full">
                                <label><?php esc_html_e( 'Categories', 'bef-calendar' ); ?></label>
                                <?php if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) : ?>
                                    <div class="bef-quick-category-list">
                                        <?php foreach ( $categories as $category ) : ?>
                                            <label class="bef-quick-chip">
                                                <input type="checkbox" name="bef_quick_categories[]" value="<?php echo esc_attr( $category->term_id ); ?>" <?php checked( in_array( (int) $category->term_id, $fields['categories'], true ) ); ?>>
                                                <?php echo esc_html( $category->name ); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else : ?>
                                    <p class="bef-quick-help"><?php esc_html_e( 'No event categories yet. You can still save the event now and add categories later.', 'bef-calendar' ); ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="bef-field-full">
                                <label><?php esc_html_e( 'Featured image', 'bef-calendar' ); ?></label>
                                <img id="bef_quick_image_preview" class="bef-quick-thumb-preview" src="<?php echo esc_url( $fields['image_url'] ); ?>" alt="" <?php echo $fields['image_url'] ? '' : 'style="display:none;"'; ?>>
                                <input type="hidden" id="bef_quick_image_id" name="bef_quick_image_id" value="<?php echo esc_attr( $fields['image_id'] ); ?>">
                                <div class="bef-quick-side-actions">
                                    <button type="button" class="button" id="bef_quick_select_image"><?php esc_html_e( 'Choose image', 'bef-calendar' ); ?></button>
                                    <button type="button" class="button" id="bef_quick_remove_image" <?php disabled( ! $fields['image_id'] ); ?>><?php esc_html_e( 'Remove image', 'bef-calendar' ); ?></button>
                                </div>
                            </div>
                        </div>

                        <details class="bef-quick-advanced">
                            <summary><?php esc_html_e( 'Advanced Options', 'bef-calendar' ); ?></summary>
                            <div class="bef-quick-grid" style="margin-top:16px;">
                                <div>
                                    <label for="bef_quick_end_date"><?php esc_html_e( 'End date', 'bef-calendar' ); ?></label>
                                    <input type="date" id="bef_quick_end_date" name="bef_quick_end_date" value="<?php echo esc_attr( $fields['end_date'] ); ?>">
                                </div>

                                <div>
                                    <label for="bef_quick_ticket_label"><?php esc_html_e( 'Ticket button label', 'bef-calendar' ); ?></label>
                                    <input type="text" id="bef_quick_ticket_label" name="bef_quick_ticket_label" value="<?php echo esc_attr( $fields['ticket_label'] ); ?>" placeholder="<?php esc_attr_e( 'Purchase Tickets', 'bef-calendar' ); ?>">
                                </div>

                                <div class="bef-field-full">
                                    <label for="bef_quick_event_url"><?php esc_html_e( 'Event website link', 'bef-calendar' ); ?></label>
                                    <input type="url" id="bef_quick_event_url" name="bef_quick_event_url" value="<?php echo esc_attr( $fields['event_url'] ); ?>" placeholder="https://">
                                </div>

                                <div>
                                    <label for="bef_quick_status"><?php esc_html_e( 'Save as', 'bef-calendar' ); ?></label>
                                    <select id="bef_quick_status" name="bef_quick_status">
                                        <option value="publish" <?php selected( 'publish', $fields['status'] ); ?>><?php esc_html_e( 'Published event', 'bef-calendar' ); ?></option>
                                        <option value="draft" <?php selected( 'draft', $fields['status'] ); ?>><?php esc_html_e( 'Draft event', 'bef-calendar' ); ?></option>
                                    </select>
                                </div>
                            </div>
                        </details>

                        <div class="bef-quick-actions">
                            <button type="submit" class="button button-primary button-large"><?php echo esc_html( $edit_id ? __( 'Update Event', 'bef-calendar' ) : __( 'Save Event', 'bef-calendar' ) ); ?></button>
                            <a class="bef-quick-button-link" href="<?php echo esc_url( $quick_url ); ?>"><?php esc_html_e( 'Start a fresh event', 'bef-calendar' ); ?></a>
                            <?php if ( $edit_id ) : ?>
                                <a class="bef-quick-button-link" href="<?php echo esc_url( get_permalink( $edit_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View event page', 'bef-calendar' ); ?></a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <aside class="bef-quick-panel">
                    <h2><?php esc_html_e( 'Helpful shortcuts', 'bef-calendar' ); ?></h2>
                    <p><?php esc_html_e( 'Duplicate a recent event to save time when formats repeat, or jump into a full edit screen if you need more control.', 'bef-calendar' ); ?></p>

                    <div class="bef-quick-side-list">
                        <?php if ( $recent_events->have_posts() ) : ?>
                            <?php while ( $recent_events->have_posts() ) : $recent_events->the_post(); ?>
                                <?php
                                $recent_id   = get_the_ID();
                                $recent_date = get_post_meta( $recent_id, self::META_DATE, true );
                                $recent_loc  = get_post_meta( $recent_id, self::META_LOCATION, true );
                                ?>
                                <div class="bef-quick-side-card">
                                    <h3><?php the_title(); ?></h3>
                                    <p class="bef-quick-side-meta">
                                        <?php if ( $recent_date ) : ?>
                                            <span><?php echo esc_html( $this->format_display_date( $recent_date ) ); ?></span>
                                        <?php endif; ?>
                                        <?php if ( $recent_date && $recent_loc ) : ?>
                                            <span> • </span>
                                        <?php endif; ?>
                                        <?php if ( $recent_loc ) : ?>
                                            <span><?php echo esc_html( $recent_loc ); ?></span>
                                        <?php endif; ?>
                                    </p>
                                    <div class="bef-quick-side-actions">
                                        <a class="button button-secondary" href="<?php echo esc_url( add_query_arg( array( 'post_type' => self::POST_TYPE, 'page' => 'bef-calendar-quick-add', 'event_id' => $recent_id ), admin_url( 'edit.php' ) ) ); ?>"><?php esc_html_e( 'Quick edit', 'bef-calendar' ); ?></a>
                                        <a class="button" href="<?php echo esc_url( add_query_arg( array( 'post_type' => self::POST_TYPE, 'page' => 'bef-calendar-quick-add', 'duplicate_event' => $recent_id ), admin_url( 'edit.php' ) ) ); ?>"><?php esc_html_e( 'Duplicate', 'bef-calendar' ); ?></a>
                                        <a class="button-link" href="<?php echo esc_url( get_edit_post_link( $recent_id ) ); ?>"><?php esc_html_e( 'Full edit', 'bef-calendar' ); ?></a>
                                    </div>
                                </div>
                            <?php endwhile; wp_reset_postdata(); ?>
                        <?php else : ?>
                            <p><?php esc_html_e( 'No events yet. Add your first one using the form on the left.', 'bef-calendar' ); ?></p>
                        <?php endif; ?>
                    </div>
                </aside>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const imageField = document.getElementById('bef_quick_image_id');
            const preview = document.getElementById('bef_quick_image_preview');
            const selectButton = document.getElementById('bef_quick_select_image');
            const removeButton = document.getElementById('bef_quick_remove_image');
            let frame;

            if (!selectButton || !imageField || !preview || !removeButton || typeof wp === 'undefined' || !wp.media) {
                return;
            }

            selectButton.addEventListener('click', function (event) {
                event.preventDefault();

                if (frame) {
                    frame.open();
                    return;
                }

                frame = wp.media({
                    title: '<?php echo esc_js( __( 'Choose event image', 'bef-calendar' ) ); ?>',
                    button: { text: '<?php echo esc_js( __( 'Use this image', 'bef-calendar' ) ); ?>' },
                    library: { type: 'image' },
                    multiple: false
                });

                frame.on('select', function () {
                    const attachment = frame.state().get('selection').first().toJSON();
                    imageField.value = attachment.id || '';
                    preview.src = (attachment.sizes && attachment.sizes.large ? attachment.sizes.large.url : attachment.url) || '';
                    preview.style.display = preview.src ? 'block' : 'none';
                    removeButton.disabled = !imageField.value;
                });

                frame.open();
            });

            removeButton.addEventListener('click', function (event) {
                event.preventDefault();
                imageField.value = '';
                preview.src = '';
                preview.style.display = 'none';
                removeButton.disabled = true;
            });
        });
        </script>
        <?php
    }

    /**
     * Get quick-form defaults.
     *
     * @param WP_Post|null $post         Source post.
     * @param bool         $is_duplicate Whether the form is duplicating.
     * @return array
     */
    private function get_quick_event_form_defaults( $post = null, $is_duplicate = false ) {
        $defaults = array(
            'title'        => '',
            'date'         => '',
            'end_date'     => '',
            'start_time'   => '',
            'end_time'     => '',
            'location'     => '',
            'event_url'    => '',
            'ticket_url'   => '',
            'ticket_label' => '',
            'excerpt'      => '',
            'content'      => '',
            'status'       => 'publish',
            'image_id'     => 0,
            'image_url'    => '',
            'categories'            => array(),
            'recurrence_frequency'  => 'none',
            'recurrence_interval'   => 1,
            'recurrence_until'      => '',
        );

        if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
            return $defaults;
        }

        $image_id = (int) get_post_thumbnail_id( $post->ID );

        return array(
            'title'        => $is_duplicate ? sprintf( __( '%s (Copy)', 'bef-calendar' ), $post->post_title ) : $post->post_title,
            'date'         => get_post_meta( $post->ID, self::META_DATE, true ),
            'end_date'     => get_post_meta( $post->ID, self::META_END_DATE, true ),
            'start_time'   => get_post_meta( $post->ID, self::META_START_TIME, true ),
            'end_time'     => get_post_meta( $post->ID, self::META_END_TIME, true ),
            'location'     => get_post_meta( $post->ID, self::META_LOCATION, true ),
            'event_url'    => get_post_meta( $post->ID, self::META_URL, true ),
            'ticket_url'   => get_post_meta( $post->ID, self::META_TICKET_URL, true ),
            'ticket_label' => get_post_meta( $post->ID, self::META_TICKET_LABEL, true ),
            'excerpt'      => $post->post_excerpt,
            'content'      => $post->post_content,
            'status'       => $is_duplicate ? 'draft' : $post->post_status,
            'image_id'     => $image_id,
            'image_url'    => $image_id ? wp_get_attachment_image_url( $image_id, 'large' ) : '',
            'categories'            => wp_get_post_terms( $post->ID, self::TAXONOMY, array( 'fields' => 'ids' ) ),
            'recurrence_frequency'  => $this->normalize_recurrence_frequency( get_post_meta( $post->ID, self::META_RECURRENCE_FREQUENCY, true ) ),
            'recurrence_interval'   => max( 1, absint( get_post_meta( $post->ID, self::META_RECURRENCE_INTERVAL, true ) ) ),
            'recurrence_until'      => get_post_meta( $post->ID, self::META_RECURRENCE_UNTIL, true ),
        );
    }

    /**
     * Handle a quick-add save request.
     *
     * @return void
     */
    public function handle_save_quick_event() {
        if ( ! current_user_can( 'edit_bef_events' ) ) {
            wp_die( esc_html__( 'You do not have permission to save events.', 'bef-calendar' ) );
        }

        check_admin_referer( 'bef_calendar_save_quick_event', 'bef_calendar_quick_event_nonce' );

        $event_id = isset( $_POST['bef_quick_event_id'] ) ? absint( $_POST['bef_quick_event_id'] ) : 0;
        $date     = isset( $_POST['bef_quick_date'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_quick_date'] ) ) : '';
        $status   = isset( $_POST['bef_quick_status'] ) ? sanitize_key( wp_unslash( $_POST['bef_quick_status'] ) ) : 'publish';
        $status   = in_array( $status, array( 'publish', 'draft' ), true ) ? $status : 'draft';

        if ( 'publish' === $status && ! current_user_can( 'publish_bef_events' ) ) {
            $status = 'draft';
        }

        if ( ! $date ) {
            wp_die( esc_html__( 'An event date is required.', 'bef-calendar' ) );
        }

        $postarr = array(
            'post_type'    => self::POST_TYPE,
            'post_title'   => isset( $_POST['bef_quick_title'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_quick_title'] ) ) : '',
            'post_excerpt' => isset( $_POST['bef_quick_excerpt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bef_quick_excerpt'] ) ) : '',
            'post_content' => isset( $_POST['bef_quick_content'] ) ? wp_kses_post( wp_unslash( $_POST['bef_quick_content'] ) ) : '',
            'post_status'  => $status,
        );

        if ( $event_id ) {
            if ( ! current_user_can( 'edit_post', $event_id ) ) {
                wp_die( esc_html__( 'You do not have permission to edit that event.', 'bef-calendar' ) );
            }

            $postarr['ID'] = $event_id;
            $event_id = wp_update_post( wp_slash( $postarr ), true );
            $updated  = true;
        } else {
            $event_id = wp_insert_post( wp_slash( $postarr ), true );
            $updated  = false;
        }

        if ( is_wp_error( $event_id ) ) {
            wp_die( esc_html( $event_id->get_error_message() ) );
        }

        $meta = array(
            self::META_DATE         => $date,
            self::META_END_DATE     => isset( $_POST['bef_quick_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_quick_end_date'] ) ) : '',
            self::META_START_TIME   => isset( $_POST['bef_quick_start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_quick_start_time'] ) ) : '',
            self::META_END_TIME     => isset( $_POST['bef_quick_end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_quick_end_time'] ) ) : '',
            self::META_LOCATION     => isset( $_POST['bef_quick_location'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_quick_location'] ) ) : '',
            self::META_URL                  => isset( $_POST['bef_quick_event_url'] ) ? esc_url_raw( wp_unslash( $_POST['bef_quick_event_url'] ) ) : '',
            self::META_TICKET_URL           => isset( $_POST['bef_quick_ticket_url'] ) ? esc_url_raw( wp_unslash( $_POST['bef_quick_ticket_url'] ) ) : '',
            self::META_TICKET_LABEL         => isset( $_POST['bef_quick_ticket_label'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_quick_ticket_label'] ) ) : '',
            self::META_RECURRENCE_FREQUENCY => $this->normalize_recurrence_frequency( isset( $_POST['bef_quick_recurrence_frequency'] ) ? wp_unslash( $_POST['bef_quick_recurrence_frequency'] ) : 'none' ),
            self::META_RECURRENCE_INTERVAL  => max( 1, absint( isset( $_POST['bef_quick_recurrence_interval'] ) ? wp_unslash( $_POST['bef_quick_recurrence_interval'] ) : 1 ) ),
            self::META_RECURRENCE_UNTIL     => isset( $_POST['bef_quick_recurrence_until'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_quick_recurrence_until'] ) ) : '',
        );

        foreach ( $meta as $meta_key => $meta_value ) {
            update_post_meta( $event_id, $meta_key, $meta_value );
        }

        $category_ids = isset( $_POST['bef_quick_categories'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['bef_quick_categories'] ) ) : array();
        wp_set_object_terms( $event_id, array_filter( $category_ids ), self::TAXONOMY, false );

        $image_id = isset( $_POST['bef_quick_image_id'] ) ? absint( $_POST['bef_quick_image_id'] ) : 0;
        if ( $image_id ) {
            set_post_thumbnail( $event_id, $image_id );
        } else {
            delete_post_thumbnail( $event_id );
        }

        $redirect = add_query_arg(
            array(
                'post_type'          => self::POST_TYPE,
                'page'               => 'bef-calendar-quick-add',
                ( $updated ? 'bef_event_updated' : 'bef_event_saved' ) => 1,
                'event_id'           => $event_id,
            ),
            admin_url( 'edit.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Render the front-end staff event portal shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function render_frontend_submission_shortcode( $atts = array() ) {
        wp_enqueue_style( 'bef-calendar-frontend' );

        $atts = shortcode_atts(
            array(
                'title'       => __( 'Staff Event Portal', 'bef-calendar' ),
                'intro'       => __( 'Add a new event, tweak an existing one, or clone a recent format without stepping into the full WordPress admin editor.', 'bef-calendar' ),
                'show_recent' => 'yes',
            ),
            $atts,
            'bef_staff_event_portal'
        );

        if ( ! is_user_logged_in() ) {
            $login_url = wp_login_url( $this->get_current_frontend_url() );

            return '<div class="bef-staff-portal bef-staff-portal--notice"><p>' . sprintf( esc_html__( 'Please %s to access the staff event portal.', 'bef-calendar' ), '<a href="' . esc_url( $login_url ) . '">' . esc_html__( 'log in', 'bef-calendar' ) . '</a>' ) . '</p></div>' ;
        }

        if ( ! current_user_can( 'edit_bef_events' ) ) {
            return '<div class="bef-staff-portal bef-staff-portal--notice"><p>' . esc_html__( 'Your account does not currently have permission to add calendar events.', 'bef-calendar' ) . '</p></div>';
        }

        $edit_id      = isset( $_GET['bef_front_event'] ) ? absint( $_GET['bef_front_event'] ) : 0;
        $duplicate_id = isset( $_GET['bef_front_duplicate'] ) ? absint( $_GET['bef_front_duplicate'] ) : 0;
        $source_id    = $edit_id ? $edit_id : $duplicate_id;
        $is_duplicate = $duplicate_id && ! $edit_id;
        $source_post  = $source_id ? get_post( $source_id ) : null;

        if ( $edit_id && ( ! $source_post || self::POST_TYPE !== $source_post->post_type || ! current_user_can( 'edit_post', $edit_id ) ) ) {
            return '<div class="bef-staff-portal bef-staff-portal--notice"><p>' . esc_html__( 'You do not have permission to edit that event.', 'bef-calendar' ) . '</p></div>';
        }

        if ( $duplicate_id && ( ! $source_post || self::POST_TYPE !== $source_post->post_type || ! current_user_can( 'edit_post', $duplicate_id ) ) ) {
            return '<div class="bef-staff-portal bef-staff-portal--notice"><p>' . esc_html__( 'You do not have permission to duplicate that event.', 'bef-calendar' ) . '</p></div>';
        }

        $fields      = $this->get_quick_event_form_defaults( $source_post, $is_duplicate );
        $categories  = get_terms( array( 'taxonomy' => self::TAXONOMY, 'hide_empty' => false ) );
        $current_url = remove_query_arg( array( 'bef_front_event', 'bef_front_duplicate', 'bef_front_saved', 'bef_front_updated' ), $this->get_current_frontend_url() );
        $show_recent = 'no' !== strtolower( (string) $atts['show_recent'] );

        $notice = '';
        if ( isset( $_GET['bef_front_saved'] ) ) {
            $notice = '<div class="bef-staff-portal__alert is-success"><p>' . esc_html__( 'Event saved successfully.', 'bef-calendar' ) . '</p></div>';
        } elseif ( isset( $_GET['bef_front_updated'] ) ) {
            $notice = '<div class="bef-staff-portal__alert is-success"><p>' . esc_html__( 'Event updated successfully.', 'bef-calendar' ) . '</p></div>';
        }

        $recent_query = null;
        if ( $show_recent ) {
            $recent_query = new WP_Query(
                array(
                    'post_type'      => self::POST_TYPE,
                    'posts_per_page' => 8,
                    'post_status'    => array( 'publish', 'draft', 'pending' ),
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                    'author'         => get_current_user_id(),
                )
            );
        }

        ob_start();
        ?>
        <div class="bef-staff-portal">
            <div class="bef-staff-portal__header">
                <div>
                    <p class="bef-calendar-kicker"><?php esc_html_e( 'Staff Event Entry', 'bef-calendar' ); ?></p>
                    <h2 class="bef-staff-portal__title"><?php echo esc_html( $atts['title'] ); ?></h2>
                </div>
                <?php if ( $atts['intro'] ) : ?>
                    <p class="bef-staff-portal__intro"><?php echo esc_html( $atts['intro'] ); ?></p>
                <?php endif; ?>
            </div>

            <?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <div class="bef-staff-portal__layout">
                <div class="bef-staff-portal__panel">
                    <h3 class="bef-staff-portal__panel-title"><?php echo esc_html( $edit_id ? __( 'Edit Event', 'bef-calendar' ) : ( $is_duplicate ? __( 'Duplicate Event', 'bef-calendar' ) : __( 'Add Event', 'bef-calendar' ) ) ); ?></h3>
                    <form class="bef-staff-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="bef_calendar_front_submit_event">
                        <input type="hidden" name="bef_front_event_id" value="<?php echo esc_attr( $edit_id ); ?>">
                        <input type="hidden" name="bef_front_redirect" value="<?php echo esc_url( $current_url ); ?>">
                        <?php wp_nonce_field( 'bef_calendar_front_submit_event', 'bef_calendar_front_event_nonce' ); ?>

                        <div class="bef-staff-form__grid">
                            <label class="bef-staff-form__field bef-staff-form__field--full">
                                <span><?php esc_html_e( 'Event Title', 'bef-calendar' ); ?></span>
                                <input type="text" name="bef_front_title" value="<?php echo esc_attr( $fields['title'] ); ?>" required>
                            </label>

                            <label class="bef-staff-form__field">
                                <span><?php esc_html_e( 'Event Date', 'bef-calendar' ); ?></span>
                                <input type="date" name="bef_front_date" value="<?php echo esc_attr( $fields['date'] ); ?>" required>
                            </label>

                            <label class="bef-staff-form__field">
                                <span><?php esc_html_e( 'End Date', 'bef-calendar' ); ?></span>
                                <input type="date" name="bef_front_end_date" value="<?php echo esc_attr( $fields['end_date'] ); ?>">
                            </label>

                            <label class="bef-staff-form__field">
                                <span><?php esc_html_e( 'Start Time', 'bef-calendar' ); ?></span>
                                <input type="time" name="bef_front_start_time" value="<?php echo esc_attr( $fields['start_time'] ); ?>">
                            </label>

                            <label class="bef-staff-form__field">
                                <span><?php esc_html_e( 'End Time', 'bef-calendar' ); ?></span>
                                <input type="time" name="bef_front_end_time" value="<?php echo esc_attr( $fields['end_time'] ); ?>">
                            </label>

                            <label class="bef-staff-form__field">
                                <span><?php esc_html_e( 'Repeats', 'bef-calendar' ); ?></span>
                                <select name="bef_front_recurrence_frequency">
                                    <option value="none" <?php selected( 'none', $fields['recurrence_frequency'] ); ?>><?php esc_html_e( 'Does not repeat', 'bef-calendar' ); ?></option>
                                    <option value="daily" <?php selected( 'daily', $fields['recurrence_frequency'] ); ?>><?php esc_html_e( 'Every day', 'bef-calendar' ); ?></option>
                                    <option value="weekly" <?php selected( 'weekly', $fields['recurrence_frequency'] ); ?>><?php esc_html_e( 'Every week', 'bef-calendar' ); ?></option>
                                    <option value="monthly" <?php selected( 'monthly', $fields['recurrence_frequency'] ); ?>><?php esc_html_e( 'Every month', 'bef-calendar' ); ?></option>
                                </select>
                            </label>

                            <label class="bef-staff-form__field">
                                <span><?php esc_html_e( 'Repeat Interval', 'bef-calendar' ); ?></span>
                                <input type="number" min="1" step="1" name="bef_front_recurrence_interval" value="<?php echo esc_attr( $fields['recurrence_interval'] ); ?>">
                            </label>

                            <label class="bef-staff-form__field">
                                <span><?php esc_html_e( 'Repeat Until', 'bef-calendar' ); ?></span>
                                <input type="date" name="bef_front_recurrence_until" value="<?php echo esc_attr( $fields['recurrence_until'] ); ?>">
                            </label>

                            <label class="bef-staff-form__field bef-staff-form__field--full">
                                <span><?php esc_html_e( 'Location', 'bef-calendar' ); ?></span>
                                <input type="text" name="bef_front_location" value="<?php echo esc_attr( $fields['location'] ); ?>">
                            </label>

                            <label class="bef-staff-form__field bef-staff-form__field--full">
                                <span><?php esc_html_e( 'Short Summary', 'bef-calendar' ); ?></span>
                                <textarea name="bef_front_excerpt" rows="3"><?php echo esc_textarea( $fields['excerpt'] ); ?></textarea>
                            </label>

                            <label class="bef-staff-form__field bef-staff-form__field--full">
                                <span><?php esc_html_e( 'Full Description', 'bef-calendar' ); ?></span>
                                <textarea name="bef_front_content" rows="7"><?php echo esc_textarea( $fields['content'] ); ?></textarea>
                            </label>
                        </div>

                        <details class="bef-staff-form__advanced">
                            <summary><?php esc_html_e( 'Advanced Options', 'bef-calendar' ); ?></summary>
                            <div class="bef-staff-form__advanced-grid">
                                <label class="bef-staff-form__field bef-staff-form__field--full">
                                    <span><?php esc_html_e( 'Event Website URL', 'bef-calendar' ); ?></span>
                                    <input type="url" name="bef_front_event_url" value="<?php echo esc_attr( $fields['event_url'] ); ?>">
                                </label>

                                <label class="bef-staff-form__field bef-staff-form__field--full">
                                    <span><?php esc_html_e( 'Ticket or Registration URL', 'bef-calendar' ); ?></span>
                                    <input type="url" name="bef_front_ticket_url" value="<?php echo esc_attr( $fields['ticket_url'] ); ?>">
                                </label>

                                <label class="bef-staff-form__field bef-staff-form__field--full">
                                    <span><?php esc_html_e( 'Ticket Button Label', 'bef-calendar' ); ?></span>
                                    <input type="text" name="bef_front_ticket_label" value="<?php echo esc_attr( $fields['ticket_label'] ); ?>" placeholder="<?php echo esc_attr__( 'Purchase Tickets', 'bef-calendar' ); ?>">
                                </label>

                                <div class="bef-staff-form__field bef-staff-form__field--full">
                                    <span><?php esc_html_e( 'Categories', 'bef-calendar' ); ?></span>
                                    <div class="bef-staff-form__checks">
                                        <?php if ( ! is_wp_error( $categories ) && $categories ) : ?>
                                            <?php foreach ( $categories as $category ) : ?>
                                                <label>
                                                    <input type="checkbox" name="bef_front_categories[]" value="<?php echo esc_attr( $category->term_id ); ?>" <?php checked( in_array( (int) $category->term_id, array_map( 'intval', (array) $fields['categories'] ), true ) ); ?>>
                                                    <span><?php echo esc_html( $category->name ); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        <?php else : ?>
                                            <p><?php esc_html_e( 'No event categories yet.', 'bef-calendar' ); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <label class="bef-staff-form__field bef-staff-form__field--full">
                                    <span><?php esc_html_e( 'Featured Image', 'bef-calendar' ); ?></span>
                                    <input type="file" name="bef_front_image_upload" accept="image/*">
                                    <?php if ( ! empty( $fields['image_url'] ) ) : ?>
                                        <span class="bef-staff-form__hint"><?php esc_html_e( 'Current image shown below. Uploading a new image will replace it.', 'bef-calendar' ); ?></span>
                                        <img class="bef-staff-form__image-preview" src="<?php echo esc_url( $fields['image_url'] ); ?>" alt="">
                                        <label class="bef-staff-form__checkbox">
                                            <input type="checkbox" name="bef_front_remove_image" value="1">
                                            <span><?php esc_html_e( 'Remove current image', 'bef-calendar' ); ?></span>
                                        </label>
                                    <?php endif; ?>
                                </label>
                            </div>
                        </details>

                        <div class="bef-staff-form__actions">
                            <button type="submit" name="bef_front_status" value="draft" class="bef-calendar-button bef-calendar-button--ghost"><?php esc_html_e( 'Save Draft', 'bef-calendar' ); ?></button>
                            <?php if ( current_user_can( 'publish_bef_events' ) ) : ?>
                                <button type="submit" name="bef_front_status" value="publish" class="bef-calendar-button"><?php echo esc_html( $edit_id ? __( 'Update Event', 'bef-calendar' ) : __( 'Publish Event', 'bef-calendar' ) ); ?></button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <?php if ( $show_recent ) : ?>
                    <aside class="bef-staff-portal__panel bef-staff-portal__panel--aside">
                        <h3 class="bef-staff-portal__panel-title"><?php esc_html_e( 'Your Recent Events', 'bef-calendar' ); ?></h3>
                        <p class="bef-staff-portal__small"><?php esc_html_e( 'Use these quick links to edit, duplicate, or view events you have already worked on.', 'bef-calendar' ); ?></p>

                        <?php if ( $recent_query && $recent_query->have_posts() ) : ?>
                            <div class="bef-staff-portal__recent-list">
                                <?php while ( $recent_query->have_posts() ) : $recent_query->the_post(); ?>
                                    <?php
                                    $recent_id   = get_the_ID();
                                    $recent_date = get_post_meta( $recent_id, self::META_DATE, true );
                                    ?>
                                    <div class="bef-staff-portal__recent-item">
                                        <strong><?php the_title(); ?></strong>
                                        <?php if ( $recent_date ) : ?>
                                            <span><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $recent_date ) ) ); ?></span>
                                        <?php endif; ?>
                                        <div class="bef-staff-portal__recent-actions">
                                            <a href="<?php echo esc_url( add_query_arg( 'bef_front_event', $recent_id, $current_url ) ); ?>"><?php esc_html_e( 'Edit', 'bef-calendar' ); ?></a>
                                            <a href="<?php echo esc_url( add_query_arg( 'bef_front_duplicate', $recent_id, $current_url ) ); ?>"><?php esc_html_e( 'Duplicate', 'bef-calendar' ); ?></a>
                                            <a href="<?php echo esc_url( get_permalink( $recent_id ) ); ?>"><?php esc_html_e( 'View', 'bef-calendar' ); ?></a>
                                        </div>
                                    </div>
                                <?php endwhile; wp_reset_postdata(); ?>
                            </div>
                        <?php else : ?>
                            <p><?php esc_html_e( 'No recent events yet. Publish one here and it will appear in your list.', 'bef-calendar' ); ?></p>
                        <?php endif; ?>
                    </aside>
                <?php endif; ?>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Handle a front-end staff event submission.
     *
     * @return void
     */
    public function handle_frontend_submit_event() {
        if ( ! is_user_logged_in() ) {
            auth_redirect();
        }

        if ( ! current_user_can( 'edit_bef_events' ) ) {
            wp_die( esc_html__( 'You do not have permission to save events.', 'bef-calendar' ) );
        }

        check_admin_referer( 'bef_calendar_front_submit_event', 'bef_calendar_front_event_nonce' );

        $redirect = isset( $_POST['bef_front_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['bef_front_redirect'] ) ) : home_url( '/' );
        $event_id = isset( $_POST['bef_front_event_id'] ) ? absint( $_POST['bef_front_event_id'] ) : 0;
        $date     = isset( $_POST['bef_front_date'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_front_date'] ) ) : '';
        $title    = isset( $_POST['bef_front_title'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_front_title'] ) ) : '';
        $status   = isset( $_POST['bef_front_status'] ) ? sanitize_key( wp_unslash( $_POST['bef_front_status'] ) ) : 'draft';
        $status   = in_array( $status, array( 'publish', 'draft' ), true ) ? $status : 'draft';

        if ( 'publish' === $status && ! current_user_can( 'publish_bef_events' ) ) {
            $status = 'draft';
        }

        if ( ! $title || ! $date ) {
            wp_die( esc_html__( 'Please add at least a title and event date.', 'bef-calendar' ) );
        }

        $postarr = array(
            'post_type'    => self::POST_TYPE,
            'post_title'   => $title,
            'post_excerpt' => isset( $_POST['bef_front_excerpt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bef_front_excerpt'] ) ) : '',
            'post_content' => isset( $_POST['bef_front_content'] ) ? wp_kses_post( wp_unslash( $_POST['bef_front_content'] ) ) : '',
            'post_status'  => $status,
        );

        if ( $event_id ) {
            if ( ! current_user_can( 'edit_post', $event_id ) ) {
                wp_die( esc_html__( 'You do not have permission to edit that event.', 'bef-calendar' ) );
            }

            $postarr['ID'] = $event_id;
            $event_id      = wp_update_post( wp_slash( $postarr ), true );
            $updated       = true;
        } else {
            $postarr['post_author'] = get_current_user_id();
            $event_id               = wp_insert_post( wp_slash( $postarr ), true );
            $updated                = false;
        }

        if ( is_wp_error( $event_id ) ) {
            wp_die( esc_html( $event_id->get_error_message() ) );
        }

        $meta = array(
            self::META_DATE         => $date,
            self::META_END_DATE     => isset( $_POST['bef_front_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_front_end_date'] ) ) : '',
            self::META_START_TIME   => isset( $_POST['bef_front_start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_front_start_time'] ) ) : '',
            self::META_END_TIME     => isset( $_POST['bef_front_end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_front_end_time'] ) ) : '',
            self::META_LOCATION     => isset( $_POST['bef_front_location'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_front_location'] ) ) : '',
            self::META_URL                  => isset( $_POST['bef_front_event_url'] ) ? esc_url_raw( wp_unslash( $_POST['bef_front_event_url'] ) ) : '',
            self::META_TICKET_URL           => isset( $_POST['bef_front_ticket_url'] ) ? esc_url_raw( wp_unslash( $_POST['bef_front_ticket_url'] ) ) : '',
            self::META_TICKET_LABEL         => isset( $_POST['bef_front_ticket_label'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_front_ticket_label'] ) ) : '',
            self::META_RECURRENCE_FREQUENCY => $this->normalize_recurrence_frequency( isset( $_POST['bef_front_recurrence_frequency'] ) ? wp_unslash( $_POST['bef_front_recurrence_frequency'] ) : 'none' ),
            self::META_RECURRENCE_INTERVAL  => max( 1, absint( isset( $_POST['bef_front_recurrence_interval'] ) ? wp_unslash( $_POST['bef_front_recurrence_interval'] ) : 1 ) ),
            self::META_RECURRENCE_UNTIL     => isset( $_POST['bef_front_recurrence_until'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_front_recurrence_until'] ) ) : '',
        );

        foreach ( $meta as $meta_key => $meta_value ) {
            update_post_meta( $event_id, $meta_key, $meta_value );
        }

        $category_ids = isset( $_POST['bef_front_categories'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['bef_front_categories'] ) ) : array();
        wp_set_object_terms( $event_id, array_filter( $category_ids ), self::TAXONOMY, false );

        if ( ! empty( $_POST['bef_front_remove_image'] ) ) {
            delete_post_thumbnail( $event_id );
        }

        if ( ! empty( $_FILES['bef_front_image_upload']['name'] ) && ! empty( $_FILES['bef_front_image_upload']['tmp_name'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            $attachment_id = media_handle_upload( 'bef_front_image_upload', $event_id );

            if ( ! is_wp_error( $attachment_id ) ) {
                set_post_thumbnail( $event_id, $attachment_id );
            }
        }

        $redirect = add_query_arg(
            array(
                $updated ? 'bef_front_updated' : 'bef_front_saved' => 1,
                'bef_front_event' => $event_id,
            ),
            remove_query_arg( array( 'bef_front_saved', 'bef_front_updated' ), $redirect )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Get the current front-end URL.
     *
     * @return string
     */
    private function get_current_frontend_url() {
        global $wp;

        if ( function_exists( 'get_permalink' ) && get_the_ID() ) {
            $permalink = get_permalink( get_the_ID() );
            if ( $permalink ) {
                return $permalink;
            }
        }

        $request = isset( $wp->request ) ? $wp->request : '';
        return home_url( '/' . ltrim( $request, '/' ) );
    }

    /**
     * Normalise recurrence frequency.
     *
     * @param string $value Raw value.
     * @return string
     */
    private function normalize_recurrence_frequency( $value ) {
        $value = sanitize_key( (string) $value );
        return in_array( $value, array( 'daily', 'weekly', 'monthly' ), true ) ? $value : 'none';
    }

    /**
     * Get recurrence settings for an event post.
     *
     * @param int $post_id Event post ID.
     * @return array<string, string|int>
     */
    public function get_event_recurrence_settings( $post_id ) {
        $frequency = $this->normalize_recurrence_frequency( get_post_meta( $post_id, self::META_RECURRENCE_FREQUENCY, true ) );
        $interval  = max( 1, absint( get_post_meta( $post_id, self::META_RECURRENCE_INTERVAL, true ) ) );
        $until     = sanitize_text_field( (string) get_post_meta( $post_id, self::META_RECURRENCE_UNTIL, true ) );

        if ( ! $this->is_valid_event_date( $until ) ) {
            $until = '';
        }

        return array(
            'frequency' => $frequency,
            'interval'  => $interval,
            'until'     => $until,
        );
    }

    /**
     * Validate an event date string.
     *
     * @param string $date Candidate date.
     * @return bool
     */
    private function is_valid_event_date( $date ) {
        return is_string( $date ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && false !== strtotime( $date );
    }

    /**
     * Add days to an event date.
     *
     * @param string $date Date.
     * @param int    $days Number of days.
     * @return string
     */
    private function add_days_to_event_date( $date, $days ) {
        if ( ! $this->is_valid_event_date( $date ) ) {
            return '';
        }

        if ( 0 === (int) $days ) {
            return $date;
        }

        return gmdate( 'Y-m-d', strtotime( $date . ( $days > 0 ? ' +' : ' ' ) . (int) $days . ' days' ) );
    }

    /**
     * Add months while preserving day-of-month where possible.
     *
     * @param string $date   Date.
     * @param int    $months Number of months.
     * @return string
     */
    private function add_months_to_event_date( $date, $months ) {
        if ( ! $this->is_valid_event_date( $date ) ) {
            return '';
        }

        $months = (int) $months;
        if ( 0 === $months ) {
            return $date;
        }

        $parts = explode( '-', $date );
        $year  = isset( $parts[0] ) ? (int) $parts[0] : 0;
        $month = isset( $parts[1] ) ? (int) $parts[1] : 0;
        $day   = isset( $parts[2] ) ? (int) $parts[2] : 0;

        if ( ! $year || ! $month || ! $day ) {
            return $date;
        }

        $month += $months;

        while ( $month > 12 ) {
            $month -= 12;
            $year++;
        }

        while ( $month < 1 ) {
            $month += 12;
            $year--;
        }

        $days_in_month = cal_days_in_month( CAL_GREGORIAN, $month, $year );
        $day           = min( $day, $days_in_month );

        return sprintf( '%04d-%02d-%02d', $year, $month, $day );
    }

    /**
     * Advance a recurrence date by its configured interval.
     *
     * @param string $date      Current date.
     * @param string $frequency Frequency.
     * @param int    $interval  Interval.
     * @return string
     */
    private function advance_recurrence_date( $date, $frequency, $interval ) {
        $interval = max( 1, absint( $interval ) );

        if ( 'daily' === $frequency ) {
            return $this->add_days_to_event_date( $date, $interval );
        }

        if ( 'weekly' === $frequency ) {
            return $this->add_days_to_event_date( $date, $interval * 7 );
        }

        if ( 'monthly' === $frequency ) {
            return $this->add_months_to_event_date( $date, $interval );
        }

        return '';
    }

    /**
     * Determine the inclusive span of an event occurrence in days.
     *
     * @param string $start_date Start date.
     * @param string $end_date   End date.
     * @return int
     */
    private function get_event_span_days( $start_date, $end_date ) {
        if ( ! $this->is_valid_event_date( $start_date ) ) {
            return 0;
        }

        if ( ! $this->is_valid_event_date( $end_date ) || $end_date < $start_date ) {
            return 0;
        }

        $seconds = strtotime( $end_date ) - strtotime( $start_date );
        return $seconds > 0 ? (int) floor( $seconds / DAY_IN_SECONDS ) : 0;
    }

    /**
     * Check whether an occurrence overlaps a requested date range.
     *
     * @param string $start       Occurrence start.
     * @param string $end         Occurrence end.
     * @param string $range_start Range start.
     * @param string $range_end   Range end.
     * @return bool
     */
    private function occurrence_overlaps_range( $start, $end, $range_start = '', $range_end = '' ) {
        $end = $end ? $end : $start;

        if ( $range_start && $end < $range_start ) {
            return false;
        }

        if ( $range_end && $start > $range_end ) {
            return false;
        }

        return true;
    }

    /**
     * Expand an event into dated occurrences.
     *
     * @param int    $post_id     Event post ID.
     * @param string $range_start Inclusive range start.
     * @param string $range_end   Inclusive range end.
     * @param int    $max         Safety cap.
     * @return array<int, array<string, string|bool>>
     */
    public function get_event_occurrences( $post_id, $range_start = '', $range_end = '', $max = 120 ) {
        $start_date = get_post_meta( $post_id, self::META_DATE, true );
        $end_date   = get_post_meta( $post_id, self::META_END_DATE, true );

        if ( ! $this->is_valid_event_date( $start_date ) ) {
            return array();
        }

        if ( $range_start && ! $this->is_valid_event_date( $range_start ) ) {
            $range_start = '';
        }

        if ( $range_end && ! $this->is_valid_event_date( $range_end ) ) {
            $range_end = '';
        }

        $max         = max( 1, absint( $max ) );
        $settings    = $this->get_event_recurrence_settings( $post_id );
        $frequency   = $settings['frequency'];
        $interval    = max( 1, absint( $settings['interval'] ) );
        $until       = ! empty( $settings['until'] ) ? (string) $settings['until'] : '';
        $span_days   = $this->get_event_span_days( $start_date, $end_date );
        $occurrences = array();

        if ( 'none' === $frequency ) {
            $single_end = $span_days ? $this->add_days_to_event_date( $start_date, $span_days ) : $start_date;

            if ( $this->occurrence_overlaps_range( $start_date, $single_end, $range_start, $range_end ) ) {
                $occurrences[] = array(
                    'date'         => $start_date,
                    'end_date'     => $single_end,
                    'is_recurring' => false,
                );
            }

            return $occurrences;
        }

        $cursor        = $start_date;
        $iterations    = 0;
        $hard_stop     = $range_end ? $range_end : $this->add_months_to_event_date( $start_date, 24 );
        $effective_end = $until ? $until : $hard_stop;

        if ( $hard_stop && $effective_end && $hard_stop < $effective_end ) {
            $effective_end = $hard_stop;
        }

        if ( $range_start && $range_start > $cursor ) {
            if ( 'daily' === $frequency ) {
                $days_diff = max( 0, (int) floor( ( strtotime( $range_start ) - strtotime( $cursor ) ) / DAY_IN_SECONDS ) );
                $steps     = (int) floor( $days_diff / $interval );
                if ( $steps > 0 ) {
                    $cursor = $this->add_days_to_event_date( $cursor, $steps * $interval );
                }
            } elseif ( 'weekly' === $frequency ) {
                $days_diff  = max( 0, (int) floor( ( strtotime( $range_start ) - strtotime( $cursor ) ) / DAY_IN_SECONDS ) );
                $weeks_diff = (int) floor( $days_diff / 7 );
                $steps      = (int) floor( $weeks_diff / $interval );
                if ( $steps > 0 ) {
                    $cursor = $this->add_days_to_event_date( $cursor, $steps * $interval * 7 );
                }
            } elseif ( 'monthly' === $frequency ) {
                $fast_forward = 0;
                while ( $cursor && $cursor < $range_start && $fast_forward < 240 ) {
                    $next_cursor = $this->advance_recurrence_date( $cursor, $frequency, $interval );
                    if ( ! $next_cursor || $next_cursor <= $cursor ) {
                        break;
                    }
                    $cursor = $next_cursor;
                    $fast_forward++;
                }
            }
        }

        while ( $cursor && $iterations < $max ) {
            $occurrence_end = $span_days ? $this->add_days_to_event_date( $cursor, $span_days ) : $cursor;

            if ( $this->occurrence_overlaps_range( $cursor, $occurrence_end, $range_start, $range_end ) ) {
                $occurrences[] = array(
                    'date'         => $cursor,
                    'end_date'     => $occurrence_end,
                    'is_recurring' => true,
                );
            }

            $next_cursor = $this->advance_recurrence_date( $cursor, $frequency, $interval );
            $iterations++;

            if ( ! $next_cursor || $next_cursor <= $cursor ) {
                break;
            }

            if ( $effective_end && $next_cursor > $effective_end ) {
                break;
            }

            $cursor = $next_cursor;
        }

        return $occurrences;
    }

    /**
     * Build a human-friendly recurrence summary.
     *
     * @param int $post_id Event post ID.
     * @return string
     */
    public function get_event_recurrence_summary( $post_id ) {
        $settings  = $this->get_event_recurrence_settings( $post_id );
        $frequency = $settings['frequency'];
        $interval  = max( 1, absint( $settings['interval'] ) );
        $until     = ! empty( $settings['until'] ) ? (string) $settings['until'] : '';

        if ( 'none' === $frequency ) {
            return '';
        }

        if ( 'daily' === $frequency ) {
            $summary = 1 === $interval ? __( 'Repeats every day', 'bef-calendar' ) : sprintf( __( 'Repeats every %d days', 'bef-calendar' ), $interval );
        } elseif ( 'weekly' === $frequency ) {
            $summary = 1 === $interval ? __( 'Repeats every week', 'bef-calendar' ) : sprintf( __( 'Repeats every %d weeks', 'bef-calendar' ), $interval );
        } else {
            $summary = 1 === $interval ? __( 'Repeats every month', 'bef-calendar' ) : sprintf( __( 'Repeats every %d months', 'bef-calendar' ), $interval );
        }

        if ( $until ) {
            $summary .= ' ' . sprintf( __( 'until %s', 'bef-calendar' ), wp_date( get_option( 'date_format' ), strtotime( $until ) ) );
        }

        return $summary;
    }

    /**
     * Get the next dated occurrences for a single event.
     *
     * @param int    $post_id   Event post ID.
     * @param int    $limit     Result limit.
     * @param string $from_date Starting date.
     * @return array<int, array<string, string|bool>>
     */
    public function get_event_next_occurrences( $post_id, $limit = 5, $from_date = '' ) {
        $from_date   = $this->is_valid_event_date( $from_date ) ? $from_date : current_time( 'Y-m-d' );
        $range_end   = $this->add_months_to_event_date( $from_date, 18 );
        $occurrences = $this->get_event_occurrences( $post_id, $from_date, $range_end, 160 );

        return array_slice( $occurrences, 0, max( 1, absint( $limit ) ) );
    }

    /**
     * Build a recurrence rule suitable for ICS and Google exports.
     *
     * @param int $post_id Event post ID.
     * @return string
     */
    private function get_event_recurrence_rrule( $post_id ) {
        $settings  = $this->get_event_recurrence_settings( $post_id );
        $frequency = $settings['frequency'];

        if ( 'none' === $frequency ) {
            return '';
        }

        $parts = array(
            'FREQ=' . strtoupper( $frequency ),
        );

        if ( ! empty( $settings['interval'] ) && absint( $settings['interval'] ) > 1 ) {
            $parts[] = 'INTERVAL=' . absint( $settings['interval'] );
        }

        if ( ! empty( $settings['until'] ) && $this->is_valid_event_date( $settings['until'] ) ) {
            $until_dt = $this->build_timezone_datetime( $settings['until'], '23:59' );
            if ( $until_dt ) {
                $parts[] = 'UNTIL=' . gmdate( 'Ymd\THis\Z', $until_dt->getTimestamp() );
            }
        }

        return implode( ';', $parts );
    }

    /**
     * Prepare archive occurrence results with pagination.
     *
     * @param string $view              Archive view.
     * @param string $selected_category Selected taxonomy slug.
     * @param int    $paged             Current page.
     * @param int    $posts_per_page    Page size.
     * @return array<string, mixed>
     */
    public function get_archive_occurrence_results( $view = 'upcoming', $selected_category = '', $paged = 1, $posts_per_page = 12 ) {
        $today = current_time( 'Y-m-d' );

        if ( 'past' === $view ) {
            $range_start = $this->add_days_to_event_date( $today, -540 );
            $range_end   = $this->add_days_to_event_date( $today, -1 );
            $sort_desc   = true;
        } elseif ( 'all' === $view ) {
            $range_start = $this->add_days_to_event_date( $today, -365 );
            $range_end   = $this->add_days_to_event_date( $today, 540 );
            $sort_desc   = false;
        } else {
            $range_start = $today;
            $range_end   = $this->add_days_to_event_date( $today, 540 );
            $sort_desc   = false;
        }

        $query_args = array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        );

        if ( $selected_category ) {
            $query_args['tax_query'] = array(
                array(
                    'taxonomy' => self::TAXONOMY,
                    'field'    => 'slug',
                    'terms'    => $selected_category,
                ),
            );
        }

        $query       = new WP_Query( $query_args );
        $occurrences = array();

        if ( $query->have_posts() ) {
            foreach ( $query->posts as $post ) {
                $post_id            = $post->ID;
                $post_occurrences   = $this->get_event_occurrences( $post_id, $range_start, $range_end, 180 );
                $ticket_label       = get_post_meta( $post_id, self::META_TICKET_LABEL, true );
                $remote_image       = get_post_meta( $post_id, self::META_REMOTE_IMAGE_URL, true );
                $source_label       = $this->get_post_source_label( $post_id );
                $event_terms        = get_the_terms( $post_id, self::TAXONOMY );
                $recurrence_summary = $this->get_event_recurrence_summary( $post_id );
                $excerpt            = get_the_excerpt( $post_id );
                if ( ! $excerpt ) {
                    $excerpt = wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ), 24 );
                }

                foreach ( $post_occurrences as $occurrence ) {
                    $occurrences[] = array(
                        'post_id'            => $post_id,
                        'title'              => get_the_title( $post_id ),
                        'date'               => $occurrence['date'],
                        'end_date'           => $occurrence['end_date'],
                        'start_time'         => get_post_meta( $post_id, self::META_START_TIME, true ),
                        'end_time'           => get_post_meta( $post_id, self::META_END_TIME, true ),
                        'location'           => get_post_meta( $post_id, self::META_LOCATION, true ),
                        'ticket_url'         => get_post_meta( $post_id, self::META_TICKET_URL, true ),
                        'ticket_label'       => $ticket_label ? $ticket_label : __( 'Purchase Tickets', 'bef-calendar' ),
                        'permalink'          => get_permalink( $post_id ),
                        'excerpt'            => $excerpt,
                        'terms'              => $event_terms,
                        'remote_image'       => $remote_image,
                        'thumbnail'          => get_the_post_thumbnail_url( $post_id, 'large' ),
                        'source_label'       => $source_label,
                        'recurrence_summary' => $recurrence_summary,
                    );
                }
            }
            wp_reset_postdata();
        }

        usort(
            $occurrences,
            static function ( $a, $b ) use ( $sort_desc ) {
                $a_key = ( $a['date'] ?? '' ) . ' ' . ( $a['start_time'] ?? '23:59' );
                $b_key = ( $b['date'] ?? '' ) . ' ' . ( $b['start_time'] ?? '23:59' );

                if ( $a_key === $b_key ) {
                    return 0;
                }

                if ( $sort_desc ) {
                    return $a_key < $b_key ? 1 : -1;
                }

                return $a_key < $b_key ? -1 : 1;
            }
        );

        $total       = count( $occurrences );
        $paged       = max( 1, absint( $paged ) );
        $per_page    = max( 1, absint( $posts_per_page ) );
        $total_pages = max( 1, (int) ceil( $total / $per_page ) );
        $offset      = ( $paged - 1 ) * $per_page;

        return array(
            'items'       => array_slice( $occurrences, $offset, $per_page ),
            'total'       => $total,
            'total_pages' => $total_pages,
        );
    }

    /**
     * Render Eventbrite settings page.
     */
    public function render_eventbrite_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings     = $this->get_eventbrite_settings();
        $org_id       = $this->get_eventbrite_organization_id( false );
        $connection   = $this->test_eventbrite_connection();
        $refresh_url  = wp_nonce_url(
            admin_url( 'admin-post.php?action=bef_calendar_refresh_eventbrite_cache' ),
            'bef_calendar_refresh_eventbrite_cache'
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'BEF Calendar Eventbrite Settings', 'bef-calendar' ); ?></h1>
            <p><?php esc_html_e( 'Connect your Eventbrite organiser account to show Eventbrite events inside the British Esports calendar.', 'bef-calendar' ); ?></p>

            <?php if ( isset( $_GET['bef_cache_refreshed'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Eventbrite cache refreshed.', 'bef-calendar' ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'bef_calendar_eventbrite' ); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Enable Eventbrite', 'bef-calendar' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_EVENTBRITE ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                                    <?php esc_html_e( 'Allow Eventbrite events to be shown in the calendar', 'bef-calendar' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bef-eventbrite-token"><?php esc_html_e( 'Private Token', 'bef-calendar' ); ?></label></th>
                            <td>
                                <input type="password" id="bef-eventbrite-token" class="regular-text" name="<?php echo esc_attr( self::OPTION_EVENTBRITE ); ?>[private_token]" value="<?php echo esc_attr( $settings['private_token'] ); ?>" autocomplete="off">
                                <p class="description"><?php esc_html_e( 'Paste your Eventbrite private token here.', 'bef-calendar' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bef-eventbrite-org"><?php esc_html_e( 'Organisation ID', 'bef-calendar' ); ?></label></th>
                            <td>
                                <input type="text" id="bef-eventbrite-org" class="regular-text" name="<?php echo esc_attr( self::OPTION_EVENTBRITE ); ?>[organization_id]" value="<?php echo esc_attr( $settings['organization_id'] ); ?>">
                                <p class="description"><?php esc_html_e( 'Optional. Leave empty to auto-detect the first organisation available to the token.', 'bef-calendar' ); ?></p>
                                <?php if ( $org_id ) : ?>
                                    <p><strong><?php esc_html_e( 'Detected organisation:', 'bef-calendar' ); ?></strong> <?php echo esc_html( $org_id ); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bef-eventbrite-cache"><?php esc_html_e( 'Cache Minutes', 'bef-calendar' ); ?></label></th>
                            <td>
                                <input type="number" min="1" step="1" id="bef-eventbrite-cache" class="small-text" name="<?php echo esc_attr( self::OPTION_EVENTBRITE ); ?>[cache_minutes]" value="<?php echo esc_attr( (string) absint( $settings['cache_minutes'] ) ); ?>">
                                <p class="description"><?php esc_html_e( 'How long to keep Eventbrite results before fetching fresh data again.', 'bef-calendar' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Show Eventbrite by Default', 'bef-calendar' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_EVENTBRITE ); ?>[default_show_external]" value="1" <?php checked( ! empty( $settings['default_show_external'] ) ); ?>>
                                    <?php esc_html_e( 'New calendar blocks and shortcodes should include Eventbrite events unless explicitly turned off.', 'bef-calendar' ); ?>
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>

            <h2><?php esc_html_e( 'Connection Status', 'bef-calendar' ); ?></h2>
            <?php if ( true === $connection['success'] ) : ?>
                <p style="color:#0a7f33;"><strong><?php esc_html_e( 'Connected.', 'bef-calendar' ); ?></strong> <?php echo esc_html( $connection['message'] ); ?></p>
            <?php else : ?>
                <p style="color:#b32d2e;"><strong><?php esc_html_e( 'Not connected.', 'bef-calendar' ); ?></strong> <?php echo esc_html( $connection['message'] ); ?></p>
            <?php endif; ?>

            <p>
                <a href="<?php echo esc_url( $refresh_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Refresh Eventbrite Cache', 'bef-calendar' ); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Handle cache refresh action.
     */
    public function handle_refresh_eventbrite_cache() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'bef-calendar' ) );
        }

        check_admin_referer( 'bef_calendar_refresh_eventbrite_cache' );

        $this->clear_eventbrite_cache();

        wp_safe_redirect(
            add_query_arg(
                array(
                    'post_type'            => self::POST_TYPE,
                    'page'                 => 'bef-calendar-eventbrite',
                    'bef_cache_refreshed'  => '1',
                ),
                admin_url( 'edit.php' )
            )
        );
        exit;
    }

    /**
     * Clear Eventbrite cache.
     */
    private function clear_eventbrite_cache() {
        delete_transient( self::TRANSIENT_EVENTS . '0' );
        delete_transient( self::TRANSIENT_EVENTS . '1' );
        delete_transient( self::TRANSIENT_ORG_ID );
    }

    /**
     * Test Eventbrite connection.
     *
     * @return array
     */
    private function test_eventbrite_connection() {
        $settings = $this->get_eventbrite_settings();

        if ( empty( $settings['enabled'] ) ) {
            return array(
                'success' => false,
                'message' => __( 'Eventbrite integration is currently disabled.', 'bef-calendar' ),
            );
        }

        if ( empty( $settings['private_token'] ) ) {
            return array(
                'success' => false,
                'message' => __( 'Add your private token to test the connection.', 'bef-calendar' ),
            );
        }

        $org_id = $this->get_eventbrite_organization_id( false );

        if ( $org_id ) {
            return array(
                'success' => true,
                'message' => sprintf( __( 'Organisation %s is ready to use.', 'bef-calendar' ), $org_id ),
            );
        }

        return array(
            'success' => false,
            'message' => __( 'The token was saved, but no organisation could be detected yet. Double-check the token or add the organisation ID manually.', 'bef-calendar' ),
        );
    }


    /**
     * Add custom cron schedules.
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public function add_cron_schedules( $schedules ) {
        $schedules['bef_every_fifteen_minutes'] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 15 Minutes', 'bef-calendar' ),
        );

        return $schedules;
    }

    /**
     * Schedule or clear the British Arena sync task.
     *
     * @param array|null $settings Optional settings override.
     * @return void
     */
    public function schedule_british_arena_sync( $settings = null ) {
        if ( null === $settings ) {
            $settings = $this->get_british_arena_settings();
        }

        wp_clear_scheduled_hook( self::CRON_HOOK_BRITISH_ARENA );

        if ( empty( $settings['enabled'] ) || empty( $settings['auto_sync'] ) || empty( $settings['source_url'] ) ) {
            return;
        }

        $interval = ! empty( $settings['sync_interval'] ) ? $settings['sync_interval'] : 'bef_every_fifteen_minutes';

        if ( ! wp_next_scheduled( self::CRON_HOOK_BRITISH_ARENA ) ) {
            wp_schedule_event( time() + 300, $interval, self::CRON_HOOK_BRITISH_ARENA );
        }
    }

    /**
     * Render the British Arena sync settings page.
     *
     * @return void
     */
    public function render_british_arena_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings    = $this->get_british_arena_settings();
        $state       = $this->get_british_arena_state();
        $sync_url    = wp_nonce_url(
            admin_url( 'admin-post.php?action=bef_calendar_sync_british_arena' ),
            'bef_calendar_sync_british_arena'
        );
        $endpoint    = '';
        $intervals   = array(
            'bef_every_fifteen_minutes' => __( 'Every 15 minutes', 'bef-calendar' ),
            'hourly'                    => __( 'Hourly', 'bef-calendar' ),
            'twicedaily'                => __( 'Twice daily', 'bef-calendar' ),
            'daily'                     => __( 'Daily', 'bef-calendar' ),
        );

        if ( ! empty( $settings['source_url'] ) ) {
            $endpoint = trailingslashit( untrailingslashit( $settings['source_url'] ) ) . ltrim( $settings['endpoint_path'], '/' );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'BEF Calendar British Arena Sync', 'bef-calendar' ); ?></h1>
            <p><?php esc_html_e( 'Pull published events from britisharena.com into local BEF Events. This works best when the same BEF Calendar plugin is active on the source site so event dates, times, ticket links, and categories are exposed over the WordPress REST API.', 'bef-calendar' ); ?></p>

            <?php if ( isset( $_GET['bef_british_arena_synced'] ) ) : ?>
                <?php $state_notice = $this->get_british_arena_state(); ?>
                <div class="notice notice-<?php echo 'success' === $state_notice['last_status'] ? 'success' : 'warning'; ?> is-dismissible"><p><?php echo esc_html( $state_notice['message'] ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'bef_calendar_british_arena' ); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Enable British Arena Sync', 'bef-calendar' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_BRITISH_ARENA ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                                    <?php esc_html_e( 'Import events from British Arena into BEF Events', 'bef-calendar' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bef_ba_source_url"><?php esc_html_e( 'Source Site URL', 'bef-calendar' ); ?></label></th>
                            <td>
                                <input type="url" class="regular-text" id="bef_ba_source_url" name="<?php echo esc_attr( self::OPTION_BRITISH_ARENA ); ?>[source_url]" value="<?php echo esc_attr( $settings['source_url'] ); ?>" placeholder="https://britisharena.com">
                                <p class="description"><?php esc_html_e( 'The site that owns the source events. Defaults to britisharena.com.', 'bef-calendar' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bef_ba_endpoint_path"><?php esc_html_e( 'REST Endpoint Path', 'bef-calendar' ); ?></label></th>
                            <td>
                                <input type="text" class="regular-text" id="bef_ba_endpoint_path" name="<?php echo esc_attr( self::OPTION_BRITISH_ARENA ); ?>[endpoint_path]" value="<?php echo esc_attr( $settings['endpoint_path'] ); ?>">
                                <?php if ( $endpoint ) : ?>
                                    <p class="description"><?php printf( esc_html__( 'Current endpoint: %s', 'bef-calendar' ), esc_html( $endpoint ) ); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Automatic Sync', 'bef-calendar' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_BRITISH_ARENA ); ?>[auto_sync]" value="1" <?php checked( ! empty( $settings['auto_sync'] ) ); ?>>
                                    <?php esc_html_e( 'Regularly poll the source site and create or update imported events', 'bef-calendar' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bef_ba_sync_interval"><?php esc_html_e( 'Sync Frequency', 'bef-calendar' ); ?></label></th>
                            <td>
                                <select id="bef_ba_sync_interval" name="<?php echo esc_attr( self::OPTION_BRITISH_ARENA ); ?>[sync_interval]">
                                    <?php foreach ( $intervals as $value => $label ) : ?>
                                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['sync_interval'], $value ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Import Categories', 'bef-calendar' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_BRITISH_ARENA ); ?>[import_categories]" value="1" <?php checked( ! empty( $settings['import_categories'] ) ); ?>>
                                    <?php esc_html_e( 'Map remote event categories into local BEF Event Categories', 'bef-calendar' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bef_ba_auth_username"><?php esc_html_e( 'REST Username (optional)', 'bef-calendar' ); ?></label></th>
                            <td>
                                <input type="text" class="regular-text" id="bef_ba_auth_username" name="<?php echo esc_attr( self::OPTION_BRITISH_ARENA ); ?>[auth_username]" value="<?php echo esc_attr( $settings['auth_username'] ); ?>">
                                <p class="description"><?php esc_html_e( 'Only needed if the source endpoint is protected.', 'bef-calendar' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bef_ba_auth_password"><?php esc_html_e( 'Application Password (optional)', 'bef-calendar' ); ?></label></th>
                            <td>
                                <input type="password" class="regular-text" id="bef_ba_auth_password" name="<?php echo esc_attr( self::OPTION_BRITISH_ARENA ); ?>[application_password]" value="<?php echo esc_attr( $settings['application_password'] ); ?>" autocomplete="new-password">
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button( __( 'Save British Arena Settings', 'bef-calendar' ) ); ?>
            </form>

            <hr>

            <h2><?php esc_html_e( 'Sync Status', 'bef-calendar' ); ?></h2>
            <p>
                <?php if ( ! empty( $state['last_sync'] ) ) : ?>
                    <?php printf( esc_html__( 'Last sync: %s', 'bef-calendar' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $state['last_sync'] ) ) ) ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'No sync has run yet.', 'bef-calendar' ); ?>
                <?php endif; ?>
            </p>
            <?php if ( ! empty( $state['message'] ) ) : ?>
                <p><?php echo esc_html( $state['message'] ); ?></p>
            <?php endif; ?>
            <p>
                <a href="<?php echo esc_url( $sync_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Sync British Arena Events Now', 'bef-calendar' ); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Handle the British Arena manual sync action.
     *
     * @return void
     */
    public function handle_sync_british_arena() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'bef-calendar' ) );
        }

        check_admin_referer( 'bef_calendar_sync_british_arena' );

        $this->sync_british_arena_events();

        wp_safe_redirect(
            add_query_arg(
                array(
                    'post_type'                => self::POST_TYPE,
                    'page'                     => 'bef-calendar-british-arena',
                    'bef_british_arena_synced' => '1',
                ),
                admin_url( 'edit.php' )
            )
        );
        exit;
    }

    /**
     * Triggered by cron to sync remote British Arena events.
     *
     * @return void
     */
    public function maybe_sync_british_arena() {
        $settings = $this->get_british_arena_settings();

        if ( empty( $settings['enabled'] ) || empty( $settings['auto_sync'] ) ) {
            return;
        }

        $this->sync_british_arena_events();
    }

    /**
     * Sync British Arena events into local BEF events.
     *
     * @return array|WP_Error
     */
    private function sync_british_arena_events() {
        $settings = $this->get_british_arena_settings();

        if ( empty( $settings['enabled'] ) ) {
            $error = new WP_Error( 'bef_ba_disabled', __( 'British Arena sync is currently disabled.', 'bef-calendar' ) );
            $this->update_british_arena_state(
                array(
                    'last_sync'   => current_time( 'mysql' ),
                    'last_status' => 'warning',
                    'message'     => $error->get_error_message(),
                    'stats'       => array(),
                )
            );
            return $error;
        }

        $endpoint = $this->get_british_arena_endpoint_url( $settings );
        if ( ! $endpoint ) {
            $error = new WP_Error( 'bef_ba_missing_url', __( 'Add a valid source site URL before syncing British Arena events.', 'bef-calendar' ) );
            $this->update_british_arena_state(
                array(
                    'last_sync'   => current_time( 'mysql' ),
                    'last_status' => 'warning',
                    'message'     => $error->get_error_message(),
                    'stats'       => array(),
                )
            );
            return $error;
        }

        $events = $this->fetch_british_arena_events( $endpoint, $settings );
        if ( is_wp_error( $events ) ) {
            $this->update_british_arena_state(
                array(
                    'last_sync'   => current_time( 'mysql' ),
                    'last_status' => 'warning',
                    'message'     => $events->get_error_message(),
                    'stats'       => array(),
                )
            );
            return $events;
        }

        $stats = array(
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed'  => 0,
        );

        foreach ( $events as $event ) {
            $result = $this->import_british_arena_event( $event, $settings );

            if ( is_wp_error( $result ) ) {
                $stats['failed']++;
                continue;
            }

            if ( isset( $stats[ $result ] ) ) {
                $stats[ $result ]++;
            }
        }

        $message = sprintf(
            /* translators: 1: created count, 2: updated count, 3: skipped count, 4: failed count. */
            __( 'British Arena sync complete. Created %1$d, updated %2$d, skipped %3$d, failed %4$d.', 'bef-calendar' ),
            (int) $stats['created'],
            (int) $stats['updated'],
            (int) $stats['skipped'],
            (int) $stats['failed']
        );

        $this->update_british_arena_state(
            array(
                'last_sync'   => current_time( 'mysql' ),
                'last_status' => 'success',
                'message'     => $message,
                'stats'       => $stats,
            )
        );

        return $stats;
    }

    /**
     * Build the remote endpoint URL.
     *
     * @param array $settings Settings array.
     * @return string
     */
    private function get_british_arena_endpoint_url( $settings ) {
        if ( empty( $settings['source_url'] ) ) {
            return '';
        }

        return trailingslashit( untrailingslashit( $settings['source_url'] ) ) . ltrim( $settings['endpoint_path'], '/' );
    }

    /**
     * Fetch paginated remote events.
     *
     * @param string $endpoint Endpoint URL.
     * @param array  $settings Settings array.
     * @return array|WP_Error
     */
    private function fetch_british_arena_events( $endpoint, $settings ) {
        $headers = array(
            'Accept' => 'application/json',
        );

        if ( ! empty( $settings['auth_username'] ) && ! empty( $settings['application_password'] ) ) {
            $headers['Authorization'] = 'Basic ' . base64_encode( $settings['auth_username'] . ':' . $settings['application_password'] );
        }

        $events      = array();
        $page        = 1;
        $total_pages = 1;

        do {
            $request_url = add_query_arg(
                array(
                    'status'   => 'publish',
                    'per_page' => 100,
                    'page'     => $page,
                    '_embed'   => 1,
                    'orderby'  => 'modified',
                    'order'    => 'desc',
                ),
                $endpoint
            );

            $response = wp_remote_get(
                $request_url,
                array(
                    'timeout' => 20,
                    'headers' => $headers,
                )
            );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $status_code = (int) wp_remote_retrieve_response_code( $response );
            if ( 200 !== $status_code ) {
                return new WP_Error(
                    'bef_ba_http_error',
                    sprintf(
                        /* translators: 1: status code, 2: endpoint url. */
                        __( 'British Arena sync failed with HTTP %1$s from %2$s.', 'bef-calendar' ),
                        $status_code,
                        $request_url
                    )
                );
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! is_array( $body ) ) {
                return new WP_Error( 'bef_ba_invalid_json', __( 'The British Arena endpoint did not return valid JSON.', 'bef-calendar' ) );
            }

            $events = array_merge( $events, $body );
            $header_total_pages = (int) wp_remote_retrieve_header( $response, 'x-wp-totalpages' );
            $total_pages        = $header_total_pages > 0 ? $header_total_pages : 1;
            $page++;
        } while ( $page <= $total_pages );

        return $events;
    }

    /**
     * Import or update a single remote event.
     *
     * @param array $event    Remote event payload.
     * @param array $settings Sync settings.
     * @return string|WP_Error
     */
    private function import_british_arena_event( $event, $settings ) {
        $remote_id = isset( $event['id'] ) ? absint( $event['id'] ) : 0;
        if ( ! $remote_id ) {
            return new WP_Error( 'bef_ba_missing_id', __( 'A British Arena event was missing an ID.', 'bef-calendar' ) );
        }

        $existing_post_id = $this->find_imported_event_post_id( 'british_arena', $remote_id );
        $title            = html_entity_decode( wp_strip_all_tags( $event['title']['rendered'] ?? '' ), ENT_QUOTES, get_bloginfo( 'charset' ) );
        $content          = isset( $event['content']['rendered'] ) ? wp_kses_post( $event['content']['rendered'] ) : '';
        $excerpt          = isset( $event['excerpt']['rendered'] ) ? wp_strip_all_tags( $event['excerpt']['rendered'] ) : '';
        $event_date       = $this->get_remote_event_meta( $event, self::META_DATE );
        $event_end_date   = $this->get_remote_event_meta( $event, self::META_END_DATE );
        $start_time       = $this->get_remote_event_meta( $event, self::META_START_TIME );
        $end_time         = $this->get_remote_event_meta( $event, self::META_END_TIME );
        $location         = $this->get_remote_event_meta( $event, self::META_LOCATION );
        $event_url        = $this->get_remote_event_meta( $event, self::META_URL );
        $ticket_url           = $this->get_remote_event_meta( $event, self::META_TICKET_URL );
        $ticket_label         = $this->get_remote_event_meta( $event, self::META_TICKET_LABEL );
        $recurrence_frequency = $this->normalize_recurrence_frequency( $this->get_remote_event_meta( $event, self::META_RECURRENCE_FREQUENCY ) );
        $recurrence_interval  = max( 1, absint( $this->get_remote_event_meta( $event, self::META_RECURRENCE_INTERVAL ) ) );
        $recurrence_until     = $this->get_remote_event_meta( $event, self::META_RECURRENCE_UNTIL );
        $remote_link      = isset( $event['link'] ) ? esc_url_raw( $event['link'] ) : '';
        $remote_modified  = sanitize_text_field( $event['modified_gmt'] ?? '' );
        $remote_image_url = $this->get_remote_featured_image_url( $event );

        if ( empty( $event_date ) ) {
            return 'skipped';
        }

        $postarr = array(
            'post_type'    => self::POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => $title ? $title : __( 'British Arena Event', 'bef-calendar' ),
            'post_content' => $content,
            'post_excerpt' => $excerpt,
        );

        if ( $existing_post_id ) {
            $postarr['ID'] = $existing_post_id;
            $post_id       = wp_update_post( $postarr, true );
            $result        = 'updated';
        } else {
            $post_id = wp_insert_post( $postarr, true );
            $result  = 'created';
        }

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        update_post_meta( $post_id, self::META_DATE, $event_date );
        update_post_meta( $post_id, self::META_END_DATE, $event_end_date );
        update_post_meta( $post_id, self::META_START_TIME, $start_time );
        update_post_meta( $post_id, self::META_END_TIME, $end_time );
        update_post_meta( $post_id, self::META_LOCATION, $location );
        update_post_meta( $post_id, self::META_URL, $event_url ? $event_url : $remote_link );
        update_post_meta( $post_id, self::META_TICKET_URL, $ticket_url );
        update_post_meta( $post_id, self::META_TICKET_LABEL, $ticket_label );
        update_post_meta( $post_id, self::META_RECURRENCE_FREQUENCY, $recurrence_frequency );
        update_post_meta( $post_id, self::META_RECURRENCE_INTERVAL, $recurrence_interval );
        update_post_meta( $post_id, self::META_RECURRENCE_UNTIL, $recurrence_until );
        update_post_meta( $post_id, self::META_SOURCE, 'british_arena' );
        update_post_meta( $post_id, self::META_REMOTE_SOURCE, 'british_arena' );
        update_post_meta( $post_id, self::META_REMOTE_ID, $remote_id );
        update_post_meta( $post_id, self::META_REMOTE_MODIFIED, $remote_modified );
        update_post_meta( $post_id, self::META_REMOTE_IMAGE_URL, $remote_image_url );

        if ( ! empty( $settings['import_categories'] ) ) {
            $term_names = $this->get_remote_event_terms( $event );
            if ( ! empty( $term_names ) ) {
                wp_set_object_terms( $post_id, $term_names, self::TAXONOMY, false );
            }
        }

        return $result;
    }

    /**
     * Find an imported local event by source and remote ID.
     *
     * @param string $source    Remote source key.
     * @param int    $remote_id Remote item ID.
     * @return int
     */
    private function find_imported_event_post_id( $source, $remote_id ) {
        $posts = get_posts(
            array(
                'post_type'      => self::POST_TYPE,
                'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'   => self::META_REMOTE_SOURCE,
                        'value' => $source,
                    ),
                    array(
                        'key'   => self::META_REMOTE_ID,
                        'value' => (string) $remote_id,
                    ),
                ),
            )
        );

        return ! empty( $posts ) ? (int) $posts[0] : 0;
    }

    /**
     * Pull a single meta value from a remote REST event payload.
     *
     * @param array  $event Remote payload.
     * @param string $key   Meta key.
     * @return string
     */
    private function get_remote_event_meta( $event, $key ) {
        if ( empty( $event['meta'] ) || ! is_array( $event['meta'] ) || ! array_key_exists( $key, $event['meta'] ) ) {
            return '';
        }

        $value = $event['meta'][ $key ];
        if ( is_array( $value ) ) {
            $value = reset( $value );
        }

        return is_scalar( $value ) ? (string) $value : '';
    }

    /**
     * Extract the remote featured image URL from a REST payload.
     *
     * @param array $event Remote payload.
     * @return string
     */
    private function get_remote_featured_image_url( $event ) {
        if ( empty( $event['_embedded']['wp:featuredmedia'][0] ) || ! is_array( $event['_embedded']['wp:featuredmedia'][0] ) ) {
            return '';
        }

        $media = $event['_embedded']['wp:featuredmedia'][0];

        if ( ! empty( $media['media_details']['sizes']['medium']['source_url'] ) ) {
            return esc_url_raw( $media['media_details']['sizes']['medium']['source_url'] );
        }

        if ( ! empty( $media['source_url'] ) ) {
            return esc_url_raw( $media['source_url'] );
        }

        return '';
    }

    /**
     * Extract remote category names.
     *
     * @param array $event Remote payload.
     * @return array
     */
    private function get_remote_event_terms( $event ) {
        $term_names = array();

        if ( empty( $event['_embedded']['wp:term'] ) || ! is_array( $event['_embedded']['wp:term'] ) ) {
            return $term_names;
        }

        foreach ( $event['_embedded']['wp:term'] as $term_group ) {
            if ( ! is_array( $term_group ) ) {
                continue;
            }

            foreach ( $term_group as $term ) {
                if ( empty( $term['name'] ) ) {
                    continue;
                }

                if ( ! empty( $term['taxonomy'] ) && self::TAXONOMY !== $term['taxonomy'] ) {
                    continue;
                }

                $term_names[] = sanitize_text_field( $term['name'] );
            }
        }

        return array_values( array_unique( array_filter( $term_names ) ) );
    }

    /**
     * Get the source label for a stored event.
     *
     * @param int $post_id Post ID.
     * @return string
     */
    private function get_post_source_label( $post_id ) {
        $source = get_post_meta( $post_id, self::META_SOURCE, true );

        if ( 'eventbrite' === $source ) {
            return __( 'Eventbrite', 'bef-calendar' );
        }

        if ( 'british_arena' === $source ) {
            return __( 'British Arena', 'bef-calendar' );
        }

        if ( 'google_sheets' === $source ) {
            return __( 'Google Sheet', 'bef-calendar' );
        }

        if ( 'google_sheet_upload' === $source ) {
            return __( 'Sheet Upload', 'bef-calendar' );
        }

        return __( 'Website', 'bef-calendar' );
    }


    /**
     * Handle front-end event export requests.
     *
     * Supported format: ICS.
     *
     * @return void
     */
    public function maybe_handle_event_export() {
        if ( empty( $_GET['bef_export'] ) || 'ics' !== sanitize_key( wp_unslash( $_GET['bef_export'] ) ) ) {
            return;
        }

        if ( ! is_singular( self::POST_TYPE ) ) {
            return;
        }

        $post_id = get_queried_object_id();

        if ( ! $post_id || self::POST_TYPE !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
            return;
        }

        $this->output_ics_for_event( $post_id );
    }

    /**
     * Output a downloadable ICS file for an event.
     *
     * @param int $post_id Event post ID.
     * @return void
     */
    private function output_ics_for_event( $post_id ) {
        $event = $this->get_export_event_data( $post_id );

        if ( empty( $event['title'] ) || empty( $event['start_value'] ) || empty( $event['end_value'] ) ) {
            wp_die( esc_html__( 'This event is missing the date details needed for calendar export.', 'bef-calendar' ) );
        }

        $ics = $this->build_ics_content( $event );
        $filename = sanitize_title( $event['title'] ) . '.ics';

        nocache_headers();
        header( 'Content-Type: text/calendar; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'X-Robots-Tag: noindex, nofollow', true );

        echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Get event data used for exports.
     *
     * @param int $post_id Event post ID.
     * @return array<string, string|bool>
     */
    public function get_export_event_data( $post_id ) {
        $post = get_post( $post_id );

        if ( ! $post || self::POST_TYPE !== get_post_type( $post_id ) ) {
            return array();
        }

        $event_date = get_post_meta( $post_id, self::META_DATE, true );
        $event_end  = get_post_meta( $post_id, self::META_END_DATE, true );
        $start_time = get_post_meta( $post_id, self::META_START_TIME, true );
        $end_time   = get_post_meta( $post_id, self::META_END_TIME, true );
        $location   = get_post_meta( $post_id, self::META_LOCATION, true );
        $ticket_url = get_post_meta( $post_id, self::META_TICKET_URL, true );
        $event_url  = get_post_meta( $post_id, self::META_URL, true );
        $permalink  = get_permalink( $post_id );
        $timezone   = wp_timezone_string();

        if ( ! $timezone ) {
            $timezone = 'UTC';
        }

        if ( ! $event_date ) {
            return array();
        }

        $all_day      = empty( $start_time ) && empty( $end_time );
        $start_value  = '';
        $end_value    = '';
        $google_start = '';
        $google_end   = '';

        if ( $all_day ) {
            $start_value  = $event_date;
            $end_boundary = $event_end ? $event_end : $event_date;
            $end_value    = gmdate( 'Y-m-d', strtotime( $end_boundary . ' +1 day' ) );
            $google_start = str_replace( '-', '', $start_value );
            $google_end   = str_replace( '-', '', $end_value );
        } else {
            $start_datetime = $this->build_timezone_datetime( $event_date, $start_time ? $start_time : '00:00' );

            if ( ! $start_datetime ) {
                return array();
            }

            if ( $end_time ) {
                $end_base_date = $event_end ? $event_end : $event_date;
                $end_datetime  = $this->build_timezone_datetime( $end_base_date, $end_time );
            } else {
                $end_datetime = $start_datetime->modify( '+1 hour' );
            }

            if ( ! $end_datetime || $end_datetime <= $start_datetime ) {
                $end_datetime = $start_datetime->modify( '+1 hour' );
            }

            $start_value  = $start_datetime->format( 'Ymd\THis' );
            $end_value    = $end_datetime->format( 'Ymd\THis' );
            $google_start = gmdate( 'Ymd\THis\Z', $start_datetime->getTimestamp() );
            $google_end   = gmdate( 'Ymd\THis\Z', $end_datetime->getTimestamp() );
        }

        $description_parts = array_filter(
            array(
                wp_strip_all_tags( has_excerpt( $post_id ) ? get_the_excerpt( $post_id ) : '' ),
                wp_strip_all_tags( get_the_content( null, false, $post ) ),
            )
        );

        $description = trim( implode( "\n\n", $description_parts ) );

        $detail_lines = array_filter(
            array(
                $description,
                $location ? sprintf( __( 'Location: %s', 'bef-calendar' ), $location ) : '',
                $ticket_url ? sprintf( __( 'Tickets: %s', 'bef-calendar' ), $ticket_url ) : '',
                $event_url ? sprintf( __( 'Event Website: %s', 'bef-calendar' ), $event_url ) : '',
                $permalink ? sprintf( __( 'Event Page: %s', 'bef-calendar' ), $permalink ) : '',
            )
        );

        return array(
            'title'        => get_the_title( $post_id ),
            'location'     => $location,
            'description'  => implode( "\n", $detail_lines ),
            'url'          => $ticket_url ? $ticket_url : ( $event_url ? $event_url : $permalink ),
            'uid'          => 'bef-event-' . $post_id . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
            'all_day'      => $all_day,
            'timezone'     => $timezone,
            'start_value'  => $start_value,
            'end_value'    => $end_value,
            'google_start' => $google_start,
            'google_end'   => $google_end,
        );
    }

    /**
     * Build a Google Calendar event link for a BEF event.
     *
     * @param int $post_id Event post ID.
     * @return string
     */
    public function get_google_calendar_url( $post_id ) {
        $event = $this->get_export_event_data( $post_id );

        if ( empty( $event ) ) {
            return '';
        }

        $query = array(
            'action'   => 'TEMPLATE',
            'text'     => $event['title'],
            'dates'    => $event['google_start'] . '/' . $event['google_end'],
            'details'  => $event['description'],
            'location' => $event['location'],
            'ctz'      => ! empty( $event['all_day'] ) ? '' : $event['timezone'],
            'sprop'    => home_url(),
        );

        if ( ! empty( $event['rrule'] ) ) {
            $query['recur'] = 'RRULE:' . $event['rrule'];
        }

        $query = array_filter(
            $query,
            static function( $value ) {
                return '' !== $value && null !== $value;
            }
        );

        return add_query_arg( rawurlencode_deep( $query ), 'https://calendar.google.com/calendar/render' );
    }

    /**
     * Get the ICS download URL for a BEF event.
     *
     * @param int $post_id Event post ID.
     * @return string
     */
    public function get_ics_export_url( $post_id ) {
        return add_query_arg( 'bef_export', 'ics', get_permalink( $post_id ) );
    }

    /**
     * Build a timezone-aware DateTime object.
     *
     * @param string $date Event date.
     * @param string $time Event time.
     * @return \DateTimeImmutable|null
     */
    private function build_timezone_datetime( $date, $time ) {
        if ( ! $date ) {
            return null;
        }

        $timezone = wp_timezone();
        $time     = $time ? $time : '00:00';
        $format   = 'Y-m-d H:i';
        $value    = $date . ' ' . substr( $time, 0, 5 );
        $dt       = \DateTimeImmutable::createFromFormat( $format, $value, $timezone );

        return $dt instanceof \DateTimeImmutable ? $dt : null;
    }

    /**
     * Build raw ICS content from event data.
     *
     * @param array<string, string|bool> $event Event export data.
     * @return string
     */
    private function build_ics_content( $event ) {
        $timezone = ! empty( $event['timezone'] ) ? (string) $event['timezone'] : 'UTC';
        $stamp    = gmdate( 'Ymd\THis\Z' );
        $lines    = array(
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//British Esports//BEF Calendar//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $this->escape_ics_text( (string) $event['uid'] ),
            'DTSTAMP:' . $stamp,
            'SUMMARY:' . $this->escape_ics_text( (string) $event['title'] ),
        );

        if ( ! empty( $event['all_day'] ) ) {
            $lines[] = 'DTSTART;VALUE=DATE:' . $event['start_value'];
            $lines[] = 'DTEND;VALUE=DATE:' . $event['end_value'];
        } else {
            $lines[] = 'DTSTART;TZID=' . $this->escape_ics_text( $timezone ) . ':' . $event['start_value'];
            $lines[] = 'DTEND;TZID=' . $this->escape_ics_text( $timezone ) . ':' . $event['end_value'];
        }

        if ( ! empty( $event['description'] ) ) {
            $lines[] = 'DESCRIPTION:' . $this->escape_ics_text( (string) $event['description'] );
        }

        if ( ! empty( $event['location'] ) ) {
            $lines[] = 'LOCATION:' . $this->escape_ics_text( (string) $event['location'] );
        }

        if ( ! empty( $event['url'] ) ) {
            $lines[] = 'URL:' . esc_url_raw( (string) $event['url'] );
        }

        if ( ! empty( $event['rrule'] ) ) {
            $lines[] = 'RRULE:' . $event['rrule'];
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        $lines = array_map( array( $this, 'fold_ics_line' ), $lines );

        return implode( "\r\n", $lines ) . "\r\n";
    }

    /**
     * Escape text for ICS output.
     *
     * @param string $value Raw text.
     * @return string
     */
    private function escape_ics_text( $value ) {
        $value = wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) );
        $value = str_replace( array( "\r\n", "\r", "\n" ), '\\n', $value );
        $value = str_replace( array( '\\', ';', ',' ), array( '\\\\', '\\;', '\\,' ), $value );

        return $value;
    }

    /**
     * Fold long ICS lines to the RFC-friendly 75 octet boundary.
     *
     * @param string $line ICS line.
     * @return string
     */
    private function fold_ics_line( $line ) {
        $line = (string) $line;
        $out  = '';

        while ( strlen( $line ) > 75 ) {
            $out .= substr( $line, 0, 75 ) . "\r\n ";
            $line = substr( $line, 75 );
        }

        return $out . $line;
    }

    /**
     * Register event meta box.
     */
    public function register_meta_boxes() {
        add_meta_box(
            'bef_event_details',
            __( 'Event Details', 'bef-calendar' ),
            array( $this, 'render_event_details_meta_box' ),
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    /**
     * Render event details meta box.
     *
     * @param WP_Post $post Current post object.
     */
    public function render_event_details_meta_box( $post ) {
        wp_nonce_field( 'bef_event_save_meta', 'bef_event_meta_nonce' );

        $event_date   = get_post_meta( $post->ID, self::META_DATE, true );
        $end_date     = get_post_meta( $post->ID, self::META_END_DATE, true );
        $start_time   = get_post_meta( $post->ID, self::META_START_TIME, true );
        $end_time     = get_post_meta( $post->ID, self::META_END_TIME, true );
        $location     = get_post_meta( $post->ID, self::META_LOCATION, true );
        $event_url    = get_post_meta( $post->ID, self::META_URL, true );
        $ticket_url   = get_post_meta( $post->ID, self::META_TICKET_URL, true );
        $ticket_label         = get_post_meta( $post->ID, self::META_TICKET_LABEL, true );
        $recurrence_frequency = $this->normalize_recurrence_frequency( get_post_meta( $post->ID, self::META_RECURRENCE_FREQUENCY, true ) );
        $recurrence_interval  = max( 1, absint( get_post_meta( $post->ID, self::META_RECURRENCE_INTERVAL, true ) ) );
        $recurrence_until     = get_post_meta( $post->ID, self::META_RECURRENCE_UNTIL, true );
        $source               = get_post_meta( $post->ID, self::META_SOURCE, true );
        ?>
        <style>
            .bef-admin-grid { display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:16px; }
            .bef-admin-grid .bef-field-full { grid-column: 1 / -1; }
            .bef-admin-grid label { display:block; font-weight:600; margin-bottom:6px; }
            .bef-admin-grid input[type="text"],
            .bef-admin-grid input[type="date"],
            .bef-admin-grid input[type="time"],
            .bef-admin-grid input[type="url"],
            .bef-admin-grid input[type="number"],
            .bef-admin-grid select { width:100%; }
            @media (max-width: 782px) { .bef-admin-grid { grid-template-columns: 1fr; } }
        </style>
        <?php if ( 'british_arena' === $source ) : ?>
            <div class="notice notice-info inline"><p><?php esc_html_e( 'This event was imported from British Arena. Any manual edits here may be overwritten the next time the sync runs.', 'bef-calendar' ); ?></p></div>
        <?php endif; ?>
        <div class="bef-admin-grid">
            <div>
                <label for="bef_event_date"><?php esc_html_e( 'Event Date', 'bef-calendar' ); ?></label>
                <input type="date" id="bef_event_date" name="bef_event_date" value="<?php echo esc_attr( $event_date ); ?>" required>
            </div>
            <div>
                <label for="bef_event_end_date"><?php esc_html_e( 'End Date (optional)', 'bef-calendar' ); ?></label>
                <input type="date" id="bef_event_end_date" name="bef_event_end_date" value="<?php echo esc_attr( $end_date ); ?>">
            </div>
            <div>
                <label for="bef_event_start_time"><?php esc_html_e( 'Start Time', 'bef-calendar' ); ?></label>
                <input type="time" id="bef_event_start_time" name="bef_event_start_time" value="<?php echo esc_attr( $start_time ); ?>">
            </div>
            <div>
                <label for="bef_event_end_time"><?php esc_html_e( 'End Time', 'bef-calendar' ); ?></label>
                <input type="time" id="bef_event_end_time" name="bef_event_end_time" value="<?php echo esc_attr( $end_time ); ?>">
            </div>
            <div class="bef-field-full">
                <label for="bef_event_location"><?php esc_html_e( 'Location', 'bef-calendar' ); ?></label>
                <input type="text" id="bef_event_location" name="bef_event_location" value="<?php echo esc_attr( $location ); ?>" placeholder="<?php esc_attr_e( 'National Esports Arena, Sunderland', 'bef-calendar' ); ?>">
            </div>
            <div class="bef-field-full">
                <label for="bef_event_url"><?php esc_html_e( 'Event URL (optional)', 'bef-calendar' ); ?></label>
                <input type="url" id="bef_event_url" name="bef_event_url" value="<?php echo esc_attr( $event_url ); ?>" placeholder="https://">
            </div>
            <div class="bef-field-full">
                <label for="bef_event_ticket_url"><?php esc_html_e( 'Ticket or Registration URL (optional)', 'bef-calendar' ); ?></label>
                <input type="url" id="bef_event_ticket_url" name="bef_event_ticket_url" value="<?php echo esc_attr( $ticket_url ); ?>" placeholder="https://">
            </div>
            <div class="bef-field-full">
                <label for="bef_event_ticket_label"><?php esc_html_e( 'Ticket Button Label (optional)', 'bef-calendar' ); ?></label>
                <input type="text" id="bef_event_ticket_label" name="bef_event_ticket_label" value="<?php echo esc_attr( $ticket_label ); ?>" placeholder="<?php esc_attr_e( 'Purchase Tickets', 'bef-calendar' ); ?>">
                <p class="description" style="margin-top:6px;"><?php esc_html_e( 'For example: Purchase Tickets, Register Now, Book Your Place.', 'bef-calendar' ); ?></p>
            </div>
            <div>
                <label for="bef_event_recurrence_frequency"><?php esc_html_e( 'Repeats', 'bef-calendar' ); ?></label>
                <select id="bef_event_recurrence_frequency" name="bef_event_recurrence_frequency">
                    <option value="none" <?php selected( 'none', $recurrence_frequency ); ?>><?php esc_html_e( 'Does not repeat', 'bef-calendar' ); ?></option>
                    <option value="daily" <?php selected( 'daily', $recurrence_frequency ); ?>><?php esc_html_e( 'Every day', 'bef-calendar' ); ?></option>
                    <option value="weekly" <?php selected( 'weekly', $recurrence_frequency ); ?>><?php esc_html_e( 'Every week', 'bef-calendar' ); ?></option>
                    <option value="monthly" <?php selected( 'monthly', $recurrence_frequency ); ?>><?php esc_html_e( 'Every month', 'bef-calendar' ); ?></option>
                </select>
            </div>
            <div>
                <label for="bef_event_recurrence_interval"><?php esc_html_e( 'Repeat interval', 'bef-calendar' ); ?></label>
                <input type="number" min="1" step="1" id="bef_event_recurrence_interval" name="bef_event_recurrence_interval" value="<?php echo esc_attr( $recurrence_interval ); ?>">
            </div>
            <div>
                <label for="bef_event_recurrence_until"><?php esc_html_e( 'Repeat until', 'bef-calendar' ); ?></label>
                <input type="date" id="bef_event_recurrence_until" name="bef_event_recurrence_until" value="<?php echo esc_attr( $recurrence_until ); ?>">
            </div>
        </div>
        <p style="margin-top:12px; color:#50575e;">
            <?php esc_html_e( 'Use the main content editor for the event description. Add the shortcode [bef_calendar] to any page, or use the BEF Calendar ACF block in the block editor.', 'bef-calendar' ); ?>
        </p>
        <?php
    }

    /**
     * Save event meta fields.
     *
     * @param int $post_id Post ID.
     */
    public function save_event_meta( $post_id ) {
        if ( ! isset( $_POST['bef_event_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bef_event_meta_nonce'] ) ), 'bef_event_save_meta' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $date         = isset( $_POST['bef_event_date'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_event_date'] ) ) : '';
        $end_date     = isset( $_POST['bef_event_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_event_end_date'] ) ) : '';
        $start        = isset( $_POST['bef_event_start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_event_start_time'] ) ) : '';
        $end          = isset( $_POST['bef_event_end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_event_end_time'] ) ) : '';
        $location     = isset( $_POST['bef_event_location'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_event_location'] ) ) : '';
        $event_url    = isset( $_POST['bef_event_url'] ) ? esc_url_raw( wp_unslash( $_POST['bef_event_url'] ) ) : '';
        $ticket_url   = isset( $_POST['bef_event_ticket_url'] ) ? esc_url_raw( wp_unslash( $_POST['bef_event_ticket_url'] ) ) : '';
        $ticket_label        = isset( $_POST['bef_event_ticket_label'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_event_ticket_label'] ) ) : '';
        $recurrence_frequency = $this->normalize_recurrence_frequency( isset( $_POST['bef_event_recurrence_frequency'] ) ? wp_unslash( $_POST['bef_event_recurrence_frequency'] ) : 'none' );
        $recurrence_interval  = max( 1, absint( isset( $_POST['bef_event_recurrence_interval'] ) ? wp_unslash( $_POST['bef_event_recurrence_interval'] ) : 1 ) );
        $recurrence_until     = isset( $_POST['bef_event_recurrence_until'] ) ? sanitize_text_field( wp_unslash( $_POST['bef_event_recurrence_until'] ) ) : '';

        update_post_meta( $post_id, self::META_DATE, $date );
        update_post_meta( $post_id, self::META_END_DATE, $end_date );
        update_post_meta( $post_id, self::META_START_TIME, $start );
        update_post_meta( $post_id, self::META_END_TIME, $end );
        update_post_meta( $post_id, self::META_LOCATION, $location );
        update_post_meta( $post_id, self::META_URL, $event_url );
        update_post_meta( $post_id, self::META_TICKET_URL, $ticket_url );
        update_post_meta( $post_id, self::META_TICKET_LABEL, $ticket_label );
        update_post_meta( $post_id, self::META_RECURRENCE_FREQUENCY, $recurrence_frequency );
        update_post_meta( $post_id, self::META_RECURRENCE_INTERVAL, $recurrence_interval );
        update_post_meta( $post_id, self::META_RECURRENCE_UNTIL, $recurrence_until );
    }

    /**
     * Register frontend assets.
     */
    public function register_frontend_assets() {
        wp_register_style(
            'bef-calendar-frontend',
            BEF_CALENDAR_URL . 'assets/css/bef-calendar.css',
            array(),
            BEF_CALENDAR_VERSION
        );

        wp_register_script(
            'bef-calendar-frontend',
            BEF_CALENDAR_URL . 'assets/js/bef-calendar.js',
            array(),
            BEF_CALENDAR_VERSION,
            true
        );
    }

    /**
     * Register the ACF block.
     */
    public function register_acf_block() {
        if ( ! function_exists( 'acf_register_block_type' ) ) {
            return;
        }

        acf_register_block_type(
            array(
                'name'            => 'bef-calendar',
                'title'           => __( 'BEF Calendar', 'bef-calendar' ),
                'description'     => __( 'British Esports front end calendar block.', 'bef-calendar' ),
                'render_callback' => array( $this, 'render_calendar_block' ),
                'category'        => 'widgets',
                'icon'            => 'calendar-alt',
                'keywords'        => array( 'calendar', 'events', 'esports', 'bef' ),
                'mode'            => 'preview',
                'supports'        => array(
                    'align'  => array( 'wide', 'full' ),
                    'anchor' => true,
                ),
            )
        );
    }

    /**
     * Register the ACF field group used by the block.
     */
    public function register_acf_field_group() {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) {
            return;
        }

        acf_add_local_field_group(
            array(
                'key'    => 'group_bef_calendar_block',
                'title'  => 'Block - BEF Calendar',
                'fields' => array(
                    array(
                        'key'       => 'field_bef_tab_block_data',
                        'label'     => 'Block Data',
                        'name'      => '',
                        'type'      => 'tab',
                        'placement' => 'left',
                    ),
                    array(
                        'key'        => 'field_bef_heading',
                        'label'      => 'Heading',
                        'name'       => 'heading',
                        'type'       => 'group',
                        'layout'     => 'block',
                        'sub_fields' => array(
                            array(
                                'key'   => 'field_bef_heading_eyebrow',
                                'label' => 'Eyebrow',
                                'name'  => 'eyebrow',
                                'type'  => 'text',
                            ),
                            array(
                                'key'   => 'field_bef_heading_title',
                                'label' => 'Title',
                                'name'  => 'title',
                                'type'  => 'text',
                            ),
                            array(
                                'key'           => 'field_bef_heading_tag',
                                'label'         => 'Heading Tag',
                                'name'          => 'tag',
                                'type'          => 'select',
                                'choices'       => array(
                                    'h1' => 'H1',
                                    'h2' => 'H2',
                                    'h3' => 'H3',
                                    'h4' => 'H4',
                                ),
                                'default_value' => 'h2',
                                'ui'            => 1,
                            ),
                        ),
                    ),
                    array(
                        'key'          => 'field_bef_content',
                        'label'        => 'Content',
                        'name'         => 'content',
                        'type'         => 'wysiwyg',
                        'tabs'         => 'all',
                        'toolbar'      => 'full',
                        'media_upload' => 1,
                    ),
                    array(
                        'key'        => 'field_bef_button',
                        'label'      => 'Button',
                        'name'       => 'button',
                        'type'       => 'group',
                        'layout'     => 'block',
                        'sub_fields' => array(
                            array(
                                'key'   => 'field_bef_button_text',
                                'label' => 'Button Text',
                                'name'  => 'text',
                                'type'  => 'text',
                            ),
                            array(
                                'key'   => 'field_bef_button_url',
                                'label' => 'Button URL',
                                'name'  => 'url',
                                'type'  => 'url',
                            ),
                            array(
                                'key'   => 'field_bef_button_target',
                                'label' => 'Open In New Tab',
                                'name'  => 'target',
                                'type'  => 'true_false',
                                'ui'    => 1,
                            ),
                        ),
                    ),
                    array(
                        'key'           => 'field_bef_background_image',
                        'label'         => 'Background Image',
                        'name'          => 'background_image',
                        'type'          => 'image',
                        'return_format' => 'array',
                        'preview_size'  => 'medium',
                        'library'       => 'all',
                    ),
                    array(
                        'key'           => 'field_bef_show_sidebar',
                        'label'         => 'Show Sidebar',
                        'name'          => 'show_sidebar',
                        'type'          => 'true_false',
                        'ui'            => 1,
                        'message'       => 'Show the selected-date event sidebar',
                        'default_value' => 1,
                    ),
                    array(
                        'key'           => 'field_bef_show_view_toggle',
                        'label'         => 'Show View Toggle',
                        'name'          => 'show_view_toggle',
                        'type'          => 'true_false',
                        'ui'            => 1,
                        'message'       => 'Allow visitors to switch between month and agenda views',
                        'default_value' => 1,
                    ),
                    array(
                        'key'           => 'field_bef_default_view',
                        'label'         => 'Default View',
                        'name'          => 'default_view',
                        'type'          => 'select',
                        'choices'       => array(
                            'month'  => 'Month',
                            'agenda' => 'Agenda',
                        ),
                        'default_value' => 'month',
                        'ui'            => 1,
                    ),
                    array(
                        'key'           => 'field_bef_include_past',
                        'label'         => 'Include Past Events',
                        'name'          => 'include_past_events',
                        'type'          => 'true_false',
                        'ui'            => 1,
                        'message'       => 'Include past events in the calendar data',
                        'default_value' => 1,
                    ),
                    array(
                        'key'           => 'field_bef_show_wordpress',
                        'label'         => 'Show WordPress Events',
                        'name'          => 'show_wordpress_events',
                        'type'          => 'true_false',
                        'ui'            => 1,
                        'message'       => 'Show events created in the BEF Calendar post type',
                        'default_value' => 1,
                    ),
                    array(
                        'key'           => 'field_bef_show_eventbrite',
                        'label'         => 'Show Eventbrite Events',
                        'name'          => 'show_eventbrite_events',
                        'type'          => 'true_false',
                        'ui'            => 1,
                        'message'       => 'Show events pulled from Eventbrite if the integration is connected',
                        'default_value' => ! empty( $this->get_eventbrite_settings()['default_show_external'] ) ? 1 : 0,
                    ),
                    array(
                        'key'       => 'field_bef_tab_block_meta',
                        'label'     => 'Block Meta',
                        'name'      => '',
                        'type'      => 'tab',
                        'placement' => 'left',
                    ),
                    array(
                        'key'        => 'field_bef_block_meta',
                        'label'      => 'Block Meta',
                        'name'       => 'block_meta',
                        'type'       => 'group',
                        'layout'     => 'block',
                        'sub_fields' => array(
                            array(
                                'key'          => 'field_bef_block_meta_anchor',
                                'label'        => 'Anchor ID',
                                'name'         => 'anchor_id',
                                'type'         => 'text',
                                'instructions' => 'Optional custom id attribute for the section wrapper.',
                            ),
                            array(
                                'key'   => 'field_bef_block_meta_classes',
                                'label' => 'Extra Classes',
                                'name'  => 'extra_classes',
                                'type'  => 'text',
                            ),
                            array(
                                'key'           => 'field_bef_block_meta_spacing',
                                'label'         => 'Section Spacing',
                                'name'          => 'section_spacing',
                                'type'          => 'select',
                                'choices'       => array(
                                    'none' => 'None',
                                    'sm'   => 'Small',
                                    'md'   => 'Medium',
                                    'lg'   => 'Large',
                                ),
                                'default_value' => 'md',
                                'ui'            => 1,
                            ),
                        ),
                    ),
                ),
                'location' => array(
                    array(
                        array(
                            'param'    => 'block',
                            'operator' => '==',
                            'value'    => 'acf/bef-calendar',
                        ),
                    ),
                ),
                'position'              => 'normal',
                'style'                 => 'default',
                'label_placement'       => 'top',
                'instruction_placement' => 'label',
                'active'                => true,
                'show_in_rest'          => 0,
            )
        );
    }

    /**
     * Render the shortcode version.
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function render_calendar_shortcode( $atts ) {
        $settings = $this->get_eventbrite_settings();

        $atts = shortcode_atts(
            array(
                'view'                 => 'month',
                'show_sidebar'         => 'yes',
                'include_past'         => 'yes',
                'title'                => __( 'Event Calendar', 'bef-calendar' ),
                'eyebrow'              => __( 'British Esports Calendar', 'bef-calendar' ),
                'content'              => '',
                'button_text'          => '',
                'button_url'           => '',
                'button_new_tab'       => 'no',
                'show_wordpress'       => 'yes',
                'show_eventbrite'      => ! empty( $settings['default_show_external'] ) ? 'yes' : 'no',
                'show_view_toggle'     => 'yes',
                'class'                => '',
                'id'                   => '',
            ),
            $atts,
            'bef_calendar'
        );

        return $this->render_calendar(
            array(
                'instance_type'        => 'shortcode',
                'eyebrow'              => $atts['eyebrow'],
                'title'                => $atts['title'],
                'heading_tag'          => 'h2',
                'content'              => $atts['content'],
                'button_text'          => $atts['button_text'],
                'button_url'           => $atts['button_url'],
                'button_target'        => 'yes' === strtolower( (string) $atts['button_new_tab'] ),
                'background_image'     => '',
                'show_sidebar'         => 'yes' === strtolower( (string) $atts['show_sidebar'] ),
                'include_past_events'  => 'yes' === strtolower( (string) $atts['include_past'] ),
                'show_wordpress'       => 'yes' === strtolower( (string) $atts['show_wordpress'] ),
                'show_eventbrite'      => 'yes' === strtolower( (string) $atts['show_eventbrite'] ),
                'show_view_toggle'     => 'yes' === strtolower( (string) $atts['show_view_toggle'] ),
                'classes'              => $atts['class'],
                'section_id'           => $atts['id'],
                'spacing'              => 'md',
                'view'                 => $atts['view'],
            )
        );
    }

    /**
     * Render the block version.
     *
     * @param array    $block      Block settings.
     * @param string   $content    Block content.
     * @param bool     $is_preview Preview mode.
     * @param int|bool $post_id    Post ID.
     * @return void
     */
    public function render_calendar_block( $block, $content = '', $is_preview = false, $post_id = 0 ) {
        $heading    = function_exists( 'get_field' ) ? (array) get_field( 'heading' ) : array();
        $button     = function_exists( 'get_field' ) ? (array) get_field( 'button' ) : array();
        $block_meta = function_exists( 'get_field' ) ? (array) get_field( 'block_meta' ) : array();
        $background = function_exists( 'get_field' ) ? get_field( 'background_image' ) : '';
        $settings   = $this->get_eventbrite_settings();

        $section_id = ! empty( $block['anchor'] ) ? $block['anchor'] : ( $block_meta['anchor_id'] ?? '' );
        $classes    = array(
            'bef-calendar-block',
            ! empty( $block['className'] ) ? $block['className'] : '',
            ! empty( $block_meta['extra_classes'] ) ? $block_meta['extra_classes'] : '',
            ! empty( $block['align'] ) ? 'align' . sanitize_html_class( $block['align'] ) : '',
        );

        echo $this->render_calendar(
            array(
                'instance_type'        => 'block',
                'eyebrow'              => $heading['eyebrow'] ?? __( 'British Esports Calendar', 'bef-calendar' ),
                'title'                => $heading['title'] ?? __( 'Event Calendar', 'bef-calendar' ),
                'heading_tag'          => $heading['tag'] ?? 'h2',
                'content'              => function_exists( 'get_field' ) ? (string) get_field( 'content' ) : '',
                'button_text'          => $button['text'] ?? '',
                'button_url'           => $button['url'] ?? '',
                'button_target'        => ! empty( $button['target'] ),
                'background_image'     => is_array( $background ) && ! empty( $background['url'] ) ? $background['url'] : '',
                'show_sidebar'         => function_exists( 'get_field' ) ? (bool) get_field( 'show_sidebar' ) : true,
                'show_view_toggle'     => function_exists( 'get_field' ) ? (bool) get_field( 'show_view_toggle' ) : true,
                'include_past_events'  => function_exists( 'get_field' ) ? (bool) get_field( 'include_past_events' ) : true,
                'show_wordpress'       => function_exists( 'get_field' ) ? (bool) get_field( 'show_wordpress_events' ) : true,
                'show_eventbrite'      => function_exists( 'get_field' ) ? (bool) get_field( 'show_eventbrite_events' ) : ! empty( $settings['default_show_external'] ),
                'classes'              => implode( ' ', array_filter( $classes ) ),
                'section_id'           => $section_id,
                'spacing'              => $block_meta['section_spacing'] ?? 'md',
                'view'                 => function_exists( 'get_field' ) ? (string) get_field( 'default_view' ) : 'month',
                'is_preview'           => $is_preview,
            )
        );
    }

    /**
     * Shared renderer for shortcode and block.
     *
     * @param array $args Render arguments.
     * @return string
     */
    private function render_calendar( $args ) {
        $defaults = array(
            'instance_type'        => 'shortcode',
            'eyebrow'              => '',
            'title'                => __( 'Event Calendar', 'bef-calendar' ),
            'heading_tag'          => 'h2',
            'content'              => '',
            'button_text'          => '',
            'button_url'           => '',
            'button_target'        => false,
            'background_image'     => '',
            'show_sidebar'         => true,
            'show_view_toggle'     => true,
            'include_past_events'  => true,
            'show_wordpress'       => true,
            'show_eventbrite'      => false,
            'classes'              => '',
            'section_id'           => '',
            'spacing'              => 'md',
            'view'                 => 'month',
            'is_preview'           => false,
        );

        $args = wp_parse_args( $args, $defaults );

        wp_enqueue_style( 'bef-calendar-frontend' );
        wp_enqueue_script( 'bef-calendar-frontend' );

        $events = $this->get_events_for_frontend(
            (bool) $args['include_past_events'],
            (bool) $args['show_wordpress'],
            (bool) $args['show_eventbrite']
        );

        $instance_id = uniqid( 'bef-calendar-', false );
        $section_id  = $args['section_id'] ? sanitize_title( $args['section_id'] ) : $instance_id;
        $heading_tag = in_array( strtolower( $args['heading_tag'] ), array( 'h1', 'h2', 'h3', 'h4' ), true ) ? strtolower( $args['heading_tag'] ) : 'h2';

        $spacing_class = 'bef-spacing-md';
        if ( 'none' === $args['spacing'] ) {
            $spacing_class = 'bef-spacing-none';
        } elseif ( 'sm' === $args['spacing'] ) {
            $spacing_class = 'bef-spacing-sm';
        } elseif ( 'lg' === $args['spacing'] ) {
            $spacing_class = 'bef-spacing-lg';
        }

        $payload = array(
            'events' => $events,
            'labels' => array(
                'today'        => __( 'Today', 'bef-calendar' ),
                'noEvents'     => __( 'No events on this day.', 'bef-calendar' ),
                'viewEvent'    => __( 'View Event', 'bef-calendar' ),
                'monthNames'   => array_values( $this->get_month_names() ),
                'dayNames'     => array_values( $this->get_day_names() ),
                'upcoming'     => __( 'Selected Date', 'bef-calendar' ),
                'selectedDate' => __( 'Selected Date', 'bef-calendar' ),
                'monthView'   => __( 'Month', 'bef-calendar' ),
                'agendaView'  => __( 'Agenda', 'bef-calendar' ),
                'agendaEmpty' => __( 'No events available right now.', 'bef-calendar' ),
                'agendaLabel' => __( 'Agenda View', 'bef-calendar' ),
            ),
            'settings' => array(
                'showSidebar'    => (bool) $args['show_sidebar'],
                'showViewToggle' => (bool) $args['show_view_toggle'],
                'view'           => sanitize_key( $args['view'] ),
            ),
        );

        $style = $args['background_image'] ? ' style="--bef-bg-image:url(' . esc_url( $args['background_image'] ) . ');"' : '';

        ob_start();
        ?>
        <section id="<?php echo esc_attr( $section_id ); ?>" class="bef-calendar-section <?php echo esc_attr( $spacing_class . ' ' . $args['classes'] ); ?>"<?php echo $style; ?>>
            <div class="bef-calendar-wrap" data-instance-id="<?php echo esc_attr( $instance_id ); ?>" data-view="<?php echo esc_attr( $args['view'] ); ?>" data-show-sidebar="<?php echo esc_attr( $args['show_sidebar'] ? '1' : '0' ); ?>">
                <script type="application/json" class="bef-calendar-data"><?php echo wp_json_encode( $payload ); ?></script>
                <div class="bef-calendar-shell <?php echo $args['background_image'] ? 'has-background' : ''; ?>">
                    <?php if ( $args['eyebrow'] || $args['title'] || $args['content'] || ( $args['button_text'] && $args['button_url'] ) ) : ?>
                        <div class="bef-calendar-intro">
                            <?php if ( $args['eyebrow'] ) : ?>
                                <p class="bef-calendar-kicker"><?php echo esc_html( $args['eyebrow'] ); ?></p>
                            <?php endif; ?>

                            <<?php echo esc_html( $heading_tag ); ?> class="bef-calendar-title"><?php echo esc_html( $args['title'] ); ?></<?php echo esc_html( $heading_tag ); ?>>

                            <?php if ( $args['content'] ) : ?>
                                <div class="bef-calendar-content"><?php echo wp_kses_post( wpautop( $args['content'] ) ); ?></div>
                            <?php endif; ?>

                            <?php if ( $args['button_text'] && $args['button_url'] ) : ?>
                                <div class="bef-calendar-actions">
                                    <a class="bef-calendar-button" href="<?php echo esc_url( $args['button_url'] ); ?>"<?php echo $args['button_target'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
                                        <?php echo esc_html( $args['button_text'] ); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="bef-calendar-header">
                        <div>
                            <p class="bef-calendar-mini-label"><?php esc_html_e( 'Plan your next match day', 'bef-calendar' ); ?></p>
                        </div>
                        <div class="bef-calendar-controls">
                            <?php if ( $args['show_view_toggle'] ) : ?>
                                <div class="bef-view-toggle" role="tablist" aria-label="<?php esc_attr_e( 'Calendar view', 'bef-calendar' ); ?>">
                                    <button type="button" class="bef-view-button" data-bef-view="month"><?php esc_html_e( 'Month', 'bef-calendar' ); ?></button>
                                    <button type="button" class="bef-view-button" data-bef-view="agenda"><?php esc_html_e( 'Agenda', 'bef-calendar' ); ?></button>
                                </div>
                            <?php endif; ?>

                            <div class="bef-calendar-nav">
                                <button type="button" class="bef-nav-button" data-bef-nav="prev" aria-label="<?php esc_attr_e( 'Previous month', 'bef-calendar' ); ?>">&larr;</button>
                                <button type="button" class="bef-today-button" data-bef-nav="today"><?php esc_html_e( 'Today', 'bef-calendar' ); ?></button>
                                <button type="button" class="bef-nav-button" data-bef-nav="next" aria-label="<?php esc_attr_e( 'Next month', 'bef-calendar' ); ?>">&rarr;</button>
                            </div>
                        </div>
                    </div>

                    <div class="bef-view-panel bef-view-panel--month">
                        <div class="bef-calendar-grid-wrap <?php echo $args['show_sidebar'] ? 'has-sidebar' : 'no-sidebar'; ?>">
                            <div class="bef-calendar-grid-panel">
                                <div class="bef-calendar-month" aria-live="polite"></div>
                                <div class="bef-calendar-weekdays"></div>
                                <div class="bef-calendar-grid"></div>
                            </div>

                            <?php if ( $args['show_sidebar'] ) : ?>
                                <aside class="bef-calendar-sidebar">
                                    <div class="bef-sidebar-head">
                                        <span class="bef-sidebar-label"><?php esc_html_e( 'Selected Date', 'bef-calendar' ); ?></span>
                                        <h3 class="bef-selected-date"></h3>
                                    </div>
                                    <div class="bef-event-list"></div>
                                </aside>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="bef-view-panel bef-view-panel--agenda" hidden>
                        <div class="bef-agenda-panel">
                            <div class="bef-agenda-list"></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <?php

        return ob_get_clean();
    }

    /**
     * Get event data for the frontend app.
     *
     * @param bool $include_past    Whether to include past events.
     * @param bool $show_wordpress  Whether to include local WordPress events.
     * @param bool $show_eventbrite Whether to include Eventbrite events.
     * @return array
     */
    private function get_events_for_frontend( $include_past = true, $show_wordpress = true, $show_eventbrite = false ) {
        $events = array();

        if ( $show_wordpress ) {
            $events = array_merge( $events, $this->get_wordpress_events( $include_past ) );
        }

        if ( $show_eventbrite ) {
            $events = array_merge( $events, $this->get_eventbrite_events( $include_past ) );
        }

        usort(
            $events,
            static function ( $a, $b ) {
                $a_key = ( $a['date'] ?? '' ) . ' ' . ( $a['startTime'] ?? '23:59' );
                $b_key = ( $b['date'] ?? '' ) . ' ' . ( $b['startTime'] ?? '23:59' );
                return strcmp( $a_key, $b_key );
            }
        );

        return $events;
    }

    /**
     * Fetch local WordPress events.
     *
     * @param bool $include_past Whether to include past events.
     * @return array
     */
    private function get_wordpress_events( $include_past = true ) {
        $range_start = $include_past ? $this->add_days_to_event_date( current_time( 'Y-m-d' ), -365 ) : current_time( 'Y-m-d' );
        $range_end   = $this->add_days_to_event_date( current_time( 'Y-m-d' ), 540 );

        $query = new WP_Query(
            array(
                'post_type'      => self::POST_TYPE,
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'orderby'        => 'date',
                'order'          => 'ASC',
                'no_found_rows'  => true,
            )
        );

        $events = array();

        if ( $query->have_posts() ) {
            foreach ( $query->posts as $post ) {
                $post_id      = $post->ID;
                $source_value = get_post_meta( $post_id, self::META_SOURCE, true );

                if ( 'eventbrite' === $source_value ) {
                    continue;
                }

                $occurrences        = $this->get_event_occurrences( $post_id, $range_start, $range_end, 180 );
                $start_time         = get_post_meta( $post_id, self::META_START_TIME, true );
                $end_time           = get_post_meta( $post_id, self::META_END_TIME, true );
                $location           = get_post_meta( $post_id, self::META_LOCATION, true );
                $event_url          = get_post_meta( $post_id, self::META_URL, true );
                $ticket_url         = get_post_meta( $post_id, self::META_TICKET_URL, true );
                $ticket_label       = get_post_meta( $post_id, self::META_TICKET_LABEL, true );
                $source_label       = $this->get_post_source_label( $post_id );
                $remote_image       = get_post_meta( $post_id, self::META_REMOTE_IMAGE_URL, true );
                $recurrence_summary = $this->get_event_recurrence_summary( $post_id );

                $excerpt = get_the_excerpt( $post_id );
                if ( ! $excerpt ) {
                    $excerpt = wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ), 24 );
                }

                foreach ( $occurrences as $occurrence ) {
                    $events[] = array(
                        'id'                => $post_id . ':' . $occurrence['date'],
                        'postId'            => $post_id,
                        'title'             => get_the_title( $post_id ),
                        'date'              => $occurrence['date'],
                        'endDate'           => $occurrence['end_date'],
                        'startTime'         => $start_time,
                        'endTime'           => $end_time,
                        'location'          => $location,
                        'url'               => get_permalink( $post_id ),
                        'externalUrl'       => $event_url,
                        'ticketUrl'         => $ticket_url,
                        'ticketLabel'       => $ticket_label ? $ticket_label : __( 'Purchase Tickets', 'bef-calendar' ),
                        'excerpt'           => $excerpt,
                        'thumbnail'         => get_the_post_thumbnail_url( $post_id, 'medium' ) ? get_the_post_thumbnail_url( $post_id, 'medium' ) : $remote_image,
                        'categories'        => wp_get_post_terms( $post_id, self::TAXONOMY, array( 'fields' => 'names' ) ),
                        'source'            => 'wordpress',
                        'sourceLabel'       => $source_label,
                        'linkLabel'         => __( 'View Event', 'bef-calendar' ),
                        'recurrenceSummary' => $recurrence_summary,
                    );
                }
            }
            wp_reset_postdata();
        }

        return $events;
    }

    /**
     * Fetch Eventbrite events.
     *
     * @param bool $include_past Whether to include past events.
     * @return array
     */
    private function get_eventbrite_events( $include_past = true ) {
        $settings = $this->get_eventbrite_settings();

        if ( empty( $settings['enabled'] ) || empty( $settings['private_token'] ) ) {
            return array();
        }

        $cache_key = self::TRANSIENT_EVENTS . ( $include_past ? '1' : '0' );
        $cached    = get_transient( $cache_key );

        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $organization_id = $this->get_eventbrite_organization_id( true );
        if ( ! $organization_id ) {
            return array();
        }

        $events = array();
        $page   = 1;
        $limit  = 10;

        while ( $page <= $limit ) {
            $url = add_query_arg(
                array(
                    'status'   => 'live,started,ended,completed',
                    'expand'   => 'venue,logo',
                    'order_by' => 'start_asc',
                    'page'     => $page,
                ),
                'https://www.eventbriteapi.com/v3/organizations/' . rawurlencode( $organization_id ) . '/events/'
            );

            $response = wp_remote_get(
                $url,
                array(
                    'timeout' => 20,
                    'headers' => array(
                        'Authorization' => 'Bearer ' . trim( $settings['private_token'] ),
                    ),
                )
            );

            if ( is_wp_error( $response ) ) {
                break;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( 200 !== $code ) {
                break;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( empty( $body['events'] ) || ! is_array( $body['events'] ) ) {
                break;
            }

            foreach ( $body['events'] as $event ) {
                $post_id = $this->import_eventbrite_event( $event );

                if ( is_wp_error( $post_id ) || ! $post_id ) {
                    continue;
                }

                $mapped = $this->map_imported_eventbrite_post( $post_id, $event );
                if ( empty( $mapped ) ) {
                    continue;
                }

                if ( ! $include_past && $this->is_past_event( $mapped ) ) {
                    continue;
                }

                $events[] = $mapped;
            }

            if ( empty( $body['pagination']['has_more_items'] ) ) {
                break;
            }

            $page++;
        }

        set_transient( $cache_key, $events, absint( $settings['cache_minutes'] ) * MINUTE_IN_SECONDS );

        return $events;
    }

    /**
     * Fetch detailed Eventbrite event data for richer single pages.
     *
     * @param string $event_id Eventbrite event ID.
     * @return array
     */
    private function get_eventbrite_event_details( $event_id ) {
        $event_id = sanitize_text_field( (string) $event_id );

        if ( '' === $event_id ) {
            return array();
        }

        $settings = $this->get_eventbrite_settings();

        if ( empty( $settings['enabled'] ) || empty( $settings['private_token'] ) ) {
            return array();
        }

        $url = add_query_arg(
            array(
                'expand' => 'venue,logo,organizer,ticket_classes,category,subcategory,format',
            ),
            'https://www.eventbriteapi.com/v3/events/' . rawurlencode( $event_id ) . '/'
        );

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . trim( $settings['private_token'] ),
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return array();
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        if ( 200 !== $code ) {
            return array();
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return is_array( $body ) ? $body : array();
    }

    /**
     * Determine if event is past.
     *
     * @param array $event Event array.
     * @return bool
     */
    private function is_past_event( $event ) {
        $today    = current_time( 'Y-m-d' );
        $end_date = ! empty( $event['endDate'] ) ? $event['endDate'] : ( $event['date'] ?? '' );

        if ( ! $end_date ) {
            return false;
        }

        return $end_date < $today;
    }

    /**
     * Import or update a single Eventbrite event as a local BEF event post.
     *
     * @param array $event Eventbrite event payload.
     * @return int|WP_Error
     */
    private function import_eventbrite_event( $event ) {
        $remote_id = isset( $event['id'] ) ? sanitize_text_field( (string) $event['id'] ) : '';

        if ( '' === $remote_id ) {
            return new WP_Error( 'bef_eventbrite_missing_id', __( 'An Eventbrite event was missing an ID.', 'bef-calendar' ) );
        }

        $event_details     = $this->get_eventbrite_event_details( $remote_id );
        if ( ! empty( $event_details ) ) {
            $event = array_replace_recursive( $event, $event_details );
        }

        $existing_post_id = $this->find_imported_event_post_id( 'eventbrite', $remote_id );
        $mapped           = $this->map_eventbrite_event( $event );

        if ( empty( $mapped['date'] ) ) {
            return 0;
        }

        $postarr = array(
            'post_type'    => self::POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => $mapped['title'],
            'post_content' => $this->get_eventbrite_event_content( $event ),
            'post_excerpt' => $mapped['excerpt'],
        );

        if ( $existing_post_id ) {
            $postarr['ID'] = $existing_post_id;
            $post_id       = wp_update_post( $postarr, true );
        } else {
            $post_id = wp_insert_post( $postarr, true );
        }

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        update_post_meta( $post_id, self::META_DATE, $mapped['date'] );
        update_post_meta( $post_id, self::META_END_DATE, $mapped['endDate'] );
        update_post_meta( $post_id, self::META_START_TIME, $this->extract_api_local_time( $event['start']['local'] ?? '' ) );
        update_post_meta( $post_id, self::META_END_TIME, $this->extract_api_local_time( $event['end']['local'] ?? '' ) );
        update_post_meta( $post_id, self::META_LOCATION, $mapped['location'] );
        update_post_meta( $post_id, self::META_URL, $mapped['externalUrl'] );
        update_post_meta( $post_id, self::META_TICKET_URL, $mapped['ticketUrl'] );
        update_post_meta( $post_id, self::META_TICKET_LABEL, $mapped['ticketLabel'] );
        update_post_meta( $post_id, self::META_RECURRENCE_FREQUENCY, 'none' );
        update_post_meta( $post_id, self::META_RECURRENCE_INTERVAL, 1 );
        update_post_meta( $post_id, self::META_RECURRENCE_UNTIL, '' );
        update_post_meta( $post_id, self::META_SOURCE, 'eventbrite' );
        update_post_meta( $post_id, self::META_REMOTE_SOURCE, 'eventbrite' );
        update_post_meta( $post_id, self::META_REMOTE_ID, $remote_id );
        update_post_meta( $post_id, self::META_REMOTE_MODIFIED, sanitize_text_field( $event['changed'] ?? '' ) );
        update_post_meta( $post_id, self::META_REMOTE_IMAGE_URL, $mapped['thumbnail'] );
        update_post_meta( $post_id, self::META_EVENTBRITE_SUMMARY, ! empty( $event['summary'] ) ? sanitize_text_field( $event['summary'] ) : '' );
        update_post_meta( $post_id, self::META_EVENTBRITE_ORGANIZER, ! empty( $event['organizer']['name'] ) ? sanitize_text_field( $event['organizer']['name'] ) : '' );
        update_post_meta( $post_id, self::META_EVENTBRITE_VENUE_ADDRESS, ! empty( $event['venue']['address']['localized_address_display'] ) ? sanitize_text_field( $event['venue']['address']['localized_address_display'] ) : '' );

        return (int) $post_id;
    }

    /**
     * Map an imported Eventbrite post into calendar event data.
     *
     * @param int   $post_id Local post ID.
     * @param array $event   Optional raw Eventbrite payload.
     * @return array
     */
    private function map_imported_eventbrite_post( $post_id, $event = array() ) {
        $event_date  = get_post_meta( $post_id, self::META_DATE, true );
        $event_end   = get_post_meta( $post_id, self::META_END_DATE, true );
        $start_time  = get_post_meta( $post_id, self::META_START_TIME, true );
        $end_time    = get_post_meta( $post_id, self::META_END_TIME, true );
        $location    = get_post_meta( $post_id, self::META_LOCATION, true );
        $external    = get_post_meta( $post_id, self::META_URL, true );
        $ticket_url  = get_post_meta( $post_id, self::META_TICKET_URL, true );
        $ticket_label = get_post_meta( $post_id, self::META_TICKET_LABEL, true );
        $thumbnail   = get_post_meta( $post_id, self::META_REMOTE_IMAGE_URL, true );
        $excerpt     = has_excerpt( $post_id ) ? get_the_excerpt( $post_id ) : '';

        if ( ! $excerpt && ! empty( $event['summary'] ) ) {
            $excerpt = sanitize_text_field( $event['summary'] );
        }

        if ( ! $event_date ) {
            return array();
        }

        return array(
            'id'          => 'eventbrite-' . $post_id,
            'postId'      => $post_id,
            'title'       => get_the_title( $post_id ),
            'date'        => $event_date,
            'endDate'     => $event_end,
            'startTime'   => $start_time ? date_i18n( get_option( 'time_format' ), strtotime( $start_time ) ) : '',
            'endTime'     => $end_time ? date_i18n( get_option( 'time_format' ), strtotime( $end_time ) ) : '',
            'location'    => $location,
            'url'         => get_permalink( $post_id ),
            'externalUrl' => $external,
            'ticketUrl'   => $ticket_url,
            'ticketLabel' => $ticket_label ? $ticket_label : __( 'Get Tickets', 'bef-calendar' ),
            'excerpt'     => $excerpt,
            'thumbnail'   => $thumbnail,
            'categories'  => wp_get_post_terms( $post_id, self::TAXONOMY, array( 'fields' => 'names' ) ),
            'source'      => 'eventbrite',
            'sourceLabel' => __( 'Eventbrite', 'bef-calendar' ),
            'linkLabel'   => __( 'View Event', 'bef-calendar' ),
        );
    }

    /**
     * Map an Eventbrite event response into calendar data.
     *
     * @param array $event Eventbrite event payload.
     * @return array
     */
    private function map_eventbrite_event( $event ) {
        $start_local = $event['start']['local'] ?? '';
        $end_local   = $event['end']['local'] ?? '';
        $date        = $start_local ? substr( $start_local, 0, 10 ) : '';
        $end_date    = $end_local ? substr( $end_local, 0, 10 ) : '';

        if ( ! $date ) {
            return array();
        }

        $location_parts = array();
        if ( ! empty( $event['online_event'] ) ) {
            $location_parts[] = __( 'Online Event', 'bef-calendar' );
        }
        if ( ! empty( $event['venue']['name'] ) ) {
            $location_parts[] = $event['venue']['name'];
        }
        if ( ! empty( $event['venue']['address']['localized_address_display'] ) ) {
            $location_parts[] = $event['venue']['address']['localized_address_display'];
        }

        $thumbnail = '';
        if ( ! empty( $event['logo']['url'] ) ) {
            $thumbnail = $event['logo']['url'];
        } elseif ( ! empty( $event['logo']['original']['url'] ) ) {
            $thumbnail = $event['logo']['original']['url'];
        }

        return array(
            'id'          => 'eventbrite-' . ( $event['id'] ?? wp_generate_uuid4() ),
            'title'       => $event['name']['text'] ?? __( 'Eventbrite Event', 'bef-calendar' ),
            'date'        => $date,
            'endDate'     => $end_date && $end_date !== $date ? $end_date : '',
            'startTime'   => $this->format_api_time_for_display( $start_local ),
            'endTime'     => $this->format_api_time_for_display( $end_local ),
            'location'    => implode( ' • ', array_filter( $location_parts ) ),
            'url'         => ! empty( $event['url'] ) ? esc_url_raw( $event['url'] ) : '',
            'externalUrl' => ! empty( $event['url'] ) ? esc_url_raw( $event['url'] ) : '',
            'ticketUrl'   => ! empty( $event['url'] ) ? esc_url_raw( $event['url'] ) : '',
            'ticketLabel' => __( 'Get Tickets', 'bef-calendar' ),
            'excerpt'     => ! empty( $event['summary'] ) ? sanitize_text_field( $event['summary'] ) : '',
            'thumbnail'   => $thumbnail,
            'source'      => 'eventbrite',
            'sourceLabel' => __( 'Eventbrite', 'bef-calendar' ),
            'linkLabel'   => __( 'View on Eventbrite', 'bef-calendar' ),
        );
    }

    /**
     * Get Eventbrite event content.
     *
     * @param array $event Eventbrite payload.
     * @return string
     */
    private function get_eventbrite_event_content( $event ) {
        if ( ! empty( $event['description']['html'] ) ) {
            return wp_kses_post( $event['description']['html'] );
        }

        if ( ! empty( $event['description']['text'] ) ) {
            return wpautop( sanitize_textarea_field( $event['description']['text'] ) );
        }

        return '';
    }

    /**
     * Extract an API time into local event storage format.
     *
     * @param string $datetime Datetime string.
     * @return string
     */
    private function extract_api_local_time( $datetime ) {
        if ( ! $datetime ) {
            return '';
        }

        $datetime = trim( (string) $datetime );
        $timezone = wp_timezone();

        if ( preg_match( '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}$/', $datetime ) ) {
            return substr( $datetime, 11, 8 );
        }

        $parsed = date_create_immutable( $datetime, $timezone );
        if ( false === $parsed ) {
            return '';
        }

        return $parsed->setTimezone( $timezone )->format( 'H:i:s' );
    }

    /**
     * Format API datetime to site time.
     *
     * @param string $datetime Datetime string.
     * @return string
     */
    private function format_api_time_for_display( $datetime ) {
        if ( ! $datetime ) {
            return '';
        }

        $datetime = trim( (string) $datetime );
        $timezone = wp_timezone();

        // Eventbrite's `local` values are already in the event's local clock time and
        // usually do not include a timezone offset. Treat those as local times directly
        // so we do not apply the site timezone a second time.
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $datetime ) ) {
            $local = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:s', $datetime, $timezone );

            if ( $local instanceof \DateTimeImmutable ) {
                return wp_date( get_option( 'time_format' ), $local->getTimestamp(), $timezone );
            }
        }

        $parsed = date_create_immutable( $datetime, $timezone );
        if ( false === $parsed ) {
            return '';
        }

        return wp_date( get_option( 'time_format' ), $parsed->setTimezone( $timezone )->getTimestamp(), $timezone );
    }

    /**
     * Resolve Eventbrite organisation ID.
     *
     * @param bool $allow_remote Whether remote lookup is allowed.
     * @return string
     */
    private function get_eventbrite_organization_id( $allow_remote = true ) {
        $settings = $this->get_eventbrite_settings();

        if ( ! empty( $settings['organization_id'] ) ) {
            return (string) $settings['organization_id'];
        }

        $cached = get_transient( self::TRANSIENT_ORG_ID );
        if ( $cached ) {
            return (string) $cached;
        }

        if ( ! $allow_remote || empty( $settings['private_token'] ) ) {
            return '';
        }

        $response = wp_remote_get(
            'https://www.eventbriteapi.com/v3/users/me/organizations/',
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . trim( $settings['private_token'] ),
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return '';
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $org  = ! empty( $body['organizations'][0]['id'] ) ? (string) $body['organizations'][0]['id'] : '';

        if ( $org ) {
            set_transient( self::TRANSIENT_ORG_ID, $org, DAY_IN_SECONDS );
        }

        return $org;
    }

    /**
     * Month labels.
     *
     * @return array
     */
    private function get_month_names() {
        return array(
            __( 'January', 'bef-calendar' ),
            __( 'February', 'bef-calendar' ),
            __( 'March', 'bef-calendar' ),
            __( 'April', 'bef-calendar' ),
            __( 'May', 'bef-calendar' ),
            __( 'June', 'bef-calendar' ),
            __( 'July', 'bef-calendar' ),
            __( 'August', 'bef-calendar' ),
            __( 'September', 'bef-calendar' ),
            __( 'October', 'bef-calendar' ),
            __( 'November', 'bef-calendar' ),
            __( 'December', 'bef-calendar' ),
        );
    }

    /**
     * Day labels.
     *
     * @return array
     */
    private function get_day_names() {
        return array(
            __( 'Mon', 'bef-calendar' ),
            __( 'Tue', 'bef-calendar' ),
            __( 'Wed', 'bef-calendar' ),
            __( 'Thu', 'bef-calendar' ),
            __( 'Fri', 'bef-calendar' ),
            __( 'Sat', 'bef-calendar' ),
            __( 'Sun', 'bef-calendar' ),
        );
    }

    /**
     * Add admin columns.
     *
     * @param array $columns Existing columns.
     * @return array
     */
    public function admin_columns( $columns ) {
        $new_columns = array();

        foreach ( $columns as $key => $label ) {
            $new_columns[ $key ] = $label;
            if ( 'title' === $key ) {
                $new_columns['bef_event_date'] = __( 'Event Date', 'bef-calendar' );
                $new_columns['bef_location']   = __( 'Location', 'bef-calendar' );
                $new_columns['bef_source']     = __( 'Source', 'bef-calendar' );
            }
        }

        return $new_columns;
    }

    /**
     * Render admin custom columns.
     *
     * @param string $column  Column key.
     * @param int    $post_id Post ID.
     */
    public function render_admin_columns( $column, $post_id ) {
        if ( 'bef_event_date' === $column ) {
            $date     = get_post_meta( $post_id, self::META_DATE, true );
            $end_date = get_post_meta( $post_id, self::META_END_DATE, true );
            $start    = get_post_meta( $post_id, self::META_START_TIME, true );
            $end      = get_post_meta( $post_id, self::META_END_TIME, true );

            if ( $date ) {
                echo esc_html( $this->format_display_date( $date ) );
                if ( $end_date ) {
                    echo ' &rarr; ' . esc_html( $this->format_display_date( $end_date ) );
                }
                if ( $start ) {
                    echo '<br><small>' . esc_html( $this->format_display_time_range( $start, $end ) ) . '</small>';
                }
            } else {
                echo '&mdash;';
            }
        }

        if ( 'bef_location' === $column ) {
            $location = get_post_meta( $post_id, self::META_LOCATION, true );
            echo $location ? esc_html( $location ) : '&mdash;';
        }

        if ( 'bef_source' === $column ) {
            echo esc_html( $this->get_post_source_label( $post_id ) );
        }
    }

    /**
     * Make event date sortable.
     *
     * @param array $columns Sortable columns.
     * @return array
     */
    public function sortable_columns( $columns ) {
        $columns['bef_event_date'] = 'bef_event_date';
        return $columns;
    }

    /**
     * Sort admin listing by event date.
     *
     * @param WP_Query $query Main query.
     */
    public function admin_orderby( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        if ( self::POST_TYPE !== $query->get( 'post_type' ) ) {
            return;
        }

        if ( 'bef_event_date' === $query->get( 'orderby' ) ) {
            $query->set( 'meta_key', self::META_DATE );
            $query->set( 'orderby', 'meta_value' );
        }

        if ( ! $query->get( 'orderby' ) ) {
            $query->set( 'meta_key', self::META_DATE );
            $query->set( 'orderby', 'meta_value' );
            $query->set( 'order', 'ASC' );
        }
    }

    /**
     * Format a stored date.
     *
     * @param string $date Stored date.
     * @return string
     */
    private function format_display_date( $date ) {
        $timestamp = strtotime( $date );

        if ( ! $timestamp ) {
            return $date;
        }

        return wp_date( get_option( 'date_format' ), $timestamp );
    }

    /**
     * Format a time range.
     *
     * @param string $start Start time.
     * @param string $end   End time.
     * @return string
     */
    private function format_display_time_range( $start, $end ) {
        $start_text = $start ? date_i18n( get_option( 'time_format' ), strtotime( $start ) ) : '';
        $end_text   = $end ? date_i18n( get_option( 'time_format' ), strtotime( $end ) ) : '';

        if ( $start_text && $end_text ) {
            return $start_text . ' - ' . $end_text;
        }

        return $start_text ? $start_text : $end_text;
    }

    /**
     * Default Google Sheets sync settings.
     *
     * @return array
     */
    private function get_default_google_sheets_settings() {
        return array(
            'enabled'              => 0,
            'spreadsheet_id'       => '',
            'sheet_range'          => 'Events!A:Z',
            'service_account_json' => '',
            'auto_sync'            => 1,
            'sync_interval'        => 'bef_every_fifteen_minutes',
            'ready_column'         => 'Ready',
            'status_column'        => 'Status',
            'import_categories'    => 1,
            'default_post_status'  => 'publish',
        );
    }

    /**
     * Get stored Google Sheets settings merged with defaults.
     *
     * @return array
     */
    private function get_google_sheets_settings() {
        $settings = get_option( self::OPTION_GOOGLE_SHEETS, array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        return wp_parse_args( $settings, $this->get_default_google_sheets_settings() );
    }

    /**
     * Get Google Sheets sync state.
     *
     * @return array
     */
    private function get_google_sheets_state() {
        $state = get_option( self::OPTION_GOOGLE_SHEETS_STATE, array() );
        if ( ! is_array( $state ) ) {
            $state = array();
        }

        return wp_parse_args(
            $state,
            array(
                'last_sync'   => '',
                'last_status' => '',
                'message'     => '',
                'stats'       => array(),
            )
        );
    }

    /**
     * Persist Google Sheets sync state.
     *
     * @param array $state Sync state.
     * @return void
     */
    private function update_google_sheets_state( $state ) {
        update_option( self::OPTION_GOOGLE_SHEETS_STATE, $state, false );
    }

    /**
     * Sanitize Google Sheets sync settings.
     *
     * @param array $input Raw settings.
     * @return array
     */
    public function sanitize_google_sheets_settings( $input ) {
        $defaults = $this->get_default_google_sheets_settings();
        $input    = is_array( $input ) ? $input : array();
        $interval = isset( $input['sync_interval'] ) ? sanitize_key( wp_unslash( $input['sync_interval'] ) ) : $defaults['sync_interval'];
        $allowed  = array( 'bef_every_fifteen_minutes', 'hourly', 'twicedaily', 'daily' );
        $status   = isset( $input['default_post_status'] ) ? sanitize_key( wp_unslash( $input['default_post_status'] ) ) : $defaults['default_post_status'];

        if ( ! in_array( $interval, $allowed, true ) ) {
            $interval = $defaults['sync_interval'];
        }

        if ( ! in_array( $status, array( 'publish', 'draft' ), true ) ) {
            $status = $defaults['default_post_status'];
        }

        $settings = array(
            'enabled'              => ! empty( $input['enabled'] ) ? 1 : 0,
            'spreadsheet_id'       => isset( $input['spreadsheet_id'] ) ? sanitize_text_field( wp_unslash( $input['spreadsheet_id'] ) ) : '',
            'sheet_range'          => isset( $input['sheet_range'] ) ? sanitize_text_field( wp_unslash( $input['sheet_range'] ) ) : $defaults['sheet_range'],
            'service_account_json' => isset( $input['service_account_json'] ) ? trim( (string) wp_unslash( $input['service_account_json'] ) ) : '',
            'auto_sync'            => ! empty( $input['auto_sync'] ) ? 1 : 0,
            'sync_interval'        => $interval,
            'ready_column'         => isset( $input['ready_column'] ) ? sanitize_text_field( wp_unslash( $input['ready_column'] ) ) : $defaults['ready_column'],
            'status_column'        => isset( $input['status_column'] ) ? sanitize_text_field( wp_unslash( $input['status_column'] ) ) : $defaults['status_column'],
            'import_categories'    => ! empty( $input['import_categories'] ) ? 1 : 0,
            'default_post_status'  => $status,
        );

        $this->schedule_google_sheets_sync( $settings );

        return $settings;
    }

    /**
     * Schedule or clear the Google Sheets sync task.
     *
     * @param array|null $settings Optional settings override.
     * @return void
     */
    public function schedule_google_sheets_sync( $settings = null ) {
        if ( null === $settings ) {
            $settings = $this->get_google_sheets_settings();
        }

        wp_clear_scheduled_hook( self::CRON_HOOK_GOOGLE_SHEETS );

        if ( empty( $settings['enabled'] ) || empty( $settings['auto_sync'] ) || empty( $settings['spreadsheet_id'] ) || empty( $settings['sheet_range'] ) || empty( $settings['service_account_json'] ) ) {
            return;
        }

        $interval = ! empty( $settings['sync_interval'] ) ? $settings['sync_interval'] : 'bef_every_fifteen_minutes';

        if ( ! wp_next_scheduled( self::CRON_HOOK_GOOGLE_SHEETS ) ) {
            wp_schedule_event( time() + 300, $interval, self::CRON_HOOK_GOOGLE_SHEETS );
        }
    }

    /**
     * Render the Google Sheets sync settings page.
     *
     * @return void
     */
    public function render_google_sheets_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings  = $this->get_google_sheets_settings();
        $state     = $this->get_google_sheets_state();
        $sync_url  = wp_nonce_url(
            admin_url( 'admin-post.php?action=bef_calendar_sync_google_sheets' ),
            'bef_calendar_sync_google_sheets'
        );
        $intervals = array(
            'bef_every_fifteen_minutes' => __( 'Every 15 minutes', 'bef-calendar' ),
            'hourly'                    => __( 'Hourly', 'bef-calendar' ),
            'twicedaily'                => __( 'Twice daily', 'bef-calendar' ),
            'daily'                     => __( 'Daily', 'bef-calendar' ),
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'BEF Calendar Google Sheets Sync', 'bef-calendar' ); ?></h1>
            <p><?php esc_html_e( 'Let staff manage events in Google Sheets, then import rows into BEF Events only when the Ready checkbox is ticked. The service account email shown below needs edit access to the sheet so the plugin can read rows and write sync status back into the sheet.', 'bef-calendar' ); ?></p>

            <?php if ( isset( $_GET['bef_google_sheets_synced'] ) ) : ?>
                <?php $state_notice = $this->get_google_sheets_state(); ?>
                <div class="notice notice-<?php echo 'success' === $state_notice['last_status'] ? 'success' : 'warning'; ?> is-dismissible"><p><?php echo esc_html( $state_notice['message'] ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'bef_calendar_google_sheets' ); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Enable Google Sheets Sync', 'bef-calendar' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_GOOGLE_SHEETS ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                                    <?php esc_html_e( 'Import ready rows from Google Sheets into BEF Events', 'bef-calendar' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bef_gs_spreadsheet_id"><?php esc_html_e( 'Spreadsheet ID', 'bef-calendar' ); ?></label></th>
                            <td>
                                <input type="text" class="regular-text code" id="bef_gs_spreadsheet_id" name="<?php echo esc_attr( self::OPTION_GOOGLE_SHEETS ); ?>[spreadsheet_id]" value="<?php echo esc_attr( $settings['spreadsheet_id'] ); ?>">
                                <p class="description"><?php esc_html_e( 'Use the long ID from the Google Sheets URL.', 'bef-calendar' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bef_gs_sheet_range"><?php esc_html_e( 'Sheet Range', 'bef-calendar' ); ?></label></th>
                            <td>
                                <input type="text" class="regular-text code" id="bef_gs_sheet_range" name="<?php echo esc_attr( self::OPTION_GOOGLE_SHEETS ); ?>[sheet_range]" value="<?php echo esc_attr( $settings['sheet_range'] ); ?>" placeholder="Events!A:Z">
                                <p class="description"><?php esc_html_e( 'Include the tab name. The first row in this range is treated as the header row.', 'bef-calendar' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bef_gs_service_account_json"><?php esc_html_e( 'Service Account JSON', 'bef-calendar' ); ?></label></th>
                            <td>
                                <textarea class="large-text code" rows="12" id="bef_gs_service_account_json" name="<?php echo esc_attr( self::OPTION_GOOGLE_SHEETS ); ?>[service_account_json]"><?php echo esc_textarea( $settings['service_account_json'] ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Paste the full Google service account JSON key here. Then share the sheet with the service account email address as an editor.', 'bef-calendar' ); ?></p>
                            </td>
                        </tr>
                        <?php
                        $service_account_email = '';
                        $decoded_credentials   = ! empty( $settings['service_account_json'] ) ? json_decode( $settings['service_account_json'], true ) : array();
                        if ( is_array( $decoded_credentials ) && ! empty( $decoded_credentials['client_email'] ) ) {
                            $service_account_email = $decoded_credentials['client_email'];
                        }
                        ?>
                        <?php if ( $service_account_email ) : ?>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Service Account Email', 'bef-calendar' ); ?></th>
                                <td>
                                    <code><?php echo esc_html( $service_account_email ); ?></code>
                                    <p class="description"><?php esc_html_e( 'Share the sheet with this email address so the plugin can read and update the rows.', 'bef-calendar' ); ?></p>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Automatic Sync', 'bef-calendar' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_GOOGLE_SHEETS ); ?>[auto_sync]" value="1" <?php checked( ! empty( $settings['auto_sync'] ) ); ?>>
                                    <?php esc_html_e( 'Regularly check the sheet and import ready rows', 'bef-calendar' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bef_gs_sync_interval"><?php esc_html_e( 'Sync Frequency', 'bef-calendar' ); ?></label></th>
                            <td>
                                <select id="bef_gs_sync_interval" name="<?php echo esc_attr( self::OPTION_GOOGLE_SHEETS ); ?>[sync_interval]">
                                    <?php foreach ( $intervals as $value => $label ) : ?>
                                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['sync_interval'], $value ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bef_gs_ready_column"><?php esc_html_e( 'Ready Checkbox Column', 'bef-calendar' ); ?></label></th>
                            <td>
                                <input type="text" class="regular-text" id="bef_gs_ready_column" name="<?php echo esc_attr( self::OPTION_GOOGLE_SHEETS ); ?>[ready_column]" value="<?php echo esc_attr( $settings['ready_column'] ); ?>">
                                <p class="description"><?php esc_html_e( 'Only rows where this checkbox column is TRUE will be imported or updated.', 'bef-calendar' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bef_gs_status_column"><?php esc_html_e( 'Status Column Label', 'bef-calendar' ); ?></label></th>
                            <td>
                                <input type="text" class="regular-text" id="bef_gs_status_column" name="<?php echo esc_attr( self::OPTION_GOOGLE_SHEETS ); ?>[status_column]" value="<?php echo esc_attr( $settings['status_column'] ); ?>">
                                <p class="description"><?php esc_html_e( 'The plugin writes back a status column plus Synced, WP Event ID, Last Synced, and Source ID columns.', 'bef-calendar' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Import Categories', 'bef-calendar' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_GOOGLE_SHEETS ); ?>[import_categories]" value="1" <?php checked( ! empty( $settings['import_categories'] ) ); ?>>
                                    <?php esc_html_e( 'Create or assign BEF Event Categories from the sheet row categories column', 'bef-calendar' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bef_gs_default_post_status"><?php esc_html_e( 'Default Imported Post Status', 'bef-calendar' ); ?></label></th>
                            <td>
                                <select id="bef_gs_default_post_status" name="<?php echo esc_attr( self::OPTION_GOOGLE_SHEETS ); ?>[default_post_status]">
                                    <option value="publish" <?php selected( $settings['default_post_status'], 'publish' ); ?>><?php esc_html_e( 'Publish', 'bef-calendar' ); ?></option>
                                    <option value="draft" <?php selected( $settings['default_post_status'], 'draft' ); ?>><?php esc_html_e( 'Draft', 'bef-calendar' ); ?></option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button( __( 'Save Google Sheets Settings', 'bef-calendar' ) ); ?>
            </form>

            <hr>

            <h2><?php esc_html_e( 'Suggested Sheet Columns', 'bef-calendar' ); ?></h2>
            <p><code>Title | Date | End Date | Start Time | End Time | Location | Description | Excerpt | Event URL | Ticket URL | Ticket Label | Categories | Image URL | Status | Ready | Recurrence Frequency | Recurrence Interval | Recurrence Until</code></p>

            <h2><?php esc_html_e( 'Sync Status', 'bef-calendar' ); ?></h2>
            <p>
                <?php if ( ! empty( $state['last_sync'] ) ) : ?>
                    <?php printf( esc_html__( 'Last sync: %s', 'bef-calendar' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $state['last_sync'] ) ) ) ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'No sync has run yet.', 'bef-calendar' ); ?>
                <?php endif; ?>
            </p>
            <?php if ( ! empty( $state['message'] ) ) : ?>
                <p><?php echo esc_html( $state['message'] ); ?></p>
            <?php endif; ?>
            <p>
                <a href="<?php echo esc_url( $sync_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Sync Google Sheet Now', 'bef-calendar' ); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Handle manual Google Sheets sync.
     *
     * @return void
     */
    public function handle_sync_google_sheets() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to sync Google Sheets.', 'bef-calendar' ) );
        }

        check_admin_referer( 'bef_calendar_sync_google_sheets' );

        $result = $this->sync_google_sheets_events();

        $redirect = add_query_arg(
            array(
                'post_type'                => self::POST_TYPE,
                'page'                     => 'bef-calendar-google-sheets',
                'bef_google_sheets_synced' => is_wp_error( $result ) ? 'warning' : '1',
            ),
            admin_url( 'edit.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Maybe sync Google Sheets events on cron.
     *
     * @return void
     */
    public function maybe_sync_google_sheets() {
        $this->sync_google_sheets_events();
    }

    /**
     * Sync events from Google Sheets into local BEF events.
     *
     * @return array|WP_Error
     */
    private function sync_google_sheets_events() {
        $settings = $this->get_google_sheets_settings();

        if ( empty( $settings['enabled'] ) ) {
            $error = new WP_Error( 'bef_gs_disabled', __( 'Google Sheets sync is currently disabled.', 'bef-calendar' ) );
            $this->update_google_sheets_state(
                array(
                    'last_sync'   => current_time( 'mysql' ),
                    'last_status' => 'warning',
                    'message'     => $error->get_error_message(),
                    'stats'       => array(),
                )
            );
            return $error;
        }

        if ( empty( $settings['spreadsheet_id'] ) || empty( $settings['sheet_range'] ) || empty( $settings['service_account_json'] ) ) {
            $error = new WP_Error( 'bef_gs_missing_settings', __( 'Add the spreadsheet ID, sheet range, and service account JSON before syncing Google Sheets.', 'bef-calendar' ) );
            $this->update_google_sheets_state(
                array(
                    'last_sync'   => current_time( 'mysql' ),
                    'last_status' => 'warning',
                    'message'     => $error->get_error_message(),
                    'stats'       => array(),
                )
            );
            return $error;
        }

        $sheet = $this->fetch_google_sheet_rows( $settings );
        if ( is_wp_error( $sheet ) ) {
            $this->update_google_sheets_state(
                array(
                    'last_sync'   => current_time( 'mysql' ),
                    'last_status' => 'warning',
                    'message'     => $sheet->get_error_message(),
                    'stats'       => array(),
                )
            );
            return $sheet;
        }

        $stats         = array(
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed'  => 0,
        );
        $write_updates = $sheet['header_updates'];

        foreach ( $sheet['rows'] as $row ) {
            if ( empty( $row['ready'] ) ) {
                continue;
            }

            $result = $this->import_google_sheet_row( $row, $settings );

            if ( is_wp_error( $result ) ) {
                $stats['failed']++;
                $write_updates = array_merge(
                    $write_updates,
                    $this->build_google_sheet_writeback_updates(
                        $sheet['sheet_name'],
                        $row['row_number'],
                        $sheet['header_map'],
                        array(
                            'source_id'   => ! empty( $row['source_id'] ) ? $row['source_id'] : '',
                            'synced'      => 'FALSE',
                            'status'      => $result->get_error_message(),
                            'last_synced' => current_time( 'mysql' ),
                        )
                    )
                );
                continue;
            }

            if ( isset( $stats[ $result['result'] ] ) ) {
                $stats[ $result['result'] ]++;
            }

            $write_updates = array_merge(
                $write_updates,
                $this->build_google_sheet_writeback_updates(
                    $sheet['sheet_name'],
                    $row['row_number'],
                    $sheet['header_map'],
                    array(
                        'source_id'   => $result['source_id'],
                        'synced'      => 'TRUE',
                        'wp_event_id' => $result['post_id'],
                        'status'      => ucfirst( $result['result'] ),
                        'last_synced' => current_time( 'mysql' ),
                    )
                )
            );
        }

        if ( ! empty( $write_updates ) ) {
            $write_result = $this->google_sheets_batch_update_values( $settings, $write_updates );
            if ( is_wp_error( $write_result ) ) {
                $stats['failed']++;
            }
        }

        $message = sprintf(
            __( 'Google Sheets sync complete. Created %1$d, updated %2$d, skipped %3$d, failed %4$d.', 'bef-calendar' ),
            (int) $stats['created'],
            (int) $stats['updated'],
            (int) $stats['skipped'],
            (int) $stats['failed']
        );

        $this->update_google_sheets_state(
            array(
                'last_sync'   => current_time( 'mysql' ),
                'last_status' => 0 === (int) $stats['failed'] ? 'success' : 'warning',
                'message'     => $message,
                'stats'       => $stats,
            )
        );

        return $stats;
    }

    /**
     * Fetch rows from the configured Google Sheet.
     *
     * @param array $settings Settings.
     * @return array|WP_Error
     */
    private function fetch_google_sheet_rows( $settings ) {
        $response = $this->google_sheets_get_values( $settings, $settings['sheet_range'] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $values = ! empty( $response['values'] ) && is_array( $response['values'] ) ? $response['values'] : array();
        if ( count( $values ) < 1 ) {
            return new WP_Error( 'bef_gs_empty_sheet', __( 'The configured Google Sheet range did not return any rows.', 'bef-calendar' ) );
        }

        $range_parts       = $this->parse_google_sheet_range( $settings['sheet_range'] );
        $sheet_name        = $range_parts['sheet_name'];
        $start_row         = $range_parts['start_row'];
        $detected_header   = $this->detect_google_sheet_header_row( $values );
        $header_row_index  = isset( $detected_header['index'] ) ? (int) $detected_header['index'] : 0;
        $header_row_number = $start_row + $header_row_index;
        $header_row        = isset( $detected_header['row'] ) ? array_map( 'strval', (array) $detected_header['row'] ) : array_map( 'strval', (array) $values[0] );
        $header_map        = array();

        foreach ( $header_row as $index => $header_label ) {
            $normalized_key = $this->normalize_google_sheet_header( $header_label );
            if ( '' === $normalized_key ) {
                continue;
            }

            $header_map[ $normalized_key ] = $index;
        }

        $writeback_headers = array(
            'source_id'   => 'Source ID',
            'synced'      => 'Synced',
            'wp_event_id' => 'WP Event ID',
            'last_synced' => 'Last Synced',
            'status'      => ! empty( $settings['status_column'] ) ? $settings['status_column'] : 'Status',
        );

        foreach ( $writeback_headers as $key => $label ) {
            if ( ! isset( $header_map[ $key ] ) ) {
                $header_map[ $key ] = count( $header_row );
                $header_row[]       = $label;
            }
        }

        $header_updates = array(
            array(
                'range'  => $sheet_name . '!A' . $header_row_number . ':' . $this->column_index_to_letters( count( $header_row ) - 1 ) . $header_row_number,
                'values' => array( $header_row ),
            ),
        );

        $rows = array();
        foreach ( array_slice( $values, $header_row_index + 1 ) as $offset => $row_values ) {
            $row_number = $header_row_number + $offset + 1;
            $rows[]     = $this->build_google_sheet_row_payload( $row_values, $header_map, $row_number, $settings );
        }

        return array(
            'sheet_name'     => $sheet_name,
            'start_row'      => $header_row_number,
            'header_map'     => $header_map,
            'header_updates' => $header_updates,
            'rows'           => $rows,
        );
    }

    /**
     * Build a parsed row payload from raw sheet values.
     *
     * @param array $row_values Raw row values.
     * @param array $header_map Normalized header map.
     * @param int   $row_number Sheet row number.
     * @param array $settings   Settings.
     * @return array
     */
    private function build_google_sheet_row_payload( $row_values, $header_map, $row_number, $settings ) {
        $get = function ( $keys ) use ( $row_values, $header_map ) {
            foreach ( (array) $keys as $key ) {
                $normalized_key = $this->normalize_google_sheet_header( $key );
                if ( isset( $header_map[ $normalized_key ] ) && array_key_exists( $header_map[ $normalized_key ], $row_values ) ) {
                    return is_scalar( $row_values[ $header_map[ $normalized_key ] ] ) ? trim( (string) $row_values[ $header_map[ $normalized_key ] ] ) : '';
                }
            }

            return '';
        };

        $ready_value      = $get( array( $settings['ready_column'], 'ready', 'ready to publish', 'publish', 'approved' ) );
        $source_id        = $get( array( 'source id', 'source_id', 'row id', 'row_id', 'id' ) );
        $categories       = $get( array( 'categories', 'category' ) );
        $event_status     = sanitize_key( $get( array( 'status', 'post status' ) ) );
        $event_status     = in_array( $event_status, array( 'publish', 'draft' ), true ) ? $event_status : $settings['default_post_status'];
        $has_ready_column = false;

        foreach ( array( $settings['ready_column'], 'ready', 'ready to publish', 'publish', 'approved' ) as $ready_key ) {
            if ( isset( $header_map[ $this->normalize_google_sheet_header( $ready_key ) ] ) ) {
                $has_ready_column = true;
                break;
            }
        }

        return array(
            'row_number'           => (int) $row_number,
            'ready'                => $has_ready_column ? $this->is_truthy_sheet_value( $ready_value ) : true,
            'source_id'            => $source_id,
            'title'                => $get( array( 'title', 'name' ) ),
            'date'                 => $this->normalize_sheet_date_value( $get( array( 'date', 'event date', 'start date' ) ) ),
            'end_date'             => $this->normalize_sheet_date_value( $get( array( 'end date', 'end date optional' ) ) ),
            'start_time'           => $this->normalize_sheet_time_value( $get( array( 'start time', 'time' ) ) ),
            'end_time'             => $this->normalize_sheet_time_value( $get( array( 'end time' ) ) ),
            'location'             => $get( array( 'location', 'venue' ) ),
            'content'              => $get( array( 'description', 'description / info', 'description info', 'content', 'details' ) ),
            'excerpt'              => $get( array( 'excerpt', 'summary' ) ),
            'event_url'            => $get( array( 'event url', 'event url optional', 'website', 'url' ) ),
            'ticket_url'           => $get( array( 'ticket url', 'ticket / registration url optional', 'ticket registration url optional', 'registration url', 'register url', 'purchase url' ) ),
            'ticket_label'         => $get( array( 'ticket label', 'ticket button label optional', 'button label', 'register label' ) ),
            'image_url'            => $get( array( 'image url', 'featured image url', 'featured image', 'image' ) ),
            'categories'           => $categories,
            'status'               => $event_status,
            'recurrence_frequency' => $this->normalize_recurrence_frequency( $get( array( 'recurrence frequency', 'repeat', 'repeats' ) ) ),
            'recurrence_interval'  => max( 1, absint( $get( array( 'recurrence interval', 'repeat interval' ) ) ) ),
            'recurrence_until'     => $this->normalize_sheet_date_value( $get( array( 'recurrence until', 'repeat until', 'repeat until date' ) ) ),
        );
    }

    /**
     * Import or update a Google Sheet row as a local event.
     *
     * @param array $row      Parsed row payload.
     * @param array $settings Settings.
     * @return array|WP_Error
     */
    private function import_google_sheet_row( $row, $settings ) {
        if ( empty( $row['title'] ) || empty( $row['date'] ) ) {
            return new WP_Error( 'bef_gs_missing_required_fields', __( 'Ready rows must contain at least a Title and Date.', 'bef-calendar' ) );
        }

        $source_id = ! empty( $row['source_id'] ) ? $row['source_id'] : 'sheet-row-' . $row['row_number'];
        $post_id   = $this->find_imported_event_post_id( 'google_sheets', $source_id );

        $postarr = array(
            'post_type'    => self::POST_TYPE,
            'post_title'   => $row['title'],
            'post_excerpt' => $row['excerpt'],
            'post_content' => $row['content'],
            'post_status'  => $row['status'],
        );

        if ( $post_id ) {
            $postarr['ID'] = $post_id;
            $post_id       = wp_update_post( wp_slash( $postarr ), true );
            $result        = 'updated';
        } else {
            $post_id = wp_insert_post( wp_slash( $postarr ), true );
            $result  = 'created';
        }

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        update_post_meta( $post_id, self::META_DATE, $row['date'] );
        update_post_meta( $post_id, self::META_END_DATE, $row['end_date'] );
        update_post_meta( $post_id, self::META_START_TIME, $row['start_time'] );
        update_post_meta( $post_id, self::META_END_TIME, $row['end_time'] );
        update_post_meta( $post_id, self::META_LOCATION, $row['location'] );
        update_post_meta( $post_id, self::META_URL, $row['event_url'] );
        update_post_meta( $post_id, self::META_TICKET_URL, $row['ticket_url'] );
        update_post_meta( $post_id, self::META_TICKET_LABEL, $row['ticket_label'] );
        update_post_meta( $post_id, self::META_RECURRENCE_FREQUENCY, $row['recurrence_frequency'] );
        update_post_meta( $post_id, self::META_RECURRENCE_INTERVAL, $row['recurrence_interval'] );
        update_post_meta( $post_id, self::META_RECURRENCE_UNTIL, $row['recurrence_until'] );
        update_post_meta( $post_id, self::META_SOURCE, 'google_sheets' );
        update_post_meta( $post_id, self::META_REMOTE_SOURCE, 'google_sheets' );
        update_post_meta( $post_id, self::META_REMOTE_ID, $source_id );
        update_post_meta( $post_id, self::META_REMOTE_MODIFIED, current_time( 'mysql' ) );
        update_post_meta( $post_id, self::META_REMOTE_IMAGE_URL, esc_url_raw( $row['image_url'] ) );

        if ( ! empty( $settings['import_categories'] ) ) {
            $terms = array();

            foreach ( preg_split( '/[,|]/', (string) $row['categories'] ) as $term_name ) {
                $term_name = trim( $term_name );
                if ( '' === $term_name ) {
                    continue;
                }

                $existing_term = term_exists( $term_name, self::TAXONOMY );
                if ( ! $existing_term ) {
                    $existing_term = wp_insert_term( $term_name, self::TAXONOMY );
                }

                if ( is_wp_error( $existing_term ) ) {
                    continue;
                }

                $terms[] = is_array( $existing_term ) ? (int) $existing_term['term_id'] : (int) $existing_term;
            }

            if ( ! empty( $terms ) ) {
                wp_set_object_terms( $post_id, array_unique( $terms ), self::TAXONOMY, false );
            }
        }

        return array(
            'result'    => $result,
            'post_id'   => (int) $post_id,
            'source_id' => $source_id,
        );
    }


    /**
     * Import rows from an uploaded CSV file exported from Google Sheets.
     *
     * @param string $tmp_name Temporary uploaded file path.
     * @param string $file_name Original file name.
     * @param array  $settings Import settings.
     * @return array|WP_Error
     */
    private function import_uploaded_sheet_file( $tmp_name, $file_name, $settings ) {
        $extension = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
        $parsed    = 'xlsx' === $extension ? $this->parse_uploaded_sheet_xlsx( $tmp_name ) : $this->parse_uploaded_sheet_csv( $tmp_name );

        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }

        $stats = array(
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed'  => 0,
        );

        foreach ( $parsed['rows'] as $row ) {
            $payload = $this->build_uploaded_sheet_row_payload( $row['values'], $parsed['header_map'], $row['row_number'], $settings, $file_name );

            if ( empty( $payload['ready'] ) ) {
                $stats['skipped']++;
                continue;
            }

            $result = $this->import_uploaded_sheet_row( $payload, $settings, $file_name );

            if ( is_wp_error( $result ) ) {
                $stats['failed']++;
                continue;
            }

            if ( isset( $stats[ $result['result'] ] ) ) {
                $stats[ $result['result'] ]++;
            }
        }

        return $stats;
    }

    /**
     * Parse an uploaded CSV export.
     *
     * @param string $tmp_name Temporary uploaded file path.
     * @return array|WP_Error
     */
    private function parse_uploaded_sheet_csv( $tmp_name ) {
        $handle = fopen( $tmp_name, 'r' );

        if ( ! $handle ) {
            return new WP_Error( 'bef_sheet_upload_open_failed', __( 'The uploaded sheet could not be opened.', 'bef-calendar' ) );
        }

        $all_rows = array();
        while ( ( $row_values = fgetcsv( $handle ) ) !== false ) {
            $all_rows[] = $row_values;
        }

        fclose( $handle );

        if ( empty( $all_rows ) ) {
            return new WP_Error( 'bef_sheet_upload_empty', __( 'The uploaded CSV file did not contain any rows.', 'bef-calendar' ) );
        }

        if ( isset( $all_rows[0][0] ) ) {
            $all_rows[0][0] = preg_replace( '/^\x{FEFF}/u', '', (string) $all_rows[0][0] );
        }

        $detected = $this->detect_google_sheet_header_row( $all_rows );
        if ( empty( $detected['row'] ) ) {
            return new WP_Error( 'bef_sheet_upload_missing_headers', __( 'The uploaded file did not contain a recognised event header row.', 'bef-calendar' ) );
        }

        $header_row_index = (int) $detected['index'];
        $header_row       = (array) $detected['row'];
        $header_map       = array();

        foreach ( $header_row as $index => $header_label ) {
            $normalized_key = $this->normalize_google_sheet_header( $header_label );
            if ( '' === $normalized_key ) {
                continue;
            }
            $header_map[ $normalized_key ] = $index;
        }

        $rows = array();
        foreach ( array_slice( $all_rows, $header_row_index + 1 ) as $offset => $row_values ) {
            if ( 1 === count( $row_values ) && '' === trim( (string) $row_values[0] ) ) {
                continue;
            }
            $rows[] = array(
                'row_number' => $header_row_index + $offset + 2,
                'values'     => $row_values,
            );
        }

        return array(
            'header_map' => $header_map,
            'rows'       => $rows,
        );
    }

    /**
     * Parse an uploaded XLSX workbook export.
     *
     * @param string $tmp_name Temporary uploaded file path.
     * @return array|WP_Error
     */
    private function parse_uploaded_sheet_xlsx( $tmp_name ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'bef_sheet_upload_zip_missing', __( 'XLSX imports need the PHP ZipArchive extension to be enabled.', 'bef-calendar' ) );
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open( $tmp_name ) ) {
            return new WP_Error( 'bef_sheet_upload_xlsx_open_failed', __( 'The uploaded XLSX file could not be opened.', 'bef-calendar' ) );
        }

        $workbook_xml = $zip->getFromName( 'xl/workbook.xml' );
        $rels_xml     = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
        $styles_xml   = $zip->getFromName( 'xl/styles.xml' );

        if ( false === $workbook_xml || false === $rels_xml ) {
            $zip->close();
            return new WP_Error( 'bef_sheet_upload_xlsx_invalid', __( 'The uploaded XLSX file is missing workbook data.', 'bef-calendar' ) );
        }

        $workbook = @simplexml_load_string( $workbook_xml );
        $rels     = @simplexml_load_string( $rels_xml );

        if ( ! $workbook || ! $rels ) {
            $zip->close();
            return new WP_Error( 'bef_sheet_upload_xlsx_invalid_xml', __( 'The uploaded XLSX file could not be read.', 'bef-calendar' ) );
        }

        $main_ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rel_ns  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $workbook->registerXPathNamespace( 'x', $main_ns );
        $workbook->registerXPathNamespace( 'r', $rel_ns );
        $rels->registerXPathNamespace( 'rel', 'http://schemas.openxmlformats.org/package/2006/relationships' );

        $shared_strings = $this->parse_xlsx_shared_strings( $zip );
        $style_map      = $this->parse_xlsx_style_map( $styles_xml );
        $sheet_target   = '';

        foreach ( $workbook->xpath( '//x:sheets/x:sheet' ) as $sheet ) {
            $name = (string) $sheet['name'];
            $rid  = (string) $sheet->attributes( $rel_ns )['id'];
            if ( 'Events' === $name ) {
                foreach ( $rels->xpath( '//rel:Relationship[@Id="' . $rid . '"]' ) as $relationship ) {
                    $sheet_target = (string) $relationship['Target'];
                    break;
                }
                break;
            }
            if ( '' === $sheet_target ) {
                foreach ( $rels->xpath( '//rel:Relationship[@Id="' . $rid . '"]' ) as $relationship ) {
                    $sheet_target = (string) $relationship['Target'];
                    break;
                }
            }
        }

        if ( '' === $sheet_target ) {
            $zip->close();
            return new WP_Error( 'bef_sheet_upload_xlsx_missing_sheet', __( 'The uploaded XLSX file does not contain a readable worksheet.', 'bef-calendar' ) );
        }

        $sheet_path = 'xl/' . ltrim( str_replace( array( '../', '..\\' ), '', $sheet_target ), '/' );
        $sheet_xml  = $zip->getFromName( $sheet_path );

        if ( false === $sheet_xml ) {
            $zip->close();
            return new WP_Error( 'bef_sheet_upload_xlsx_missing_sheet_xml', __( 'The Events worksheet could not be opened from the XLSX file.', 'bef-calendar' ) );
        }

        $sheet = @simplexml_load_string( $sheet_xml );
        if ( ! $sheet ) {
            $zip->close();
            return new WP_Error( 'bef_sheet_upload_xlsx_invalid_sheet', __( 'The Events worksheet could not be read.', 'bef-calendar' ) );
        }

        $sheet->registerXPathNamespace( 'x', $main_ns );
        $all_rows = array();

        foreach ( $sheet->xpath( '//x:sheetData/x:row' ) as $row ) {
            $row_number = (int) $row['r'];
            $cells      = array();
            foreach ( $row->xpath( 'x:c' ) as $cell ) {
                $ref      = (string) $cell['r'];
                $index    = $this->xlsx_column_letters_to_index( preg_replace( '/\d+/', '', $ref ) );
                $type     = isset( $cell['t'] ) ? (string) $cell['t'] : '';
                $style_id = isset( $cell['s'] ) ? (int) $cell['s'] : -1;
                $value    = isset( $cell->v ) ? (string) $cell->v : '';
                $cells[ $index ] = $this->format_xlsx_cell_value( $value, $type, $style_id, $style_map, $shared_strings );
            }

            if ( ! empty( $cells ) ) {
                ksort( $cells );
                $all_rows[ $row_number - 1 ] = array_values( $this->expand_sparse_row( $cells ) );
            }
        }

        $zip->close();

        if ( empty( $all_rows ) ) {
            return new WP_Error( 'bef_sheet_upload_xlsx_empty', __( 'The uploaded XLSX file did not contain any readable rows.', 'bef-calendar' ) );
        }

        ksort( $all_rows );
        $all_rows = array_values( $all_rows );

        $detected = $this->detect_google_sheet_header_row( $all_rows );
        if ( empty( $detected['row'] ) ) {
            return new WP_Error( 'bef_sheet_upload_missing_headers', __( 'The uploaded XLSX file did not contain a recognised event header row.', 'bef-calendar' ) );
        }

        $header_row_index = (int) $detected['index'];
        $header_row       = (array) $detected['row'];
        $header_map       = array();

        foreach ( $header_row as $index => $header_label ) {
            $normalized_key = $this->normalize_google_sheet_header( $header_label );
            if ( '' === $normalized_key ) {
                continue;
            }
            $header_map[ $normalized_key ] = $index;
        }

        $rows = array();
        foreach ( array_slice( $all_rows, $header_row_index + 1 ) as $offset => $row_values ) {
            if ( ! $this->row_has_any_value( $row_values ) ) {
                continue;
            }
            $rows[] = array(
                'row_number' => $header_row_index + $offset + 2,
                'values'     => $row_values,
            );
        }

        return array(
            'header_map' => $header_map,
            'rows'       => $rows,
        );
    }

    /**
     * Import or update a row from an uploaded CSV file.    /**
     * Import or update a row from an uploaded CSV file.
     *
     * @param array  $row      Parsed row payload.
     * @param array  $settings Import settings.
     * @param string $file_name Original file name.
     * @return array|WP_Error
     */
    private function import_uploaded_sheet_row( $row, $settings, $file_name ) {
        if ( empty( $row['title'] ) || empty( $row['date'] ) ) {
            return new WP_Error( 'bef_sheet_upload_missing_required', __( 'Ready rows must contain at least a Title and Date.', 'bef-calendar' ) );
        }

        $source_id = ! empty( $row['source_id'] ) ? $row['source_id'] : md5( implode( '|', array( $file_name, $row['title'], $row['date'], $row['start_time'], $row['location'] ) ) );
        $post_id   = $this->find_imported_event_post_id( 'google_sheet_upload', $source_id );

        $postarr = array(
            'post_type'    => self::POST_TYPE,
            'post_title'   => $row['title'],
            'post_excerpt' => $row['excerpt'],
            'post_content' => $row['content'],
            'post_status'  => $row['status'],
        );

        if ( $post_id ) {
            $postarr['ID'] = $post_id;
            $post_id       = wp_update_post( wp_slash( $postarr ), true );
            $result        = 'updated';
        } else {
            $post_id = wp_insert_post( wp_slash( $postarr ), true );
            $result  = 'created';
        }

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        update_post_meta( $post_id, self::META_DATE, $row['date'] );
        update_post_meta( $post_id, self::META_END_DATE, $row['end_date'] );
        update_post_meta( $post_id, self::META_START_TIME, $row['start_time'] );
        update_post_meta( $post_id, self::META_END_TIME, $row['end_time'] );
        update_post_meta( $post_id, self::META_LOCATION, $row['location'] );
        update_post_meta( $post_id, self::META_URL, $row['event_url'] );
        update_post_meta( $post_id, self::META_TICKET_URL, $row['ticket_url'] );
        update_post_meta( $post_id, self::META_TICKET_LABEL, $row['ticket_label'] );
        update_post_meta( $post_id, self::META_RECURRENCE_FREQUENCY, $row['recurrence_frequency'] );
        update_post_meta( $post_id, self::META_RECURRENCE_INTERVAL, $row['recurrence_interval'] );
        update_post_meta( $post_id, self::META_RECURRENCE_UNTIL, $row['recurrence_until'] );
        update_post_meta( $post_id, self::META_SOURCE, 'google_sheet_upload' );
        update_post_meta( $post_id, self::META_REMOTE_SOURCE, 'google_sheet_upload' );
        update_post_meta( $post_id, self::META_REMOTE_ID, $source_id );
        update_post_meta( $post_id, self::META_REMOTE_MODIFIED, current_time( 'mysql' ) );
        update_post_meta( $post_id, self::META_REMOTE_IMAGE_URL, esc_url_raw( $row['image_url'] ) );

        if ( ! empty( $settings['import_categories'] ) ) {
            $terms = array();

            foreach ( preg_split( '/[,|]/', (string) $row['categories'] ) as $term_name ) {
                $term_name = trim( $term_name );
                if ( '' === $term_name ) {
                    continue;
                }

                $existing_term = term_exists( $term_name, self::TAXONOMY );
                if ( ! $existing_term ) {
                    $existing_term = wp_insert_term( $term_name, self::TAXONOMY );
                }

                if ( is_wp_error( $existing_term ) ) {
                    continue;
                }

                $terms[] = is_array( $existing_term ) ? (int) $existing_term['term_id'] : (int) $existing_term;
            }

            if ( ! empty( $terms ) ) {
                wp_set_object_terms( $post_id, array_unique( $terms ), self::TAXONOMY, false );
            }
        }

        return array(
            'result'    => $result,
            'post_id'   => (int) $post_id,
            'source_id' => $source_id,
        );
    }

    /**
     * Build a parsed row payload from an uploaded CSV file.
     *
     * @param array  $row_values Raw row values.
     * @param array  $header_map Header map.
     * @param int    $row_number Row number.
     * @param array  $settings   Import settings.
     * @param string $file_name  Uploaded file name.
     * @return array
     */
    private function build_uploaded_sheet_row_payload( $row_values, $header_map, $row_number, $settings, $file_name ) {
        $get = function ( $keys ) use ( $row_values, $header_map ) {
            foreach ( (array) $keys as $key ) {
                $normalized_key = $this->normalize_google_sheet_header( $key );
                if ( isset( $header_map[ $normalized_key ] ) && array_key_exists( $header_map[ $normalized_key ], $row_values ) ) {
                    return is_scalar( $row_values[ $header_map[ $normalized_key ] ] ) ? trim( (string) $row_values[ $header_map[ $normalized_key ] ] ) : '';
                }
            }

            return '';
        };

        $ready_value      = $get( array( $settings['ready_column'], 'ready', 'ready to publish', 'publish', 'approved' ) );
        $source_id        = $get( array( 'source id', 'source_id', 'row id', 'row_id', 'id' ) );
        $categories       = $get( array( 'categories', 'category' ) );
        $event_status     = sanitize_key( $get( array( 'status', 'post status' ) ) );
        $event_status     = in_array( $event_status, array( 'publish', 'draft' ), true ) ? $event_status : $settings['default_post_status'];
        $has_ready_column = false;

        foreach ( array( $settings['ready_column'], 'ready', 'ready to publish', 'publish', 'approved' ) as $ready_key ) {
            if ( isset( $header_map[ $this->normalize_google_sheet_header( $ready_key ) ] ) ) {
                $has_ready_column = true;
                break;
            }
        }

        return array(
            'row_number'           => (int) $row_number,
            'ready'                => $has_ready_column ? $this->is_truthy_sheet_value( $ready_value ) : true,
            'source_id'            => $source_id ? $source_id : 'upload-' . md5( $file_name . '|' . $row_number ),
            'title'                => $get( array( 'title', 'name' ) ),
            'date'                 => $this->normalize_sheet_date_value( $get( array( 'date', 'event date', 'start date' ) ) ),
            'end_date'             => $this->normalize_sheet_date_value( $get( array( 'end date', 'end date optional' ) ) ),
            'start_time'           => $this->normalize_sheet_time_value( $get( array( 'start time', 'time' ) ) ),
            'end_time'             => $this->normalize_sheet_time_value( $get( array( 'end time' ) ) ),
            'location'             => $get( array( 'location', 'venue' ) ),
            'content'              => $get( array( 'description', 'description / info', 'description info', 'content', 'details' ) ),
            'excerpt'              => $get( array( 'excerpt', 'summary' ) ),
            'event_url'            => $get( array( 'event url', 'event url optional', 'website', 'url' ) ),
            'ticket_url'           => $get( array( 'ticket url', 'ticket / registration url optional', 'ticket registration url optional', 'registration url', 'register url', 'purchase url' ) ),
            'ticket_label'         => $get( array( 'ticket label', 'ticket button label optional', 'button label', 'register label' ) ),
            'image_url'            => $get( array( 'image url', 'featured image url', 'featured image', 'image' ) ),
            'categories'           => $categories,
            'status'               => $event_status,
            'recurrence_frequency' => $this->normalize_recurrence_frequency( $get( array( 'recurrence frequency', 'repeat', 'repeats' ) ) ),
            'recurrence_interval'  => max( 1, absint( $get( array( 'recurrence interval', 'repeat interval' ) ) ) ),
            'recurrence_until'     => $this->normalize_sheet_date_value( $get( array( 'recurrence until', 'repeat until', 'repeat until date' ) ) ),
        );
    }

    /**
     * Build writeback updates for a specific sheet row.
     *
     * @param string $sheet_name Sheet tab name.
     * @param int    $row_number Row number.
     * @param array  $header_map Header map.
     * @param array  $data       Values keyed by normalized header name.
     * @return array
     */
    private function build_google_sheet_writeback_updates( $sheet_name, $row_number, $header_map, $data ) {
        $updates = array();

        foreach ( $data as $key => $value ) {
            if ( ! isset( $header_map[ $key ] ) ) {
                continue;
            }

            $column = $this->column_index_to_letters( (int) $header_map[ $key ] );
            $updates[] = array(
                'range'  => $sheet_name . '!' . $column . $row_number,
                'values' => array( array( $value ) ),
            );
        }

        return $updates;
    }

    /**
     * Read values from Google Sheets.
     *
     * @param array  $settings Settings.
     * @param string $range    A1 range.
     * @return array|WP_Error
     */
    private function google_sheets_get_values( $settings, $range ) {
        $token = $this->get_google_sheets_access_token( $settings );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $endpoint = sprintf(
            'https://sheets.googleapis.com/v4/spreadsheets/%1$s/values/%2$s',
            rawurlencode( $settings['spreadsheet_id'] ),
            rawurlencode( $range )
        );

        return $this->google_sheets_api_request(
            $endpoint,
            array(
                'method'  => 'GET',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                ),
                'timeout' => 20,
            )
        );
    }

    /**
     * Batch update Google Sheets values.
     *
     * @param array $settings Settings.
     * @param array $updates  ValueRange payloads.
     * @return array|WP_Error
     */
    private function google_sheets_batch_update_values( $settings, $updates ) {
        if ( empty( $updates ) ) {
            return array();
        }

        $token = $this->get_google_sheets_access_token( $settings );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $endpoint = sprintf(
            'https://sheets.googleapis.com/v4/spreadsheets/%1$s/values:batchUpdate',
            rawurlencode( $settings['spreadsheet_id'] )
        );

        return $this->google_sheets_api_request(
            $endpoint,
            array(
                'method'  => 'POST',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode(
                    array(
                        'valueInputOption' => 'USER_ENTERED',
                        'data'             => array_values( $updates ),
                    )
                ),
                'timeout' => 20,
            )
        );
    }

    /**
     * Make a Google Sheets API request and decode JSON.
     *
     * @param string $url  Endpoint.
     * @param array  $args Request args.
     * @return array|WP_Error
     */
    private function google_sheets_api_request( $url, $args ) {
        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $decoded     = json_decode( $body, true );

        if ( $status_code < 200 || $status_code >= 300 ) {
            $message = __( 'Google Sheets request failed.', 'bef-calendar' );
            if ( is_array( $decoded ) && ! empty( $decoded['error']['message'] ) ) {
                $message = sanitize_text_field( $decoded['error']['message'] );
            }

            return new WP_Error( 'bef_gs_http_error', $message );
        }

        if ( '' === $body ) {
            return array();
        }

        if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
            return new WP_Error( 'bef_gs_invalid_json', __( 'Google Sheets returned an invalid JSON response.', 'bef-calendar' ) );
        }

        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Get an OAuth access token for the configured service account.
     *
     * @param array $settings Settings.
     * @return string|WP_Error
     */
    private function get_google_sheets_access_token( $settings ) {
        $credentials = json_decode( $settings['service_account_json'], true );

        if ( ! is_array( $credentials ) || empty( $credentials['client_email'] ) || empty( $credentials['private_key'] ) ) {
            return new WP_Error( 'bef_gs_invalid_credentials', __( 'The Google service account JSON is missing or invalid.', 'bef-calendar' ) );
        }

        $cache_key = 'bef_gs_token_' . md5( $credentials['client_email'] . '|' . $credentials['private_key'] );
        $cached    = get_transient( $cache_key );

        if ( is_string( $cached ) && '' !== $cached ) {
            return $cached;
        }

        $issued_at = time();
        $expires   = $issued_at + HOUR_IN_SECONDS;

        $header = array(
            'alg' => 'RS256',
            'typ' => 'JWT',
        );

        if ( ! empty( $credentials['private_key_id'] ) ) {
            $header['kid'] = $credentials['private_key_id'];
        }

        $claims = array(
            'iss'   => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $expires,
            'iat'   => $issued_at,
        );

        $segments = array(
            $this->base64url_encode( wp_json_encode( $header ) ),
            $this->base64url_encode( wp_json_encode( $claims ) ),
        );

        $signing_input = implode( '.', $segments );
        $private_key   = str_replace( array( '\r\n', '\n', '\r' ), array( "\r\n", "\n", "\r" ), (string) $credentials['private_key'] );
        $signature     = '';

        if ( ! function_exists( 'openssl_sign' ) || ! openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 ) ) {
            return new WP_Error( 'bef_gs_signing_error', __( 'Could not sign the Google service account request. Make sure OpenSSL is available and the private key is valid.', 'bef-calendar' ) );
        }

        $jwt = $signing_input . '.' . $this->base64url_encode( $signature );

        $token_response = wp_remote_post(
            'https://oauth2.googleapis.com/token',
            array(
                'timeout' => 20,
                'body'    => array(
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ),
            )
        );

        if ( is_wp_error( $token_response ) ) {
            return $token_response;
        }

        $status_code = (int) wp_remote_retrieve_response_code( $token_response );
        $body        = json_decode( wp_remote_retrieve_body( $token_response ), true );

        if ( 200 !== $status_code || ! is_array( $body ) || empty( $body['access_token'] ) ) {
            $message = __( 'Google Sheets authentication failed.', 'bef-calendar' );
            if ( is_array( $body ) && ! empty( $body['error_description'] ) ) {
                $message = sanitize_text_field( $body['error_description'] );
            } elseif ( is_array( $body ) && ! empty( $body['error'] ) ) {
                $message = is_array( $body['error'] ) && ! empty( $body['error']['message'] ) ? sanitize_text_field( $body['error']['message'] ) : sanitize_text_field( (string) $body['error'] );
            }

            return new WP_Error( 'bef_gs_token_error', $message );
        }

        $ttl = ! empty( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 60 ) : 3500;
        set_transient( $cache_key, $body['access_token'], $ttl );

        return (string) $body['access_token'];
    }

    /**
     * Parse a sheet range into its components.
     *
     * @param string $range A1 range.
     * @return array
     */
    private function parse_google_sheet_range( $range ) {
        $sheet_name = 'Sheet1';
        $start_row  = 1;

        if ( false !== strpos( $range, '!' ) ) {
            list( $sheet_name, $range_part ) = explode( '!', $range, 2 );
            $sheet_name = trim( $sheet_name, "' " );

            if ( preg_match( '/[A-Z]+(\d+)/i', $range_part, $matches ) ) {
                $start_row = max( 1, (int) $matches[1] );
            }
        } elseif ( preg_match( '/[A-Z]+(\d+)/i', $range, $matches ) ) {
            $start_row = max( 1, (int) $matches[1] );
        }

        return array(
            'sheet_name' => $sheet_name,
            'start_row'  => $start_row,
        );
    }


    /**
     * Detect the first row that looks like an event header row.
     *
     * @param array $rows Sheet rows.
     * @return array
     */
    private function detect_google_sheet_header_row( $rows ) {
        $limit = min( 15, count( $rows ) );

        for ( $index = 0; $index < $limit; $index++ ) {
            $row = isset( $rows[ $index ] ) ? (array) $rows[ $index ] : array();
            if ( empty( $row ) ) {
                continue;
            }

            $normalized = array_map( array( $this, 'normalize_google_sheet_header' ), $row );
            if ( in_array( 'title', $normalized, true ) && ( in_array( 'event_date', $normalized, true ) || in_array( 'date', $normalized, true ) ) ) {
                return array(
                    'index' => $index,
                    'row'   => $row,
                );
            }
        }

        return array(
            'index' => 0,
            'row'   => isset( $rows[0] ) ? (array) $rows[0] : array(),
        );
    }

    /**
     * Determine whether a row contains any non-empty values.
     *
     * @param array $row Row values.
     * @return bool
     */
    private function row_has_any_value( $row ) {
        foreach ( (array) $row as $value ) {
            if ( '' !== trim( (string) $value ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse XLSX shared strings.
     *
     * @param ZipArchive $zip Open zip archive.
     * @return array
     */
    private function parse_xlsx_shared_strings( $zip ) {
        $shared_strings = array();
        $xml            = $zip->getFromName( 'xl/sharedStrings.xml' );

        if ( false === $xml ) {
            return $shared_strings;
        }

        $shared = @simplexml_load_string( $xml );
        if ( ! $shared ) {
            return $shared_strings;
        }

        $shared->registerXPathNamespace( 'x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
        foreach ( $shared->xpath( '//x:si' ) as $index => $item ) {
            $parts = array();
            foreach ( $item->xpath( './/x:t' ) as $text_node ) {
                $parts[] = (string) $text_node;
            }
            $shared_strings[ $index ] = implode( '', $parts );
        }

        return $shared_strings;
    }

    /**
     * Parse XLSX style information into a simple style map.
     *
     * @param string|false $styles_xml Raw styles XML.
     * @return array
     */
    private function parse_xlsx_style_map( $styles_xml ) {
        $style_map = array();
        if ( false === $styles_xml ) {
            return $style_map;
        }

        $styles = @simplexml_load_string( $styles_xml );
        if ( ! $styles ) {
            return $style_map;
        }

        $styles->registerXPathNamespace( 'x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
        $custom_formats = array();

        foreach ( $styles->xpath( '//x:numFmts/x:numFmt' ) as $num_format ) {
            $custom_formats[ (int) $num_format['numFmtId'] ] = (string) $num_format['formatCode'];
        }

        foreach ( $styles->xpath( '//x:cellXfs/x:xf' ) as $index => $xf ) {
            $num_fmt_id = isset( $xf['numFmtId'] ) ? (int) $xf['numFmtId'] : 0;
            $format_code = isset( $custom_formats[ $num_fmt_id ] ) ? $custom_formats[ $num_fmt_id ] : '';
            $style_map[ $index ] = array(
                'numFmtId'    => $num_fmt_id,
                'format_code' => $format_code,
                'type'        => $this->infer_xlsx_format_type( $num_fmt_id, $format_code ),
            );
        }

        return $style_map;
    }

    /**
     * Infer whether an XLSX number format is a date or time field.
     *
     * @param int    $num_fmt_id Number format id.
     * @param string $format_code Format code.
     * @return string
     */
    private function infer_xlsx_format_type( $num_fmt_id, $format_code ) {
        if ( in_array( (int) $num_fmt_id, array( 14, 15, 16, 17, 22, 27, 30, 36, 45, 46, 47, 50, 57, 58, 200, 201, 202 ), true ) ) {
            if ( in_array( (int) $num_fmt_id, array( 45, 46, 47, 201 ), true ) ) {
                return 'time';
            }
            if ( in_array( (int) $num_fmt_id, array( 22 ), true ) ) {
                return 'datetime';
            }
            return 'date';
        }

        $normalized = strtolower( preg_replace( '/\[[^\]]+\]/', '', (string) $format_code ) );
        if ( '' === $normalized ) {
            return '';
        }
        if ( false !== strpos( $normalized, 'h' ) && false === strpos( $normalized, 'y' ) && false === strpos( $normalized, 'd' ) ) {
            return 'time';
        }
        if ( false !== strpos( $normalized, 'y' ) || false !== strpos( $normalized, 'd' ) ) {
            return false !== strpos( $normalized, 'h' ) ? 'datetime' : 'date';
        }
        return '';
    }

    /**
     * Convert an XLSX cell value into a display string.
     *
     * @param string $value Raw value.
     * @param string $type Cell type.
     * @param int    $style_id Style id.
     * @param array  $style_map Parsed styles.
     * @param array  $shared_strings Shared string table.
     * @return string
     */
    private function format_xlsx_cell_value( $value, $type, $style_id, $style_map, $shared_strings ) {
        if ( '' === $value ) {
            return '';
        }

        if ( 's' === $type ) {
            $index = (int) $value;
            return isset( $shared_strings[ $index ] ) ? (string) $shared_strings[ $index ] : '';
        }

        if ( in_array( $type, array( 'str', 'inlineStr' ), true ) ) {
            return (string) $value;
        }

        if ( 'b' === $type ) {
            return '1' === (string) $value ? 'TRUE' : 'FALSE';
        }

        if ( is_numeric( $value ) ) {
            $style = isset( $style_map[ $style_id ] ) ? $style_map[ $style_id ] : array();
            $kind  = isset( $style['type'] ) ? $style['type'] : '';
            if ( 'date' === $kind || 'time' === $kind || 'datetime' === $kind ) {
                return $this->format_xlsx_excel_serial( (float) $value, $kind );
            }

            return (string) $value;
        }

        return (string) $value;
    }

    /**
     * Convert an Excel serial number into a date/time string.
     *
     * @param float  $serial Excel serial.
     * @param string $kind Date, time or datetime.
     * @return string
     */
    private function format_xlsx_excel_serial( $serial, $kind ) {
        $seconds = (int) round( ( $serial - 25569 ) * DAY_IN_SECONDS );
        if ( 'time' === $kind ) {
            $seconds = (int) round( fmod( $serial, 1 ) * DAY_IN_SECONDS );
            if ( $seconds < 0 ) {
                $seconds += DAY_IN_SECONDS;
            }
            return gmdate( 'H:i', $seconds );
        }

        if ( 'datetime' === $kind ) {
            return gmdate( 'Y-m-d H:i', $seconds );
        }

        return gmdate( 'Y-m-d', $seconds );
    }

    /**
     * Expand a sparse row keyed by column index.
     *
     * @param array $cells Sparse row.
     * @return array
     */
    private function expand_sparse_row( $cells ) {
        $expanded = array();
        $max      = max( array_keys( $cells ) );
        for ( $index = 0; $index <= $max; $index++ ) {
            $expanded[ $index ] = isset( $cells[ $index ] ) ? $cells[ $index ] : '';
        }
        return $expanded;
    }

    /**
     * Convert column letters into a zero-based index.
     *
     * @param string $letters Column letters.
     * @return int
     */
    private function xlsx_column_letters_to_index( $letters ) {
        $letters = strtoupper( (string) $letters );
        $length  = strlen( $letters );
        $index   = 0;

        for ( $i = 0; $i < $length; $i++ ) {
            $index = ( $index * 26 ) + ( ord( $letters[ $i ] ) - 64 );
        }

        return max( 0, $index - 1 );
    }

    /**
     * Normalize a sheet header label into a map key.
     *
     * @param string $header Header label.
     * @return string
     */
    private function normalize_google_sheet_header( $header ) {
        $header = strtolower( trim( (string) $header ) );
        $header = preg_replace( '/[^a-z0-9]+/i', '_', $header );
        return trim( (string) $header, '_' );
    }


    /**
     * Normalize a sheet date value.
     *
     * @param string $value Raw value.
     * @return string
     */
    private function normalize_sheet_date_value( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return '';
        }

        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/' , $value ) ) {
            return $value;
        }

        $timestamp = strtotime( $value );
        return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : $value;
    }

    /**
     * Normalize a sheet time value.
     *
     * @param string $value Raw value.
     * @return string
     */
    private function normalize_sheet_time_value( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return '';
        }

        if ( preg_match( '/^\d{2}:\d{2}(?::\d{2})?$/' , $value ) ) {
            return substr( $value, 0, 5 );
        }

        $timestamp = strtotime( $value );
        return false !== $timestamp ? gmdate( 'H:i', $timestamp ) : $value;
    }

    /**
     * Determine whether a sheet checkbox value is truthy.
     *
     * @param string $value Cell value.
     * @return bool
     */
    private function is_truthy_sheet_value( $value ) {
        $value = strtolower( trim( (string) $value ) );
        return in_array( $value, array( '1', 'true', 'yes', 'y', 'ready', 'publish', 'published', 'x' ), true );
    }

    /**
     * Convert a zero-based column index to spreadsheet letters.
     *
     * @param int $index Zero-based column index.
     * @return string
     */
    private function column_index_to_letters( $index ) {
        $index  = (int) $index;
        $result = '';

        do {
            $remainder = $index % 26;
            $result    = chr( 65 + $remainder ) . $result;
            $index     = (int) floor( $index / 26 ) - 1;
        } while ( $index >= 0 );

        return $result;
    }

    /**
     * Base64url encode a string.
     *
     * @param string $value Raw value.
     * @return string
     */
    private function base64url_encode( $value ) {
        return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
    }

}
