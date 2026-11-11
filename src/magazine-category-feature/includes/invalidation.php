<?php
/**
 * Drops cached post IDs when a post's place in a category can change,
 * then asks the page cache to forget URLs that embed this block.
 *
 * The object cache is not the cache that serves two million homepage views.
 * CloudFront and WP Engine's page cache serve HTML without running PHP.
 * Clearing post IDs only helps the next uncached render. The purge at the
 * bottom is what makes a new article show up on a cached landing page.
 *
 * @package MagazineCategoryFeature
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'magazine_category_feature_maybe_install_lookup_table' );
add_action( 'pre_post_update', 'magazine_category_feature_remember_categories' );
add_action( 'save_post_post', 'magazine_category_feature_refresh_post_categories' );
add_action( 'wp_trash_post', 'magazine_category_feature_refresh_post_categories' );
add_action( 'untrash_post', 'magazine_category_feature_refresh_post_categories' );
add_action( 'before_delete_post', 'magazine_category_feature_refresh_post_categories' );
add_action( 'save_post', 'magazine_category_feature_refresh_lookup_for_content', 20, 2 );
add_action( 'before_delete_post', 'magazine_category_feature_delete_lookup_for_content' );
add_action( 'delete_category', 'magazine_category_feature_forget_category' );

/**
 * Creates the category-to-content lookup table.
 *
 * Rows are small and append-only per content save. This avoids LIKE scans on
 * post_content when deciding which URLs to purge.
 */
function magazine_category_feature_maybe_install_lookup_table(): void {
	$installed = (int) get_option( 'magazine_category_feature_lookup_table_version', 0 );
	if ( $installed >= 1 ) {
		return;
	}

	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = magazine_category_feature_lookup_table_name();
	$charset_collate = $wpdb->get_charset_collate();
	$sql             = "CREATE TABLE {$table_name} (
		object_id bigint(20) unsigned NOT NULL,
		object_type varchar(32) NOT NULL,
		category_id bigint(20) unsigned NOT NULL,
		PRIMARY KEY  (object_id, object_type, category_id),
		KEY category_id (category_id),
		KEY object_type (object_type)
	) {$charset_collate};";

	dbDelta( $sql );
	magazine_category_feature_backfill_lookup_table();
	update_option( 'magazine_category_feature_lookup_table_version', 1, true );
}

/**
 * Fully qualified lookup table name.
 */
function magazine_category_feature_lookup_table_name(): string {
	global $wpdb;

	return $wpdb->prefix . 'magazine_category_feature_lookup';
}

/**
 * Post types whose own permalink can be purged.
 *
 * Templates and template parts are omitted. They have no public URL, and the
 * pages that render them are not known from the template alone.
 *
 * @return string[]
 */
function magazine_category_feature_lookup_post_types(): array {
	return array( 'page', 'post' );
}

/**
 * Seeds the lookup table from existing block content.
 */
function magazine_category_feature_backfill_lookup_table(): void {
	$supported_types = magazine_category_feature_lookup_post_types();
	$query           = new WP_Query(
		array(
			'post_type'              => $supported_types,
			'post_status'            => array( 'publish', 'private', 'draft', 'future' ),
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	foreach ( $query->posts as $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			continue;
		}

		$category_ids = magazine_category_feature_block_category_ids_from_content( (string) $post->post_content );
		magazine_category_feature_replace_lookup_rows( (int) $post->ID, $post->post_type, $category_ids );
	}
}

/**
 * Refreshes lookup rows for one content object after save.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Saved post object.
 */
function magazine_category_feature_refresh_lookup_for_content( int $post_id, WP_Post $post ): void {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( ! in_array( $post->post_type, magazine_category_feature_lookup_post_types(), true ) ) {
		return;
	}

	$category_ids = magazine_category_feature_block_category_ids_from_content( (string) $post->post_content );
	magazine_category_feature_replace_lookup_rows( $post_id, $post->post_type, $category_ids );
}

/**
 * Removes lookup rows for one content object before deletion.
 *
 * @param int $post_id Post ID.
 */
function magazine_category_feature_delete_lookup_for_content( int $post_id ): void {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return;
	}

	magazine_category_feature_replace_lookup_rows( $post_id, $post->post_type, array() );
}

