<?php
/**
 * Plugin Name: WP Book Catalog
 * Plugin URI: https://github.com/mirkomontecchiani/wp-book-catalog
 * Description: A WordPress plugin to display a book catalog with custom post type, shortcodes, hover effects and ISBN autofill from Google Books and Open Library.
 * Version: 1.1.0
 * Author: Mirko Montecchiani
 * Author URI: https://github.com/mirkomontecchiani
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-book-catalog
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.2
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WPBC_VERSION', '1.1.0');
define('WPBC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPBC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPBC_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
class WP_Book_Catalog {

    /**
     * Single instance of the class
     */
    private static $instance = null;

    /**
     * Get single instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required files
     */
    private function load_dependencies() {
        require_once WPBC_PLUGIN_DIR . 'includes/class-wpbc-post-type.php';
        require_once WPBC_PLUGIN_DIR . 'includes/class-wpbc-meta-boxes.php';
        require_once WPBC_PLUGIN_DIR . 'includes/class-wpbc-settings.php';
        require_once WPBC_PLUGIN_DIR . 'includes/class-wpbc-shortcode.php';
        require_once WPBC_PLUGIN_DIR . 'includes/class-wpbc-isbn-lookup.php';
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Since WP 6.7 translations must not be loaded before the 'init' hook.
        add_action('init', array($this, 'load_textdomain'), 1);
        add_action('wp_enqueue_scripts', array($this, 'register_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'wp-book-catalog',
            false,
            dirname(WPBC_PLUGIN_BASENAME) . '/languages'
        );
    }

    /**
     * Register frontend assets.
     *
     * They are only enqueued when the [books] shortcode actually renders,
     * so pages without the catalog do not load them.
     */
    public function register_frontend_assets() {
        wp_register_style(
            'wpbc-frontend',
            WPBC_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            WPBC_VERSION
        );

        wp_register_script(
            'wpbc-frontend',
            WPBC_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            WPBC_VERSION,
            true
        );

        wp_localize_script('wpbc-frontend', 'wpbc_ajax', array(
            'ajax_url'   => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('wpbc_nonce'),
            'error_text' => __('Could not load the books. Please try again.', 'wp-book-catalog'),
        ));
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen) {
            return;
        }

        $is_book_screen = ('book' === $screen->post_type);

        // The settings page hook is "book_page_wpbc-settings" because it lives
        // under edit.php?post_type=book.
        if ($is_book_screen || 'book_page_wpbc-settings' === $screen->id) {
            wp_enqueue_style(
                'wpbc-admin',
                WPBC_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                WPBC_VERSION
            );
        }

        // ISBN lookup script only on the book edit screen.
        if ($is_book_screen && 'post' === $screen->base) {
            wp_enqueue_script(
                'wpbc-admin',
                WPBC_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                WPBC_VERSION,
                true
            );

            wp_localize_script('wpbc-admin', 'wpbc_admin', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('wpbc_admin_nonce'),
                'strings'  => array(
                    'enter_isbn'        => __('Please enter an ISBN first.', 'wp-book-catalog'),
                    'lookup_failed'     => __('Lookup failed. Please try again.', 'wp-book-catalog'),
                    'import_failed'     => __('Cover import failed. Please try again.', 'wp-book-catalog'),
                    'importing'         => __('Importing…', 'wp-book-catalog'),
                    'use_as_cover'      => __('Use as cover', 'wp-book-catalog'),
                    'cover_imported'    => __('Cover imported.', 'wp-book-catalog'),
                    'filled_fields'     => __('Fields filled:', 'wp-book-catalog'),
                    'nothing_filled'    => __('No empty fields to fill. Existing values were kept.', 'wp-book-catalog'),
                    'source'            => __('Source:', 'wp-book-catalog'),
                    'field_author'      => __('author', 'wp-book-catalog'),
                    'field_publisher'   => __('publisher', 'wp-book-catalog'),
                    'field_year'        => __('year', 'wp-book-catalog'),
                    'field_pages'       => __('pages', 'wp-book-catalog'),
                    'field_language'    => __('language', 'wp-book-catalog'),
                    'field_title'       => __('title', 'wp-book-catalog'),
                    'field_description' => __('description', 'wp-book-catalog'),
                ),
            ));
        }
    }

    /**
     * Plugin activation
     */
    public static function activate() {
        // At activation time the 'init' hook has already fired, so register
        // the post type and taxonomy directly before flushing rewrite rules.
        require_once WPBC_PLUGIN_DIR . 'includes/class-wpbc-post-type.php';
        $post_type = WPBC_Post_Type::get_instance();
        $post_type->register_post_type();
        $post_type->register_taxonomy();

        // Flush rewrite rules
        flush_rewrite_rules();

        // Set default options (merge to preserve values on re-activation)
        $defaults = array(
            'default_author'           => '',
            'author_gender'            => 'male',
            'columns'                  => 3,
            'isbn_source'              => 'both',
            'google_api_key'           => '',
            'delete_data_on_uninstall' => 0,
        );

        $existing = get_option('wpbc_settings', array());
        update_option('wpbc_settings', wp_parse_args($existing, $defaults));
    }

    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        flush_rewrite_rules();
    }
}

// Activation and deactivation hooks
register_activation_hook(__FILE__, array('WP_Book_Catalog', 'activate'));
register_deactivation_hook(__FILE__, array('WP_Book_Catalog', 'deactivate'));

// Initialize plugin
function wpbc_init() {
    return WP_Book_Catalog::get_instance();
}

// Start the plugin
wpbc_init();
