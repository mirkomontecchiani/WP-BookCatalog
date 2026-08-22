<?php
/**
 * Plugin Name: WP Book Catalog
 * Plugin URI: https://github.com/mirkomontecchiani/WP-BookCatalog
 * Description: Book catalog with a Book post type, genres, a responsive shortcode grid and one-click ISBN autofill from Google Books and Open Library.
 * Version: 1.2.0
 * Author: Mirko Montecchiani
 * Author URI: https://github.com/mirkomontecchiani
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-book-catalog
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.2
 *
 * @package WP_Book_Catalog
 *
 * WP Book Catalog is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 2 of the License, or (at your option)
 * any later version.
 *
 * WP Book Catalog is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this plugin. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WPBC_VERSION', '1.2.0');
define('WPBC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPBC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPBC_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
class WPBC_Plugin {

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
        require_once WPBC_PLUGIN_DIR . 'includes/class-wpbc-meta-order.php';
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

        // No nonce here on purpose: the "Show All Books" endpoint only returns
        // books that are already public on this page, and a nonce baked into a
        // full-page-cached HTML document expires after 24 hours and would break
        // the button for anonymous visitors. See WPBC_Shortcode::ajax_load_all_books().
        wp_localize_script('wpbc-frontend', 'wpbc_ajax', array(
            'ajax_url'    => admin_url('admin-ajax.php'),
            'error_text'  => __('Could not load the books. Please try again.', 'wp-book-catalog'),
            'loaded_text' => __('All books are now shown.', 'wp-book-catalog'),
        ));

        // Enqueue here when the shortcode can be found in the content, so the
        // stylesheet goes into <head>. Rendering the shortcode enqueues it too,
        // which covers widgets and templates at the cost of a footer stylesheet.
        if (self::current_content_has_shortcode()) {
            wp_enqueue_style('wpbc-frontend');
            wp_enqueue_script('wpbc-frontend');
        }
    }

    /**
     * Whether the content about to be rendered contains the books shortcode
     *
     * @return bool
     */
    private static function current_content_has_shortcode() {
        if (!is_singular()) {
            return false;
        }

        $post = get_post();

        if (!$post || empty($post->post_content)) {
            return false;
        }

        return has_shortcode($post->post_content, 'books')
            || has_shortcode($post->post_content, 'wpbc_books');
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets() {
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
                    'enter_isbn'     => __('Please enter an ISBN first.', 'wp-book-catalog'),
                    'lookup_failed'  => __('Lookup failed. Please try again.', 'wp-book-catalog'),
                    'import_failed'  => __('Cover import failed. Please try again.', 'wp-book-catalog'),
                    'importing'      => __('Importing…', 'wp-book-catalog'),
                    'use_as_cover'   => __('Use as cover', 'wp-book-catalog'),
                    'cover_imported' => __('Cover imported.', 'wp-book-catalog'),
                    /* translators: %s: comma separated list of field names */
                    'filled_fields'  => __('Fields filled: %s', 'wp-book-catalog'),
                    'nothing_filled' => __('No empty fields to fill. Existing values were kept.', 'wp-book-catalog'),
                    /* translators: %s: comma separated list of data sources, e.g. "Google Books, Open Library" */
                    'source'         => __('Source: %s', 'wp-book-catalog'),
                    /* translators: separator between the field names listed in the "Fields filled" notice */
                    'separator'      => _x(', ', 'list item separator', 'wp-book-catalog'),
                    'fields'         => array(
                        'author'      => _x('author', 'book field name', 'wp-book-catalog'),
                        'publisher'   => _x('publisher', 'book field name', 'wp-book-catalog'),
                        'year'        => _x('year', 'book field name', 'wp-book-catalog'),
                        'pages'       => _x('pages', 'book field name', 'wp-book-catalog'),
                        'language'    => _x('language', 'book field name', 'wp-book-catalog'),
                        'title'       => _x('title', 'book field name', 'wp-book-catalog'),
                        'description' => _x('description', 'book field name', 'wp-book-catalog'),
                    ),
                ),
            ));
        }
    }

    /**
     * Plugin activation
     *
     * @param bool $network_wide Whether the plugin is being activated network-wide.
     */
    public static function activate($network_wide = false) {
        if (is_multisite() && $network_wide) {
            // WordPress fires the activation hook only once, in the context of
            // the current site, so every site has to be set up explicitly.
            $site_ids = get_sites(array('fields' => 'ids', 'number' => 0));

            foreach ($site_ids as $site_id) {
                switch_to_blog($site_id);
                self::activate_single_site();
                restore_current_blog();
            }

            return;
        }

        self::activate_single_site();
    }

    /**
     * Set one site up: register the objects, create the options, flush rewrites
     */
    private static function activate_single_site() {
        // At activation time the 'init' hook has already fired, so register
        // the post type and taxonomy directly before flushing rewrite rules.
        require_once WPBC_PLUGIN_DIR . 'includes/class-wpbc-meta-order.php';
        require_once WPBC_PLUGIN_DIR . 'includes/class-wpbc-post-type.php';
        require_once WPBC_PLUGIN_DIR . 'includes/class-wpbc-settings.php';

        $post_type = WPBC_Post_Type::get_instance();
        $post_type->register_post_type();
        $post_type->register_taxonomy();

        // Set default options (merge to preserve values on re-activation)
        $existing = get_option('wpbc_settings', array());
        if (!is_array($existing)) {
            $existing = array();
        }

        update_option('wpbc_settings', wp_parse_args($existing, WPBC_Settings::get_defaults()));

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Set a site up when it is created while the plugin is network-active
     *
     * @param int|WP_Site $site New site.
     */
    public static function activate_new_site($site) {
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!is_plugin_active_for_network(WPBC_PLUGIN_BASENAME)) {
            return;
        }

        $site_id = is_object($site) ? (int) $site->blog_id : (int) $site;

        switch_to_blog($site_id);
        self::activate_single_site();
        restore_current_blog();
    }

    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        flush_rewrite_rules();
    }
}

// Activation and deactivation hooks
register_activation_hook(__FILE__, array('WPBC_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('WPBC_Plugin', 'deactivate'));

// Multisite: set new sites up when the plugin is network-active.
add_action('wp_initialize_site', array('WPBC_Plugin', 'activate_new_site'), 20);

// Initialize plugin
function wpbc_init() {
    return WPBC_Plugin::get_instance();
}

// Start the plugin
wpbc_init();
