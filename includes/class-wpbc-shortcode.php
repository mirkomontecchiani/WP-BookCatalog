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
        ), $atts, 'books');

        // Get columns from settings if not specified
        $columns = !empty($atts['columns']) ? absint($atts['columns']) : WPBC_Settings::get_setting('columns', 3);
        $columns = max(1, min(5, $columns));

        // Determine if we're limiting items
        $limit = !empty($atts['hitem']) ? absint($atts['hitem']) : -1;
        $show_all_button = $limit > 0;

        // Query arguments
        $query_args = array(
            'post_type'      => 'book',
            'posts_per_page' => $limit,
            'orderby'        => sanitize_key($atts['orderby']),
            'order'          => strtoupper($atts['order']) === 'ASC' ? 'ASC' : 'DESC',
            'post_status'    => 'publish',
        );

        $books = new WP_Query($query_args);

        if (!$books->have_posts()) {
            return '<p class="wpbc-no-books">' . __('No books found.', 'wp-book-catalog') . '</p>';
        }

        // Generate unique ID for this instance
        $instance_id = 'wpbc-' . uniqid();

        ob_start();
        ?>
        <div id="<?php echo esc_attr($instance_id); ?>" class="wpbc-container" data-columns="<?php echo esc_attr($columns); ?>">
            <div class="wpbc-grid wpbc-columns-<?php echo esc_attr($columns); ?>">
                <?php
                while ($books->have_posts()) {
                    $books->the_post();
                    echo $this->render_book_item(get_the_ID());
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
                            data-order="<?php echo esc_attr($atts['order']); ?>">
                        <?php _e('Show All Books', 'wp-book-catalog'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <?php

        return ob_get_clean();
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

        // Get author gender from settings
        $author_gender = WPBC_Settings::get_setting('author_gender', 'male');
        $author_label = ($author_gender === 'female') ? __('Authoress:', 'wp-book-catalog') : __('Author:', 'wp-book-catalog');

        // Fallback image
        if (!$thumbnail) {
            $thumbnail = WPBC_PLUGIN_URL . 'assets/images/no-cover.svg';
        }

        // Determine if book is clickable
        $has_link = !empty($shop_link);
        $tag_open = $has_link ? '<a href="' . esc_url($shop_link) . '" class="wpbc-book-link" target="_blank" rel="noopener noreferrer">' : '<div class="wpbc-book-link">';
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

                            <?php if (!empty($meta['author'])) : ?>
                                <p class="wpbc-book-author">
                                    <span class="wpbc-label"><?php echo esc_html($author_label); ?></span>
                                    <?php echo esc_html($meta['author']); ?>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($meta['publisher'])) : ?>
                                <p class="wpbc-book-publisher">
                                    <span class="wpbc-label"><?php _e('Publisher:', 'wp-book-catalog'); ?></span>
                                    <?php echo esc_html($meta['publisher']); ?>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($meta['year'])) : ?>
                                <p class="wpbc-book-year">
                                    <span class="wpbc-label"><?php _e('Year:', 'wp-book-catalog'); ?></span>
                                    <?php echo esc_html($meta['year']); ?>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($meta['isbn'])) : ?>
                                <p class="wpbc-book-isbn">
                                    <span class="wpbc-label"><?php _e('ISBN:', 'wp-book-catalog'); ?></span>
                                    <?php echo esc_html($meta['isbn']); ?>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($description)) : ?>
                                <p class="wpbc-book-description"><?php echo esc_html(wp_trim_words($description, 15)); ?></p>
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
     * AJAX handler to load all books
     */
    public function ajax_load_all_books() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wpbc_nonce')) {
            wp_send_json_error(__('Security check failed.', 'wp-book-catalog'));
        }

        $columns = isset($_POST['columns']) ? absint($_POST['columns']) : 3;
        $orderby = isset($_POST['orderby']) ? sanitize_key($_POST['orderby']) : 'date';
        $order = isset($_POST['order']) && strtoupper($_POST['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Query all books
        $query_args = array(
            'post_type'      => 'book',
            'posts_per_page' => -1,
            'orderby'        => $orderby,
            'order'          => $order,
            'post_status'    => 'publish',
        );

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
            'html'    => $html,
            'columns' => $columns,
        ));
    }
}

// Initialize
WPBC_Shortcode::get_instance();
