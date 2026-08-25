<?php
/**
 * Custom Post Type for Books
 *
 * @package Montecchiani_Book_Catalog
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MBCat_Post_Type
 */
class MBCat_Post_Type {

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

        // Admin list table columns
        add_filter('manage_mbcat_book_posts_columns', array($this, 'add_admin_columns'));
        add_action('manage_mbcat_book_posts_custom_column', array($this, 'render_admin_columns'), 10, 2);
        add_filter('manage_edit-mbcat_book_sortable_columns', array($this, 'sortable_admin_columns'));
        add_action('pre_get_posts', array($this, 'handle_admin_sorting'));
    }

    /**
     * Register Book post type
     */
    public function register_post_type() {
        $labels = array(
            'name'                  => _x('Books', 'Post type general name', 'montecchiani-book-catalog'),
            'singular_name'         => _x('Book', 'Post type singular name', 'montecchiani-book-catalog'),
            'menu_name'             => _x('Book Catalog', 'Admin Menu text', 'montecchiani-book-catalog'),
            'name_admin_bar'        => _x('Book', 'Add New on Toolbar', 'montecchiani-book-catalog'),
            'add_new'               => __('Add New', 'montecchiani-book-catalog'),
            'add_new_item'          => __('Add New Book', 'montecchiani-book-catalog'),
            'new_item'              => __('New Book', 'montecchiani-book-catalog'),
            'edit_item'             => __('Edit Book', 'montecchiani-book-catalog'),
            'view_item'             => __('View Book', 'montecchiani-book-catalog'),
            'all_items'             => __('All Books', 'montecchiani-book-catalog'),
            'search_items'          => __('Search Books', 'montecchiani-book-catalog'),
            'parent_item_colon'     => __('Parent Books:', 'montecchiani-book-catalog'),
            'not_found'             => __('No books found.', 'montecchiani-book-catalog'),
            'not_found_in_trash'    => __('No books found in Trash.', 'montecchiani-book-catalog'),
            'featured_image'        => _x('Book Cover', 'Overrides the "Featured Image" phrase', 'montecchiani-book-catalog'),
            'set_featured_image'    => _x('Set book cover', 'Overrides the "Set featured image" phrase', 'montecchiani-book-catalog'),
            'remove_featured_image' => _x('Remove book cover', 'Overrides the "Remove featured image" phrase', 'montecchiani-book-catalog'),
            'use_featured_image'    => _x('Use as book cover', 'Overrides the "Use as featured image" phrase', 'montecchiani-book-catalog'),
            'archives'              => _x('Book archives', 'The post type archive label', 'montecchiani-book-catalog'),
            'insert_into_item'      => _x('Insert into book', 'Overrides the "Insert into post" phrase', 'montecchiani-book-catalog'),
            'uploaded_to_this_item' => _x('Uploaded to this book', 'Overrides the "Uploaded to this post" phrase', 'montecchiani-book-catalog'),
            'filter_items_list'     => _x('Filter books list', 'Screen reader text', 'montecchiani-book-catalog'),
            'items_list_navigation' => _x('Books list navigation', 'Screen reader text', 'montecchiani-book-catalog'),
            'items_list'            => _x('Books list', 'Screen reader text', 'montecchiani-book-catalog'),
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
            'supports'           => array('title', 'editor', 'excerpt', 'thumbnail'),
            'show_in_rest'       => true,
        );

        register_post_type('mbcat_book', $args);
    }

    /**
     * Register Book Genre taxonomy
     */
    public function register_taxonomy() {
        $labels = array(
            'name'              => _x('Genres', 'taxonomy general name', 'montecchiani-book-catalog'),
            'singular_name'     => _x('Genre', 'taxonomy singular name', 'montecchiani-book-catalog'),
            'search_items'      => __('Search Genres', 'montecchiani-book-catalog'),
            'all_items'         => __('All Genres', 'montecchiani-book-catalog'),
            'parent_item'       => __('Parent Genre', 'montecchiani-book-catalog'),
            'parent_item_colon' => __('Parent Genre:', 'montecchiani-book-catalog'),
            'edit_item'         => __('Edit Genre', 'montecchiani-book-catalog'),
            'update_item'       => __('Update Genre', 'montecchiani-book-catalog'),
            'add_new_item'      => __('Add New Genre', 'montecchiani-book-catalog'),
            'new_item_name'     => __('New Genre Name', 'montecchiani-book-catalog'),
            'menu_name'         => __('Genres', 'montecchiani-book-catalog'),
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

        register_taxonomy('mbcat_genre', array('mbcat_book'), $args);
    }

    /**
     * Add custom columns to the Books admin list
     *
     * @param array $columns Existing columns.
     * @return array
     */
    public function add_admin_columns($columns) {
        $new_columns = array();

        foreach ($columns as $key => $label) {
            if ('title' === $key) {
                $new_columns['mbcat_cover'] = __('Cover', 'montecchiani-book-catalog');
                $new_columns['title']      = $label;
                $new_columns['mbcat_author'] = __('Author', 'montecchiani-book-catalog');
                $new_columns['mbcat_year']   = __('Year', 'montecchiani-book-catalog');
                $new_columns['mbcat_isbn']   = __('ISBN', 'montecchiani-book-catalog');
            } else {
                $new_columns[$key] = $label;
            }
        }

        return $new_columns;
    }

    /**
     * Render custom column content
     *
     * @param string $column  Column key.
     * @param int    $post_id Post ID.
     */
    public function render_admin_columns($column, $post_id) {
        switch ($column) {
            case 'mbcat_cover':
                if (has_post_thumbnail($post_id)) {
                    echo '<a href="' . esc_url(get_edit_post_link($post_id)) . '">';
                    echo get_the_post_thumbnail($post_id, array(40, 60), array('class' => 'mbcat-admin-cover'));
                    echo '</a>';
                } else {
                    echo '<span class="dashicons dashicons-book-alt mbcat-admin-no-cover" aria-hidden="true"></span>';
                }
                break;

            case 'mbcat_author':
                // Resolve through get_book_meta() so the list matches the front
                // end when the "Default Author" setting is used.
                $meta = MBCat_Meta_Boxes::get_book_meta($post_id);
                echo $meta['author'] ? esc_html($meta['author']) : '&#8212;';
                break;

            case 'mbcat_year':
                $year = get_post_meta($post_id, 'mbcat_year', true);
                echo $year ? esc_html($year) : '&#8212;';
                break;

            case 'mbcat_isbn':
                $isbn = get_post_meta($post_id, 'mbcat_isbn', true);
                echo $isbn ? esc_html($isbn) : '&#8212;';
                break;
        }
    }

    /**
     * Make custom columns sortable
     *
     * @param array $columns Sortable columns.
     * @return array
     */
    public function sortable_admin_columns($columns) {
        $columns['mbcat_author'] = 'mbcat_author';
        $columns['mbcat_year']   = 'mbcat_year';
        return $columns;
    }

    /**
     * Handle sorting by the custom columns in the admin list
     *
     * @param WP_Query $query Current query.
     */
    public function handle_admin_sorting($query) {
        if (!is_admin() || !$query->is_main_query() || 'mbcat_book' !== $query->get('post_type')) {
            return;
        }

        $orderby = $query->get('orderby');

        if (!is_string($orderby) || !array_key_exists($orderby, MBCat_Meta_Order::get_supported_keys())) {
            return;
        }

        $dir = $query->get('order') ? $query->get('order') : 'ASC';

        // A keyed LEFT JOIN, so sorting by these columns does not hide books
        // that have no value for the meta key.
        MBCat_Meta_Order::add_to_query($query, $orderby, $dir);
    }
}

// Initialize
MBCat_Post_Type::get_instance();
