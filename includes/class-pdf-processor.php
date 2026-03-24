<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use setasign\Fpdi\Fpdi;

/**
 * Handles all PDF generation: merging uploaded content with the letterhead template.
 */
class Watermarker_PDF_Processor {

    /** Image extensions that FPDF can handle natively. */
    private const NATIVE_IMAGE_EXT = [ 'jpg', 'jpeg', 'png', 'gif' ];

    /** Image extensions we can convert via GD / Imagick. */
    private const ALL_IMAGE_EXT = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif' ];

    /** Extensions we send to LibreOffice for conversion. */
    private const OFFICE_EXT = [ 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'rtf', 'txt', 'html', 'htm', 'odt', 'ods', 'odp', 'csv' ];

    /**
     * Process an uploaded file and overlay it on the letterhead.
     *
     * @param  string $uploaded_path   Absolute path to the uploaded file.
     * @param  string $letterhead_path Absolute path to the letterhead template (PDF or image).
     * @param  bool   $apply_all       Apply letterhead to all pages (true) or first only (false).
     * @return string Absolute path to the generated PDF.
     * @throws Exception On any processing error.
     */
    public function process( $uploaded_path, $letterhead_path, $apply_all = true ) {
        $ext          = strtolower( pathinfo( $uploaded_path, PATHINFO_EXTENSION ) );
        $lh_ext       = strtolower( pathinfo( $letterhead_path, PATHINFO_EXTENSION ) );
        $lh_is_pdf    = ( 'pdf' === $lh_ext );
        $is_image     = in_array( $ext, self::ALL_IMAGE_EXT, true );
        $temp_files   = []; // Track temp files for cleanup.

        try {
            // --- Resolve content to either a PDF path or an image path --------
            $content_pdf = null;

            if ( 'pdf' === $ext ) {
                $content_pdf = $uploaded_path;
            } elseif ( $is_image ) {
                // Images are placed directly; no conversion to PDF needed.
                $content_pdf = null;
            } elseif ( in_array( $ext, self::OFFICE_EXT, true ) ) {
                $content_pdf   = $this->convert_office_to_pdf( $uploaded_path, $ext );
                $temp_files[]  = $content_pdf;
            } else {
                throw new \Exception( "Unsupported file format: .{$ext}" );
            }

            // --- Build the output PDF -----------------------------------------
            $pdf = new Fpdi();
            $pdf->SetAutoPageBreak( false );

            // Import letterhead template(s).
            $lh_templates = [];
            if ( $lh_is_pdf ) {
                $lh_page_count  = $pdf->setSourceFile( $letterhead_path );
                $lh_templates[] = $pdf->importPage( 1 );
                if ( $lh_page_count >= 2 ) {
                    $lh_templates[] = $pdf->importPage( 2 );
                }
            }

            // Determine default page dimensions from the letterhead.
            if ( ! empty( $lh_templates ) ) {
                $lh_size = $pdf->getTemplateSize( $lh_templates[0] );
                $def_w   = $lh_size['width'];
                $def_h   = $lh_size['height'];
                $def_o   = $lh_size['orientation'];
            } else {
                // Fallback: A4.
                $def_w = 210;
                $def_h = 297;
                $def_o = 'P';
            }

            if ( $is_image ) {
                // --- Single-page output with image centred --------------------
                $pdf->AddPage( $def_o, [ $def_w, $def_h ] );
                $this->apply_letterhead( $pdf, $letterhead_path, $lh_is_pdf, $lh_templates, 0, $def_w, $def_h );

                $compat_image = $this->ensure_compatible_image( $uploaded_path );
                if ( $compat_image !== $uploaded_path ) {
                    $temp_files[] = $compat_image;
                }
                $this->place_image_centred( $pdf, $compat_image, $def_w, $def_h );

            } elseif ( $content_pdf ) {
                // --- Multi-page PDF overlay -----------------------------------
                $page_count = $pdf->setSourceFile( $content_pdf );

                for ( $i = 1; $i <= $page_count; $i++ ) {
                    $tpl  = $pdf->importPage( $i );
                    $size = $pdf->getTemplateSize( $tpl );

                    $pdf->AddPage( $size['orientation'], [ $size['width'], $size['height'] ] );

                    $show_lh = ( 1 === $i ) || $apply_all;
                    if ( $show_lh ) {
                        $this->apply_letterhead(
                            $pdf,
                            $letterhead_path,
                            $lh_is_pdf,
                            $lh_templates,
                            $i - 1, // 0-based page index for letterhead selection.
                            $size['width'],
                            $size['height']
                        );
                    }

                    // Content on top.
                    $pdf->useTemplate( $tpl );
                }
            }

            $output = tempnam( sys_get_temp_dir(), 'watermarker_out_' ) . '.pdf';
            $pdf->Output( 'F', $output );

            return $output;

        } finally {
            foreach ( $temp_files as $f ) {
                if ( file_exists( $f ) ) {
                    @unlink( $f );
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Letterhead helpers
    // ------------------------------------------------------------------

    /**
     * Place the letterhead background on the current page.
     *
     * @param Fpdi   $pdf          The FPDI instance.
     * @param string $lh_path      Path to the letterhead file.
     * @param bool   $lh_is_pdf    Whether the letterhead is a PDF.
     * @param array  $lh_templates Array of imported FPDI template IDs (may be empty for image letterheads).
     * @param int    $page_index   0-based page index of the current content page.
     * @param float  $page_w       Target page width in mm.
     * @param float  $page_h       Target page height in mm.
     */
    private function apply_letterhead( $pdf, $lh_path, $lh_is_pdf, $lh_templates, $page_index, $page_w, $page_h ) {
        if ( $lh_is_pdf && ! empty( $lh_templates ) ) {
            // Use page-2 template for subsequent pages if available, otherwise page-1.
            $tpl_index = ( $page_index >= 1 && isset( $lh_templates[1] ) ) ? 1 : 0;
            $pdf->useTemplate( $lh_templates[ $tpl_index ], 0, 0, $page_w, $page_h );
        } else {
            // Image letterhead — stretch to fill page.
            $compat = $this->ensure_compatible_image( $lh_path );
            $pdf->Image( $compat, 0, 0, $page_w, $page_h );
        }
    }

    // ------------------------------------------------------------------
    // Image helpers
    // ------------------------------------------------------------------

    /**
     * Centre and fit an image within the given page dimensions.
     * Maintains aspect ratio; does not crop or upscale.
     */
    private function place_image_centred( $pdf, $image_path, $page_w, $page_h ) {
        $info = @getimagesize( $image_path );
        if ( ! $info ) {
            throw new \Exception( 'Could not read image dimensions.' );
        }

        $img_w_px = $info[0];
        $img_h_px = $info[1];

        // Px → mm at 96 DPI.
        $px_mm   = 25.4 / 96;
        $img_w   = $img_w_px * $px_mm;
        $img_h   = $img_h_px * $px_mm;

        $margin  = 20; // mm safety margin.
        $avail_w = $page_w - 2 * $margin;
        $avail_h = $page_h - 2 * $margin;

        $scale     = min( $avail_w / $img_w, $avail_h / $img_h, 1 );
        $display_w = $img_w * $scale;
        $display_h = $img_h * $scale;

        $x = ( $page_w - $display_w ) / 2;
        $y = ( $page_h - $display_h ) / 2;

        $pdf->Image( $image_path, $x, $y, $display_w, $display_h );
    }

    /**
     * Convert non-native image formats (webp, bmp, tiff) to PNG so FPDF can use them.
     * Returns the original path unchanged for jpg/png/gif.
     */
    private function ensure_compatible_image( $path ) {
        static $cache = [];
        if ( isset( $cache[ $path ] ) ) {
            return $cache[ $path ];
        }

        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        if ( in_array( $ext, self::NATIVE_IMAGE_EXT, true ) ) {
            $cache[ $path ] = $path;
            return $path;
        }

        // Try GD first.
        $gd_image = null;
        switch ( $ext ) {
            case 'webp':
                if ( function_exists( 'imagecreatefromwebp' ) ) {
                    $gd_image = @imagecreatefromwebp( $path );
                }
                break;
            case 'bmp':
                if ( function_exists( 'imagecreatefrombmp' ) ) {
                    $gd_image = @imagecreatefrombmp( $path );
                }
                break;
        }

        if ( $gd_image ) {
            $png = tempnam( sys_get_temp_dir(), 'wm_img_' ) . '.png';
            imagepng( $gd_image, $png );
            imagedestroy( $gd_image );
            $cache[ $path ] = $png;
            return $png;
        }

        // Fall back to Imagick (handles tiff and others).
        if ( class_exists( 'Imagick' ) ) {
            $im  = new \Imagick( $path );
            $png = tempnam( sys_get_temp_dir(), 'wm_img_' ) . '.png';
            $im->setImageFormat( 'png' );
            $im->writeImage( $png );
            $im->destroy();
            $cache[ $path ] = $png;
            return $png;
        }

        throw new \Exception( "Cannot convert .{$ext} image — neither GD nor Imagick could handle it." );
    }

    // ------------------------------------------------------------------
    // Office document conversion
    // ------------------------------------------------------------------

    /** Extensions that PhpWord can handle natively (no shell needed). */
    private const PHPWORD_EXT = [ 'docx', 'rtf', 'html', 'htm' ];

    private function convert_office_to_pdf( $file_path, $ext ) {
        // Try PHP-native conversion first for supported formats.
        if ( in_array( $ext, self::PHPWORD_EXT, true ) ) {
            return $this->convert_with_phpword( $file_path, $ext );
        }

        // Fall back to LibreOffice for everything else.
        return $this->convert_with_libreoffice( $file_path );
    }

    private function convert_with_phpword( $file_path, $ext ) {
        $reader_map = [
            'docx' => 'Word2007',
            'rtf'  => 'RTF',
            'html' => 'HTML',
            'htm'  => 'HTML',
        ];

        $reader_name = $reader_map[ $ext ] ?? null;
        if ( ! $reader_name ) {
            throw new \Exception( "No PHP-native reader for .{$ext} files." );
        }

        $phpWord = \PhpOffice\PhpWord\IOFactory::createReader( $reader_name )->load( $file_path );

        $output = tempnam( sys_get_temp_dir(), 'wm_phpword_' ) . '.pdf';

        \PhpOffice\PhpWord\Settings::setPdfRendererName( \PhpOffice\PhpWord\Settings::PDF_RENDERER_TCPDF );
        \PhpOffice\PhpWord\Settings::setPdfRendererPath( WATERMARKER_PLUGIN_DIR . 'vendor/tecnickcom/tcpdf' );

        $writer = \PhpOffice\PhpWord\IOFactory::createWriter( $phpWord, 'PDF' );
        $writer->save( $output );

        if ( ! file_exists( $output ) || filesize( $output ) === 0 ) {
            throw new \Exception( 'PHP-native DOCX to PDF conversion failed.' );
        }

        return $output;
    }

    // ------------------------------------------------------------------
    // LibreOffice conversion (fallback for xls, ppt, odt, etc.)
    // ------------------------------------------------------------------

    private function convert_with_libreoffice( $file_path ) {
        $lo = self::find_libreoffice();
        if ( ! $lo ) {
            throw new \Exception(
                'LibreOffice is required to convert this file type but was not found on the server. '
                . 'Please install LibreOffice or upload a PDF instead.'
            );
        }

        $out_dir = sys_get_temp_dir();
        $cmd     = sprintf(
            '%s --headless --convert-to pdf --outdir %s %s 2>&1',
            escapeshellarg( $lo ),
            escapeshellarg( $out_dir ),
            escapeshellarg( $file_path )
        );

        $code   = 1;
        $output = [];

        if ( self::function_available( 'exec' ) ) {
            exec( $cmd, $output, $code );
        } elseif ( self::function_available( 'shell_exec' ) ) {
            $result = @shell_exec( $cmd );
            $output = $result ? explode( "\n", $result ) : [];
            $code   = ( $result !== null && $result !== false ) ? 0 : 1;
        } elseif ( function_exists( 'proc_open' ) ) {
            $proc = proc_open( $cmd, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
            if ( is_resource( $proc ) ) {
                $result = stream_get_contents( $pipes[1] );
                fclose( $pipes[1] );
                fclose( $pipes[2] );
                $code   = proc_close( $proc );
                $output = explode( "\n", $result );
            }
        } else {
            throw new \Exception( 'No shell execution function is available (exec, shell_exec, proc_open). Please ask your host to enable one.' );
        }

        if ( 0 !== $code ) {
            throw new \Exception( 'LibreOffice conversion failed: ' . implode( "\n", $output ) );
        }

        $pdf_path = $out_dir . '/' . pathinfo( $file_path, PATHINFO_FILENAME ) . '.pdf';
        if ( ! file_exists( $pdf_path ) ) {
            throw new \Exception( 'Conversion completed but the output PDF was not found.' );
        }

        return $pdf_path;
    }

    /**
     * Locate the LibreOffice binary on the system.
     *
     * @return string|null Path to the binary, or null if not found.
     */
    private static function function_available( $name ) {
        return function_exists( $name ) && ! in_array( $name, array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true );
    }

    public static function find_libreoffice() {
        // Try the PATH first (skip if shell_exec is disabled).
        if ( self::function_available( 'shell_exec' ) ) {
            foreach ( [ 'libreoffice', 'soffice' ] as $bin ) {
                $result = trim( (string) @shell_exec( 'which ' . escapeshellarg( $bin ) . ' 2>/dev/null' ) );
                if ( $result && is_executable( $result ) ) {
                    return $result;
                }
            }
        }

        // Well-known install paths.
        $paths = [
            '/usr/bin/libreoffice',
            '/usr/local/bin/libreoffice',
            '/usr/bin/soffice',
            '/snap/bin/libreoffice',
            '/Applications/LibreOffice.app/Contents/MacOS/soffice',
        ];
        foreach ( $paths as $p ) {
            if ( file_exists( $p ) && is_executable( $p ) ) {
                return $p;
            }
        }

        return null;
    }
}
