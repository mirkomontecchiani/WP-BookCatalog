<?php
/**
 * Meta Boxes for Book custom fields
 *
 * @package WP_Book_Catalog
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
        $this->setup_fields();
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post_book', array($this, 'save_meta_boxes'), 10, 2);
    }

    /**
     * Setup meta fields
     */
    private function setup_fields() {
        $this->meta_fields = array(
            'wpbc_author' => array(
                'label'       => __('Author', 'wp-book-catalog'),
                'type'        => 'text',
                'placeholder' => __('Enter book author', 'wp-book-catalog'),
            ),
            'wpbc_publisher' => array(
                'label'       => __('Publisher', 'wp-book-catalog'),
                'type'        => 'text',
                'placeholder' => __('Enter publisher name', 'wp-book-catalog'),
            ),
            'wpbc_year' => array(
                'label'       => __('Publication Year', 'wp-book-catalog'),
                'type'        => 'number',
                'placeholder' => __('Enter publication year', 'wp-book-catalog'),
            ),
            'wpbc_isbn' => array(
                'label'       => __('ISBN', 'wp-book-catalog'),
                'type'        => 'text',
                'placeholder' => __('Enter ISBN', 'wp-book-catalog'),
            ),
            'wpbc_shop_link' => array(
                'label'       => __('Shop Link', 'wp-book-catalog'),
                'type'        => 'url',
                'placeholder' => __('Enter shop URL', 'wp-book-catalog'),
            ),
        );
    }

    /**
     * Add meta boxes
     */
    public function add_meta_boxes() {
        add_meta_box(
            'wpbc_book_details',
            __('Book Details', 'wp-book-catalog'),
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
        $settings = get_option('wpbc_settings', array());
        $default_author = isset($settings['default_author']) ? $settings['default_author'] : '';

        echo '<div class="wpbc-meta-box-wrapper">';

        foreach ($this->meta_fields as $key => $field) {
            $value = get_post_meta($post->ID, $key, true);

            // For author field, show default if set and field is empty
            if ('wpbc_author' === $key && empty($value) && !empty($default_author)) {
                $value = $default_author;
            }

            echo '<div class="wpbc-field-wrapper">';
            echo '<label for="' . esc_attr($key) . '">' . esc_html($field['label']) . '</label>';

            switch ($field['type']) {
                case 'url':
                    echo '<input type="url" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_url($value) . '" placeholder="' . esc_attr($field['placeholder']) . '" class="widefat" />';
                    break;

                case 'number':
                    echo '<input type="number" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($field['placeholder']) . '" class="widefat" min="1000" max="2100" />';
                    break;

                case 'textarea':
                    echo '<textarea id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" placeholder="' . esc_attr($field['placeholder']) . '" class="widefat" rows="4">' . esc_textarea($value) . '</textarea>';
                    break;

                default:
                    echo '<input type="text" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($field['placeholder']) . '" class="widefat" />';
                    break;
            }

            // Show note for author field if default is set
            if ('wpbc_author' === $key && !empty($default_author)) {
                echo '<p class="description">' . sprintf(
                    /* translators: %s: default author name */
                    __('Default author from settings: %s', 'wp-book-catalog'),
                    esc_html($default_author)
                ) . '</p>';
            }

            echo '</div>';
        }

        echo '</div>';

        // Add inline styles for meta box
        echo '<style>
            .wpbc-meta-box-wrapper {
                display: grid;
                gap: 15px;
            }
            .wpbc-field-wrapper {
                display: flex;
                flex-direction: column;
                gap: 5px;
            }
            .wpbc-field-wrapper label {
                font-weight: 600;
            }
            .wpbc-field-wrapper .description {
                color: #666;
                font-style: italic;
                margin: 5px 0 0;
            }
        </style>';
    }

    /**
     * Save meta boxes
     */
    public function save_meta_boxes($post_id, $post) {
        // Verify nonce
        if (!isset($_POST['wpbc_meta_nonce']) || !wp_verify_nonce($_POST['wpbc_meta_nonce'], 'wpbc_save_meta_boxes')) {
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

        // Save each field
        foreach ($this->meta_fields as $key => $field) {
            if (isset($_POST[$key])) {
                $value = $_POST[$key];

                // Sanitize based on field type
                switch ($field['type']) {
                    case 'url':
                        $value = esc_url_raw($value);
                        break;

                    case 'number':
                        $value = absint($value);
                        break;

                    case 'textarea':
                        $value = sanitize_textarea_field($value);
                        break;

                    default:
                        $value = sanitize_text_field($value);
                        break;
                }

                update_post_meta($post_id, $key, $value);
            }
        }
    }

    /**
     * Get book meta data
     */
    public static function get_book_meta($post_id) {
        $settings = get_option('wpbc_settings', array());
        $default_author = isset($settings['default_author']) ? $settings['default_author'] : '';

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
            'shop_link' => get_post_meta($post_id, 'wpbc_shop_link', true),
        );
    }
}

// Initialize
WPBC_Meta_Boxes::get_instance();
