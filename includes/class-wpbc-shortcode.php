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
        // [wpbc_books] is the prefixed, canonical name; [books] is kept as an
        // alias so that content written before 1.2.0 keeps working.
        add_shortcode('wpbc_books', array($this, 'render_shortcode'));
        add_shortcode('books', array($this, 'render_shortcode'));

        add_action('wp_ajax_wpbc_load_all_books', array($this, 'ajax_load_all_books'));
        add_action('wp_ajax_nopriv_wpbc_load_all_books', array($this, 'ajax_load_all_books'));
    }

    /**
     * Maximum number of books rendered in a single request
     *
     * Keeps a very large catalog from exhausting the PHP memory limit, both in
     * the shortcode and in the "Show All Books" AJAX response.
     *
     * @return int
     */
    public static function get_max_books() {
        /**
         * Filters the maximum number of books rendered in one request.
         *
         * @since 1.2.0
         *
         * @param int $max Maximum number of books. Default 500.
         */
        $max = (int) apply_filters('wpbc_max_books_per_request', 500);

        return $max > 0 ? $max : 500;
    }

    /**
     * Render the shortcode
     */
    public function render_shortcode($atts, $content = '', $tag = 'wpbc_books') {
        $atts = shortcode_atts(array(
            'hitem'   => '',
            'columns' => '',
            'orderby' => 'date',
            'order'   => 'DESC',
            'genre'   => '',
        ), $atts, $tag);

        // Assets are registered globally but only loaded when the shortcode renders.
        wp_enqueue_style('wpbc-frontend');
        wp_enqueue_script('wpbc-frontend');

        // Get columns from settings if not specified
        $columns = !empty($atts['columns']) ? absint($atts['columns']) : absint(WPBC_Settings::get_setting('columns', 3));
        $columns = max(1, min(5, $columns));

        // Determine if we're limiting items
        $limit = !empty($atts['hitem']) ? absint($atts['hitem']) : -1;

        // Query arguments
        $query_args = self::build_query_args($atts['orderby'], $atts['order'], $atts['genre'], $limit);

        $books = new WP_Query($query_args);

        if (!$books->have_posts()) {
            return '<p class="wpbc-no-books">' . esc_html__('No books found.', 'wp-book-catalog') . '</p>';
        }

        // Load every cover attachment in one query instead of one per book.
        update_post_thumbnail_cache($books);

        // The query asked for one book more than requested, so an extra row
        // means there is something left for the "Show All Books" button.
        $show_all_button = ($limit > 0) && ($books->post_count > $limit);

        // Generate unique ID for this instance (guaranteed unique within the request)
        $instance_id = wp_unique_id('wpbc-');

        $schema_items = array();

        ob_start();
        ?>
        <div id="<?php echo esc_attr($instance_id); ?>" class="wpbc-container" data-columns="<?php echo esc_attr($columns); ?>">
            <p class="wpbc-status" role="status"></p>
            <div class="wpbc-grid wpbc-columns-<?php echo esc_attr($columns); ?>" aria-busy="false">
                <?php
                $rendered = 0;

                while ($books->have_posts()) {
                    $books->the_post();

                    if ($limit > 0 && $rendered >= $limit) {
                        break;
                    }

                    $book_id      = get_the_ID();
                    $book_meta    = WPBC_Meta_Boxes::get_book_meta($book_id);
                    $book_excerpt = get_the_excerpt($book_id);

                    $this->render_book_item($book_id, $book_meta, $book_excerpt);
                    $schema_items[] = $this->get_book_schema($book_id, $book_meta, $book_excerpt);
                    ++$rendered;
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

            <?php $this->render_schema($schema_items); ?>
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
     * @param int    $limit   Number of posts (-1 for "as many as allowed").
     * @return array
     */
    public static function build_query_args($orderby, $order, $genre, $limit) {
        $max   = self::get_max_books();
        $limit = (int) $limit;

        // -1 means "all", but never more than the per-request maximum. When a
        // limit is given, one extra row is fetched so the caller can tell
        // whether more books exist without paying for SQL_CALC_FOUND_ROWS,
        // which is deprecated in MySQL 8 and scans the whole matching set.
        $posts_per_page = ($limit < 1) ? $max : min($limit + 1, $max);

        $args = array(
            'post_type'           => 'book',
            'posts_per_page'      => $posts_per_page,
            'post_status'         => 'publish',
            'has_password'        => false, // Never expose password-protected books in the public catalog.
            'order'               => (strtoupper($order) === 'ASC') ? 'ASC' : 'DESC',
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
        );

        switch ($orderby) {
            case 'year':
                // A keyed LEFT JOIN, so books without a year are still listed
                // (they simply sort last) instead of vanishing from the grid.
                $args = WPBC_Meta_Order::add_to_args($args, 'wpbc_year', $args['order']);
                break;

            case 'author':
                $args = WPBC_Meta_Order::add_to_args($args, 'wpbc_author', $args['order']);
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

        /**
         * Filters the WP_Query arguments used to list books.
         *
         * @since 1.2.0
         *
         * @param array  $args    Query arguments.
         * @param string $orderby Requested order key.
         * @param string $order   Requested order direction.
         * @param string $genre   Requested genre slugs, comma separated.
         * @param int    $limit   Requested limit (-1 for all).
         */
        return apply_filters('wpbc_shortcode_query_args', $args, $orderby, $order, $genre, $limit);
    }

    /**
     * Render a single book item
     *
     * Everything is escaped where it is printed, so the caller can simply call
     * this method (there is no unescaped markup to hand around).
     *
     * @param int         $post_id Book post ID.
     * @param array|null  $meta    Book meta, when the caller already resolved it.
     * @param string|null $excerpt Book excerpt, when the caller already resolved it.
     */
    private function render_book_item($post_id, $meta = null, $excerpt = null) {
        $meta        = (null === $meta) ? WPBC_Meta_Boxes::get_book_meta($post_id) : $meta;
        $description = (null === $excerpt) ? get_the_excerpt($post_id) : $excerpt;
        $thumbnail   = get_the_post_thumbnail_url($post_id, 'medium');
        $title       = get_the_title($post_id);
        $shop_link   = !empty($meta['shop_link']) ? $meta['shop_link'] : '';

        // Genre names (plain text: the whole card may already be a link)
        $genres = get_the_terms($post_id, 'book_genre');
        $genre_names = (!empty($genres) && !is_wp_error($genres)) ? implode(', ', wp_list_pluck($genres, 'name')) : '';

        // Get author gender from settings
        $author_gender = WPBC_Settings::get_setting('author_gender', 'male');
        $author_label  = ('female' === $author_gender) ? __('Authoress:', 'wp-book-catalog') : __('Author:', 'wp-book-catalog');

        /**
         * Filters the label shown before the author name.
         *
         * The setting is site-wide; this filter allows a per-book label on
         * catalogs that mix authors.
         *
         * @since 1.2.0
         *
         * @param string $author_label Label, including its colon.
         * @param int    $post_id      Book post ID.
         */
        $author_label = apply_filters('wpbc_author_label', $author_label, $post_id);

        // Fallback image
        if (!$thumbnail) {
            $thumbnail = WPBC_PLUGIN_URL . 'assets/images/no-cover.svg';
        }

        // Determine if book is clickable
        $has_link = !empty($shop_link);

        /* translators: %s: book title */
        $link_label = sprintf(__('%s (opens in a new tab)', 'wp-book-catalog'), $title);
        ?>
        <div class="wpbc-book-item">
            <?php if ($has_link) : ?>
            <a href="<?php echo esc_url($shop_link); ?>" class="wpbc-book-link" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr($link_label); ?>">
            <?php else : ?>
            <div class="wpbc-book-link" tabindex="0" role="group" aria-label="<?php echo esc_attr($title); ?>">
            <?php endif; ?>
                <div class="wpbc-book-cover">
                    <?php // The title is part of the card, so the cover itself is decorative. ?>
                    <img src="<?php echo esc_url($thumbnail); ?>" alt="" loading="lazy" />

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
            <?php if ($has_link) : ?>
            </a>
            <?php else : ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Build the schema.org Book record for a post
     *
     * @param int         $post_id Book post ID.
     * @param array|null  $meta    Book meta, when the caller already resolved it.
     * @param string|null $excerpt Book excerpt, when the caller already resolved it.
     * @return array
     */
    private function get_book_schema($post_id, $meta = null, $excerpt = null) {
        $meta = (null === $meta) ? WPBC_Meta_Boxes::get_book_meta($post_id) : $meta;

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

        $excerpt = (null === $excerpt) ? get_the_excerpt($post_id) : $excerpt;
        if (!empty($excerpt)) {
            $item['description'] = wp_strip_all_tags($excerpt);
        }

        return $item;
    }

    /**
     * Print the JSON-LD structured data block for SEO
     *
     * @param array $schema_items Array of Book schema records.
     */
    private function render_schema($schema_items) {
        if (empty($schema_items)) {
            return;
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

        // JSON_HEX_TAG / JSON_HEX_AMP keep "<", ">" and "&" out of the literal
        // output, so no book field can close the <script> element early.
        $json = wp_json_encode($schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === $json) {
            return;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD payload, escaped above with JSON_HEX_TAG | JSON_HEX_AMP.
        echo '<script type="application/ld+json">' . $json . '</script>';
    }

    /**
     * AJAX handler to load all books
     *
     * This endpoint is deliberately not protected by a nonce. It performs no
     * action and returns nothing that is not already public on the page the
     * request came from - the very same published, non password protected books
     * the shortcode has just rendered. A nonce would add no protection here and
     * would break the button on any site with full page caching, because the
     * cached HTML would keep serving a token that expires after 24 hours.
     */
    public function ajax_load_all_books() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Public, read-only endpoint; see the method docblock.
        $orderby = isset($_POST['orderby']) ? sanitize_key(wp_unslash($_POST['orderby'])) : 'date';
        $order   = isset($_POST['order']) ? sanitize_key(wp_unslash($_POST['order'])) : 'DESC';
        $genre   = isset($_POST['genre']) ? sanitize_text_field(wp_unslash($_POST['genre'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        // Query all books with the same filters used by the shortcode instance
        $query_args = self::build_query_args($orderby, $order, $genre, -1);

        $books = new WP_Query($query_args);

        if (!$books->have_posts()) {
            wp_send_json_error(array('message' => __('No books found.', 'wp-book-catalog')));
        }

        update_post_thumbnail_cache($books);

        $schema_items = array();

        ob_start();
        while ($books->have_posts()) {
            $books->the_post();

            $book_id      = get_the_ID();
            $book_meta    = WPBC_Meta_Boxes::get_book_meta($book_id);
            $book_excerpt = get_the_excerpt($book_id);

            $this->render_book_item($book_id, $book_meta, $book_excerpt);
            $schema_items[] = $this->get_book_schema($book_id, $book_meta, $book_excerpt);
        }
        wp_reset_postdata();

        $html = ob_get_clean();

        ob_start();
        $this->render_schema($schema_items);
        $schema_html = ob_get_clean();

        wp_send_json_success(array(
            'html'   => $html,
            // So the JSON-LD keeps describing what the page actually shows.
            'schema' => $schema_html,
        ));
    }
}

// Initialize
WPBC_Shortcode::get_instance();
