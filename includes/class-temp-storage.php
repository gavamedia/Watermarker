<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Centralized management for Watermarker's temporary files.
 *
 * Why this class exists:
 * - Processed documents must never live in a web-accessible uploads directory.
 *   A guessed URL should not be enough to download someone else's document.
 * - The plugin creates several short-lived files per request (uploads, converted
 *   PDFs, LibreOffice profiles, image conversions). Keeping all of them in one
 *   private location makes cleanup predictable.
 * - Hosts vary widely, so we probe a small set of temp-directory candidates and
 *   reject any candidate that lives inside public WordPress directories.
 */
class Watermarker_Temp_Storage {

    /**
     * Private subdirectory used inside the selected temp base.
     */
    private const PRIVATE_SUBDIR = 'watermarker';

    /**
     * Legacy public directory from older plugin versions.
     *
     * We still know about this path so upgrades can clean up old files that may
     * have been left behind before temp storage moved out of uploads/.
     */
    private const LEGACY_PUBLIC_SUBDIR = 'watermarker-temp';

    /**
     * Cache the resolved directory for the current request.
     *
     * @var string|null
     */
    private static $private_temp_dir = null;

    /**
     * Return the plugin's private temp directory, creating it when needed.
     *
     * The returned directory is guaranteed to be outside uploads/, the plugin
     * directory, and ABSPATH. If no safe writable location exists, we fail closed
     * with a clear error rather than silently storing documents in a public path.
     *
     * @return string
     * @throws RuntimeException When no private writable temp directory is available.
     */
    public static function get_private_temp_dir() {
        if ( null !== self::$private_temp_dir ) {
            return self::$private_temp_dir;
        }

        foreach ( self::get_temp_base_candidates() as $base_dir ) {
            if ( ! self::is_safe_temp_base( $base_dir ) ) {
                continue;
            }

            $dir = trailingslashit( $base_dir ) . self::PRIVATE_SUBDIR;
            if ( self::ensure_directory( $dir ) ) {
                self::$private_temp_dir = trailingslashit( wp_normalize_path( $dir ) );
                return self::$private_temp_dir;
            }
        }

        throw new \RuntimeException(
            'Watermarker could not find a writable private temp directory outside the public WordPress folders. '
            . 'Configure WP_TEMP_DIR to point to a non-public writable path such as /tmp.'
        );
    }

    /**
     * Generate a unique private file path without exposing the original filename.
     *
     * Physical files use opaque names so the filesystem path cannot be guessed.
     * The user-facing filename is stored separately in the download transient.
     *
     * @param string $prefix    Short prefix describing the file purpose.
     * @param string $extension Optional extension without or with a leading dot.
     * @return string
     */
    public static function generate_file_path( $prefix, $extension = '' ) {
        $extension = ltrim( (string) $extension, '.' );

        do {
            $filename = sanitize_file_name(
                $prefix . str_replace( '-', '', wp_generate_uuid4() )
            );
            $path = self::get_private_temp_dir() . $filename;
            if ( '' !== $extension ) {
                $path .= '.' . $extension;
            }
        } while ( file_exists( $path ) );

        return $path;
    }

    /**
     * Create and return a unique private directory path.
     *
     * This is primarily used for isolated LibreOffice profiles so concurrent
     * conversions cannot stomp on each other's state.
     *
     * @param string $prefix Short prefix describing the directory purpose.
     * @return string
     * @throws RuntimeException When the directory cannot be created.
     */
    public static function generate_directory_path( $prefix ) {
        do {
            $dir = self::get_private_temp_dir()
                . sanitize_file_name( $prefix . str_replace( '-', '', wp_generate_uuid4() ) );
        } while ( file_exists( $dir ) );

        if ( ! @mkdir( $dir, 0700 ) ) {
            throw new \RuntimeException( 'Failed to create a private working directory for Watermarker.' );
        }

        return trailingslashit( wp_normalize_path( $dir ) );
    }

    /**
     * Check whether a path belongs to Watermarker's managed private temp storage.
     *
     * This protects downloads and cleanup from ever touching arbitrary files if a
     * transient becomes corrupted or is manually edited.
     *
     * @param string $path Absolute path to verify.
     * @return bool
     */
    public static function is_managed_path( $path ) {
        if ( empty( $path ) ) {
            return false;
        }

        try {
            $managed_root = wp_normalize_path( realpath( self::get_private_temp_dir() ) ?: self::get_private_temp_dir() );
        } catch ( \Throwable $e ) {
            return false;
        }

        $candidate = wp_normalize_path( realpath( $path ) ?: $path );

        return 0 === strpos( $candidate, trailingslashit( $managed_root ) );
    }

    /**
     * Remove stale temp files and legacy public leftovers.
     *
     * @param int $max_age Maximum allowed age in seconds.
     * @return void
     */
    public static function cleanup_old_files( $max_age = 7200 ) {
        $cutoff = time() - max( 300, (int) $max_age );

        try {
            self::cleanup_directory_contents( self::get_private_temp_dir(), $cutoff );
        } catch ( \Throwable $e ) {
            // Cleanup should never block user-facing uploads.
        }

        self::cleanup_directory_contents( self::get_legacy_public_temp_dir(), $cutoff );
    }

    /**
     * Remove all managed temp files, including the legacy public temp directory.
     *
     * Used by uninstall so the plugin can clean up after itself.
     *
     * @return void
     */
    public static function purge_all() {
        try {
            self::recursive_rmdir( self::get_private_temp_dir() );
        } catch ( \Throwable $e ) {
            // Ignore cleanup failures during uninstall.
        }

        self::recursive_rmdir( self::get_legacy_public_temp_dir() );
    }

