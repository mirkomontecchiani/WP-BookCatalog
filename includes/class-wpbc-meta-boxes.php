<?php
/**
 * Meta Boxes for Book custom fields
 *
 * @package MM_Book_Catalog
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WPBC_Meta_Boxes
 */
class WPBC_Meta_Boxes {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Meta fields configuration
     */
    private $meta_fields = array();

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
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post_book', array($this, 'save_meta_boxes'));
    }

    /**
     * Setup meta fields (lazily, so labels are translated after init)
     */
    private function get_fields() {
        if (!empty($this->meta_fields)) {
            return $this->meta_fields;
        }

        $this->meta_fields = array(
            'wpbc_isbn' => array(
                'label'       => __('ISBN', 'mm-book-catalog'),
                'type'        => 'isbn',
                'placeholder' => __('Enter ISBN (10 or 13 digits)', 'mm-book-catalog'),
            ),
            'wpbc_author' => array(
                'label'       => __('Author', 'mm-book-catalog'),
                'type'        => 'text',
                'placeholder' => __('Enter book author', 'mm-book-catalog'),
            ),
            'wpbc_publisher' => array(
                'label'       => __('Publisher', 'mm-book-catalog'),
                'type'        => 'text',
                'placeholder' => __('Enter publisher name', 'mm-book-catalog'),
            ),
            'wpbc_year' => array(
                'label'       => __('Publication Year', 'mm-book-catalog'),
                'type'        => 'number',
                'placeholder' => __('Enter publication year', 'mm-book-catalog'),
                'min'         => 1,
                'max'         => 2100,
            ),
            'wpbc_pages' => array(
                'label'       => __('Number of Pages', 'mm-book-catalog'),
                'type'        => 'number',
                'placeholder' => __('Enter number of pages', 'mm-book-catalog'),
                'min'         => 1,
                'max'         => 100000,
            ),
            'wpbc_language' => array(
                'label'       => __('Language', 'mm-book-catalog'),
                'type'        => 'text',
                'placeholder' => __('Enter book language (e.g. it, en)', 'mm-book-catalog'),
            ),
            'wpbc_shop_link' => array(
                'label'       => __('Shop Link', 'mm-book-catalog'),
                'type'        => 'url',
                'placeholder' => __('Enter shop URL', 'mm-book-catalog'),
            ),
        );

        return $this->meta_fields;
    }

    /**
     * Add meta boxes
     */
    public function add_meta_boxes() {
        add_meta_box(
            'wpbc_book_details',
            __('Book Details', 'mm-book-catalog'),
            array($this, 'render_meta_box'),
            'book',
            'normal',
            'high'
        );
    }

    /**
     * Render meta box
     */
    public function render_meta_box($post) {
        // Add nonce for security
        wp_nonce_field('wpbc_save_meta_boxes', 'wpbc_meta_nonce');

        // Get plugin settings
        $default_author = WPBC_Settings::get_setting('default_author', '');

        echo '<div class="wpbc-meta-box-wrapper">';

        foreach ($this->get_fields() as $key => $field) {
            $value       = get_post_meta($post->ID, $key, true);
            $placeholder = $field['placeholder'];

            // The default author is shown as a placeholder, never as a value: writing
            // it into the post meta would freeze it, so that later changes to the
            // setting would no longer apply to this book.
            if ('wpbc_author' === $key && !empty($default_author)) {
                $placeholder = $default_author;
            }

            echo '<div class="wpbc-field-wrapper">';
            echo '<label for="' . esc_attr($key) . '">' . esc_html($field['label']) . '</label>';

            switch ($field['type']) {
                case 'isbn':
                    echo '<div class="wpbc-isbn-row">';
                    echo '<input type="text" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '" class="widefat" />';
                    echo '<button type="button" class="button button-secondary" id="wpbc-isbn-lookup-btn">';
                    echo '<span class="dashicons dashicons-search" aria-hidden="true"></span> ';
                    echo esc_html__('Autofill from ISBN', 'mm-book-catalog');
                    echo '</button>';
                    echo '<span class="spinner" id="wpbc-isbn-spinner"></span>';
                    echo '</div>';
                    echo '<p class="description">' . esc_html__('Fetches title, author, publisher, year, pages, description and cover from Google Books / Open Library.', 'mm-book-catalog') . '</p>';
                    echo '<div id="wpbc-isbn-lookup-result" style="display:none;"></div>';
                    echo '<div id="wpbc-cover-preview" style="display:none;"></div>';
                    break;

                case 'url':
                    echo '<input type="url" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '" class="widefat" />';
                    break;

                case 'number':
                    $min = isset($field['min']) ? (int) $field['min'] : 0;
                    $max = isset($field['max']) ? (int) $field['max'] : 9999;
                    echo '<input type="number" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '" class="widefat" min="' . esc_attr($min) . '" max="' . esc_attr($max) . '" />';
                    break;

                default:
                    echo '<input type="text" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '" class="widefat" />';
                    break;
            }

            // Show note for author field if default is set
            if ('wpbc_author' === $key && !empty($default_author)) {
                echo '<p class="description">' . esc_html(sprintf(
                    /* translators: %s: default author name */
                    __('Leave empty to use the default author from the settings: %s', 'mm-book-catalog'),
                    $default_author
                )) . '</p>';
            }

            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Save meta boxes
     *
     * @param int $post_id Book post ID.
     */
    public function save_meta_boxes($post_id) {
        // Verify nonce
        if (!isset($_POST['wpbc_meta_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['wpbc_meta_nonce'])), 'wpbc_save_meta_boxes')) {
            return;
        }

        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save each field; delete the meta row when the field is emptied.
        foreach ($this->get_fields() as $key => $field) {
            if (!isset($_POST[$key]) || !is_scalar($_POST[$key])) {
                continue;
            }

            // Sanitize where the superglobal is read, per field type.
            switch ($field['type']) {
                case 'url':
                    $value = esc_url_raw(trim(wp_unslash($_POST[$key]))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() is the sanitizer for a URL.
                    break;

                case 'number':
                    $value = trim(sanitize_text_field(wp_unslash($_POST[$key])));

                    if ('' !== $value) {
                        $min    = isset($field['min']) ? (int) $field['min'] : 0;
                        $max    = isset($field['max']) ? (int) $field['max'] : PHP_INT_MAX;
                        $number = absint($value);

                        // Out of range means the input is not a usable value, so
                        // the field is cleared rather than stored as a wrong number.
                        $value = ($number < $min || $number > $max) ? '' : (string) $number;
                    }
                    break;

                default:
                    $value = sanitize_text_field(wp_unslash($_POST[$key]));
                    break;
            }

            // An emptied field removes the meta row; anything else is stored as
            // typed, so that a legitimate 0 is not silently discarded.
            if ('' === $value) {
                delete_post_meta($post_id, $key);
            } else {
                update_post_meta($post_id, $key, $value);
            }
        }
    }

    /**
     * Get book meta data
     */
    public static function get_book_meta($post_id) {
        $default_author = WPBC_Settings::get_setting('default_author', '');

        $author = get_post_meta($post_id, 'wpbc_author', true);

        // Use default author if field is empty and default is set
        if (empty($author) && !empty($default_author)) {
            $author = $default_author;
        }

        return array(
            'author'    => $author,
            'publisher' => get_post_meta($post_id, 'wpbc_publisher', true),
            'year'      => get_post_meta($post_id, 'wpbc_year', true),
            'isbn'      => get_post_meta($post_id, 'wpbc_isbn', true),
            'pages'     => get_post_meta($post_id, 'wpbc_pages', true),
            'language'  => get_post_meta($post_id, 'wpbc_language', true),
            'shop_link' => get_post_meta($post_id, 'wpbc_shop_link', true),
        );
    }
}

// Initialize
WPBC_Meta_Boxes::get_instance();
