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
        register_setting( 'watermarker_settings', 'watermarker_apply_all_pages', [
            'sanitize_callback' => 'absint',
            'default'           => 1,
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
        $apply_all      = get_option( 'watermarker_apply_all_pages', '1' );
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

                    <!-- Apply to All Pages -->
                    <tr>
                        <th scope="row">Apply Letterhead To</th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="watermarker_apply_all_pages" value="1"
                                        <?php checked( $apply_all, '1' ); ?>>
                                    All pages
                                </label><br>
                                <label>
                                    <input type="radio" name="watermarker_apply_all_pages" value="0"
                                        <?php checked( $apply_all, '0' ); ?>>
                                    First page only
                                </label>
                            </fieldset>
                            <p class="description">
                                When a multi-page document is uploaded, choose whether the letterhead appears on every page or just the first.
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
                </tbody>
            </table>
        </div>
        <?php
    }
}
