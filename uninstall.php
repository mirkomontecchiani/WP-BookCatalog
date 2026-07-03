<?php
/**
 * Uninstall handler - cleans up plugin data when the plugin is deleted
 *
 * @package WP_Book_Catalog
 */

// Exit if uninstall is not called from WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$wpbc_settings = get_option('wpbc_settings', array());

// Only remove content data when the user opted in from the settings page.
if (!empty($wpbc_settings['delete_data_on_uninstall'])) {
    // Delete all book posts (and their post meta) permanently.
    $wpbc_books = get_posts(array(
        'post_type'      => 'book',
        'post_status'    => 'any',
        'numberposts'    => -1,
        'fields'         => 'ids',
    ));

    foreach ($wpbc_books as $wpbc_book_id) {
        wp_delete_post($wpbc_book_id, true);
    }

    // Delete all genre terms.
    $wpbc_terms = get_terms(array(
        'taxonomy'   => 'book_genre',
        'hide_empty' => false,
        'fields'     => 'ids',
    ));

    if (!is_wp_error($wpbc_terms)) {
        foreach ($wpbc_terms as $wpbc_term_id) {
            wp_delete_term($wpbc_term_id, 'book_genre');
        }
    }
}

// Always remove the plugin options and cached lookups.
delete_option('wpbc_settings');

global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_wpbc\_isbn\_%'
        OR option_name LIKE '\_transient\_timeout\_wpbc\_isbn\_%'"
);
