<?php
/**
 * Plugin Settings Page
 *
 * @package WP_Book_Catalog
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WPBC_Settings
 */
class WPBC_Settings {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Settings option name
     */
    private $option_name = 'wpbc_settings';

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
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    /**
     * Add settings page to admin menu
     */
    public function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=book',
            __('Book Catalog Settings', 'wp-book-catalog'),
            __('Settings', 'wp-book-catalog'),
            'manage_options',
            'wpbc-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'wpbc_settings_group',
            $this->option_name,
            array($this, 'sanitize_settings')
        );

        // General Settings Section
        add_settings_section(
            'wpbc_general_section',
            __('General Settings', 'wp-book-catalog'),
            array($this, 'render_general_section'),
            'wpbc-settings'
        );

        // Default Author Field
        add_settings_field(
            'default_author',
            __('Default Author', 'wp-book-catalog'),
            array($this, 'render_default_author_field'),
            'wpbc-settings',
            'wpbc_general_section'
        );

        // Display Settings Section
        add_settings_section(
            'wpbc_display_section',
            __('Display Settings', 'wp-book-catalog'),
            array($this, 'render_display_section'),
            'wpbc-settings'
        );

        // Columns Field
        add_settings_field(
            'columns',
            __('Number of Columns', 'wp-book-catalog'),
            array($this, 'render_columns_field'),
            'wpbc-settings',
            'wpbc_display_section'
        );
    }

    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();

        // Sanitize default author
        if (isset($input['default_author'])) {
            $sanitized['default_author'] = sanitize_text_field($input['default_author']);
        }

        // Sanitize columns (1-5)
        if (isset($input['columns'])) {
            $columns = absint($input['columns']);
            $sanitized['columns'] = max(1, min(5, $columns));
        }

        return $sanitized;
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Show success message
        if (isset($_GET['settings-updated'])) {
            add_settings_error(
                'wpbc_messages',
                'wpbc_message',
                __('Settings saved successfully.', 'wp-book-catalog'),
                'updated'
            );
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php settings_errors('wpbc_messages'); ?>

            <form action="options.php" method="post">
                <?php
                settings_fields('wpbc_settings_group');
                do_settings_sections('wpbc-settings');
                submit_button(__('Save Settings', 'wp-book-catalog'));
                ?>
            </form>

            <hr>

            <h2><?php _e('Shortcode Usage', 'wp-book-catalog'); ?></h2>
            <div class="wpbc-shortcode-info">
                <h3><?php _e('Display All Books', 'wp-book-catalog'); ?></h3>
                <code>[books]</code>
                <p><?php _e('Displays all books in the catalog.', 'wp-book-catalog'); ?></p>

                <h3><?php _e('Display Limited Books', 'wp-book-catalog'); ?></h3>
                <code>[books hitem="3"]</code>
                <p><?php _e('Displays 3 books with a "Show All" button at the bottom. Replace 3 with any number.', 'wp-book-catalog'); ?></p>

                <h3><?php _e('Available Attributes', 'wp-book-catalog'); ?></h3>
                <ul>
                    <li><code>hitem</code> - <?php _e('Number of books to display (shows "Show All" button)', 'wp-book-catalog'); ?></li>
                    <li><code>columns</code> - <?php _e('Override default columns (1-5)', 'wp-book-catalog'); ?></li>
                    <li><code>orderby</code> - <?php _e('Order by: date, title, rand (default: date)', 'wp-book-catalog'); ?></li>
                    <li><code>order</code> - <?php _e('Order direction: ASC, DESC (default: DESC)', 'wp-book-catalog'); ?></li>
                </ul>

                <h3><?php _e('Example with All Attributes', 'wp-book-catalog'); ?></h3>
                <code>[books hitem="6" columns="3" orderby="title" order="ASC"]</code>
            </div>

            <style>
                .wpbc-shortcode-info {
                    background: #fff;
                    padding: 20px;
                    border: 1px solid #ccd0d4;
                    border-radius: 4px;
                    max-width: 600px;
                }
                .wpbc-shortcode-info code {
                    display: inline-block;
                    background: #f0f0f1;
                    padding: 5px 10px;
                    margin: 5px 0;
                    border-radius: 3px;
                }
                .wpbc-shortcode-info ul {
                    list-style: disc;
                    margin-left: 20px;
                }
                .wpbc-shortcode-info li {
                    margin: 5px 0;
                }
            </style>
        </div>
        <?php
    }

    /**
     * Render general section description
     */
    public function render_general_section() {
        echo '<p>' . __('Configure the default settings for all books.', 'wp-book-catalog') . '</p>';
    }

    /**
     * Render display section description
     */
    public function render_display_section() {
        echo '<p>' . __('Configure how books are displayed on the frontend.', 'wp-book-catalog') . '</p>';
    }

    /**
     * Render default author field
     */
    public function render_default_author_field() {
        $options = get_option($this->option_name, array());
        $value = isset($options['default_author']) ? $options['default_author'] : '';

        echo '<input type="text" id="wpbc_default_author" name="' . $this->option_name . '[default_author]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('If set, this author will be used for all books that don\'t have an author specified.', 'wp-book-catalog') . '</p>';
    }

    /**
     * Render columns field
     */
    public function render_columns_field() {
        $options = get_option($this->option_name, array());
        $value = isset($options['columns']) ? absint($options['columns']) : 3;

        echo '<select id="wpbc_columns" name="' . $this->option_name . '[columns]">';
        for ($i = 1; $i <= 5; $i++) {
            $selected = selected($value, $i, false);
            echo '<option value="' . $i . '"' . $selected . '>' . $i . ' ' . _n('column', 'columns', $i, 'wp-book-catalog') . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . __('Number of columns to display books in. The layout will be responsive.', 'wp-book-catalog') . '</p>';
    }

    /**
     * Get setting value
     */
    public static function get_setting($key, $default = '') {
        $options = get_option('wpbc_settings', array());
        return isset($options[$key]) ? $options[$key] : $default;
    }
}

// Initialize
WPBC_Settings::get_instance();
