<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin settings page for Watermarker.
 */
class Watermarker_Admin_Settings {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_action( 'update_option_watermarker_url_slug', [ $this, 'on_slug_change' ], 10, 2 );
        add_filter( 'plugin_action_links_' . plugin_basename( WATERMARKER_PLUGIN_DIR . 'watermarker.php' ), [ $this, 'add_action_links' ] );
        add_action( 'wp_ajax_watermarker_upload_font', [ $this, 'ajax_upload_font' ] );
        add_action( 'wp_ajax_watermarker_delete_font', [ $this, 'ajax_delete_font' ] );
    }

    public function add_action_links( $links ) {
        $settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=watermarker' ) ) . '">Settings</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public function add_menu_page() {
        add_options_page(
            'Watermarker Settings',
            'Watermarker',
            'manage_options',
            'watermarker',
            [ $this, 'render_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'watermarker_settings', 'watermarker_url_slug', [
            'sanitize_callback' => [ $this, 'sanitize_slug' ],
            'default'           => 'letterhead',
        ] );
        register_setting( 'watermarker_settings', 'watermarker_letterhead_id', [
            'sanitize_callback' => 'absint',
        ] );
        register_setting( 'watermarker_settings', 'watermarker_show_logo', [
            'sanitize_callback' => 'absint',
            'default'           => 1,
        ] );
        register_setting( 'watermarker_settings', 'watermarker_show_site_name', [
            'sanitize_callback' => 'absint',
            'default'           => 1,
        ] );
    }

    public function sanitize_slug( $value ) {
        $value = sanitize_title( $value );
        return $value ?: 'letterhead';
    }

    public function enqueue_scripts( $hook ) {
        if ( 'settings_page_watermarker' !== $hook ) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script(
            'watermarker-admin',
            WATERMARKER_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            WATERMARKER_VERSION,
            true
        );
        wp_localize_script( 'watermarker-admin', 'watermarkerFonts', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'watermarker_font_action' ),
            'families'  => Watermarker_Font_Manager::get_font_families(),
            'installed' => Watermarker_Font_Manager::get_installed_status(),
        ] );
    }

    public function on_slug_change( $old_value, $new_value ) {
        if ( $old_value !== $new_value ) {
            Watermarker_Frontend_Page::register_rewrite_rules();
            flush_rewrite_rules();
        }
    }

    public function render_page() {
        $slug           = get_option( 'watermarker_url_slug', 'letterhead' );
        $letterhead_id  = get_option( 'watermarker_letterhead_id', '' );
        $show_logo      = get_option( 'watermarker_show_logo', '1' );
        $show_site_name = get_option( 'watermarker_show_site_name', '1' );
        $has_letterhead = ! empty( $letterhead_id );
        $filename       = $has_letterhead ? basename( (string) get_attached_file( $letterhead_id ) ) : '';
        ?>
        <div class="wrap">
            <h1>Watermarker Settings</h1>

            <form method="post" action="options.php">
                <?php settings_fields( 'watermarker_settings' ); ?>

                <table class="form-table" role="presentation">
                    <!-- URL Slug -->
                    <tr>
                        <th scope="row"><label for="watermarker_url_slug">Page URL</label></th>
                        <td>
                            <code><?php echo esc_html( home_url( '/' ) ); ?></code>
                            <input type="text" id="watermarker_url_slug" name="watermarker_url_slug"
                                   value="<?php echo esc_attr( $slug ); ?>" class="regular-text"
                                   pattern="[a-z0-9\-]+" title="Lowercase letters, numbers, and hyphens only.">
                            <p class="description">
                                The URL slug where the upload page will be accessible.
                                After changing, visit
                                <a href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>">Settings &rarr; Permalinks</a>
                                and click "Save Changes" if the page doesn't load.
                            </p>
                        </td>
                    </tr>

                    <!-- Letterhead Template -->
                    <tr>
                        <th scope="row">Letterhead Template</th>
                        <td>
                            <div id="watermarker-letterhead-preview">
                                <?php if ( $has_letterhead ) : ?>
                                    <p>Current file: <strong><?php echo esc_html( $filename ); ?></strong></p>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" id="watermarker_letterhead_id" name="watermarker_letterhead_id"
                                   value="<?php echo esc_attr( $letterhead_id ); ?>">
                            <button type="button" class="button" id="watermarker-upload-btn">
                                <?php echo $has_letterhead ? 'Change Letterhead' : 'Upload Letterhead'; ?>
                            </button>
                            <?php if ( $has_letterhead ) : ?>
                                <button type="button" class="button" id="watermarker-remove-btn">Remove</button>
                            <?php endif; ?>
                            <p class="description">
                                Upload a <strong>PDF</strong> or <strong>image</strong> (PNG, JPG) to use as the letterhead background.
                                For best results use a full-page PDF sized to your target paper (e.g.&nbsp;A4 or Letter).
                                If the letterhead PDF has two pages, page&nbsp;1 is used for the first content page and page&nbsp;2 for subsequent pages.
                            </p>
                        </td>
                    </tr>

                    <!-- Upload Page Display -->
                    <tr>
                        <th scope="row">Upload Page Display</th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="watermarker_show_logo" value="1"
                                        <?php checked( $show_logo, '1' ); ?>>
                                    Show logo
                                </label><br>
                                <label>
                                    <input type="checkbox" name="watermarker_show_site_name" value="1"
                                        <?php checked( $show_site_name, '1' ); ?>>
                                    Show site name
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>
            <h2>System Info</h2>
            <table class="widefat fixed" style="max-width:600px">
	                <tbody>
	                    <tr>
	                        <td><strong>Upload page URL</strong></td>
	                        <td><a href="<?php echo esc_url( home_url( '/' . $slug . '/' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/' . $slug . '/' ) ); ?></a></td>
	                    </tr>
	                    <tr>
	                        <td><strong>Max upload size</strong></td>
	                        <td><?php echo esc_html( size_format( wp_max_upload_size() ) ); ?></td>
	                    </tr>
	                    <tr>
	                        <td><strong>Private temp storage</strong></td>
	                        <td>
	                            <?php
	                            try {
	                                $temp_dir = Watermarker_Temp_Storage::get_private_temp_dir();
	                                echo '<span style="color:green">Ready:</span> <code>' . esc_html( $temp_dir ) . '</code><br>';
	                                echo '<span class="description">Uploads and generated PDFs are stored here briefly so they are not exposed under a public uploads URL.</span>';
	                            } catch ( \Throwable $e ) {
	                                echo '<span style="color:#b32d2e">Unavailable</span> &mdash; ' . esc_html( $e->getMessage() );
	                            }
	                            ?>
	                        </td>
	                    </tr>
	                    <tr>
	                        <td><strong>LibreOffice</strong></td>
	                        <td>
	                            <?php
	                            try {
	                                $lo = Watermarker_PDF_Processor::find_libreoffice();
	                            } catch ( \Throwable $e ) {
	                                $lo = null;
	                            }
	                            echo $lo
	                                ? '<span style="color:green">Found:</span> <code>' . esc_html( $lo ) . '</code>'
	                                : '<span style="color:orange">Not found</span> &mdash; DOCX / Office format conversion will not work. Install LibreOffice on the server to enable it.';
	                            ?>
	                        </td>
	                    </tr>
	                    <tr>
	                        <td><strong>PHP extensions</strong></td>
	                        <td>
	                            <?php
	                            $fileinfo_ok = function_exists( 'finfo_open' ) && defined( 'FILEINFO_MIME_TYPE' );
	                            $zip_ok      = class_exists( 'ZipArchive' );
	                            ?>
	                            Fileinfo:
	                            <?php echo $fileinfo_ok ? '<span style="color:green">Loaded</span>' : '<span style="color:#b32d2e">Missing</span>'; ?><br>
	                            ZipArchive:
	                            <?php echo $zip_ok ? '<span style="color:green">Loaded</span>' : '<span style="color:orange">Missing</span> &mdash; DOCX pre-processing and PHP fallback conversion will be limited without it.'; ?>
	                        </td>
	                    </tr>
	                </tbody>
	            </table>

            <hr>
            <h2>Fonts</h2>
            <p class="description">Upload Microsoft Office TTF font files for accurate DOCX&#8209;to&#8209;PDF conversion. Only <code>.ttf</code> files are accepted.</p>
            <?php
            $font_status = Watermarker_Font_Manager::get_installed_status();
            $font_families = Watermarker_Font_Manager::get_font_families();
            ?>
            <table class="widefat fixed" style="max-width:700px" id="watermarker-fonts-table">
                <thead>
                    <tr>
                        <th>Font Family</th>
                        <th>Regular</th>
                        <th>Bold</th>
                        <th>Italic</th>
                        <th>Bold Italic</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $font_families as $key => $family ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $family['label'] ); ?></strong></td>
                        <?php foreach ( [ 'regular', 'bold', 'italic', 'bolditalic' ] as $variant ) : ?>
                        <td id="wm-font-<?php echo esc_attr( $key . '-' . $variant ); ?>">
                            <?php if ( $font_status[ $key ][ $variant ] ) : ?>
                                <span style="color:green">Installed</span>
                                <button type="button" class="button-link wm-font-delete" data-family="<?php echo esc_attr( $key ); ?>" data-variant="<?php echo esc_attr( $variant ); ?>" style="color:#b32d2e;margin-left:4px;">Delete</button>
                            <?php else : ?>
                                <button type="button" class="button button-small wm-font-upload" data-family="<?php echo esc_attr( $key ); ?>" data-variant="<?php echo esc_attr( $variant ); ?>">Upload</button>
                                <input type="file" accept=".ttf" style="display:none;">
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function ajax_upload_font() {
        check_ajax_referer( 'watermarker_font_action', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $family  = sanitize_text_field( $_POST['family'] ?? '' );
        $variant = sanitize_text_field( $_POST['variant'] ?? '' );

        if ( empty( $_FILES['font_file']['tmp_name'] ) ) {
            wp_send_json_error( [ 'message' => 'No file uploaded.' ] );
        }

        $result = Watermarker_Font_Manager::install_font(
            $_FILES['font_file']['tmp_name'],
            $family,
            $variant
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'message' => 'Font installed successfully.' ] );
    }

    public function ajax_delete_font() {
        check_ajax_referer( 'watermarker_font_action', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $family  = sanitize_text_field( $_POST['family'] ?? '' );
        $variant = sanitize_text_field( $_POST['variant'] ?? '' );

        Watermarker_Font_Manager::delete_font( $family, $variant );

        wp_send_json_success( [ 'message' => 'Font removed.' ] );
    }
}
