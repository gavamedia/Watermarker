<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the public-facing upload page and AJAX handlers.
 */
class Watermarker_Frontend_Page {

    public function __construct() {
        add_action( 'init', [ __CLASS__, 'register_rewrite_rules' ] );
        add_filter( 'query_vars', [ $this, 'query_vars' ] );
        add_action( 'template_redirect', [ $this, 'maybe_render_page' ] );

        // Upload AJAX (logged-in and anonymous visitors).
        add_action( 'wp_ajax_watermarker_upload', [ $this, 'ajax_upload' ] );
        add_action( 'wp_ajax_nopriv_watermarker_upload', [ $this, 'ajax_upload' ] );

        // Download AJAX.
        add_action( 'wp_ajax_watermarker_download', [ $this, 'ajax_download' ] );
        add_action( 'wp_ajax_nopriv_watermarker_download', [ $this, 'ajax_download' ] );
    }

    // ------------------------------------------------------------------
    // Rewrite rules
    // ------------------------------------------------------------------

    public static function register_rewrite_rules() {
        $slug = get_option( 'watermarker_url_slug', 'letterhead' );
        add_rewrite_rule(
            '^' . preg_quote( $slug, '/' ) . '/?$',
            'index.php?watermarker_page=1',
            'top'
        );
    }

    public function query_vars( $vars ) {
        $vars[] = 'watermarker_page';
        return $vars;
    }

    // ------------------------------------------------------------------
    // Page rendering
    // ------------------------------------------------------------------

    public function maybe_render_page() {
        if ( ! get_query_var( 'watermarker_page' ) ) {
            return;
        }

        status_header( 200 );
        nocache_headers();

        $nonce      = wp_create_nonce( 'watermarker_upload' );
        $ajax_url   = admin_url( 'admin-ajax.php' );
        $max_size   = size_format( wp_max_upload_size() );
        $site_name  = get_bloginfo( 'name' );
        $has_lh     = (bool) get_option( 'watermarker_letterhead_id', '' );

        include WATERMARKER_PLUGIN_DIR . 'templates/upload-page.php';
        exit;
    }

    // ------------------------------------------------------------------
    // AJAX: upload & process
    // ------------------------------------------------------------------

    public function ajax_upload() {
        // Security check.
        if ( ! wp_verify_nonce( sanitize_text_field( $_POST['nonce'] ?? '' ), 'watermarker_upload' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token. Please refresh the page and try again.' ] );
        }

        if ( empty( $_FILES['file'] ) || UPLOAD_ERR_OK !== (int) $_FILES['file']['error'] ) {
            $code = (int) ( $_FILES['file']['error'] ?? -1 );
            $msg  = $this->upload_error_message( $code );
            wp_send_json_error( [ 'message' => $msg ] );
        }

        // Letterhead configured?
        $lh_id   = get_option( 'watermarker_letterhead_id', '' );
        $lh_path = $lh_id ? get_attached_file( $lh_id ) : '';
        if ( ! $lh_path || ! file_exists( $lh_path ) ) {
            wp_send_json_error( [ 'message' => 'No letterhead template configured. Please ask the site administrator to set one up.' ] );
        }

        $file = $_FILES['file'];

        // Validate MIME type via fileinfo (not the browser-supplied type).
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime  = finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );

        $allowed = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/vnd.oasis.opendocument.presentation',
            'application/rtf',
            'text/rtf',
            'text/plain',
            'text/html',
            'text/csv',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/bmp',
            'image/tiff',
            // DOCX is a zip; some servers report this.
            'application/zip',
            'application/x-zip-compressed',
            'application/octet-stream',
        ];

        // For generic MIME types, also check the file extension.
        $ext           = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        $allowed_ext   = [ 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'rtf', 'txt', 'html', 'htm', 'odt', 'ods', 'odp', 'csv', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif' ];

        if ( ! in_array( $mime, $allowed, true ) && ! in_array( $ext, $allowed_ext, true ) ) {
            wp_send_json_error( [ 'message' => 'Unsupported file type (' . esc_html( $mime ) . '). Please upload a PDF, Word document, or image.' ] );
        }

        if ( ! in_array( $ext, $allowed_ext, true ) ) {
            wp_send_json_error( [ 'message' => 'Unsupported file extension: .' . esc_html( $ext ) ] );
        }

        // Move to temp dir.
        $temp_dir = $this->get_temp_dir();
        $dest     = $temp_dir . wp_unique_filename( $temp_dir, sanitize_file_name( $file['name'] ) );

        if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
            wp_send_json_error( [ 'message' => 'Failed to save the uploaded file.' ] );
        }

        try {
            $processor  = new Watermarker_PDF_Processor();
            $apply_all  = (bool) get_option( 'watermarker_apply_all_pages', '1' );
            $output     = $processor->process( $dest, $lh_path, $apply_all );

            // Move output into our temp dir with a friendly name.
            $out_name  = pathinfo( $file['name'], PATHINFO_FILENAME ) . '-letterhead.pdf';
            $out_final = $temp_dir . wp_unique_filename( $temp_dir, sanitize_file_name( $out_name ) );
            rename( $output, $out_final );

            // Create time-limited download key.
            $key = wp_generate_password( 32, false );
            set_transient( 'watermarker_dl_' . $key, $out_final, HOUR_IN_SECONDS );

            wp_send_json_success( [
                'message'      => 'Document processed successfully!',
                'download_url' => admin_url( 'admin-ajax.php' ) . '?action=watermarker_download&key=' . rawurlencode( $key ),
                'filename'     => $out_name,
            ] );
        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        } finally {
            // Always remove the uploaded source file.
            if ( file_exists( $dest ) ) {
                @unlink( $dest );
            }
            $this->cleanup_old_temp_files();
        }
    }

    // ------------------------------------------------------------------
    // AJAX: download
    // ------------------------------------------------------------------

    public function ajax_download() {
        $key  = sanitize_text_field( $_GET['key'] ?? '' );
        $path = get_transient( 'watermarker_dl_' . $key );

        if ( ! $path || ! file_exists( $path ) ) {
            wp_die( 'This download link has expired or is invalid. Please process your document again.', 'Download expired', [ 'response' => 410 ] );
        }

        $filename = basename( $path );

        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'Cache-Control: no-store' );

        readfile( $path );

        // Cleanup.
        @unlink( $path );
        delete_transient( 'watermarker_dl_' . $key );
        exit;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function get_temp_dir() {
        $upload_dir = wp_upload_dir();
        $dir        = $upload_dir['basedir'] . '/watermarker-temp/';

        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
            // Prevent direct access.
            @file_put_contents( $dir . '.htaccess', "Deny from all\n" );
            @file_put_contents( $dir . 'index.php', '<?php // Silence is golden.' );
        }

        return $dir;
    }

    /**
     * Remove temp files older than 2 hours.
     */
    private function cleanup_old_temp_files() {
        $dir = $this->get_temp_dir();
        $now = time();

        foreach ( glob( $dir . '*' ) as $file ) {
            if ( is_file( $file ) && ! in_array( basename( $file ), [ '.htaccess', 'index.php' ], true ) ) {
                if ( $now - filemtime( $file ) > 7200 ) {
                    @unlink( $file );
                }
            }
        }
    }

    private function upload_error_message( $code ) {
        switch ( $code ) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'The file is too large. Maximum upload size is ' . size_format( wp_max_upload_size() ) . '.';
            case UPLOAD_ERR_PARTIAL:
                return 'The file was only partially uploaded. Please try again.';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded.';
            default:
                return 'Upload failed (error code ' . $code . '). Please try again.';
        }
    }
}
