<?php
/**
 * Front-end and editor preview markup.
 *
 * WordPress includes this file with $attributes, $content and $block in scope.
 * One template serves both, so the editor preview matches the page.
 *
 * Headings start at h2. The page title is the h1, and Yoast owns that.
 * This file does not print schema, a canonical, or a meta description.
 *
 * @package MagazineCategoryFeature
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner content. Unused.
 * @var WP_Block             $block      Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$category_id  = isset( $attributes['categoryId'] ) ? (int) $attributes['categoryId'] : 0;
$show_label   = ! isset( $attributes['showCategoryLabel'] ) || (bool) $attributes['showCategoryLabel'];
$show_excerpt = ! isset( $attributes['showExcerpt'] ) || (bool) $attributes['showExcerpt'];

if ( $category_id <= 0 ) {
	magazine_category_feature_render_notice(
		__( 'Select a category in the block settings. The newest post becomes the feature, and the next four become cards.', 'magazine-category-feature' )
	);
	return;
}

$term = get_term( $category_id, 'category' );
if ( ! $term instanceof WP_Term ) {
	magazine_category_feature_render_notice(
		__( 'That category is no longer available. Choose another one in the block settings.', 'magazine-category-feature' )
	);
	return;
}

$posts = magazine_category_feature_get_posts( $category_id );
if ( ! $posts ) {
	magazine_category_feature_render_notice(
		sprintf(
			/* translators: %s: category name. */
			__( '%s has no published posts yet. They will show up here when it does, without editing this page.', 'magazine-category-feature' ),
			$term->name
		)
	);
	return;
}


$hero  = $posts[0];
$cards = array_slice( $posts, 1, 4 );

$hero_link = get_permalink( $hero );
$hero_link = is_string( $hero_link ) ? $hero_link : '';
$excerpt   = $show_excerpt ? wp_trim_words( get_the_excerpt( $hero ), 22, '…' ) : '';

printf(
	'<section %s>',
	get_block_wrapper_attributes(
		array(
			'class'      => 'magazine-category-feature',
			'aria-label' => sprintf(
				/* translators: %s: category name. */
				__( 'Latest in %s', 'magazine-category-feature' ),
				$term->name
			),
		)
	)
);
?>
	<article class="magazine-category-feature__hero">
		<a class="magazine-category-feature__hero-link" href="<?php echo esc_url( $hero_link ); ?>">
			<?php echo magazine_category_feature_media_html( $hero, 'hero', $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes. ?>
			<?php if ( $show_label ) : ?>
				<p class="magazine-category-feature__label"><?php echo esc_html( $term->name ); ?></p>
			<?php endif; ?>
			<h2 class="magazine-category-feature__hero-title"><?php echo esc_html( get_the_title( $hero ) ); ?></h2>
			<?php if ( '' !== $excerpt ) : ?>
				<p class="magazine-category-feature__excerpt"><?php echo esc_html( $excerpt ); ?></p>
			<?php endif; ?>
		</a>
	</article>
	<?php if ( has_action( 'magazine_category_feature_ad_slot' ) ) : ?>
		<div class="magazine-category-feature__ad">
			<?php
			/**
			 * Ad between the feature and the cards.
			 *
			 * The wrapper keeps --magazine-category-feature-ad-min-height (default 280px) so a late
			 * creative does not push the cards down. Leave that height in
			 * place when the auction is empty. Collapsing it is a layout shift.
			 *
			 * @param int     $category_id Category shown in this block.
			 * @param WP_Post $hero        Featured post, in case a placement needs it.
			 */
			do_action( 'magazine_category_feature_ad_slot', $category_id, $hero );
			?>
		</div>
	<?php endif; ?>

	<?php if ( $cards ) : ?>
		<div class="magazine-category-feature__rows">
			<?php foreach ( $cards as $card ) : ?>
				<?php
				$card_link = get_permalink( $card );
				$card_link = is_string( $card_link ) ? $card_link : '';
				$sponsored = magazine_category_feature_sponsored_text( $card );
				?>
				<article class="magazine-category-feature__card">
					<a class="magazine-category-feature__card-link" href="<?php echo esc_url( $card_link ); ?>">
						<?php echo magazine_category_feature_media_html( $card, 'card', $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes. ?>
						<div class="magazine-category-feature__card-body">
							<?php if ($show_label  &&  '' === $sponsored) : ?>
								<p class="magazine-category-feature__label"><?php echo esc_html( $term->name ); ?></p>
							<?php endif; ?>
							<h3 class="magazine-category-feature__card-title"><?php echo esc_html( get_the_title( $card ) ); ?></h3>
							<?php if ( '' !== $sponsored ) : ?>
								<p class="magazine-category-feature__sponsored">
									<?php
									printf(
										/* translators: %s: sponsor name. */
										esc_html__( 'Sponsored by %s', 'magazine-category-feature' ),
										esc_html( $sponsored )
									);
									?>
								</p>
							<?php endif; ?>
						</div>
					</a>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>