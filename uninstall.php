<?php
/**
 * Uninstall handler - cleans up plugin data when the plugin is deleted
 *
 * @package MM_Book_Catalog
 */

// Exit if uninstall is not called from WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Register the book objects for the duration of the uninstall
 *
 * WordPress runs uninstall.php with the plugin already deactivated, so nothing
 * in includes/ has been loaded and neither the post type nor the taxonomy
 * exists. Without them get_terms() returns an "invalid taxonomy" error and
 * wp_delete_post() cannot clean up the term relationships, so the opt-in data
 * removal would silently leave every genre behind.
 */
function wpbc_uninstall_register_objects() {
    register_post_type('book', array(
        'public'  => false,
        'rewrite' => false,
    ));

    register_taxonomy('book_genre', array('book'), array(
        'public'  => false,
        'rewrite' => false,
    ));
}

/**
 * Remove the plugin data of the current site
 *
 * Content is only deleted when the site owner opted in from the settings page.
 * The options and the cached ISBN lookups are always removed.
 */
function wpbc_uninstall_site() {
    global $wpdb;

    $settings = get_option('wpbc_settings', array());

    if (is_array($settings) && !empty($settings['delete_data_on_uninstall'])) {
        // 'any' silently excludes trashed and auto-draft posts, so the statuses
        // are listed explicitly: the user asked for everything to go.
        $statuses = array_merge(
            array_keys(get_post_stati()),
            array('trash', 'auto-draft', 'inherit')
        );
        $statuses = array_values(array_unique($statuses));

        // Delete book posts (and their post meta and term relationships)
        // permanently, in batches so a large catalog cannot exhaust the memory
        // limit or the request time.
        do {
            $book_ids = get_posts(array(
                'post_type'              => 'book',
                'post_status'            => $statuses,
                'numberposts'            => 100,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ));

            foreach ($book_ids as $book_id) {
                wp_delete_post($book_id, true);
            }
        } while (!empty($book_ids));

        // Delete all genre terms.
        $terms = get_terms(array(
            'taxonomy'   => 'book_genre',
            'hide_empty' => false,
            'fields'     => 'ids',
        ));

        if (!is_wp_error($terms)) {
            foreach ($terms as $term_id) {
                wp_delete_term($term_id, 'book_genre');
            }
        }
    }

    // Always remove the plugin options and the cached ISBN lookups. The transient
    // names are not known up front (they embed a hash), so the option table has
    // to be searched; each transient is then removed through the API so that a
    // persistent object cache is invalidated too. On a site using an external
    // object cache the transients never reach the option table at all - those
    // simply expire on their own within twelve hours.
    delete_option('wpbc_settings');

    $like = $wpdb->esc_like('_transient_wpbc_isbn_') . '%';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup on uninstall; no cache API can look option names up by prefix.
    $option_names = $wpdb->get_col(
        $wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like)
    );

    foreach ((array) $option_names as $option_name) {
        delete_transient(substr($option_name, strlen('_transient_')));
    }
}

wpbc_uninstall_register_objects();

if (is_multisite()) {
    // The plugin stores its data per site, so every site has to be cleaned.
    $wpbc_site_ids = get_sites(array(
        'fields'   => 'ids',
        'number'   => 0,
        'deleted'  => 0,
        'archived' => 0,
    ));

    foreach ($wpbc_site_ids as $wpbc_site_id) {
        switch_to_blog($wpbc_site_id);
        wpbc_uninstall_site();
        restore_current_blog();
    }
} else {
    wpbc_uninstall_site();
}
