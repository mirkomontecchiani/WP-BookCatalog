<?php
/**
 * Plugin Name: Montecchiani Book Catalog
 * Plugin URI: https://github.com/mirkomontecchiani/montecchiani-book-catalog
 * Description: Book catalog with a Book post type, genres, a responsive shortcode grid and one-click ISBN autofill from Google Books and Open Library.
 * Version: 1.3.0
 * Author: Mirko Montecchiani
 * Author URI: https://github.com/mirkomontecchiani
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: montecchiani-book-catalog
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.2
 *
 * @package Montecchiani_Book_Catalog
 *
 * Montecchiani Book Catalog is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 2 of the License, or (at your option)
 * any later version.
 *
 * Montecchiani Book Catalog is distributed in the hope that it will be useful, but
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
define('MBCAT_VERSION', '1.3.0');
define('MBCAT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MBCAT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MBCAT_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
class MBCat_Plugin {

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
        require_once MBCAT_PLUGIN_DIR . 'includes/class-mbcat-meta-order.php';
        require_once MBCAT_PLUGIN_DIR . 'includes/class-mbcat-post-type.php';
        require_once MBCAT_PLUGIN_DIR . 'includes/class-mbcat-meta-boxes.php';
        require_once MBCAT_PLUGIN_DIR . 'includes/class-mbcat-settings.php';
        require_once MBCAT_PLUGIN_DIR . 'includes/class-mbcat-shortcode.php';
        require_once MBCAT_PLUGIN_DIR . 'includes/class-mbcat-isbn-lookup.php';
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('wp_enqueue_scripts', array($this, 'register_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Register frontend assets.
     *
     * They are only enqueued when the [mbcat_books] shortcode actually renders,
     * so pages without the catalog do not load them.
     */
    public function register_frontend_assets() {
        wp_register_style(
            'mbcat-frontend',
            MBCAT_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            MBCAT_VERSION
        );

        wp_register_script(
            'mbcat-frontend',
            MBCAT_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            MBCAT_VERSION,
            true
        );

        // No nonce here on purpose: the "Show All Books" endpoint only returns
        // books that are already public on this page, and a nonce baked into a
        // full-page-cached HTML document expires after 24 hours and would break
        // the button for anonymous visitors. See MBCat_Shortcode::ajax_load_all_books().
        wp_localize_script('mbcat-frontend', 'mbcat_ajax', array(
            'ajax_url'    => admin_url('admin-ajax.php'),
            'error_text'  => __('Could not load the books. Please try again.', 'montecchiani-book-catalog'),
            'loaded_text' => __('All books are now shown.', 'montecchiani-book-catalog'),
        ));

        // Enqueue here when the shortcode can be found in the content, so the
        // stylesheet goes into <head>. Rendering the shortcode enqueues it too,
        // which covers widgets and templates at the cost of a footer stylesheet.
        if (self::current_content_has_shortcode()) {
            wp_enqueue_style('mbcat-frontend');
            wp_enqueue_script('mbcat-frontend');
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

        return has_shortcode($post->post_content, 'mbcat_books');
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen) {
            return;
        }

        $is_book_screen = ('mbcat_book' === $screen->post_type);

        // The settings page screen ID is "{post_type}_page_{menu_slug}"
        // because it lives under edit.php?post_type=mbcat_book.
        if ($is_book_screen || 'mbcat_book_page_mbcat-settings' === $screen->id) {
            wp_enqueue_style(
                'mbcat-admin',
                MBCAT_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                MBCAT_VERSION
            );
        }

        // ISBN lookup script only on the book edit screen.
        if ($is_book_screen && 'post' === $screen->base) {
            wp_enqueue_script(
                'mbcat-admin',
                MBCAT_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                MBCAT_VERSION,
                true
            );

            wp_localize_script('mbcat-admin', 'mbcat_admin', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('mbcat_admin_nonce'),
                'strings'  => array(
                    'enter_isbn'     => __('Please enter an ISBN first.', 'montecchiani-book-catalog'),
                    'lookup_failed'  => __('Lookup failed. Please try again.', 'montecchiani-book-catalog'),
                    'import_failed'  => __('Cover import failed. Please try again.', 'montecchiani-book-catalog'),
                    'importing'      => __('Importing…', 'montecchiani-book-catalog'),
                    'use_as_cover'   => __('Use as cover', 'montecchiani-book-catalog'),
                    'cover_imported' => __('Cover imported.', 'montecchiani-book-catalog'),
                    /* translators: %s: comma separated list of field names */
                    'filled_fields'  => __('Fields filled: %s', 'montecchiani-book-catalog'),
                    'nothing_filled' => __('No empty fields to fill. Existing values were kept.', 'montecchiani-book-catalog'),
                    /* translators: %s: comma separated list of data sources, e.g. "Google Books, Open Library" */
                    'source'         => __('Source: %s', 'montecchiani-book-catalog'),
                    /* translators: separator between the field names listed in the "Fields filled" notice */
                    'separator'      => _x(', ', 'list item separator', 'montecchiani-book-catalog'),
                    'fields'         => array(
                        'author'      => _x('author', 'book field name', 'montecchiani-book-catalog'),
                        'publisher'   => _x('publisher', 'book field name', 'montecchiani-book-catalog'),
                        'year'        => _x('year', 'book field name', 'montecchiani-book-catalog'),
                        'pages'       => _x('pages', 'book field name', 'montecchiani-book-catalog'),
                        'language'    => _x('language', 'book field name', 'montecchiani-book-catalog'),
                        'title'       => _x('title', 'book field name', 'montecchiani-book-catalog'),
                        'description' => _x('description', 'book field name', 'montecchiani-book-catalog'),
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
        require_once MBCAT_PLUGIN_DIR . 'includes/class-mbcat-meta-order.php';
        require_once MBCAT_PLUGIN_DIR . 'includes/class-mbcat-post-type.php';
        require_once MBCAT_PLUGIN_DIR . 'includes/class-mbcat-settings.php';

        $post_type = MBCat_Post_Type::get_instance();
        $post_type->register_post_type();
        $post_type->register_taxonomy();

        // Set default options (merge to preserve values on re-activation)
        $existing = get_option('mbcat_settings', array());
        if (!is_array($existing)) {
            $existing = array();
        }

        update_option('mbcat_settings', wp_parse_args($existing, MBCat_Settings::get_defaults()));

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

        if (!is_plugin_active_for_network(MBCAT_PLUGIN_BASENAME)) {
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
register_activation_hook(__FILE__, array('MBCat_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('MBCat_Plugin', 'deactivate'));

// Multisite: set new sites up when the plugin is network-active.
add_action('wp_initialize_site', array('MBCat_Plugin', 'activate_new_site'), 20);

// Initialize plugin
function mbcat_init() {
    return MBCat_Plugin::get_instance();
}

// Start the plugin
mbcat_init();
