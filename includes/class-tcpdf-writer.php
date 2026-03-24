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

        $pdf->AddPage();

        // Set minimal vertical space for <p> tags.
        // TCPDF setHtmlVSpace: 'n' = number of lines, 'h' = line height in pt.
        // We want near-zero spacing between <p> tags since PhpWord handles
        // paragraph spacing via inline CSS margin-top/margin-bottom.
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
        $this->prepareToWrite( $pdf );

        $html = $this->getContent();

        // TextBreaks render as bare <p>&nbsp;</p> with no style attr.
        // In the DOCX these have before=0 after=0, but without inline styles
        // they inherit the p,.Normal rule's margins. Zero them out explicitly.
        $html = str_replace(
            '<p>&nbsp;</p>',
            '<p style="margin:0; padding:0;">&nbsp;</p>',
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
