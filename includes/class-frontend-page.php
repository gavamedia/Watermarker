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
            '^' . preg_quote( $slug ) . '/?$',
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

        wp_enqueue_style( 'watermarker-frontend', WATERMARKER_PLUGIN_URL . 'assets/css/frontend.css', [], WATERMARKER_VERSION );
        wp_enqueue_script( 'watermarker-frontend', WATERMARKER_PLUGIN_URL . 'assets/js/frontend.js', [], WATERMARKER_VERSION, true );
        wp_localize_script( 'watermarker-frontend', 'watermarkerConfig', [
            'ajaxUrl' => $ajax_url,
            'nonce'   => $nonce,
            'maxSize' => wp_max_upload_size(),
        ] );

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

        // Validate file extension first (the authoritative gate).
        $ext         = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        $allowed_ext = [ 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'rtf', 'txt', 'html', 'htm', 'odt', 'ods', 'odp', 'csv', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif' ];

        if ( ! in_array( $ext, $allowed_ext, true ) ) {
            wp_send_json_error( [ 'message' => 'Unsupported file extension: .' . esc_html( $ext ) ] );
        }

        $this->enforce_rate_limits( $ext );

        // Validate MIME type using the best available server-side detector.
        // We do not trust the browser-supplied MIME type for authorization.
        $mime = $this->detect_mime_type( $file['tmp_name'], $file['name'] );
        if ( is_wp_error( $mime ) ) {
            wp_send_json_error( [ 'message' => $mime->get_error_message() ] );
        }

        $allowed_mime = [
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
        ];

        // ZIP-based formats (docx, xlsx, pptx) may report as zip/octet-stream.
        $zip_ext = [ 'docx', 'xlsx', 'pptx' ];
        $generic_mime_ok = in_array( $ext, $zip_ext, true )
            && in_array( $mime, [ 'application/zip', 'application/x-zip-compressed', 'application/octet-stream' ], true );

        if ( ! in_array( $mime, $allowed_mime, true ) && ! $generic_mime_ok ) {
            wp_send_json_error( [ 'message' => 'Unsupported file type. Please upload a PDF, Word document, or image.' ] );
        }

        $dest                = null;
        $output              = null;
        $lock_key            = null;
        $download_registered = false;
        $success_payload     = null;
        $error_payload       = null;

        try {
            // Store the physical upload under an opaque filename in private temp
            // storage. The original name is retained separately only for the final
            // download filename shown to the user.
            $dest = Watermarker_Temp_Storage::generate_file_path( 'upload_', $ext );
            if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
                throw new \RuntimeException( 'Failed to save the uploaded file.' );
            }

            $lock_key = $this->acquire_processing_lock();
            $processor  = new Watermarker_PDF_Processor();
            $apply_all  = '0' !== (string) sanitize_text_field( $_POST['apply_all'] ?? '1' );
            $output     = $processor->process( $dest, $lh_path, $apply_all );

            $base_name = sanitize_file_name( pathinfo( $file['name'], PATHINFO_FILENAME ) );
            if ( '' === $base_name ) {
                $base_name = 'document';
            }
            $out_name = $base_name . '-letterhead.pdf';

            // Create time-limited download key.
            $key = wp_generate_password( 32, false );
            if ( ! set_transient( 'watermarker_dl_' . $key, [
                'path'     => $output,
                'filename' => $out_name,
            ], HOUR_IN_SECONDS ) ) {
                throw new \RuntimeException( 'Unable to prepare the download link. Please try again.' );
            }

            $download_registered = true;
            $success_payload     = [
                'message'      => 'Document processed successfully!',
                'download_url' => admin_url( 'admin-ajax.php' ) . '?action=watermarker_download&key=' . rawurlencode( $key ) . '&t=' . time(),
                'filename'     => $out_name,
            ];
        } catch ( \Throwable $e ) {
            error_log( 'Watermarker processing error [' . get_class( $e ) . ']: ' . $e->getMessage() );
            // Only show safe messages to the client; hide internal paths/details.
            $safe_msg = $e->getMessage();
            if ( strpos( $safe_msg, '/' ) !== false || strpos( $safe_msg, '\\' ) !== false ) {
                $safe_msg = 'An error occurred while processing your document. Please try again or upload a PDF instead.';
            }
            $error_payload = [ 'message' => $safe_msg ];
        } finally {
            if ( $lock_key ) {
                $this->release_processing_lock( $lock_key );
            }

            // Always remove the uploaded source file.
            if ( $dest && file_exists( $dest ) && Watermarker_Temp_Storage::is_managed_path( $dest ) ) {
                @unlink( $dest );
            }

            // If we generated an output file but could not hand it off to a download
            // transient, clean it up immediately instead of waiting for age-based
            // sweeping. This keeps failed requests from accumulating PDFs.
            if ( $output && ! $download_registered && file_exists( $output ) && Watermarker_Temp_Storage::is_managed_path( $output ) ) {
                @unlink( $output );
            }

            $this->cleanup_old_temp_files();
        }

        if ( $error_payload ) {
            wp_send_json_error( $error_payload );
        }

        wp_send_json_success( $success_payload );
    }

    // ------------------------------------------------------------------
    // AJAX: download
    // ------------------------------------------------------------------

    public function ajax_download() {
        $key      = sanitize_text_field( $_GET['key'] ?? '' );
        $download = get_transient( 'watermarker_dl_' . $key );

        if ( ! is_array( $download ) || empty( $download['path'] ) ) {
            wp_die( 'This download link has expired or is invalid. Please process your document again.', 'Download expired', [ 'response' => 410 ] );
        }

        $path = $download['path'];
        if ( ! Watermarker_Temp_Storage::is_managed_path( $path ) || ! file_exists( $path ) ) {
            wp_die( 'This download link has expired or is invalid. Please process your document again.', 'Download expired', [ 'response' => 410 ] );
        }

        $filename = sanitize_file_name( $download['filename'] ?? basename( $path ) );
        if ( '' === $filename ) {
            $filename = 'document.pdf';
        }

        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'Cache-Control: no-store' );
        header( 'X-Content-Type-Options: nosniff' );

        readfile( $path );

        // Keep the transient until it expires so interrupted downloads can be
        // retried within the existing one-hour window. The backing file still
        // lives in private temp storage and is removed later by age-based cleanup.
        exit;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function cleanup_old_temp_files() {
        // Centralized cleanup handles both the new private temp area and the
        // legacy uploads-based directory from older plugin versions.
        Watermarker_Temp_Storage::cleanup_old_files( 2 * HOUR_IN_SECONDS );
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

    /**
     * Detect a file's MIME type using server-side inspection only.
     *
     * We intentionally avoid trusting $_FILES['type']; it comes from the browser
     * and is useful for hints, not for security decisions. The order here prefers
     * stronger detectors first, then falls back to WordPress helpers when needed.
     *
     * @param string $tmp_path       Temporary upload path.
     * @param string $original_name  Original client filename.
     * @return string|\WP_Error
     */
    private function detect_mime_type( $tmp_path, $original_name ) {
        if ( function_exists( 'finfo_open' ) && defined( 'FILEINFO_MIME_TYPE' ) ) {
            $finfo = @finfo_open( FILEINFO_MIME_TYPE );
            if ( $finfo ) {
                $mime = @finfo_file( $finfo, $tmp_path );
                @finfo_close( $finfo );
                if ( is_string( $mime ) && '' !== $mime ) {
                    return $mime;
                }
            }
        }

        if ( function_exists( 'mime_content_type' ) ) {
            $mime = @mime_content_type( $tmp_path );
            if ( is_string( $mime ) && '' !== $mime ) {
                return $mime;
            }
        }

        $image_mime = wp_get_image_mime( $tmp_path );
        if ( is_string( $image_mime ) && '' !== $image_mime ) {
            return $image_mime;
        }

        $wp_check = wp_check_filetype_and_ext( $tmp_path, $original_name );
        if ( ! empty( $wp_check['type'] ) ) {
            return $wp_check['type'];
        }

        return new \WP_Error(
            'mime_detection_unavailable',
            'The server is missing the file-type detection support needed to validate uploads safely. '
            . 'Please ask the site administrator to enable the PHP Fileinfo extension.'
        );
    }

    /**
     * Apply layered rate limits for public uploads.
     *
     * The upload page is intentionally public, so the server-side pipeline needs
     * guardrails beyond a nonce. We enforce:
     * - A general per-IP request budget.
     * - A stricter per-IP budget for office-style conversions, which are more
     *   expensive because they may trigger LibreOffice or PHP-based rendering.
     *
     * The limits are filterable so site owners can tune them for their traffic.
     *
     * @param string $ext Sanitized file extension.
     * @return void
     */
    private function enforce_rate_limits( $ext ) {
        $client_key           = $this->get_client_rate_key();
        $window_seconds       = max( 60, (int) apply_filters( 'watermarker_rate_limit_window', 10 * MINUTE_IN_SECONDS ) );
        $max_requests         = max( 1, (int) apply_filters( 'watermarker_rate_limit_max_requests', 10 ) );
        $max_office_requests  = max( 1, (int) apply_filters( 'watermarker_rate_limit_max_office_requests', 4 ) );

        $request_key = 'watermarker_rate_' . $client_key;
        $requests    = (int) get_transient( $request_key );
        if ( $requests >= $max_requests ) {
            wp_send_json_error( [ 'message' => 'Too many uploads from this connection. Please wait a few minutes and try again.' ] );
        }
        set_transient( $request_key, $requests + 1, $window_seconds );

        if ( Watermarker_PDF_Processor::is_office_extension( $ext ) ) {
            $office_key = 'watermarker_rate_office_' . $client_key;
            $office_requests = (int) get_transient( $office_key );
            if ( $office_requests >= $max_office_requests ) {
                wp_send_json_error( [ 'message' => 'Too many document conversions from this connection. Please wait a few minutes and try again.' ] );
            }
            set_transient( $office_key, $office_requests + 1, $window_seconds );
        }
    }

    /**
     * Prevent the same IP from starting multiple expensive conversions in parallel.
     *
     * This is intentionally lightweight: it does not try to solve every abuse case,
     * but it meaningfully reduces accidental double-submits and low-effort parallel
     * abuse against the anonymous AJAX endpoint.
     *
     * @return string Transient key that must be released in finally{}.
     */
    private function acquire_processing_lock() {
        $lock_key = 'watermarker_lock_' . $this->get_client_rate_key();
        $lock_ttl = max( 30, (int) apply_filters( 'watermarker_processing_lock_ttl', 5 * MINUTE_IN_SECONDS ) );

        if ( get_transient( $lock_key ) ) {
            throw new \RuntimeException( 'A document from this connection is already being processed. Please wait for it to finish and then try again.' );
        }

        set_transient( $lock_key, time(), $lock_ttl );

        return $lock_key;
    }

    /**
     * Release the per-IP processing lock created by acquire_processing_lock().
     *
     * @param string $lock_key Transient key.
     * @return void
     */
    private function release_processing_lock( $lock_key ) {
        delete_transient( $lock_key );
    }

    /**
     * Build a stable rate-limit key from REMOTE_ADDR only.
     *
     * We intentionally ignore forwarded headers here. Trusting proxy headers
     * without site-specific configuration often makes rate limits easier to spoof.
     *
     * @return string
     */
    private function get_client_rate_key() {
        $ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );

        return md5( $ip );
    }
}
