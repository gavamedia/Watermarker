<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages custom font uploads for TCPDF rendering.
 */
class Watermarker_Font_Manager {

    /** Common Office fonts with TCPDF naming conventions. */
    private const FONT_FAMILIES = [
        'aptos' => [
            'label'    => 'Aptos',
            'variants' => [
                'regular'    => 'aptos',
                'bold'       => 'aptosb',
                'italic'     => 'aptosi',
                'bolditalic' => 'aptosbi',
            ],
        ],
        'calibri' => [
            'label'    => 'Calibri',
            'variants' => [
                'regular'    => 'calibri',
                'bold'       => 'calibrib',
                'italic'     => 'calibrii',
                'bolditalic' => 'calibribi',
            ],
        ],
        'cambria' => [
            'label'    => 'Cambria',
            'variants' => [
                'regular'    => 'cambria',
                'bold'       => 'cambriab',
                'italic'     => 'cambriai',
                'bolditalic' => 'cambriabi',
            ],
        ],
        'timesnewroman' => [
            'label'    => 'Times New Roman',
            'variants' => [
                'regular'    => 'timesnewroman',
                'bold'       => 'timesnewromanb',
                'italic'     => 'timesnewromani',
                'bolditalic' => 'timesnewromanbi',
            ],
        ],
        'arial' => [
            'label'    => 'Arial',
            'variants' => [
                'regular'    => 'arial',
                'bold'       => 'arialb',
                'italic'     => 'ariali',
                'bolditalic' => 'arialbi',
            ],
        ],
        'segoeui' => [
            'label'    => 'Segoe UI',
            'variants' => [
                'regular'    => 'segoeui',
                'bold'       => 'segoeuib',
                'italic'     => 'segoeuii',
                'bolditalic' => 'segoeuibi',
            ],
        ],
        'consolas' => [
            'label'    => 'Consolas',
            'variants' => [
                'regular'    => 'consolas',
                'bold'       => 'consolasb',
                'italic'     => 'consolasi',
                'bolditalic' => 'consolasbi',
            ],
        ],
    ];

    /**
     * Get the custom fonts directory path (inside the plugin).
     */
    public static function get_fonts_dir() {
        return WATERMARKER_PLUGIN_DIR . 'fonts/';
    }

    /**
     * Ensure the fonts directory exists.
     */
    public static function ensure_fonts_dir() {
        $dir = self::get_fonts_dir();
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
            file_put_contents( $dir . '.htaccess', "Deny from all\n" );
        }
        return $dir;
    }

    /**
     * Get the font families definition.
     */
    public static function get_font_families() {
        return self::FONT_FAMILIES;
    }

    /**
     * Get installed fonts status.
     * Returns: [ 'calibri' => [ 'regular' => true, 'bold' => false, ... ], ... ]
     */
    public static function get_installed_status() {
        $dir    = self::get_fonts_dir();
        $status = [];

        foreach ( self::FONT_FAMILIES as $key => $family ) {
            $status[ $key ] = [];
            foreach ( $family['variants'] as $variant => $tcpdf_name ) {
                $status[ $key ][ $variant ] = file_exists( $dir . $tcpdf_name . '.php' );
            }
        }

        return $status;
    }

    /**
     * Check if a specific font family has the regular variant installed.
     */
    public static function is_font_installed( $family_key ) {
        $dir = self::get_fonts_dir();
        $families = self::FONT_FAMILIES;
        if ( ! isset( $families[ $family_key ] ) ) {
            return false;
        }
        $tcpdf_name = $families[ $family_key ]['variants']['regular'];
        return file_exists( $dir . $tcpdf_name . '.php' );
    }

    /**
     * Get list of all installed font family labels (for the substitution system).
     */
    public static function get_installed_font_labels() {
        $labels = [];
        foreach ( self::FONT_FAMILIES as $key => $family ) {
            if ( self::is_font_installed( $key ) ) {
                $labels[] = $family['label'];
            }
        }
        return $labels;
    }

    /**
     * Process an uploaded TTF file for a specific font family and variant.
     *
     * @param string $tmp_path    Path to the uploaded temp file.
     * @param string $family_key  e.g. 'calibri'
     * @param string $variant_key e.g. 'bold'
     * @return true|\WP_Error
     */
    public static function install_font( $tmp_path, $family_key, $variant_key ) {
        $families = self::FONT_FAMILIES;
        if ( ! isset( $families[ $family_key ]['variants'][ $variant_key ] ) ) {
            return new \WP_Error( 'invalid_font', 'Unknown font family or variant.' );
        }

        // Validate it's a TTF file (magic bytes: 00 01 00 00 or 'true').
        $header = file_get_contents( $tmp_path, false, null, 0, 4 );
        if ( $header !== "\x00\x01\x00\x00" && $header !== 'true' ) {
            return new \WP_Error( 'invalid_ttf', 'The file does not appear to be a valid TTF font.' );
        }

        $fonts_dir  = self::ensure_fonts_dir();
        $tcpdf_name = $families[ $family_key ]['variants'][ $variant_key ];

        // Copy to temp location with canonical name for TCPDF.
        $temp_ttf = $fonts_dir . $tcpdf_name . '.ttf';
        if ( ! copy( $tmp_path, $temp_ttf ) ) {
            return new \WP_Error( 'copy_failed', 'Failed to copy font file.' );
        }

        // Convert using TCPDF.
        require_once WATERMARKER_PLUGIN_DIR . 'vendor/tecnickcom/tcpdf/include/tcpdf_fonts.php';

        $result = \TCPDF_FONTS::addTTFfont( $temp_ttf, 'TrueTypeUnicode', '', 32, $fonts_dir );

        // Clean up the TTF source (TCPDF creates .php, .z, .ctg.z).
        @unlink( $temp_ttf );

        if ( ! $result ) {
            return new \WP_Error( 'conversion_failed', 'TCPDF failed to convert the font. Ensure it is a valid TrueType (.ttf) file.' );
        }

        // Verify the definition file was created.
        if ( ! file_exists( $fonts_dir . $tcpdf_name . '.php' ) ) {
            return new \WP_Error( 'missing_output', 'Font conversion completed but the output file was not found.' );
        }

        return true;
    }

    /**
     * Remove a font variant.
     */
    public static function delete_font( $family_key, $variant_key ) {
        $families = self::FONT_FAMILIES;
        if ( ! isset( $families[ $family_key ]['variants'][ $variant_key ] ) ) {
            return false;
        }

        $dir        = self::get_fonts_dir();
        $tcpdf_name = $families[ $family_key ]['variants'][ $variant_key ];

        @unlink( $dir . $tcpdf_name . '.php' );
        @unlink( $dir . $tcpdf_name . '.z' );
        @unlink( $dir . $tcpdf_name . '.ctg.z' );

        return true;
    }

    /**
     * Register all installed custom fonts with a TCPDF instance.
     * Must be called before writeHTML().
     */
    public static function register_fonts_with_tcpdf( $pdf ) {
        $dir = self::get_fonts_dir();
        if ( ! is_dir( $dir ) ) {
            return;
        }

        $style_map = [
            'regular'    => '',
            'bold'       => 'B',
            'italic'     => 'I',
            'bolditalic' => 'BI',
        ];

        foreach ( self::FONT_FAMILIES as $key => $family ) {
            foreach ( $family['variants'] as $variant => $tcpdf_name ) {
                $def_file = $dir . $tcpdf_name . '.php';
                if ( file_exists( $def_file ) ) {
                    $pdf->AddFont( $tcpdf_name, '', $def_file );
                }
            }
        }
    }
}
