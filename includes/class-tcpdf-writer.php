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

        // Apply paragraph spacing from Normal style.
        $customStyles = \PhpOffice\PhpWord\Style::getStyles();
        $normal = $customStyles['Normal'] ?? null;
        if ( $normal instanceof \PhpOffice\PhpWord\Style\Paragraph ) {
            $before = $normal->getSpaceBefore();
            $after  = $normal->getSpaceAfter();
            if ( is_numeric( $before ) && is_numeric( $after ) ) {
                $height = $normal->getLineHeight() ?? '';
                $pdf->setHtmlVSpace( [
                    'p' => [
                        [ 'n' => $before, 'h' => $height ],
                        [ 'n' => $after, 'h' => $height ],
                    ],
                ] );
            }
        }
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
        $pdf->writeHTML( $this->getContent() );

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