/**
 * Replaces all lookup rows for one content object.
 *
 * @param int    $object_id   Post ID that stores block markup.
 * @param string $object_type Content type, page or post.
 * @param int[]  $category_ids Category IDs used by this object's block instances.
 */
function magazine_category_feature_replace_lookup_rows( int $object_id, string $object_type, array $category_ids ): void {
	global $wpdb;

	$table_name = magazine_category_feature_lookup_table_name();
	$object_id  = (int) $object_id;

	$wpdb->delete(
		$table_name,
		array(
			'object_id'   => $object_id,
			'object_type' => $object_type,
		),
		array( '%d', '%s' )
	);

	$category_ids = array_values( array_unique( array_filter( array_map( 'intval', $category_ids ) ) ) );
	foreach ( $category_ids as $category_id ) {
		$wpdb->insert(
			$table_name,
			array(
				'object_id'   => $object_id,
				'object_type' => $object_type,
				'category_id' => $category_id,
			),
			array( '%d', '%s', '%d' )
		);
	}
}

/**
 * Reads this block's category IDs from serialized block markup.
 *
 * @param string $content Block content to parse.
 * @return int[]
 */
function magazine_category_feature_block_category_ids_from_content( string $content ): array {
	if ( '' === $content || ! has_blocks( $content ) ) {
		return array();
	}

	$category_ids = array();
	$blocks       = parse_blocks( $content );

	foreach ( $blocks as $block ) {
		magazine_category_feature_collect_block_category_ids( $block, $category_ids );
	}

	return array_values( array_unique( array_filter( array_map( 'intval', $category_ids ) ) ) );
}

/**
 * Walks nested blocks and collects category IDs for this block type.
 *
 * @param array<string, mixed> $block        Parsed block.
 * @param int[]                $category_ids Collected category IDs by reference.
 */
function magazine_category_feature_collect_block_category_ids( array $block, array &$category_ids ): void {
	if ( ( $block['blockName'] ?? '' ) === 'magazine-category-feature/articles' ) {
		$value = $block['attrs']['categoryId'] ?? 0;
		$value = (int) $value;
		if ( $value > 0 ) {
			$category_ids[] = $value;
		}
	}

	$inner_blocks = $block['innerBlocks'] ?? array();
	foreach ( $inner_blocks as $inner_block ) {
		if ( is_array( $inner_block ) ) {
			magazine_category_feature_collect_block_category_ids( $inner_block, $category_ids );
		}
	}
}

/**
 * Stores the categories a post had before this save.
 *
 * save_post only sees the new terms. Without this, moving a post from
 * Gardening to News would leave Gardening's cached five IDs in place.
 */
function magazine_category_feature_remember_categories( int $post_id ): void {
	if ( 'post' !== get_post_type( $post_id ) ) {
		return;
	}

	magazine_category_feature_previous_categories( $post_id, wp_get_post_categories( $post_id ) );
}

/**
 * Reads or stores the category IDs captured in pre_post_update.
 *
 * @param int        $post_id Post ID.
 * @param int[]|null $set     Pass an array to store. Omit it to read.
 * @return int[]
 */
function magazine_category_feature_previous_categories( int $post_id, ?array $set = null ): array {
	static $stored = array();

	if ( is_array( $set ) ) {
		$stored[ $post_id ] = array_map( 'intval', $set );
	}

	return $stored[ $post_id ] ?? array();
}

/**
 * Clears the ID-list cache for every category this post is leaving or joining.
 *
 * @param int $post_id Post ID.
 */
function magazine_category_feature_refresh_post_categories( int $post_id ): void {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( 'post' !== get_post_type( $post_id ) ) {
		return;
	}

	$category_ids = array_unique(
		array_merge(
			magazine_category_feature_previous_categories( $post_id ),
			wp_get_post_categories( $post_id )
		)
	);

	magazine_category_feature_forget_categories( $category_ids );
	magazine_category_feature_purge_pages_for_categories( $category_ids );
}

