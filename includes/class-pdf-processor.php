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

                    // Content first (as base layer — may have opaque background).
                    $pdf->useTemplate( $tpl );

                    // Letterhead on top (designed with transparent center area).
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

    /** Extensions that PhpWord can handle as a last resort (no shell needed). */
    private const PHPWORD_EXT = [ 'docx', 'rtf', 'html', 'htm' ];

    /**
     * Font substitution map: each entry lists fallbacks in priority order.
     * The first font found on the system wins. If none are found, the last
     * entry is used as a safe default (core fonts available everywhere).
     */
    private const FONT_MAP = [
        'Aptos'            => [ 'Aptos', 'Helvetica Neue', 'Helvetica', 'Arial' ],
        'Aptos Display'    => [ 'Aptos Display', 'Helvetica Neue', 'Helvetica', 'Arial' ],
        'Aptos Narrow'     => [ 'Aptos Narrow', 'Helvetica Neue', 'Helvetica', 'Arial' ],
        'Calibri'          => [ 'Calibri', 'Helvetica Neue', 'Helvetica', 'Arial' ],
        'Calibri Light'    => [ 'Calibri Light', 'Helvetica Neue Light', 'Helvetica', 'Arial' ],
        'Cambria'          => [ 'Cambria', 'Times New Roman', 'Times' ],
        'Segoe UI'         => [ 'Segoe UI', 'Helvetica Neue', 'Helvetica', 'Arial' ],
        'Consolas'         => [ 'Consolas', 'Courier New', 'Courier' ],
        'Cascadia Code'    => [ 'Cascadia Code', 'Courier New', 'Courier' ],
        'Cascadia Mono'    => [ 'Cascadia Mono', 'Courier New', 'Courier' ],
    ];

    /** Cache of resolved font substitutions. */
    private static $resolved_fonts = null;

    /**
     * Build the effective font map by checking which fonts are installed.
     * Returns [ 'Aptos' => 'Times New Roman', ... ] with only the entries
     * that actually need substituting.
     */
    private static function get_font_substitutions() {
        if ( null !== self::$resolved_fonts ) {
            return self::$resolved_fonts;
        }

        $installed = self::get_installed_fonts();
        self::$resolved_fonts = [];

        // Check which fonts have been uploaded via the Font Manager.
        $uploaded_labels = class_exists( 'Watermarker_Font_Manager' )
            ? Watermarker_Font_Manager::get_installed_font_labels()
            : [];

        foreach ( self::FONT_MAP as $original => $fallbacks ) {
            // If the original font is installed on the system, no substitution needed.
            if ( isset( $installed[ strtolower( $original ) ] ) ) {
                continue;
            }
            // If the font was uploaded via the Font Manager, no substitution needed.
            if ( in_array( $original, $uploaded_labels, true ) ) {
                continue;
            }
            // Find the first available fallback.
            foreach ( $fallbacks as $candidate ) {
                if ( $candidate === $original ) {
                    continue;
                }
                if ( isset( $installed[ strtolower( $candidate ) ] ) ) {
                    self::$resolved_fonts[ $original ] = $candidate;
                    break;
                }
            }
            // If nothing found, use the last fallback (core font, should always exist).
            if ( ! isset( self::$resolved_fonts[ $original ] ) ) {
                self::$resolved_fonts[ $original ] = end( $fallbacks );
            }
        }

        return self::$resolved_fonts;
    }

    /**
     * Get a set of installed font family names (lowercased) on this system.
     */
    private static function get_installed_fonts() {
        static $cache = null;
        if ( null !== $cache ) {
            return $cache;
        }

        $cache = [];
        $dirs  = [];

        if ( PHP_OS_FAMILY === 'Darwin' ) {
            $dirs = [
                '/System/Library/Fonts',
                '/Library/Fonts',
                getenv( 'HOME' ) . '/Library/Fonts',
            ];
        } elseif ( PHP_OS_FAMILY === 'Linux' ) {
            $dirs = [
                '/usr/share/fonts',
                '/usr/local/share/fonts',
                getenv( 'HOME' ) . '/.fonts',
            ];
        } elseif ( PHP_OS_FAMILY === 'Windows' ) {
            $dirs = [ getenv( 'WINDIR' ) . '\\Fonts' ];
        }

        foreach ( $dirs as $dir ) {
            if ( ! is_dir( $dir ) ) {
                continue;
            }
            $it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
            foreach ( $it as $file ) {
                $ext = strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );
                if ( in_array( $ext, [ 'ttf', 'otf', 'ttc', 'woff', 'woff2' ], true ) ) {
                    // Derive family name from filename (e.g. "TimesNewRoman-Bold.ttf" → "times new roman").
                    $name = pathinfo( $file->getFilename(), PATHINFO_FILENAME );
                    $name = preg_replace( '/[-_](Bold|Italic|Light|Regular|Medium|Thin|Semi|Demi|Extra|Condensed|BoldItalic|It|Bd|Rg|Lt|Bk|Blk).*$/i', '', $name );
                    $name = preg_replace( '/([a-z])([A-Z])/', '$1 $2', $name ); // CamelCase → spaces.
                    $cache[ strtolower( trim( $name ) ) ] = true;
                }
            }
        }

        return $cache;
    }

    private function convert_office_to_pdf( $file_path, $ext ) {
        // Pre-process DOCX: fix fonts and spacing directly in the ZIP XML.
        $preprocessed = null;
        if ( 'docx' === $ext ) {
            $preprocessed = $this->preprocess_docx( $file_path );
            if ( $preprocessed ) {
                $file_path = $preprocessed;
            }
        }

        // Try LibreOffice first (much better quality).
        $has_shell = self::function_available( 'exec' )
                  || self::function_available( 'shell_exec' )
                  || function_exists( 'proc_open' );

        if ( $has_shell && self::find_libreoffice() ) {
            try {
                $result = $this->convert_with_libreoffice( $file_path );
                if ( $preprocessed ) { @unlink( $preprocessed ); }
                return $result;
            } catch ( \Exception $e ) {
                // Fall through to PhpWord.
            }
        }

        // Fall back to PhpWord for supported formats.
        if ( in_array( $ext, self::PHPWORD_EXT, true ) ) {
            $result = $this->convert_with_phpword( $file_path, $ext );
            if ( $preprocessed ) { @unlink( $preprocessed ); }
            return $result;
        }

        if ( $preprocessed ) { @unlink( $preprocessed ); }
        throw new \Exception(
            'LibreOffice is required to convert this file type but is not available. '
            . 'Please upload a PDF instead.'
        );
    }

    /**
     * Pre-process a DOCX file: replace Microsoft fonts and normalize paragraph spacing.
     * DOCX is a ZIP archive — we modify the XML inside directly.
     *
     * @return string|null Path to the modified DOCX temp file, or null on failure.
     */
    private function preprocess_docx( $file_path ) {
        $tmp = tempnam( sys_get_temp_dir(), 'wm_docx_' ) . '.docx';
        if ( ! copy( $file_path, $tmp ) ) {
            return null;
        }

        $zip = new \ZipArchive();
        if ( $zip->open( $tmp ) !== true ) {
            @unlink( $tmp );
            return null;
        }

        // Process ALL xml files in the DOCX, not just a hardcoded list.
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $name = $zip->getNameIndex( $i );
            if ( ! preg_match( '/\.xml$|\.rels$/i', $name ) ) {
                continue;
            }

            $xml = $zip->getFromName( $name );
            if ( false === $xml ) {
                continue;
            }

            $modified = false;

            // Replace fonts only when the original isn't installed on this system.
            foreach ( self::get_font_substitutions() as $from => $to ) {
                $count = 0;
                $xml = str_ireplace(
                    [ 'w:ascii="' . $from . '"', 'w:hAnsi="' . $from . '"', 'w:cs="' . $from . '"', 'w:eastAsia="' . $from . '"', 'val="' . $from . '"' ],
                    [ 'w:ascii="' . $to . '"',   'w:hAnsi="' . $to . '"',   'w:cs="' . $to . '"',   'w:eastAsia="' . $to . '"',   'val="' . $to . '"' ],
                    $xml,
                    $count
                );
                if ( $count > 0 ) { $modified = true; }
            }

            // Remove autospacing attributes — PhpWord and LibreOffice both
            // interpret these very differently from Word. The paragraph-level
            // before/after values should be used as-is instead.
            $xml = preg_replace( '/\s*w:beforeAutospacing="[^"]*"/', '', $xml, -1, $count );
            if ( $count > 0 ) { $modified = true; }
            $xml = preg_replace( '/\s*w:afterAutospacing="[^"]*"/', '', $xml, -1, $count );
            if ( $count > 0 ) { $modified = true; }

            // Remove contextualSpacing — LibreOffice/PhpWord disagree with Word.
            $xml = preg_replace( '/<w:contextualSpacing[^\/]*\/>/', '', $xml, -1, $count );
            if ( $count > 0 ) { $modified = true; }
            $xml = preg_replace( '/<w:contextualSpacing[^>]*>[^<]*<\/w:contextualSpacing>/', '', $xml, -1, $count );
            if ( $count > 0 ) { $modified = true; }

            if ( $modified ) {
                $zip->addFromString( $name, $xml );
            }
        }

        $zip->close();
        return $tmp;
    }

    /**
     * Parse styles.xml to extract the line spacing for each named style
     * and the document default. Maps both styleId and w:name to handle
     * PhpWord's inconsistent naming (registry uses w:name, elements use styleId).
     */
    /**
     * Word's "single spacing" is font-metric-based, not 1.0x font size.
     * For typical Western fonts, single line height ≈ 1.15x the font size.
     * So: CSS line-height = (w:line / 240) * WORD_SINGLE_LINE_FACTOR
     */
    private const WORD_SINGLE_LINE_FACTOR = 1.15;

    private function read_docx_spacing_map( $file_path ) {
        $result = [
            'default' => self::WORD_SINGLE_LINE_FACTOR, // Single spacing default.
            'styles'  => [],
        ];

        $ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        if ( 'docx' !== $ext ) {
            return $result;
        }

        $zip = new \ZipArchive();
        if ( $zip->open( $file_path, \ZipArchive::RDONLY ) !== true ) {
            return $result;
        }

        $styles_xml = $zip->getFromName( 'word/styles.xml' );
        $zip->close();

        if ( false === $styles_xml ) {
            return $result;
        }

        // 1. Document default from pPrDefault.
        if ( preg_match( '/<w:pPrDefault>.*?<\/w:pPrDefault>/s', $styles_xml, $dm ) ) {
            if ( preg_match( '/w:line="(\d+)"/', $dm[0], $lm ) ) {
                $result['default'] = ( (int) $lm[1] / 240.0 ) * self::WORD_SINGLE_LINE_FACTOR;
            }
        }

        // 2. Per-style line spacing — map both styleId and w:name.
        if ( preg_match_all( '/<w:style\b[^>]*>.*?<\/w:style>/s', $styles_xml, $sm ) ) {
            foreach ( $sm[0] as $block ) {
                // Extract styleId and w:name.
                $style_id = null;
                $style_name = null;
                if ( preg_match( '/w:styleId="([^"]+)"/', $block, $idm ) ) {
                    $style_id = $idm[1];
                }
                if ( preg_match( '/<w:name\s+w:val="([^"]+)"/', $block, $nm ) ) {
                    $style_name = $nm[1];
                }

                // Look for w:spacing with w:line inside this style's pPr.
                if ( preg_match( '/<w:pPr>.*?<\/w:pPr>/s', $block, $ppr ) ) {
                    if ( preg_match( '/w:line="(\d+)"/', $ppr[0], $slm ) ) {
                        $lh = ( (int) $slm[1] / 240.0 ) * self::WORD_SINGLE_LINE_FACTOR;
                        // Map under both names so lookups work regardless of which key PhpWord uses.
                        if ( $style_id ) {
                            $result['styles'][ $style_id ] = $lh;
                        }
                        if ( $style_name ) {
                            $result['styles'][ $style_name ] = $lh;
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Fix PhpWord spacing: set lineHeight on all styles using the actual
     * values parsed from the DOCX (PhpWord doesn't read w:line at all).
     */
    private function fix_phpword_all_spacing( $phpWord, $spacing_map ) {
        $defLineHeight = $spacing_map['default'];
        $styleLineMap  = $spacing_map['styles'];

        // 1. Fix all named styles in the global registry.
        $styles = \PhpOffice\PhpWord\Style::getStyles();
        foreach ( $styles as $name => $style ) {
            $lh = $styleLineMap[ $name ] ?? $defLineHeight;

            if ( $style instanceof \PhpOffice\PhpWord\Style\Paragraph ) {
                if ( null === $style->getLineHeight() ) {
                    $style->setLineHeight( $lh );
                }
            }
            if ( $style instanceof \PhpOffice\PhpWord\Style\Font ) {
                try {
                    $pStyle = $style->getParagraph();
                    if ( $pStyle instanceof \PhpOffice\PhpWord\Style\Paragraph && null === $pStyle->getLineHeight() ) {
                        $pStyle->setLineHeight( $lh );
                    }
                } catch ( \Throwable $e ) {}
            }
        }

        // 2. Walk all elements and set lineHeight on inline paragraph styles,
        //    using the element's styleName to look up the correct value.
        foreach ( $phpWord->getSections() as $section ) {
            $this->fix_phpword_spacing( $section, $spacing_map );
        }
    }

    /**
     * Recursively walk PhpWord elements and set lineHeight where missing,
     * using the spacing map to look up per-style values.
     */
    private function fix_phpword_spacing( $container, $spacing_map ) {
        if ( ! method_exists( $container, 'getElements' ) ) {
            return;
        }

        $defLineHeight = $spacing_map['default'];
        $styleLineMap  = $spacing_map['styles'];

        foreach ( $container->getElements() as $element ) {
            if ( method_exists( $element, 'getParagraphStyle' ) ) {
                $pStyle = $element->getParagraphStyle();
                if ( $pStyle instanceof \PhpOffice\PhpWord\Style\Paragraph && null === $pStyle->getLineHeight() ) {
                    // Look up the style's line height by its styleName (= styleId from DOCX).
                    $styleName = $pStyle->getStyleName();
                    $lh = $defLineHeight;
                    if ( $styleName && isset( $styleLineMap[ $styleName ] ) ) {
                        $lh = $styleLineMap[ $styleName ];
                    }
                    $pStyle->setLineHeight( $lh );
                }
            }

            if ( method_exists( $element, 'getFontStyle' ) ) {
                $fStyle = $element->getFontStyle();
                if ( $fStyle instanceof \PhpOffice\PhpWord\Style\Font ) {
                    try {
                        $pStyle = $fStyle->getParagraph();
                        if ( $pStyle instanceof \PhpOffice\PhpWord\Style\Paragraph && null === $pStyle->getLineHeight() ) {
                            $styleName = $pStyle->getStyleName();
                            $lh = $defLineHeight;
                            if ( $styleName && isset( $styleLineMap[ $styleName ] ) ) {
                                $lh = $styleLineMap[ $styleName ];
                            }
                            $pStyle->setLineHeight( $lh );
                        }
                    } catch ( \Throwable $e ) {}
                }
            }

            if ( method_exists( $element, 'getElements' ) ) {
                $this->fix_phpword_spacing( $element, $spacing_map );
            }
            if ( method_exists( $element, 'getRows' ) ) {
                foreach ( $element->getRows() as $row ) {
                    foreach ( $row->getCells() as $cell ) {
                        $this->fix_phpword_spacing( $cell, $spacing_map );
                    }
                }
            }
        }
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

        // Read per-style line spacing from the DOCX (PhpWord doesn't read w:line).
        $spacing_map = $this->read_docx_spacing_map( $file_path );

        $phpWord = \PhpOffice\PhpWord\IOFactory::createReader( $reader_name )->load( $file_path );

        // Apply the correct line height to each style.
        $this->fix_phpword_all_spacing( $phpWord, $spacing_map );

        $output = tempnam( sys_get_temp_dir(), 'wm_phpword_' ) . '.pdf';

        \PhpOffice\PhpWord\Settings::setPdfRendererName( \PhpOffice\PhpWord\Settings::PDF_RENDERER_TCPDF );
        \PhpOffice\PhpWord\Settings::setPdfRendererPath( WATERMARKER_PLUGIN_DIR . 'vendor/tecnickcom/tcpdf' );

        $writer = new \Watermarker_TCPDF_Writer( $phpWord );
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
            '%s --headless --norestore --convert-to pdf --outdir %s %s 2>&1',
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

        $pdf_path = $out_dir . '/' . pathinfo( $file_path, PATHINFO_FILENAME ) . '.pdf';
        if ( ! file_exists( $pdf_path ) ) {
            throw new \Exception( 'LibreOffice conversion failed: ' . implode( "\n", $output ) );
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
