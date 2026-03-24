<?php
/**
 * Plugin Name: Watermarker
 * Plugin URI:  https://gavamedia.com
 * Description: Upload documents and combine them with your letterhead template to create branded PDFs. Supports PDF, DOCX, images, and more.
 * Version:     1.0.1
 * Author:      GAVAMEDIA
 * Author URI:  https://gavamedia.com
 * License:     GPL v2 or later
 * Text Domain: watermarker
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WATERMARKER_VERSION', '1.0.1' );
define( 'WATERMARKER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WATERMARKER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Check for Composer autoloader.
if ( ! file_exists( WATERMARKER_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>Watermarker:</strong> Dependencies not installed. ';
        echo 'Please run <code>composer install</code> in the <code>' . esc_html( WATERMARKER_PLUGIN_DIR ) . '</code> directory.';
        echo '</p></div>';
    } );
    return;
}

require_once WATERMARKER_PLUGIN_DIR . 'vendor/autoload.php';
require_once WATERMARKER_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once WATERMARKER_PLUGIN_DIR . 'includes/class-frontend-page.php';
require_once WATERMARKER_PLUGIN_DIR . 'includes/class-pdf-processor.php';

/**
 * Main plugin class.
 */
final class Watermarker {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        new Watermarker_Admin_Settings();
        new Watermarker_Frontend_Page();

        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );
    }

    public function activate() {
        add_option( 'watermarker_url_slug', 'letterhead' );
        add_option( 'watermarker_letterhead_id', '' );
        add_option( 'watermarker_apply_all_pages', '1' );

        Watermarker_Frontend_Page::register_rewrite_rules();
        flush_rewrite_rules();
    }

    public function deactivate() {
        flush_rewrite_rules();
    }
}

Watermarker::instance();