/**
 * Clears one category when the term itself is deleted.
 */
function magazine_category_feature_forget_category( int $term_id ): void {
	magazine_category_feature_forget_categories( array( $term_id ) );
}

/**
 * Deletes cached ID lists.
 *
 * @param int[] $category_ids Category term IDs.
 */
function magazine_category_feature_forget_categories( array $category_ids ): void {
	foreach ( $category_ids as $category_id ) {
		$category_id = (int) $category_id;
		if ( $category_id <= 0 ) {
			continue;
		}
		wp_cache_delete( magazine_category_feature_cache_key( $category_id ), MAGAZINE_CATEGORY_FEATURE_CACHE_GROUP );
	}
}

/**
 * Purges published content that contains this block for the given categories.
 *
 * Targeting comes from the lookup table written when a page or post is saved.
 *
 * @param int[] $category_ids Category term IDs.
 */
function magazine_category_feature_purge_pages_for_categories( array $category_ids ): void {
	$category_ids = array_values( array_unique( array_filter( array_map( 'intval', $category_ids ) ) ) );
	if ( ! $category_ids ) {
		return;
	}

	$post_ids = magazine_category_feature_lookup_rows_for_categories( $category_ids );
	$urls     = array();

	foreach ( $post_ids as $post_id ) {
		$permalink = get_permalink( $post_id );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			$urls[] = $permalink;
		}
		magazine_category_feature_purge_host_cache( $post_id );
	}

	$urls = array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) );

	/**
	 * Lets a CDN integration purge the exact URLs a new article affects.
	 *
	 * CloudFront does not watch WordPress post saves. Something in the stack
	 * has to invalidate these paths, or the edge keeps the previous HTML
	 * until its own TTL.
	 *
	 * @param string[] $urls         Public URLs whose HTML embeds the block.
	 * @param int[]    $category_ids Categories whose membership just changed.
	 */
	do_action( 'magazine_category_feature_purge_urls', $urls, array_values( array_unique( array_map( 'intval', $category_ids ) ) ) );
}

/**
 * Returns page and post IDs from the lookup table for categories.
 *
 * Template rows already stored in the table are ignored. They have no permalink
 * to purge.
 *
 * @param int[] $category_ids Category IDs.
 * @return int[]
 */
function magazine_category_feature_lookup_rows_for_categories( array $category_ids ): array {
	global $wpdb;

	$table_name      = magazine_category_feature_lookup_table_name();
	$supported_types = magazine_category_feature_lookup_post_types();
	$in              = implode( ',', array_fill( 0, count( $category_ids ), '%d' ) );
	$type_in         = implode( ',', array_fill( 0, count( $supported_types ), '%s' ) );
	$sql             = "SELECT object_id FROM {$table_name} WHERE category_id IN ({$in}) AND object_type IN ({$type_in})";

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are generated from the category and post-type counts.
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $category_ids, $supported_types ) ), ARRAY_A );

	$post_ids = array();
	foreach ( $rows as $row ) {
		$object_id = isset( $row['object_id'] ) ? (int) $row['object_id'] : 0;
		if ( $object_id <= 0 ) {
			continue;
		}
		$post_ids[] = $object_id;
	}

	return array_values( array_unique( $post_ids ) );
}

/**
 * Asks WP Engine's page cache to drop one post, when that plugin is present.
 *
 * Local Docker has neither WP Engine nor a persistent object cache, so this
 * is a no-op in development. The hooks are still the ones that run in production.
 */
function magazine_category_feature_purge_host_cache( int $post_id ): void {
	if ( function_exists( 'wpe_purge_post_cache' ) ) {
		wpe_purge_post_cache( $post_id );
		return;
	}

	if ( class_exists( 'WpeCommon' ) && method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
		WpeCommon::purge_varnish_cache( $post_id );
	}
}
