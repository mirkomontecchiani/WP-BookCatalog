<?php
/**
 * ISBN Lookup - fetch book data from external databases (Google Books, Open Library)
 *
 * @package Montecchiani_Book_Catalog
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MBCat_ISBN_Lookup
 *
 * Provides admin-side AJAX endpoints to fetch book metadata by ISBN from
 * Google Books and Open Library, and to import the book cover as the
 * featured image. Results are cached in a transient for 12 hours.
 *
 * Every request to the two catalogs is made by this server, never by the
 * browser. The editor only receives data that has already been downloaded
 * and checked here: the cover preview travels inline as a data URI, and the
 * cover import is resolved on the server from the cached lookup of the ISBN.
 * No remote URL is ever loaded by, or accepted from, the browser.
 */
class MBCat_ISBN_Lookup {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Largest cover preview embedded in a lookup response, in bytes
     *
     * Previews are the small thumbnails of the catalogs (a few kilobytes).
     * Anything bigger is simply not previewed; the cover can still be imported.
     */
    const PREVIEW_MAX_BYTES = 131072;

    /**
     * Largest cover file imported into the Media Library, in bytes
     */
    const COVER_MAX_BYTES = 10485760;

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
        add_action('wp_ajax_mbcat_isbn_lookup', array($this, 'ajax_isbn_lookup'));
        add_action('wp_ajax_mbcat_import_cover', array($this, 'ajax_import_cover'));
    }

    /**
     * AJAX: look up book data by ISBN
     */
    public function ajax_isbn_lookup() {
        check_ajax_referer('mbcat_admin_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => __('You are not allowed to do this.', 'montecchiani-book-catalog')), 403);
        }

        $raw_isbn = isset($_POST['isbn']) ? sanitize_text_field(wp_unslash($_POST['isbn'])) : '';
        $isbn     = self::normalize_isbn($raw_isbn);

        if (!$isbn) {
            wp_send_json_error(array('message' => __('Invalid ISBN. Please enter a 10 or 13 digit ISBN.', 'montecchiani-book-catalog')), 400);
        }

        $data = self::lookup($isbn);

        if (empty($data) || empty($data['found'])) {
            wp_send_json_error(array('message' => __('No book found for this ISBN. Try the other data source or check the number.', 'montecchiani-book-catalog')), 404);
        }

        $response                  = self::prepare_for_editor($data);
        $response['cover_preview'] = self::get_cover_preview($data);

        wp_send_json_success($response);
    }

    /**
     * Reduce a lookup record to what the editor needs
     *
     * The remote cover URLs stay on the server. The browser gets a flag saying
     * whether a cover can be imported, and the preview bytes inline (added by
     * the caller, see get_cover_preview()).
     *
     * @param array $data Lookup record, as returned by lookup().
     * @return array
     */
    public static function prepare_for_editor($data) {
        $data['has_cover']     = !empty($data['cover']);
        $data['cover_preview'] = '';

        unset($data['cover'], $data['cover_thumb']);

        return $data;
    }

    /**
     * AJAX: import the cover found for an ISBN and set it as featured image
     */
    public function ajax_import_cover() {
        check_ajax_referer('mbcat_admin_nonce', 'nonce');

        $post_id  = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $raw_isbn = isset($_POST['isbn']) ? sanitize_text_field(wp_unslash($_POST['isbn'])) : '';
        $isbn     = self::normalize_isbn($raw_isbn);

        // The cover is stored in the Media Library, so uploading must be allowed
        // too: 'edit_post' alone is satisfied by a Contributor on their own draft.
        if (!$post_id || 'mbcat_book' !== get_post_type($post_id)
            || !current_user_can('edit_post', $post_id)
            || !current_user_can('upload_files')) {
            wp_send_json_error(array('message' => __('You are not allowed to do this.', 'montecchiani-book-catalog')), 403);
        }

        if (!$isbn) {
            wp_send_json_error(array('message' => __('Invalid ISBN. Please enter a 10 or 13 digit ISBN.', 'montecchiani-book-catalog')), 400);
        }

        // The cover URL comes from the (cached) lookup, never from the request.
        $data = self::lookup($isbn);

        if (empty($data['found'])) {
            // The cached record has expired and the catalogs did not answer
            // this time. Forget the failed attempt at once, so that it does
            // not block "Autofill from ISBN" for the next hour as well.
            delete_transient(self::cache_key($isbn));
            wp_send_json_error(array('message' => __('The book data could not be retrieved again. Please run the ISBN autofill once more.', 'montecchiani-book-catalog')), 404);
        }

        if (empty($data['cover'])) {
            wp_send_json_error(array('message' => __('No cover is available for this ISBN.', 'montecchiani-book-catalog')), 404);
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = self::sideload_cover($data['cover'], $post_id, $isbn);

        if (is_wp_error($attachment_id)) {
            wp_send_json_error(array('message' => $attachment_id->get_error_message()), 500);
        }

        set_post_thumbnail($post_id, $attachment_id);

        wp_send_json_success(array(
            'attachment_id' => $attachment_id,
            'thumbnail_url' => wp_get_attachment_image_url($attachment_id, 'medium'),
            'message'       => __('Cover imported and set as featured image.', 'montecchiani-book-catalog'),
        ));
    }

    /**
     * User-Agent sent to the catalogs
     *
     * Deliberately carries no site URL: nothing that identifies this site is
     * sent to the external services (see readme.txt).
     *
     * @return string
     */
    private static function user_agent() {
        return 'Montecchiani-Book-Catalog/' . MBCAT_VERSION;
    }

    /**
     * Image MIME type of a binary string, from its leading bytes
     *
     * Works on a string, unlike the PHP and WordPress image functions, and
     * never raises a notice on data that is not an image.
     *
     * @param string $bytes File contents (at least the first 12 bytes).
     * @return string|false MIME type, or false when not a supported image.
     */
    public static function detect_image_mime($bytes) {
        $bytes = (string) $bytes;

        if (strlen($bytes) < 12) {
            return false;
        }

        if ("\xFF\xD8\xFF" === substr($bytes, 0, 3)) {
            return 'image/jpeg';
        }

        if ("\x89PNG\r\n\x1A\n" === substr($bytes, 0, 8)) {
            return 'image/png';
        }

        if ('GIF87a' === substr($bytes, 0, 6) || 'GIF89a' === substr($bytes, 0, 6)) {
            return 'image/gif';
        }

        if ('RIFF' === substr($bytes, 0, 4) && 'WEBP' === substr($bytes, 8, 4)) {
            return 'image/webp';
        }

        return false;
    }

    /**
     * Cover preview of a lookup record, as a data URI
     *
     * The preview is cached on its own, and only when its download worked, so
     * that a passing network failure does not hide the preview for as long as
     * the lookup record itself stays cached.
     *
     * @param array $data Lookup record, as returned by lookup().
     * @return string Data URI, or an empty string when no preview is possible.
     */
    public static function get_cover_preview($data) {
        if (empty($data['cover'])) {
            return '';
        }

        $url = !empty($data['cover_thumb']) ? $data['cover_thumb'] : $data['cover'];

        // Same "mbcat_isbn_" prefix as the lookup records, which uninstall.php
        // removes.
        $cache_key = 'mbcat_isbn_preview_' . md5($url);
        $cached    = get_transient($cache_key);

        if (is_string($cached) && '' !== $cached) {
            return $cached;
        }

        $preview = self::fetch_cover_preview($url);

        if ('' !== $preview) {
            set_transient($cache_key, $preview, 12 * HOUR_IN_SECONDS);
        }

        return $preview;
    }

    /**
     * Download a small cover thumbnail and inline it as a data URI
     *
     * The preview is shown in the editor before the cover is imported. It is
     * fetched by the server and embedded in the lookup response, so the editor
     * page never loads an image from a third-party host.
     *
     * @param string $url Thumbnail URL, as returned by the catalog API.
     * @return string Data URI, or an empty string when no preview is possible.
     */
    public static function fetch_cover_preview($url) {
        $url = esc_url_raw($url);

        if (!$url) {
            return '';
        }

        // wp_safe_remote_get() rejects private and loopback addresses, also on
        // redirects. The byte limit caps what is kept in memory: a thumbnail
        // bigger than that is simply not previewed.
        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => 8,
                'limit_response_size' => self::PREVIEW_MAX_BYTES,
                'user-agent'          => self::user_agent(),
            )
        );

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return '';
        }

        $body = (string) wp_remote_retrieve_body($response);

        // A body at the limit has been cut short: too big for an inline preview.
        if (strlen($body) >= self::PREVIEW_MAX_BYTES) {
            return '';
        }

        $mime = self::detect_image_mime($body);

        if (!$mime) {
            return '';
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A data URI is base64 by definition; this only inlines the thumbnail bytes checked above.
        return 'data:' . $mime . ';base64,' . base64_encode($body);
    }

    /**
     * Download a remote file into a temporary file, with a size limit
     *
     * download_url() is not used because it has no size limit and sends the
     * default WordPress User-Agent, which carries the site URL (see readme.txt:
     * nothing that identifies this site is sent to the catalogs).
     *
     * @param string $url       File URL.
     * @param int    $max_bytes Files of this size or larger are rejected.
     * @return string|WP_Error Path of the temporary file, or error.
     */
    private static function download_to_temp_file($url, $max_bytes) {
        $url = esc_url_raw($url);

        if (!$url) {
            return new WP_Error('mbcat_no_cover_url', __('No cover is available for this ISBN.', 'montecchiani-book-catalog'));
        }

        $tmp_file = wp_tempnam('mbcat-cover');

        if (!$tmp_file) {
            return new WP_Error('mbcat_no_tmp_file', __('Could not create a temporary file.', 'montecchiani-book-catalog'));
        }

        // wp_safe_remote_get() rejects private and loopback addresses, also on
        // redirects; the byte limit caps what is written to disk.
        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => 30,
                'stream'              => true,
                'filename'            => $tmp_file,
                'limit_response_size' => $max_bytes,
                'user-agent'          => self::user_agent(),
            )
        );

        if (is_wp_error($response)) {
            wp_delete_file($tmp_file);
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $size = file_exists($tmp_file) ? (int) filesize($tmp_file) : 0;

        // Fewer than 12 bytes cannot even hold an image signature, and the image
        // functions used later raise a notice on such a file.
        if (200 !== $code || $size < 12) {
            wp_delete_file($tmp_file);
            return new WP_Error('mbcat_cover_download', __('The cover could not be downloaded.', 'montecchiani-book-catalog'));
        }

        // A file at the limit has been cut short by the byte limit.
        if ($size >= $max_bytes) {
            wp_delete_file($tmp_file);
            return new WP_Error('mbcat_cover_too_large', __('The cover file is too large to import.', 'montecchiani-book-catalog'));
        }

        return $tmp_file;
    }

    /**
     * Download a cover image and attach it to the book
     *
     * media_sideload_image() cannot be used here: it requires the URL itself to
     * end with an image extension, while Google Books serves its covers from
     * extension-less endpoints such as /books/content?id=…. The file type is
     * therefore taken from the downloaded bytes instead of from the URL.
     *
     * @param string $url     Cover URL, as returned by the catalog API.
     * @param int    $post_id Book post ID.
     * @param string $isbn    Normalized ISBN, used for the file name.
     * @return int|WP_Error Attachment ID or error.
     */
    private static function sideload_cover($url, $post_id, $isbn) {
        $tmp_file = self::download_to_temp_file($url, self::COVER_MAX_BYTES);

        if (is_wp_error($tmp_file)) {
            return $tmp_file;
        }

        $extensions = array(
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        );

        // The type is read from the file signature; the download helper has
        // already rejected anything too short to have one.
        $mime = wp_get_image_mime($tmp_file);

        if (!$mime || !isset($extensions[$mime])) {
            wp_delete_file($tmp_file);
            return new WP_Error(
                'mbcat_invalid_cover',
                __('The downloaded file is not a supported image.', 'montecchiani-book-catalog')
            );
        }

        $file_array = array(
            'name'     => sanitize_file_name($isbn . '-cover.' . $extensions[$mime]),
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
     * Transient key of the cached lookup record of an ISBN
     *
     * The data source (and API-key presence) is part of the key, so changing
     * the source setting does not serve results from another source. The
     * trailing "2" is the record format, which changed in 1.3.1 (cover_thumb).
     *
     * @param string $isbn Normalized ISBN.
     * @return string
     */
    private static function cache_key($isbn) {
        $source  = MBCat_Settings::get_setting('isbn_source', 'both');
        $has_key = MBCat_Settings::get_setting('google_api_key', '') !== '' ? '1' : '0';

        return 'mbcat_isbn_' . md5($isbn . '|' . $source . '|' . $has_key . '|2');
    }

    /**
     * Look up an ISBN against the configured data sources (with caching)
     *
     * @param string $isbn Normalized ISBN.
     * @return array Normalized book data.
     */
    public static function lookup($isbn) {
        $cache_key = self::cache_key($isbn);
        $cached    = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $source       = MBCat_Settings::get_setting('isbn_source', 'both');
        $google       = in_array($source, array('both', 'google'), true) ? self::fetch_google_books($isbn) : null;
        $open_library = in_array($source, array('both', 'openlibrary'), true) ? self::fetch_open_library($isbn) : null;

        $data = self::merge_results($google, $open_library);
        $data['isbn'] = $isbn;

        $cover_before = $data['cover'];

        /**
         * Filters the normalized book record returned by an ISBN lookup.
         *
         * The record holds the remote cover URLs in 'cover' (the file that
         * "Use as cover" imports) and 'cover_thumb' (the thumbnail previewed
         * in the editor). Both stay on the server. A filter that replaces
         * 'cover' may leave 'cover_thumb' alone: it is then dropped, and the
         * new cover is previewed directly.
         *
         * @since 1.2.0
         *
         * @param array  $data Normalized record.
         * @param string $isbn Normalized ISBN that was looked up.
         */
        $data = apply_filters('mbcat_lookup_data', $data, $isbn);

        // The preview must show the cover that is actually imported.
        if (!isset($data['cover']) || $data['cover'] !== $cover_before) {
            $data['cover_thumb'] = '';
        }

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

        // Prefer the Open Library cover (higher resolution scans), fall back to
        // Google. The preview thumbnail is taken from the same source, so the
        // editor previews the very cover that would be imported.
        $cover       = '';
        $cover_thumb = '';
        foreach (array($open_library, $google) as $candidate) {
            if (!empty($candidate['cover'])) {
                $cover       = $candidate['cover'];
                $cover_thumb = !empty($candidate['cover_thumb']) ? $candidate['cover_thumb'] : '';
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
            'cover_thumb' => $cover_thumb,
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

        $api_key = MBCat_Settings::get_setting('google_api_key', '');
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
     * Pick the first available image link, forced to https
     *
     * @param array    $links Map of size name => URL.
     * @param string[] $sizes Size names, in order of preference.
     * @return string URL or empty string.
     */
    private static function pick_image_link($links, $sizes) {
        if (empty($links) || !is_array($links)) {
            return '';
        }

        foreach ($sizes as $size) {
            if (!empty($links[$size]) && is_string($links[$size])) {
                return esc_url_raw(set_url_scheme($links[$size], 'https'));
            }
        }

        return '';
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

        $links = !empty($info['imageLinks']) ? $info['imageLinks'] : array();

        return array(
            'title'       => isset($info['title']) ? sanitize_text_field($info['title']) : '',
            'authors'     => !empty($info['authors']) && is_array($info['authors']) ? sanitize_text_field(implode(', ', $info['authors'])) : '',
            'publisher'   => isset($info['publisher']) ? sanitize_text_field($info['publisher']) : '',
            'year'        => $year,
            'description' => isset($info['description']) ? self::normalize_description($info['description']) : '',
            'pages'       => !empty($info['pageCount']) ? absint($info['pageCount']) : '',
            'language'    => isset($info['language']) ? sanitize_text_field($info['language']) : '',
            // Prefer larger sizes for the import, the small ones for the preview.
            'cover'       => self::pick_image_link($links, array('extraLarge', 'large', 'medium', 'small', 'thumbnail', 'smallThumbnail')),
            'cover_thumb' => self::pick_image_link($links, array('thumbnail', 'smallThumbnail')),
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

        $links = !empty($record['cover']) ? $record['cover'] : array();

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
            // Prefer larger sizes for the import, the small ones for the preview.
            'cover'       => self::pick_image_link($links, array('large', 'medium', 'small')),
            'cover_thumb' => self::pick_image_link($links, array('medium', 'small')),
        );
    }

    /**
     * GET a URL and decode the JSON body
     *
     * @param string $url Request URL.
     * @return array|null Decoded body or null on failure.
     */
    private static function remote_get_json($url) {
        // The timeout is kept short because with the "both" data source two of
        // these run back to back inside one admin request.
        $response = wp_remote_get(
            $url,
            array(
                'timeout'    => 8,
                'user-agent' => self::user_agent(),
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
MBCat_ISBN_Lookup::get_instance();
