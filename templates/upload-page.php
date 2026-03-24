<?php
/**
 * Front-end upload page template.
 *
 * Variables available (set by Watermarker_Frontend_Page::maybe_render_page):
 *   $nonce, $ajax_url, $max_size, $site_name, $has_lh
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html( $site_name ); ?> &mdash; Upload Document</title>
    <link rel="stylesheet" href="<?php echo esc_url( WATERMARKER_PLUGIN_URL . 'assets/css/frontend.css?v=' . WATERMARKER_VERSION ); ?>">
    <?php wp_head(); ?>
</head>
<body class="watermarker-page">

<div class="wm-container">
    <header class="wm-header">
        <?php
        $logo_url = '';
        // Try custom logo first.
        $custom_logo_id = get_theme_mod( 'custom_logo' );
        if ( $custom_logo_id ) {
            $logo_url = wp_get_attachment_image_url( $custom_logo_id, 'medium' );
        }
        // Fall back to site icon.
        if ( ! $logo_url ) {
            $logo_url = get_site_icon_url( 512 );
        }
        if ( $logo_url ) : ?>
            <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $site_name ); ?>" class="wm-logo">
        <?php endif;
        ?>
        <h1><?php echo esc_html( $site_name ); ?></h1>
        <p class="wm-subtitle">Upload a document to apply the company letterhead</p>
    </header>

    <?php if ( ! $has_lh ) : ?>
        <div class="wm-notice wm-notice--error">
            No letterhead template has been configured yet. Please contact the site administrator.
        </div>
    <?php else : ?>

    <div class="wm-upload-area" id="wm-drop-zone">
        <div class="wm-drop-idle" id="wm-drop-idle">
            <svg class="wm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            <p class="wm-drop-text">Drag &amp; drop your file here</p>
            <p class="wm-drop-or">or</p>
            <button type="button" class="wm-btn wm-btn--primary" id="wm-upload-btn">Upload File</button>
            <input type="file" id="wm-file-input" class="wm-sr-only"
                   accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.rtf,.txt,.html,.odt,.ods,.odp,.csv,.jpg,.jpeg,.png,.gif,.webp,.bmp,.tiff,.tif">
            <p class="wm-drop-hint">
                PDF, Word, Excel, PowerPoint, images, and more &mdash; max <?php echo esc_html( $max_size ); ?>
            </p>
        </div>

        <div class="wm-drop-hover" id="wm-drop-hover">
            <svg class="wm-icon wm-icon--lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            <p class="wm-drop-text">Drop it here!</p>
        </div>

        <div class="wm-processing" id="wm-processing">
            <div class="wm-spinner"></div>
            <p class="wm-processing-text" id="wm-processing-text">Processing your document&hellip;</p>
            <div class="wm-progress-bar" id="wm-progress-bar">
                <div class="wm-progress-fill" id="wm-progress-fill"></div>
            </div>
        </div>

        <div class="wm-result" id="wm-result">
            <div class="wm-result-success" id="wm-result-success">
                <svg class="wm-icon wm-icon--success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                <p class="wm-result-text" id="wm-result-text">Done!</p>
                <a href="#" class="wm-btn wm-btn--primary" id="wm-download-btn" download>Download PDF</a>
                <button type="button" class="wm-btn wm-btn--ghost" id="wm-reset-btn">Upload another file</button>
            </div>
            <div class="wm-result-error" id="wm-result-error">
                <svg class="wm-icon wm-icon--error" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="15" y1="9" x2="9" y2="15"/>
                    <line x1="9" y1="9" x2="15" y2="15"/>
                </svg>
                <p class="wm-result-text" id="wm-error-text">Something went wrong.</p>
                <button type="button" class="wm-btn wm-btn--primary" id="wm-retry-btn">Try again</button>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<script>
    window.watermarkerConfig = {
        ajaxUrl: <?php echo wp_json_encode( $ajax_url ); ?>,
        nonce:   <?php echo wp_json_encode( $nonce ); ?>,
        maxSize: <?php echo (int) wp_max_upload_size(); ?>
    };
</script>
<script src="<?php echo esc_url( WATERMARKER_PLUGIN_URL . 'assets/js/frontend.js?v=' . WATERMARKER_VERSION ); ?>"></script>

<?php wp_footer(); ?>
</body>
</html>
