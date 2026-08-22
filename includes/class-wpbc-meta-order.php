<?php
/**
 * Ordering a books query by one of the custom meta fields
 *
 * @package WP_Book_Catalog
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WPBC_Meta_Order
 *
 * WP_Query cannot order by a meta value without also requiring that meta value
 * to exist: 'meta_key' + 'orderby' => 'meta_value' produces an INNER JOIN, so
 * books that have no publication year would disappear from a list ordered by
 * year. The usual workaround - an OR'ed EXISTS / NOT EXISTS meta_query - keeps
 * those books but joins the postmeta table on the post ID only, so a book
 * without the key matches every one of its other meta rows and ends up sorted
 * by an unrelated value (_edit_last, _thumbnail_id, ...).
 *
 * This class instead adds one explicit LEFT JOIN keyed on the meta key, which
 * produces exactly one row per book and a stable order: books that do have a
 * value first (in the requested direction), books without one last.
 */
class WPBC_Meta_Order {

    /**
     * Query var holding the meta key to order by
     */
    const QUERY_VAR = 'wpbc_meta_order';

    /**
     * Whether the posts_clauses filter is registered
     *
     * @var bool
     */
    private static $hooked = false;

    /**
     * Meta keys that may be used for ordering
     *
     * @return array Map of order key => whether the value is numeric.
     */
    public static function get_supported_keys() {
        return array(
            'wpbc_year'   => true,
            'wpbc_author' => false,
        );
    }

    /**
     * Configure a query (or a set of query args) to order by a meta key
     *
     * @param array  $args     Query arguments to extend.
     * @param string $meta_key Meta key to order by.
     * @param string $order    ASC or DESC.
     * @return array
     */
    public static function add_to_args($args, $meta_key, $order) {
        self::register();

        $args[self::QUERY_VAR] = $meta_key;
        $args['order']         = ('ASC' === strtoupper($order)) ? 'ASC' : 'DESC';

        // The ORDER BY is built entirely in the posts_clauses filter.
        $args['orderby'] = 'none';

        return $args;
    }

    /**
     * Configure an existing WP_Query object (used from pre_get_posts)
     *
     * @param WP_Query $query    Query to modify.
     * @param string   $meta_key Meta key to order by.
     * @param string   $order    ASC or DESC.
     */
    public static function add_to_query($query, $meta_key, $order) {
        self::register();

        $query->set(self::QUERY_VAR, $meta_key);
        $query->set('order', ('ASC' === strtoupper($order)) ? 'ASC' : 'DESC');
        $query->set('orderby', 'none');
    }

    /**
     * Register the posts_clauses filter once
     */
    public static function register() {
        if (self::$hooked) {
            return;
        }

        add_filter('posts_clauses', array(__CLASS__, 'filter_clauses'), 10, 2);
        self::$hooked = true;
    }

    /**
     * Add the keyed LEFT JOIN and the ORDER BY to a books query
     *
     * @param array    $clauses SQL clauses.
     * @param WP_Query $query   Query being run.
     * @return array
     */
    public static function filter_clauses($clauses, $query) {
        global $wpdb;

        $meta_key = $query->get(self::QUERY_VAR);
        $numeric  = self::get_supported_keys();

        if (empty($meta_key) || !isset($numeric[$meta_key])) {
            return $clauses;
        }

        $clauses['join'] .= $wpdb->prepare(
            " LEFT JOIN {$wpdb->postmeta} AS wpbc_ord ON (wpbc_ord.post_id = {$wpdb->posts}.ID AND wpbc_ord.meta_key = %s)",
            $meta_key
        );

        $direction = ('ASC' === strtoupper((string) $query->get('order'))) ? 'ASC' : 'DESC';
        $value     = $numeric[$meta_key] ? 'CAST(wpbc_ord.meta_value AS SIGNED)' : 'wpbc_ord.meta_value';

        // Books without a value always come last, whatever the direction, and
        // post_date breaks any remaining tie so the order is reproducible.
        $clauses['orderby'] = "(wpbc_ord.meta_value IS NULL OR wpbc_ord.meta_value = '') ASC, "
            . $value . ' ' . $direction . ", {$wpdb->posts}.post_date DESC";

        return $clauses;
    }
}
