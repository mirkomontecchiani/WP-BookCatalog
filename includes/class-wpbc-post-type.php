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

        // Admin list table columns
        add_filter('manage_book_posts_columns', array($this, 'add_admin_columns'));
        add_action('manage_book_posts_custom_column', array($this, 'render_admin_columns'), 10, 2);
        add_filter('manage_edit-book_sortable_columns', array($this, 'sortable_admin_columns'));
        add_action('pre_get_posts', array($this, 'handle_admin_sorting'));
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
            'supports'           => array('title', 'editor', 'excerpt', 'thumbnail'),
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
                $new_columns['wpbc_cover'] = __('Cover', 'wp-book-catalog');
                $new_columns['title']      = $label;
                $new_columns['wpbc_author'] = __('Author', 'wp-book-catalog');
                $new_columns['wpbc_year']   = __('Year', 'wp-book-catalog');
                $new_columns['wpbc_isbn']   = __('ISBN', 'wp-book-catalog');
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
            case 'wpbc_cover':
                if (has_post_thumbnail($post_id)) {
                    echo '<a href="' . esc_url(get_edit_post_link($post_id)) . '">';
                    echo get_the_post_thumbnail($post_id, array(40, 60), array('class' => 'wpbc-admin-cover'));
                    echo '</a>';
                } else {
                    echo '<span class="dashicons dashicons-book-alt wpbc-admin-no-cover" aria-hidden="true"></span>';
                }
                break;

            case 'wpbc_author':
                $author = get_post_meta($post_id, 'wpbc_author', true);
                echo $author ? esc_html($author) : '&#8212;';
                break;

            case 'wpbc_year':
                $year = get_post_meta($post_id, 'wpbc_year', true);
                echo $year ? esc_html($year) : '&#8212;';
                break;

            case 'wpbc_isbn':
                $isbn = get_post_meta($post_id, 'wpbc_isbn', true);
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
        $columns['wpbc_author'] = 'wpbc_author';
        $columns['wpbc_year']   = 'wpbc_year';
        return $columns;
    }

    /**
     * Handle sorting by the custom columns in the admin list
     *
     * @param WP_Query $query Current query.
     */
    public function handle_admin_sorting($query) {
        if (!is_admin() || !$query->is_main_query() || 'book' !== $query->get('post_type')) {
            return;
        }

        $orderby = $query->get('orderby');
        $dir     = $query->get('order') ? $query->get('order') : 'ASC';

        // Use an OR EXISTS/NOT EXISTS meta_query (LEFT JOIN) so sorting by these
        // columns does not hide books that have no such meta value.
        if ('wpbc_year' === $orderby) {
            $query->set('meta_query', $this->meta_order_clause('wpbc_year', 'NUMERIC'));
            $query->set('orderby', array('wpbc_meta_order' => $dir));
        } elseif ('wpbc_author' === $orderby) {
            $query->set('meta_query', $this->meta_order_clause('wpbc_author', 'CHAR'));
            $query->set('orderby', array('wpbc_meta_order' => $dir));
        }
    }

    /**
     * OR EXISTS/NOT EXISTS meta_query so meta ordering keeps posts without the key.
     *
     * @param string $key  Meta key.
     * @param string $type 'NUMERIC' or 'CHAR'.
     * @return array
     */
    private function meta_order_clause($key, $type = 'CHAR') {
        return array(
            'relation' => 'OR',
            'wpbc_meta_order' => array(
                'key'     => $key,
                'type'    => $type,
                'compare' => 'EXISTS',
            ),
            array(
                'key'     => $key,
                'compare' => 'NOT EXISTS',
            ),
        );
    }
}

// Initialize
WPBC_Post_Type::get_instance();