    /**
     * Build the old uploads-based temp directory path used by previous versions.
     *
     * @return string
     */
    private static function get_legacy_public_temp_dir() {
        $upload_dir = wp_upload_dir();
        $base_dir   = ! empty( $upload_dir['basedir'] ) ? $upload_dir['basedir'] : '';

        return $base_dir
            ? trailingslashit( wp_normalize_path( $base_dir ) ) . self::LEGACY_PUBLIC_SUBDIR . '/'
            : '';
    }

    /**
     * Return the candidate base directories to probe for private temp storage.
     *
     * Order matters:
     * 1. WP_TEMP_DIR lets site owners explicitly choose a safe path.
     * 2. upload_tmp_dir often points at the PHP/webserver temp location.
     * 3. sys_get_temp_dir() is the common portable fallback.
     * 4. get_temp_dir() is last because WordPress may fall back to public paths.
     *
     * @return string[]
     */
    private static function get_temp_base_candidates() {
        $candidates = [];

        if ( defined( 'WP_TEMP_DIR' ) && WP_TEMP_DIR ) {
            $candidates[] = WP_TEMP_DIR;
        }

        $upload_tmp_dir = (string) ini_get( 'upload_tmp_dir' );
        if ( '' !== $upload_tmp_dir ) {
            $candidates[] = $upload_tmp_dir;
        }

        if ( function_exists( 'sys_get_temp_dir' ) ) {
            $candidates[] = sys_get_temp_dir();
        }

        if ( function_exists( 'get_temp_dir' ) ) {
            $candidates[] = get_temp_dir();
        }

        $normalized = [];
        foreach ( $candidates as $candidate ) {
            if ( ! is_string( $candidate ) || '' === trim( $candidate ) ) {
                continue;
            }
            $normalized[] = trailingslashit( wp_normalize_path( trim( $candidate ) ) );
        }

        return array_values( array_unique( $normalized ) );
    }

    /**
     * Determine whether a temp base is safe to use for private document storage.
     *
     * We reject any path inside ABSPATH, uploads/, or the plugin directory because
     * those locations are commonly web-accessible. The plugin should fail closed
     * rather than relying on server-specific deny rules to protect documents.
     *
     * @param string $base_dir Candidate base directory.
     * @return bool
     */
    private static function is_safe_temp_base( $base_dir ) {
        if ( '' === $base_dir ) {
            return false;
        }

        $normalized_base = wp_normalize_path( realpath( $base_dir ) ?: $base_dir );
        $normalized_base = trailingslashit( $normalized_base );

        $plugin_dir = defined( 'WATERMARKER_PLUGIN_DIR' )
            ? WATERMARKER_PLUGIN_DIR
            : dirname( __DIR__ ) . '/';

        $public_roots = [
            trailingslashit( wp_normalize_path( realpath( ABSPATH ) ?: ABSPATH ) ),
            trailingslashit( wp_normalize_path( realpath( $plugin_dir ) ?: $plugin_dir ) ),
        ];

        $upload_dir = wp_upload_dir();
        if ( ! empty( $upload_dir['basedir'] ) ) {
            $public_roots[] = trailingslashit(
                wp_normalize_path( realpath( $upload_dir['basedir'] ) ?: $upload_dir['basedir'] )
            );
        }

        foreach ( $public_roots as $public_root ) {
            if ( 0 === strpos( $normalized_base, $public_root ) ) {
                return false;
            }
        }

        $parent_dir = is_dir( $normalized_base )
            ? $normalized_base
            : trailingslashit( wp_normalize_path( dirname( untrailingslashit( $normalized_base ) ) ) );

        return is_dir( $parent_dir ) && is_writable( $parent_dir );
    }

    /**
     * Create a directory if needed and verify it is writable.
     *
     * @param string $dir Directory path.
     * @return bool
     */
    private static function ensure_directory( $dir ) {
        if ( is_dir( $dir ) ) {
            return is_writable( $dir );
        }

        if ( ! wp_mkdir_p( $dir ) ) {
            return false;
        }

        return is_dir( $dir ) && is_writable( $dir );
    }

    /**
     * Remove stale top-level files/directories inside a managed temp directory.
     *
     * We only delete entries older than the cutoff so current uploads/conversions
     * cannot be swept out from under an active request. Directories are removed
     * recursively because LibreOffice profiles contain nested files.
     *
     * @param string $dir    Directory to inspect.
     * @param int    $cutoff Unix timestamp cutoff.
     * @return void
     */
    private static function cleanup_directory_contents( $dir, $cutoff ) {
        if ( empty( $dir ) || ! is_dir( $dir ) ) {
            return;
        }

        $items = @scandir( $dir );
        if ( ! $items ) {
            return;
        }

        foreach ( $items as $item ) {
            if ( '.' === $item || '..' === $item ) {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            $mtime = @filemtime( $path );

            if ( false === $mtime || $mtime >= $cutoff ) {
                continue;
            }

            if ( is_dir( $path ) ) {
                self::recursive_rmdir( $path );
            } elseif ( is_file( $path ) ) {
                @unlink( $path );
            }
        }
    }

    /**
     * Recursively delete a directory using scandir() so hidden files are included.
     *
     * glob() ignores dotfiles by default; uninstall and cleanup must handle files
     * such as .htaccess or any future hidden markers reliably.
     *
     * @param string $dir Directory to remove.
     * @return void
     */
    private static function recursive_rmdir( $dir ) {
        if ( empty( $dir ) || ! is_dir( $dir ) ) {
            return;
        }

        $items = @scandir( $dir );
        if ( ! $items ) {
            return;
        }

        foreach ( $items as $item ) {
            if ( '.' === $item || '..' === $item ) {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if ( is_dir( $path ) ) {
                self::recursive_rmdir( $path );
            } else {
                @unlink( $path );
            }
        }

        @rmdir( $dir );
    }
}
