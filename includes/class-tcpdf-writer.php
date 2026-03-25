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
}
