<?php
/**
 * ISBN Lookup - fetch book data from external databases (Google Books, Open Library)
 *
 * @package MM_Book_Catalog
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WPBC_ISBN_Lookup
 *
 * Provides admin-side AJAX endpoints to fetch book metadata by ISBN from
 * Google Books and Open Library, and to import the book cover as the
 * featured image. Results are cached in a transient for 12 hours.
 */
class WPBC_ISBN_Lookup {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Allowed hosts for cover image sideloading
     */
    private static $allowed_cover_hosts = array(
        'books.google.com',
        'books.googleusercontent.com',
        'covers.openlibrary.org',
    );

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
        add_action('wp_ajax_wpbc_isbn_lookup', array($this, 'ajax_isbn_lookup'));
        add_action('wp_ajax_wpbc_import_cover', array($this, 'ajax_import_cover'));
    }

    /**
     * AJAX: look up book data by ISBN
     */
    public function ajax_isbn_lookup() {
        check_ajax_referer('wpbc_admin_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => __('You are not allowed to do this.', 'mm-book-catalog')), 403);
        }

        $raw_isbn = isset($_POST['isbn']) ? sanitize_text_field(wp_unslash($_POST['isbn'])) : '';
        $isbn     = self::normalize_isbn($raw_isbn);

        if (!$isbn) {
            wp_send_json_error(array('message' => __('Invalid ISBN. Please enter a 10 or 13 digit ISBN.', 'mm-book-catalog')), 400);
        }

        $data = self::lookup($isbn);

        if (empty($data) || empty($data['found'])) {
            wp_send_json_error(array('message' => __('No book found for this ISBN. Try the other data source or check the number.', 'mm-book-catalog')), 404);
        }

        wp_send_json_success($data);
    }

    /**
     * AJAX: import a cover image from an allowed host and set it as featured image
     */
    public function ajax_import_cover() {
        check_ajax_referer('wpbc_admin_nonce', 'nonce');

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $url     = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';

        // The cover is stored in the Media Library, so uploading must be allowed
        // too: 'edit_post' alone is satisfied by a Contributor on their own draft.
        if (!$post_id || 'book' !== get_post_type($post_id)
            || !current_user_can('edit_post', $post_id)
            || !current_user_can('upload_files')) {
            wp_send_json_error(array('message' => __('You are not allowed to do this.', 'mm-book-catalog')), 403);
        }

        if (!self::is_allowed_cover_url($url)) {
            wp_send_json_error(array('message' => __('Cover URL is not from an allowed source.', 'mm-book-catalog')), 400);
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = self::sideload_cover($url, $post_id);

        if (is_wp_error($attachment_id)) {
            wp_send_json_error(array('message' => $attachment_id->get_error_message()), 500);
        }

        set_post_thumbnail($post_id, $attachment_id);

        wp_send_json_success(array(
            'attachment_id' => $attachment_id,
            'thumbnail_url' => wp_get_attachment_image_url($attachment_id, 'medium'),
            'message'       => __('Cover imported and set as featured image.', 'mm-book-catalog'),
        ));
    }

    /**
     * Whether a cover URL points at one of the allowed cover hosts
     *
     * @param string $url Cover URL.
     * @return bool
     */
    public static function is_allowed_cover_url($url) {
        if (empty($url)) {
            return false;
        }

        $host = wp_parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || '' === $host) {
            return false;
        }

        /**
         * Filters the hosts a book cover may be downloaded from.
         *
         * @since 1.2.0
         *
         * @param string[] $hosts Allowed host names.
         */
        $allowed = apply_filters('wpbc_allowed_cover_hosts', self::$allowed_cover_hosts);
        $allowed = array_map('strtolower', array_map('strval', (array) $allowed));

        return in_array(strtolower($host), $allowed, true);
    }

    /**
     * Download a cover image and attach it to the book
     *
     * media_sideload_image() cannot be used here: it requires the URL itself to
     * end with an image extension, while Google Books serves its covers from
     * extension-less endpoints such as /books/content?id=…. The file type is
     * therefore taken from the downloaded bytes instead of from the URL.
     *
     * @param string $url     Cover URL (already validated against the host allowlist).
     * @param int    $post_id Book post ID.
     * @return int|WP_Error Attachment ID or error.
     */
    private static function sideload_cover($url, $post_id) {
        // download_url() uses wp_safe_remote_get(), which rejects private and
        // loopback addresses, including on redirects.
        $tmp_file = download_url($url, 30);

        if (is_wp_error($tmp_file)) {
            return $tmp_file;
        }

        $image_size = wp_getimagesize($tmp_file);
        $mime       = (is_array($image_size) && !empty($image_size['mime'])) ? $image_size['mime'] : '';

        $extensions = array(
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        );

        if (!isset($extensions[$mime])) {
            wp_delete_file($tmp_file);
            return new WP_Error(
                'wpbc_invalid_cover',
                __('The downloaded file is not a supported image.', 'mm-book-catalog')
            );
        }

        $isbn      = get_post_meta($post_id, 'wpbc_isbn', true);
        $base_name = $isbn ? $isbn : 'book-' . $post_id;

        $file_array = array(
            'name'     => sanitize_file_name($base_name . '-cover.' . $extensions[$mime]),
            'tmp_name' => $tmp_file,
        );

        $attachment_id = media_handle_sideload($file_array, $post_id, get_the_title($post_id));

        if (is_wp_error($attachment_id)) {
            wp_delete_file($tmp_file);
        }

        return $attachment_id;
    }

    /**
     * Normalize and validate an ISBN (10 or 13 characters)
     *
     * @param string $raw Raw user input.
     * @return string|false Normalized ISBN or false when invalid.
     */
    public static function normalize_isbn($raw) {
        $isbn = strtoupper(preg_replace('/[^0-9Xx]/', '', (string) $raw));

        if (10 === strlen($isbn)) {
            // 'X' allowed only as the last check character of an ISBN-10.
            if (preg_match('/^[0-9]{9}[0-9X]$/', $isbn)) {
                return $isbn;
            }
            return false;
        }

        if (13 === strlen($isbn) && ctype_digit($isbn)) {
            return $isbn;
        }

        return false;
    }

    /**
     * Look up an ISBN against the configured data sources (with caching)
     *
     * @param string $isbn Normalized ISBN.
     * @return array Normalized book data.
     */
    public static function lookup($isbn) {
        $source  = WPBC_Settings::get_setting('isbn_source', 'both');
        $has_key = WPBC_Settings::get_setting('google_api_key', '') !== '' ? '1' : '0';

        // Include the data source (and API-key presence) in the cache key so
        // changing the source setting does not serve results from another source.
        $cache_key = 'wpbc_isbn_' . md5($isbn . '|' . $source . '|' . $has_key);
        $cached    = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $google       = in_array($source, array('both', 'google'), true) ? self::fetch_google_books($isbn) : null;
        $open_library = in_array($source, array('both', 'openlibrary'), true) ? self::fetch_open_library($isbn) : null;

        $data = self::merge_results($google, $open_library);
        $data['isbn'] = $isbn;

        /**
         * Filters the normalized book record returned by an ISBN lookup.
         *
         * @since 1.2.0
         *
         * @param array  $data Normalized record.
         * @param string $isbn Normalized ISBN that was looked up.
         */
        $data = apply_filters('wpbc_lookup_data', $data, $isbn);

        // Cache also negative results, but for a shorter time.
        $ttl = !empty($data['found']) ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS;
        set_transient($cache_key, $data, $ttl);

        return $data;
    }

    /**
     * Merge results from the two sources into one normalized record
     *
     * Google Books usually has richer descriptions; Open Library usually has
     * better cover scans. Each field falls back to the other source.
     *
     * @param array|null $google       Normalized Google Books record.
     * @param array|null $open_library Normalized Open Library record.
     * @return array
     */
    public static function merge_results($google, $open_library) {
        $sources = array();
        if (!empty($google)) {
            $sources[] = 'Google Books';
        }
        if (!empty($open_library)) {
            $sources[] = 'Open Library';
        }

        $pick = function ($key) use ($google, $open_library) {
            if (!empty($google[$key])) {
                return $google[$key];
            }
            if (!empty($open_library[$key])) {
                return $open_library[$key];
            }
            return '';
        };

        // Prefer the Open Library cover (higher resolution scans), fall back to Google.
        // Only covers served by an allowed host are kept, so the URL handed back
        // to the browser is always one the import endpoint would accept.
        $cover = '';
        foreach (array($open_library, $google) as $candidate) {
            if (!empty($candidate['cover']) && self::is_allowed_cover_url($candidate['cover'])) {
                $cover = $candidate['cover'];
                break;
            }
        }

        return array(
            'found'       => !empty($sources),
            'title'       => $pick('title'),
            'authors'     => $pick('authors'),
            'publisher'   => $pick('publisher'),
            'year'        => $pick('year'),
            'description' => $pick('description'),
            'pages'       => $pick('pages'),
            'language'    => $pick('language'),
            'cover'       => $cover,
            'sources'     => $sources,
        );
    }

    /**
     * Fetch book data from the Google Books API
     *
     * @param string $isbn Normalized ISBN.
     * @return array|null Normalized record or null when nothing was found.
     */
    public static function fetch_google_books($isbn) {
        $url = add_query_arg(
            array('q' => rawurlencode('isbn:' . $isbn)),
            'https://www.googleapis.com/books/v1/volumes'
        );

        $api_key = WPBC_Settings::get_setting('google_api_key', '');
        if (!empty($api_key)) {
            $url = add_query_arg('key', rawurlencode($api_key), $url);
        }

        $body = self::remote_get_json($url);
        if (empty($body['items'][0]['volumeInfo'])) {
            return null;
        }

        $info = $body['items'][0]['volumeInfo'];

        return self::parse_google_volume($info);
    }

    /**
     * Turn an HTML description from a remote catalog into plain text
     *
     * Line breaks and paragraph ends become newlines first, so that stripping
     * the tags does not run the surrounding sentences together.
     *
     * @param string $html Raw description.
     * @return string
     */
    public static function normalize_description($html) {
        $text = preg_replace('#<br\s*/?>#i', "\n", (string) $html);
        $text = preg_replace('#</?(p|div|li|ul|ol|h[1-6])(\s[^>]*)?>#i', "\n\n", $text);
        $text = sanitize_textarea_field($text);
        $text = preg_replace("#\n{3,}#", "\n\n", $text);

        // sanitize_textarea_field() strips the tags but leaves the entities, so
        // they are decoded once here and never escaped twice downstream.
        return trim(wp_specialchars_decode($text, ENT_QUOTES));
    }

    /**
     * Normalize a Google Books volumeInfo structure
     *
     * @param array $info volumeInfo array.
     * @return array
     */
    public static function parse_google_volume($info) {
        $year = '';
        if (!empty($info['publishedDate']) && preg_match('/\d{4}/', $info['publishedDate'], $m)) {
            $year = $m[0];
        }

        $cover = '';
        if (!empty($info['imageLinks'])) {
            // Prefer larger sizes when available.
            foreach (array('extraLarge', 'large', 'medium', 'small', 'thumbnail', 'smallThumbnail') as $size) {
                if (!empty($info['imageLinks'][$size])) {
                    $cover = esc_url_raw(set_url_scheme($info['imageLinks'][$size], 'https'));
                    break;
                }
            }
        }

        return array(
            'title'       => isset($info['title']) ? sanitize_text_field($info['title']) : '',
            'authors'     => !empty($info['authors']) && is_array($info['authors']) ? sanitize_text_field(implode(', ', $info['authors'])) : '',
            'publisher'   => isset($info['publisher']) ? sanitize_text_field($info['publisher']) : '',
            'year'        => $year,
            'description' => isset($info['description']) ? self::normalize_description($info['description']) : '',
            'pages'       => !empty($info['pageCount']) ? absint($info['pageCount']) : '',
            'language'    => isset($info['language']) ? sanitize_text_field($info['language']) : '',
            'cover'       => $cover,
        );
    }

    /**
     * Fetch book data from the Open Library Books API
     *
     * @param string $isbn Normalized ISBN.
     * @return array|null Normalized record or null when nothing was found.
     */
    public static function fetch_open_library($isbn) {
        $url = add_query_arg(
            array(
                'bibkeys' => rawurlencode('ISBN:' . $isbn),
                'format'  => 'json',
                'jscmd'   => 'data',
            ),
            'https://openlibrary.org/api/books'
        );

        $body = self::remote_get_json($url);
        if (empty($body['ISBN:' . $isbn])) {
            return null;
        }

        return self::parse_open_library_record($body['ISBN:' . $isbn]);
    }

    /**
     * Normalize an Open Library "data" record
     *
     * @param array $record Record as returned for one bibkey.
     * @return array
     */
    public static function parse_open_library_record($record) {
        $authors = '';
        if (!empty($record['authors']) && is_array($record['authors'])) {
            $names = array();
            foreach ($record['authors'] as $author) {
                if (!empty($author['name'])) {
                    $names[] = $author['name'];
                }
            }
            $authors = sanitize_text_field(implode(', ', $names));
        }

        $publisher = '';
        if (!empty($record['publishers'][0]['name'])) {
            $publisher = sanitize_text_field($record['publishers'][0]['name']);
        }

        $year = '';
        if (!empty($record['publish_date']) && preg_match('/\d{4}/', $record['publish_date'], $m)) {
            $year = $m[0];
        }

        $cover = '';
        if (!empty($record['cover'])) {
            foreach (array('large', 'medium', 'small') as $size) {
                if (!empty($record['cover'][$size])) {
                    $cover = esc_url_raw(set_url_scheme($record['cover'][$size], 'https'));
                    break;
                }
            }
        }

        // The "data" endpoint may include excerpts; use the first one as a description fallback.
        $description = '';
        if (!empty($record['excerpts'][0]['text'])) {
            $description = self::normalize_description($record['excerpts'][0]['text']);
        }

        return array(
            'title'       => isset($record['title']) ? sanitize_text_field($record['title']) : '',
            'authors'     => $authors,
            'publisher'   => $publisher,
            'year'        => $year,
            'description' => $description,
            'pages'       => !empty($record['number_of_pages']) ? absint($record['number_of_pages']) : '',
            'language'    => '',
            'cover'       => $cover,
        );
    }

    /**
     * GET a URL and decode the JSON body
     *
     * @param string $url Request URL.
     * @return array|null Decoded body or null on failure.
     */
    private static function remote_get_json($url) {
        // The User-Agent deliberately carries no site URL: nothing that identifies
        // this site is sent to the external services (see readme.txt).
        // The timeout is kept short because with the "both" data source two of
        // these run back to back inside one admin request.
        $response = wp_remote_get(
            $url,
            array(
                'timeout'    => 8,
                'user-agent' => 'MM-Book-Catalog/' . WPBC_VERSION,
            )
        );

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return is_array($body) ? $body : null;
    }
}

// Initialize
WPBC_ISBN_Lookup::get_instance();
