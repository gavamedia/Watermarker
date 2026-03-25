<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Custom TCPDF writer that applies document section margins and paper size.
 */
class Watermarker_TCPDF_Writer extends \PhpOffice\PhpWord\Writer\PDF\TCPDF {

    protected function prepareToWrite( \TCPDF $pdf ): void {
        $phpWord  = $this->getPhpWord();
        $sections = $phpWord->getSections();

        if ( ! empty( $sections ) ) {
            $style = $sections[0]->getStyle();

            // Convert twips to points (1 twip = 1/20 pt).
            $marginTop    = $style->getMarginTop() / 20;
            $marginBottom = $style->getMarginBottom() / 20;
            $marginLeft   = $style->getMarginLeft() / 20;
            $marginRight  = $style->getMarginRight() / 20;

            $pdf->SetMargins( $marginLeft, $marginTop, $marginRight );
            $pdf->SetAutoPageBreak( true, $marginBottom );
        }

        // Set TCPDF's cell height ratio to match Word's single-spacing
        // for Western fonts (~1.15× font size). This is used as the base
        // line height and also affects margin/padding rendering.
        $pdf->setCellHeightRatio( 1.15 );

        $pdf->AddPage();

        // Zero TCPDF's default <p> tag spacing — CSS handles all spacing.
        $pdf->setHtmlVSpace( [
            'p' => [
                [ 'n' => 0, 'h' => 0 ],
                [ 'n' => 0, 'h' => 0 ],
            ],
        ] );
    }

    public function save( string $filename ): void {
        $fileHandle = parent::prepareForSave( $filename );

        // Read paper size from the document section.
        $phpWord    = $this->getPhpWord();
        $sections   = $phpWord->getSections();
        $paperSize  = 'LETTER';
        $orientation = 'P';

        if ( ! empty( $sections ) ) {
            $style = $sections[0]->getStyle();
            $w     = $style->getPageSizeW(); // twips
            $h     = $style->getPageSizeH(); // twips

            // Detect paper size from dimensions.
            // Letter: 12240 x 15840 twips, A4: 11906 x 16838 twips.
            if ( abs( $w - 12240 ) < 100 && abs( $h - 15840 ) < 100 ) {
                $paperSize = 'LETTER';
            } elseif ( abs( $w - 15840 ) < 100 && abs( $h - 12240 ) < 100 ) {
                $paperSize   = 'LETTER';
                $orientation = 'L';
            } elseif ( abs( $w - 16838 ) < 100 && abs( $h - 11906 ) < 100 ) {
                $paperSize   = 'A4';
                $orientation = 'L';
            } else {
                $paperSize = 'A4';
            }
        }

        $pdf = $this->createExternalWriterInstance( $orientation, 'pt', $paperSize );
        $pdf->setFontSubsetting( false );
        $pdf->setPrintHeader( false );
        $pdf->setPrintFooter( false );

        // Remove the "Powered by TCPDF" link/watermark that TCPDF adds
        // to every document. The property is protected, so we use reflection.
        $ref = new \ReflectionProperty( $pdf, 'tcpdflink' );
        $ref->setAccessible( true );
        $ref->setValue( $pdf, false );
        $pdf->SetFont( $this->getFont() );

        // Register any custom-uploaded fonts before rendering.
        Watermarker_Font_Manager::register_fonts_with_tcpdf( $pdf );

        $this->prepareToWrite( $pdf );

        $html = $this->getContent();

        // Extract the document default margin-bottom from the p,.Normal CSS rule
        // (PhpWord generates this from the Normal style's spaceAfter).
        $defaultMarginBottom = '0';
        if ( preg_match( '/p,\s*\.Normal\s*\{[^}]*margin-bottom:\s*([^;]+);/', $html, $mb ) ) {
            $defaultMarginBottom = trim( $mb[1] );
        }

        // Detect the most common content font-size from span styles.
        // TextBreaks lose their font info in PhpWord, so we use this to size them.
        $contentFontSize = '10pt';
        if ( preg_match_all( '/font-size:\s*(\d+(?:\.\d+)?pt)/', $html, $fsm ) ) {
            $counts = array_count_values( $fsm[1] );
            arsort( $counts );
            $contentFontSize = key( $counts );
        }

        // Zero out the p,.Normal CSS rule — styled paragraphs have inline margins,
        // and we'll handle unstyled paragraphs and TextBreaks explicitly below.
        $html = preg_replace(
            '/p,\s*\.Normal\s*\{[^}]*\}/',
            'p, .Normal {margin-top: 0; margin-bottom: 0;}',
            $html
        );

        // TextBreaks render as bare <p>&nbsp;</p> with no style attr.
        // PhpWord strips their font size. Use a size slightly above the content
        // font to approximate the DOCX paragraph mark height (typically 11pt
        // when content is 10pt).
        $textBreakSize = intval( $contentFontSize ) + 1;
        $html = str_replace(
            '<p>&nbsp;</p>',
            '<p style="margin:0; padding:0; font-size: ' . $textBreakSize . 'pt; line-height: 1.0;">&nbsp;</p>',
            $html
        );

        // Unstyled content paragraphs only have inline line-height, no margins.
        // TCPDF ignores CSS margin-bottom on <p> when setHtmlVSpace is zeroed,
        // so we insert a spacer <p> after each to simulate the document default
        // spaceAfter. Use font-size to control spacer height (TCPDF multiplies
        // by cellHeightRatio, so 6pt * 1.15 ≈ 7pt gap, close to Word's 8pt).
        $html = preg_replace(
            '/<p style="line-height: ([^"]+);">/',
            '<p class="unstyled-para" style="margin-top: 0; margin-bottom: 0; line-height: $1;">',
            $html
        );
        $html = preg_replace(
            '/(<p class="unstyled-para"[^>]*>.*?<\/p>)\n/s',
            '$1' . "\n" . '<p style="margin:0; padding:0; font-size: 6pt; line-height: 0.5;">&nbsp;</p>' . "\n",
            $html
        );

        // Convert vertical-align: super/sub CSS (which TCPDF ignores) to
        // proper <sup>/<sub> HTML tags that TCPDF does render.
        $html = $this->convert_vertical_align_to_tags( $html );

        // Boost line-height: 1 (DOCX single-spacing w:line="240") to match
        // Word's actual rendering. Word's "single" line spacing is ~1.15×
        // the font size, but TCPDF interprets line-height: 1 as exactly 1×.
        // We adjust styled paragraphs to 1.15 to close the gap.
        $html = preg_replace(
            '/line-height:\s*1;/',
            'line-height: 1.15;',
            $html
        );

        $pdf->writeHTML( $html );

        // Document properties.
        $docProps = $phpWord->getDocInfo();
        $pdf->SetTitle( $docProps->getTitle() );
        $pdf->SetAuthor( $docProps->getCreator() );
        $pdf->SetSubject( $docProps->getSubject() );
        $pdf->SetKeywords( $docProps->getKeywords() );
        $pdf->SetCreator( $docProps->getCreator() );

        fwrite( $fileHandle, $pdf->Output( $filename, 'S' ) );
        parent::restoreStateAfterSave( $fileHandle );
    }

