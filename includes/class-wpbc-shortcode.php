<?php
/**
 * Shortcode functionality for displaying books
 *
 * @package WP_Book_Catalog
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WPBC_Shortcode
 */
class WPBC_Shortcode {

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
        add_shortcode('books', array($this, 'render_shortcode'));
        add_action('wp_ajax_wpbc_load_all_books', array($this, 'ajax_load_all_books'));
        add_action('wp_ajax_nopriv_wpbc_load_all_books', array($this, 'ajax_load_all_books'));
    }

    /**
     * Render the shortcode
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'hitem'   => '',
            'columns' => '',
            'orderby' => 'date',
            'order'   => 'DESC',
            'genre'   => '',
        ), $atts, 'books');

        // Assets are registered globally but only loaded when the shortcode renders.
        wp_enqueue_style('wpbc-frontend');
        wp_enqueue_script('wpbc-frontend');

        // Get columns from settings if not specified
        $columns = !empty($atts['columns']) ? absint($atts['columns']) : WPBC_Settings::get_setting('columns', 3);
        $columns = max(1, min(5, $columns));

        // Determine if we're limiting items
        $limit = !empty($atts['hitem']) ? absint($atts['hitem']) : -1;

        // Query arguments
        $query_args = self::build_query_args($atts['orderby'], $atts['order'], $atts['genre'], $limit);

        $books = new WP_Query($query_args);

        if (!$books->have_posts()) {
            return '<p class="wpbc-no-books">' . esc_html__('No books found.', 'wp-book-catalog') . '</p>';
        }

        // Only show the button when there actually are more books to load.
        $show_all_button = ($limit > 0) && ((int) $books->found_posts > $limit);

        // Generate unique ID for this instance (guaranteed unique within the request)
        $instance_id = wp_unique_id('wpbc-');

        $schema_items = array();

        ob_start();
        ?>
        <div id="<?php echo esc_attr($instance_id); ?>" class="wpbc-container" data-columns="<?php echo esc_attr($columns); ?>">
            <div class="wpbc-grid wpbc-columns-<?php echo esc_attr($columns); ?>" aria-live="polite" aria-busy="false">
                <?php
                while ($books->have_posts()) {
                    $books->the_post();
                    echo $this->render_book_item(get_the_ID());
                    $schema_items[] = $this->get_book_schema(get_the_ID());
                }
                wp_reset_postdata();
                ?>
            </div>

            <?php if ($show_all_button) : ?>
                <div class="wpbc-show-all-wrapper">
                    <button type="button" class="wpbc-show-all-btn"
                            data-instance="<?php echo esc_attr($instance_id); ?>"
                            data-columns="<?php echo esc_attr($columns); ?>"
                            data-orderby="<?php echo esc_attr($atts['orderby']); ?>"
                            data-order="<?php echo esc_attr($atts['order']); ?>"
                            data-genre="<?php echo esc_attr($atts['genre']); ?>">
                        <?php esc_html_e('Show All Books', 'wp-book-catalog'); ?>
                    </button>
                </div>
            <?php endif; ?>

            <?php echo $this->render_schema($schema_items); ?>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Build WP_Query arguments shared by the shortcode and the AJAX handler
     *
     * @param string $orderby Order key (date, title, rand, menu_order, modified, year, author).
     * @param string $order   ASC or DESC.
     * @param string $genre   Comma separated genre slugs.
     * @param int    $limit   Number of posts (-1 for all).
     * @return array
     */
    public static function build_query_args($orderby, $order, $genre, $limit) {
        $args = array(
            'post_type'      => 'book',
            'posts_per_page' => (int) $limit,
            'post_status'    => 'publish',
            'has_password'   => false, // Never expose password-protected books in the public catalog.
            'order'          => (strtoupper($order) === 'ASC') ? 'ASC' : 'DESC',
        );

        switch ($orderby) {
            case 'year':
                // LEFT JOIN via OR EXISTS/NOT EXISTS so books without a year are
                // still returned (they just sort as empty) instead of vanishing.
                $args['meta_query'] = self::meta_order_clause('wpbc_year', 'NUMERIC');
                $args['orderby']    = array('wpbc_meta_order' => $args['order']);
                break;

            case 'author':
                $args['meta_query'] = self::meta_order_clause('wpbc_author', 'CHAR');
                $args['orderby']    = array('wpbc_meta_order' => $args['order']);
                break;

            case 'title':
            case 'rand':
            case 'menu_order':
            case 'modified':
            case 'date':
                $args['orderby'] = $orderby;
                break;

            default:
                $args['orderby'] = 'date';
                break;
        }

        if (!empty($genre)) {
            $slugs = array_filter(array_map('sanitize_title', explode(',', $genre)));
            if (!empty($slugs)) {
                $args['tax_query'] = array(
                    array(
                        'taxonomy' => 'book_genre',
                        'field'    => 'slug',
                        'terms'    => $slugs,
                    ),
                );
            }
        }

        return $args;
    }

    /**
     * Build an OR EXISTS/NOT EXISTS meta_query that lets WP_Query order by a
     * meta value with a LEFT JOIN, so posts missing the key are still returned.
     *
     * The EXISTS branch is named 'wpbc_meta_order' so it can be referenced by
     * the orderby argument.
     *
     * @param string $key  Meta key to order by.
     * @param string $type SQL type for ordering ('NUMERIC' or 'CHAR').
     * @return array
     */
    public static function meta_order_clause($key, $type = 'CHAR') {
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

    /**
     * Render a single book item
     */
    private function render_book_item($post_id) {
        $meta = WPBC_Meta_Boxes::get_book_meta($post_id);
        $thumbnail = get_the_post_thumbnail_url($post_id, 'medium');
        $title = get_the_title($post_id);
        $description = get_the_excerpt($post_id);
        $shop_link = !empty($meta['shop_link']) ? $meta['shop_link'] : '';

        // Genre names (plain text: the whole card may already be a link)
        $genres = get_the_terms($post_id, 'book_genre');
        $genre_names = (!empty($genres) && !is_wp_error($genres)) ? implode(', ', wp_list_pluck($genres, 'name')) : '';

        // Get author gender from settings
        $author_gender = WPBC_Settings::get_setting('author_gender', 'male');
        $author_label = ($author_gender === 'female') ? __('Authoress:', 'wp-book-catalog') : __('Author:', 'wp-book-catalog');

        // Fallback image
        if (!$thumbnail) {
            $thumbnail = WPBC_PLUGIN_URL . 'assets/images/no-cover.svg';
        }

        // Determine if book is clickable
        $has_link = !empty($shop_link);
        $tag_open = $has_link
            ? '<a href="' . esc_url($shop_link) . '" class="wpbc-book-link" target="_blank" rel="noopener noreferrer">'
            : '<div class="wpbc-book-link" tabindex="0">';
        $tag_close = $has_link ? '</a>' : '</div>';

        ob_start();
        ?>
        <div class="wpbc-book-item">
            <?php echo $tag_open; ?>
                <div class="wpbc-book-cover">
                    <img src="<?php echo esc_url($thumbnail); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy" />

                    <div class="wpbc-book-overlay">
                        <div class="wpbc-book-info">
                            <h3 class="wpbc-book-title"><?php echo esc_html($title); ?></h3>

                            <?php if (!empty($description)) : ?>
                                <p class="wpbc-book-description"><?php echo esc_html(wp_trim_words($description, 15)); ?></p>
                            <?php endif; ?>

                            <hr class="wpbc-separator" />

                            <?php if (!empty($meta['author'])) : ?>
                                <p class="wpbc-book-author">
                                    <span class="wpbc-label"><?php echo esc_html($author_label); ?></span>
                                    <?php echo esc_html($meta['author']); ?>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($meta['publisher'])) : ?>
                                <p class="wpbc-book-publisher">
                                    <span class="wpbc-label"><?php esc_html_e('Publisher:', 'wp-book-catalog'); ?></span>
                                    <?php echo esc_html($meta['publisher']); ?>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($meta['year'])) : ?>
                                <p class="wpbc-book-year">
                                    <span class="wpbc-label"><?php esc_html_e('Year:', 'wp-book-catalog'); ?></span>
                                    <?php echo esc_html($meta['year']); ?>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($meta['pages'])) : ?>
                                <p class="wpbc-book-pages">
                                    <span class="wpbc-label"><?php esc_html_e('Pages:', 'wp-book-catalog'); ?></span>
                                    <?php echo esc_html($meta['pages']); ?>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($genre_names)) : ?>
                                <p class="wpbc-book-genre">
                                    <span class="wpbc-label"><?php esc_html_e('Genre:', 'wp-book-catalog'); ?></span>
                                    <?php echo esc_html($genre_names); ?>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($meta['isbn'])) : ?>
                                <p class="wpbc-book-isbn">
                                    <span class="wpbc-label"><?php esc_html_e('ISBN:', 'wp-book-catalog'); ?></span>
                                    <?php echo esc_html($meta['isbn']); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php echo $tag_close; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Build the schema.org Book record for a post
     *
     * @param int $post_id Book post ID.
     * @return array
     */
    private function get_book_schema($post_id) {
        $meta = WPBC_Meta_Boxes::get_book_meta($post_id);

        $item = array(
            '@type' => 'Book',
            'name'  => get_the_title($post_id),
        );

        if (!empty($meta['author'])) {
            $item['author'] = array(
                '@type' => 'Person',
                'name'  => $meta['author'],
            );
        }

        if (!empty($meta['publisher'])) {
            $item['publisher'] = array(
                '@type' => 'Organization',
                'name'  => $meta['publisher'],
            );
        }

        if (!empty($meta['year'])) {
            $item['datePublished'] = (string) $meta['year'];
        }

        if (!empty($meta['isbn'])) {
            $item['isbn'] = $meta['isbn'];
        }

        if (!empty($meta['pages'])) {
            $item['numberOfPages'] = (int) $meta['pages'];
        }

        if (!empty($meta['language'])) {
            $item['inLanguage'] = $meta['language'];
        }

        $thumbnail = get_the_post_thumbnail_url($post_id, 'large');
        if ($thumbnail) {
            $item['image'] = $thumbnail;
        }

        if (!empty($meta['shop_link'])) {
            $item['url'] = $meta['shop_link'];
        }

        $excerpt = get_the_excerpt($post_id);
        if (!empty($excerpt)) {
            $item['description'] = wp_strip_all_tags($excerpt);
        }

        return $item;
    }

    /**
     * Render the JSON-LD structured data block for SEO
     *
     * @param array $schema_items Array of Book schema records.
     * @return string
     */
    private function render_schema($schema_items) {
        if (empty($schema_items)) {
            return '';
        }

        $elements = array();
        foreach (array_values($schema_items) as $index => $item) {
            $elements[] = array(
                '@type'    => 'ListItem',
                'position' => $index + 1,
                'item'     => $item,
            );
        }

        $schema = array(
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'itemListElement' => $elements,
        );

        return '<script type="application/ld+json">' . wp_json_encode($schema) . '</script>';
    }

    /**
     * AJAX handler to load all books
     */
    public function ajax_load_all_books() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['nonce'])), 'wpbc_nonce')) {
            wp_send_json_error(__('Security check failed.', 'wp-book-catalog'));
        }

        $orderby = isset($_POST['orderby']) ? sanitize_key(wp_unslash($_POST['orderby'])) : 'date';
        $order   = isset($_POST['order']) ? sanitize_key(wp_unslash($_POST['order'])) : 'DESC';
        $genre   = isset($_POST['genre']) ? sanitize_text_field(wp_unslash($_POST['genre'])) : '';

        // Query all books with the same filters used by the shortcode instance
        $query_args = self::build_query_args($orderby, $order, $genre, -1);

        $books = new WP_Query($query_args);

        if (!$books->have_posts()) {
            wp_send_json_error(__('No books found.', 'wp-book-catalog'));
        }

        ob_start();
        while ($books->have_posts()) {
            $books->the_post();
            echo $this->render_book_item(get_the_ID());
        }
        wp_reset_postdata();

        $html = ob_get_clean();

        wp_send_json_success(array(
            'html' => $html,
        ));
    }
}

// Initialize
WPBC_Shortcode::get_instance();
