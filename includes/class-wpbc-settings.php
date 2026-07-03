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

        // Author Gender Field
        add_settings_field(
            'author_gender',
            __('Author Gender', 'wp-book-catalog'),
            array($this, 'render_author_gender_field'),
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

        // ISBN Lookup Section
        add_settings_section(
            'wpbc_isbn_section',
            __('ISBN Autofill', 'wp-book-catalog'),
            array($this, 'render_isbn_section'),
            'wpbc-settings'
        );

        // Data Source Field
        add_settings_field(
            'isbn_source',
            __('Data Source', 'wp-book-catalog'),
            array($this, 'render_isbn_source_field'),
            'wpbc-settings',
            'wpbc_isbn_section'
        );

        // Google API Key Field
        add_settings_field(
            'google_api_key',
            __('Google Books API Key', 'wp-book-catalog'),
            array($this, 'render_google_api_key_field'),
            'wpbc-settings',
            'wpbc_isbn_section'
        );

        // Advanced Section
        add_settings_section(
            'wpbc_advanced_section',
            __('Advanced', 'wp-book-catalog'),
            array($this, 'render_advanced_section'),
            'wpbc-settings'
        );

        // Delete data on uninstall
        add_settings_field(
            'delete_data_on_uninstall',
            __('Delete Data on Uninstall', 'wp-book-catalog'),
            array($this, 'render_delete_data_field'),
            'wpbc-settings',
            'wpbc_advanced_section'
        );
    }

    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        // Start from saved values so a partial submit never wipes other keys.
        $sanitized = get_option($this->option_name, array());
        if (!is_array($sanitized)) {
            $sanitized = array();
        }
        if (!is_array($input)) {
            $input = array();
        }

        // Sanitize default author
        if (isset($input['default_author'])) {
            $sanitized['default_author'] = sanitize_text_field($input['default_author']);
        }

        // Sanitize author gender
        if (isset($input['author_gender'])) {
            $sanitized['author_gender'] = in_array($input['author_gender'], array('male', 'female'), true) ? $input['author_gender'] : 'male';
        }

        // Sanitize columns (1-5)
        if (isset($input['columns'])) {
            $columns = absint($input['columns']);
            $sanitized['columns'] = max(1, min(5, $columns));
        }

        // Sanitize ISBN data source
        if (isset($input['isbn_source'])) {
            $sanitized['isbn_source'] = in_array($input['isbn_source'], array('both', 'google', 'openlibrary'), true) ? $input['isbn_source'] : 'both';
        }

        // Sanitize Google API key
        if (isset($input['google_api_key'])) {
            $sanitized['google_api_key'] = sanitize_text_field($input['google_api_key']);
        }

        // Checkboxes are absent from the POST when unchecked, so always set them
        // explicitly when the settings form is submitted.
        $sanitized['delete_data_on_uninstall'] = !empty($input['delete_data_on_uninstall']) ? 1 : 0;

        return $sanitized;
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // This page is not under options-general.php, so show the saved notice manually.
        if (isset($_GET['settings-updated'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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

            <h2><?php esc_html_e('Shortcode Usage', 'wp-book-catalog'); ?></h2>
            <div class="wpbc-shortcode-info">
                <h3><?php esc_html_e('Display All Books', 'wp-book-catalog'); ?></h3>
                <code>[books]</code>
                <p><?php esc_html_e('Displays all books in the catalog.', 'wp-book-catalog'); ?></p>

                <h3><?php esc_html_e('Display Limited Books', 'wp-book-catalog'); ?></h3>
                <code>[books hitem="3"]</code>
                <p><?php esc_html_e('Displays 3 books with a "Show All" button at the bottom. Replace 3 with any number.', 'wp-book-catalog'); ?></p>

                <h3><?php esc_html_e('Available Attributes', 'wp-book-catalog'); ?></h3>
                <ul>
                    <li><code>hitem</code> - <?php esc_html_e('Number of books to display (shows "Show All" button)', 'wp-book-catalog'); ?></li>
                    <li><code>columns</code> - <?php esc_html_e('Override default columns (1-5)', 'wp-book-catalog'); ?></li>
                    <li><code>orderby</code> - <?php esc_html_e('Order by: date, title, year, author, rand (default: date)', 'wp-book-catalog'); ?></li>
                    <li><code>order</code> - <?php esc_html_e('Order direction: ASC, DESC (default: DESC)', 'wp-book-catalog'); ?></li>
                    <li><code>genre</code> - <?php esc_html_e('Filter by genre slug(s), comma separated', 'wp-book-catalog'); ?></li>
                </ul>

                <h3><?php esc_html_e('Example with All Attributes', 'wp-book-catalog'); ?></h3>
                <code>[books hitem="6" columns="3" orderby="title" order="ASC" genre="fantasy,thriller"]</code>
            </div>
        </div>
        <?php
    }

    /**
     * Render general section description
     */
    public function render_general_section() {
        echo '<p>' . esc_html__('Configure the default settings for all books.', 'wp-book-catalog') . '</p>';
    }

    /**
     * Render display section description
     */
    public function render_display_section() {
        echo '<p>' . esc_html__('Configure how books are displayed on the frontend.', 'wp-book-catalog') . '</p>';
    }

    /**
     * Render ISBN section description
     */
    public function render_isbn_section() {
        echo '<p>' . esc_html__('Configure the external book databases used to autofill book details from an ISBN.', 'wp-book-catalog') . '</p>';
    }

    /**
     * Render advanced section description
     */
    public function render_advanced_section() {
        echo '<p>' . esc_html__('Advanced options.', 'wp-book-catalog') . '</p>';
    }

    /**
     * Render default author field
     */
    public function render_default_author_field() {
        $value = self::get_setting('default_author', '');

        echo '<input type="text" id="wpbc_default_author" name="' . esc_attr($this->option_name) . '[default_author]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('If set, this author will be used for all books that don\'t have an author specified.', 'wp-book-catalog') . '</p>';
    }

    /**
     * Render author gender field
     */
    public function render_author_gender_field() {
        $value = self::get_setting('author_gender', 'male');

        echo '<select id="wpbc_author_gender" name="' . esc_attr($this->option_name) . '[author_gender]">';
        echo '<option value="male"' . selected($value, 'male', false) . '>' . esc_html__('Male (Author)', 'wp-book-catalog') . '</option>';
        echo '<option value="female"' . selected($value, 'female', false) . '>' . esc_html__('Female (Authoress)', 'wp-book-catalog') . '</option>';
        echo '</select>';
        echo '<p class="description">' . esc_html__('Select the gender to display the correct label (Author/Authoress).', 'wp-book-catalog') . '</p>';
    }

    /**
     * Render columns field
     */
    public function render_columns_field() {
        $value = absint(self::get_setting('columns', 3));

        echo '<select id="wpbc_columns" name="' . esc_attr($this->option_name) . '[columns]">';
        for ($i = 1; $i <= 5; $i++) {
            $selected = selected($value, $i, false);
            /* translators: %d: number of columns */
            echo '<option value="' . esc_attr($i) . '"' . $selected . '>' . esc_html(sprintf(_n('%d column', '%d columns', $i, 'wp-book-catalog'), $i)) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Number of columns to display books in. The layout will be responsive.', 'wp-book-catalog') . '</p>';
    }

    /**
     * Render ISBN data source field
     */
    public function render_isbn_source_field() {
        $value = self::get_setting('isbn_source', 'both');

        echo '<select id="wpbc_isbn_source" name="' . esc_attr($this->option_name) . '[isbn_source]">';
        echo '<option value="both"' . selected($value, 'both', false) . '>' . esc_html__('Google Books + Open Library (recommended)', 'wp-book-catalog') . '</option>';
        echo '<option value="google"' . selected($value, 'google', false) . '>' . esc_html__('Google Books only', 'wp-book-catalog') . '</option>';
        echo '<option value="openlibrary"' . selected($value, 'openlibrary', false) . '>' . esc_html__('Open Library only', 'wp-book-catalog') . '</option>';
        echo '</select>';
        echo '<p class="description">' . esc_html__('Which database to query when autofilling book data from an ISBN. Using both merges the best data from each source.', 'wp-book-catalog') . '</p>';
    }

    /**
     * Render Google API key field
     */
    public function render_google_api_key_field() {
        $value = self::get_setting('google_api_key', '');

        echo '<input type="text" id="wpbc_google_api_key" name="' . esc_attr($this->option_name) . '[google_api_key]" value="' . esc_attr($value) . '" class="regular-text" autocomplete="off" />';
        echo '<p class="description">' . esc_html__('Optional. Google Books works without a key, but a key raises the request quota. Create one in the Google Cloud Console.', 'wp-book-catalog') . '</p>';
    }

    /**
     * Render delete data on uninstall field
     */
    public function render_delete_data_field() {
        $value = self::get_setting('delete_data_on_uninstall', 0);

        echo '<label for="wpbc_delete_data">';
        echo '<input type="checkbox" id="wpbc_delete_data" name="' . esc_attr($this->option_name) . '[delete_data_on_uninstall]" value="1"' . checked($value, 1, false) . ' /> ';
        echo esc_html__('Permanently delete all books, genres and settings when the plugin is uninstalled.', 'wp-book-catalog');
        echo '</label>';
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
