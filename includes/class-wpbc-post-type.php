<?php
/**
 * Custom Post Type for Books
 *
 * @package WP_Book_Catalog
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WPBC_Post_Type
 */
class WPBC_Post_Type {

    /**
     * Single instance
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
        add_action('init', array($this, 'register_post_type'));
        add_action('init', array($this, 'register_taxonomy'));
    }

    /**
     * Register Book post type
     */
    public function register_post_type() {
        $labels = array(
            'name'                  => _x('Books', 'Post type general name', 'wp-book-catalog'),
            'singular_name'         => _x('Book', 'Post type singular name', 'wp-book-catalog'),
            'menu_name'             => _x('Book Catalog', 'Admin Menu text', 'wp-book-catalog'),
            'name_admin_bar'        => _x('Book', 'Add New on Toolbar', 'wp-book-catalog'),
            'add_new'               => __('Add New', 'wp-book-catalog'),
            'add_new_item'          => __('Add New Book', 'wp-book-catalog'),
            'new_item'              => __('New Book', 'wp-book-catalog'),
            'edit_item'             => __('Edit Book', 'wp-book-catalog'),
            'view_item'             => __('View Book', 'wp-book-catalog'),
            'all_items'             => __('All Books', 'wp-book-catalog'),
            'search_items'          => __('Search Books', 'wp-book-catalog'),
            'parent_item_colon'     => __('Parent Books:', 'wp-book-catalog'),
            'not_found'             => __('No books found.', 'wp-book-catalog'),
            'not_found_in_trash'    => __('No books found in Trash.', 'wp-book-catalog'),
            'featured_image'        => _x('Book Cover', 'Overrides the "Featured Image" phrase', 'wp-book-catalog'),
            'set_featured_image'    => _x('Set book cover', 'Overrides the "Set featured image" phrase', 'wp-book-catalog'),
            'remove_featured_image' => _x('Remove book cover', 'Overrides the "Remove featured image" phrase', 'wp-book-catalog'),
            'use_featured_image'    => _x('Use as book cover', 'Overrides the "Use as featured image" phrase', 'wp-book-catalog'),
            'archives'              => _x('Book archives', 'The post type archive label', 'wp-book-catalog'),
            'insert_into_item'      => _x('Insert into book', 'Overrides the "Insert into post" phrase', 'wp-book-catalog'),
            'uploaded_to_this_item' => _x('Uploaded to this book', 'Overrides the "Uploaded to this post" phrase', 'wp-book-catalog'),
            'filter_items_list'     => _x('Filter books list', 'Screen reader text', 'wp-book-catalog'),
            'items_list_navigation' => _x('Books list navigation', 'Screen reader text', 'wp-book-catalog'),
            'items_list'            => _x('Books list', 'Screen reader text', 'wp-book-catalog'),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array('slug' => 'book'),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => 20,
            'menu_icon'          => 'dashicons-book',
            'supports'           => array('title', 'editor', 'thumbnail'),
            'show_in_rest'       => true,
        );

        register_post_type('book', $args);
    }

    /**
     * Register Book Genre taxonomy
     */
    public function register_taxonomy() {
        $labels = array(
            'name'              => _x('Genres', 'taxonomy general name', 'wp-book-catalog'),
            'singular_name'     => _x('Genre', 'taxonomy singular name', 'wp-book-catalog'),
            'search_items'      => __('Search Genres', 'wp-book-catalog'),
            'all_items'         => __('All Genres', 'wp-book-catalog'),
            'parent_item'       => __('Parent Genre', 'wp-book-catalog'),
            'parent_item_colon' => __('Parent Genre:', 'wp-book-catalog'),
            'edit_item'         => __('Edit Genre', 'wp-book-catalog'),
            'update_item'       => __('Update Genre', 'wp-book-catalog'),
            'add_new_item'      => __('Add New Genre', 'wp-book-catalog'),
            'new_item_name'     => __('New Genre Name', 'wp-book-catalog'),
            'menu_name'         => __('Genres', 'wp-book-catalog'),
        );

        $args = array(
            'hierarchical'      => true,
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => array('slug' => 'book-genre'),
            'show_in_rest'      => true,
        );

        register_taxonomy('book_genre', array('book'), $args);
    }
}

// Initialize
WPBC_Post_Type::get_instance();