    /**
     * Convert spans with vertical-align: super/sub CSS into <sup>/<sub> tags.
     *
     * PhpWord outputs superscript/subscript as:
     *   <span style="...vertical-align: super;">text</span>
     * but TCPDF only renders <sup>/<sub> HTML tags. This method rewrites
     * those spans to use proper tags.
     *
     * @param string $html The HTML content.
     * @return string Modified HTML.
     */
    private function convert_vertical_align_to_tags( $html ) {
        // Match <span style="...vertical-align: super;...">content</span>
        // and wrap content in <sup> (removing the vertical-align from style).
        // We set an explicit font-size on the <sup> tag (58% of parent, matching
        // Word's superscript sizing) so TCPDF won't apply its own K_SMALL_RATIO.
        $html = preg_replace_callback(
            '/<span\s+style="([^"]*vertical-align:\s*super[^"]*)">(.*?)<\/span>/s',
            function ( $m ) {
                $style = $m[1];
                // Extract font-size from the span to calculate superscript size.
                $supSize = '';
                if ( preg_match( '/font-size:\s*([\d.]+)pt/', $style, $fs ) ) {
                    // Word renders superscript at ~58% of the parent font size,
                    // but TCPDF's <sup> Y-shift (0.7 * fontSizePt) expands the
                    // line. We use a smaller ratio (0.45) to keep the visual
                    // weight subtle and the Y-shift small enough that the line
                    // height stays close to normal.
                    $sz = round( (float) $fs[1] * 0.5, 1 );
                    $supSize = ' style="font-size: ' . $sz . 'pt; line-height: 0;"';
                }
                $style = preg_replace( '/\s*vertical-align:\s*super;?\s*/', '', $style );
                $style = trim( $style, '; ' );
                if ( $style ) {
                    return '<sup' . $supSize . '><span style="' . $style . '">' . $m[2] . '</span></sup>';
                }
                return '<sup' . $supSize . '>' . $m[2] . '</sup>';
            },
            $html
        );

        // Same for subscript.
        $html = preg_replace_callback(
            '/<span\s+style="([^"]*vertical-align:\s*sub[^"]*)">(.*?)<\/span>/s',
            function ( $m ) {
                $style = $m[1];
                $subSize = '';
                if ( preg_match( '/font-size:\s*([\d.]+)pt/', $style, $fs ) ) {
                    $sz = round( (float) $fs[1] * 0.45, 1 );
                    $subSize = ' style="font-size: ' . $sz . 'pt; line-height: 0.5;"';
                }
                $style = preg_replace( '/\s*vertical-align:\s*sub;?\s*/', '', $style );
                $style = trim( $style, '; ' );
                if ( $style ) {
                    return '<sub' . $subSize . '><span style="' . $style . '">' . $m[2] . '</span></sub>';
                }
                return '<sub' . $subSize . '>' . $m[2] . '</sub>';
            },
            $html
        );

        return $html;
    }
}
