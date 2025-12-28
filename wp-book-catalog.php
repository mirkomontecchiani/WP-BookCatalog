<?php
/**
 * Plugin Name: WP Book Catalog
 * Plugin URI: https://github.com/mirkomontecchiani/wp-book-catalog
 * Description: A WordPress plugin to display a book catalog with custom post type, shortcodes and hover effects.
 * Version: 1.0.0
 * Author: Mirko Montecchiani
 * Author URI: https://github.com/mirkomontecchiani
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-book-catalog
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WPBC_VERSION', '1.0.0');
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
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_filter('single_template', array($this, 'load_single_book_template'));
    }

    /**
     * Load custom template for single book
     */
    public function load_single_book_template($template) {
        global $post;

        if ('book' === $post->post_type) {
            $plugin_template = WPBC_PLUGIN_DIR . 'templates/single-book.php';

            if (file_exists($plugin_template)) {
                return $plugin_template;
            }
        }

        return $template;
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
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        wp_enqueue_style(
            'wpbc-frontend',
            WPBC_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            WPBC_VERSION
        );

        wp_enqueue_script(
            'wpbc-frontend',
            WPBC_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            WPBC_VERSION,
            true
        );

        wp_localize_script('wpbc-frontend', 'wpbc_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpbc_nonce'),
            'show_all_text' => __('Show All Books', 'wp-book-catalog'),
        ));
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        global $post_type;

        if ('book' === $post_type || 'settings_page_wpbc-settings' === $hook) {
            wp_enqueue_style(
                'wpbc-admin',
                WPBC_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                WPBC_VERSION
            );
        }
    }

    /**
     * Plugin activation
     */
    public static function activate() {
        // Register post type on activation
        require_once WPBC_PLUGIN_DIR . 'includes/class-wpbc-post-type.php';
        WPBC_Post_Type::get_instance();

        // Flush rewrite rules
        flush_rewrite_rules();

        // Set default options
        $default_options = array(
            'default_author' => '',
            'columns' => 3,
        );

        if (!get_option('wpbc_settings')) {
            add_option('wpbc_settings', $default_options);
        }
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
