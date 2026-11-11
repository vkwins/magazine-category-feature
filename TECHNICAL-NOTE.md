# Technical note

## 1. Caching at scale

The homepage HTML is the cache that matters. At two million views a day, CloudFront and WP Engine’s page cache answer almost every request. PHP, and therefore this block’s query, does not run. A fresh query inside the block cannot update a page that is served from the edge.

The block stays current because a publish tells cache to drop affected landing-page URLs. `includes/invalidation.php` runs on save, update, trash, untrash, and delete. It remembers the categories a post had before the save, so moving a story from Gardening to News clears both lists. A lookup table written on content save maps category ids to pages and posts that use this block, so publish-time invalidation reads that table instead of scanning `wp_posts.post_content`. From that mapping it purges those page and post permalinks. Templates and template parts are not tracked, because they have no public URL of their own. It then calls WP Engine’s per-post purge when `wpe_purge_post_cache` or `WpeCommon::purge_varnish_cache` exists. The same URL list is passed to the `magazine_category_feature_purge_urls` action, which is where a CloudFront invalidation belongs. CloudFront keeps old HTML until those paths are invalidated.

The query itself is cached one level down, for the requests that do reach PHP. `magazine_category_feature_get_posts()` stores five post IDs in the object cache under `magazine-category-feature` / `cat_{id}`. The current TTL is one hour; that value is a tuning knob and can be adjusted to match production requirements. Titles and images are loaded after that, so they still come from WordPress’s own post cache, which core already clears on save. The ID list is what would go stale when a new article should enter the five. The save hook deletes it. The TTL is only a backstop for a write that skipped the hook, such as a raw SQL import.

The query is already small: five published posts, sticky posts ignored, and term-cache work reduced. Caching IDs is there so a cache miss is cheap, not because the SQL is expensive. I would not also cache the rendered HTML in a transient. That second copy goes stale on its own schedule and fights the page cache.

A lookup table stores content object id, object type, and category id when a page or post containing the block is saved. Purge reads this table directly, so publish-time invalidation does not scan `wp_posts.post_content`. Only those page and post permalinks are purged.

Local Docker has no persistent object cache and no WP Engine, so the purge functions no-op and the ID cache dies at the end of the request. That is expected. It is not evidence that production would query on every view.

## 2. Core Web Vitals

The block can move LCP, CLS, and, if it were careless, INP.

LCP is the hero. It is the largest element near the top of a landing page. The first hero image on the page is eager and `fetchpriority="high"`. Card images are lazy and `fetchpriority="low"`, so they do not compete for bandwidth. A second copy of the block on the same page does not get another high-priority image. No font file is loaded. The type falls back to the theme serif or Georgia.

CLS is the space around that image. The hero box is `aspect-ratio: 1630 / 918` and the card box is square, before the file arrives. A missing image is a placeholder in that same box, not a collapsed gap. The stylesheet is enqueued on `wp_enqueue_scripts` when the singular content contains the block, so the rule is in the head rather than arriving with the footer. There is no front-end JavaScript, so the block does not add a long task and does not affect INP by itself. An ad tag later would, which is why the slot is a fixed-height hook and not a script in this plugin.

I would measure field impact in the CrUX data already coming from the site, or the web-vitals snippet reporting to the analytics tool the brands use, split by template. Compare the landing-page template for the two weeks before and after release, at the 75th percentile, on phones. Lighthouse is a single lab run on a quiet machine. It will not show a mid-range Android phone on a slow network, it will not show cache-hit versus cache-miss, and it will not show what happens when an ad creative is late. A green lab score can sit next to a poor field LCP.

## 3. Ads

I would not print an ad unit in the plugin. Editors will want different networks per brand. The hook `magazine_category_feature_ad_slot` fires between the hero and the cards, only when something is attached, so an unused block has no empty gap.

The callback prints its tag into `.magazine-category-feature__ad`, which has `min-height: var(--magazine-category-feature-ad-min-height, 280px)`. Set that variable to the booked creative: 250px for a billboard, 280px for a medium rectangle, and so on. The grid below is already painted in the right place, so the creative arriving late does not move it.

I would not collapse the slot when the auction returns empty. Collapsing is the layout shift. I would also not lazy-load an ad that sits this high on the page. A lazy ad under the hero is often still below the viewability threshold when it finally loads, and a late injection is another shift if the height was not reserved. The creative’s own script stays out of the hero’s request path. The hero image does not wait for the ad.

## 4. SEO

The feature headline is an `h2`. Card headlines are `h3`. There is no `h1`, because the page title already is one and Yoast is responsible for it. The category name is a paragraph, not a heading. Each article is an `article` inside a named `section`.

The whole story is one `a` element with an `href` to the post. The link is in the HTML. Nothing has to run for a crawler to see it. Image alt text is the attachment alt. If that is empty, the alt is the post title, which is always present for a published story. The no-image placeholder is decorative (`aria-hidden="true"`), so screen readers go straight to the article text and link.

The block does not call Yoast’s schema API, does not print `NewsArticle` JSON-LD, and does not set a canonical or a meta description. Yoast’s graph for the landing page stays the only one. Headlines inside the links are real text, so they are part of the page Yoast is already describing.

After launch I would check Search Console for the landing pages, not for the block as an object. Coverage and page indexing should be unchanged. I would look at the enhancement reports for invalid items in case a theme and this block together produced a second `h1` or broken heading order. I would compare the impression and CTR trend on those URLs after a few weeks, and I would fetch a published URL as Googlebot and confirm the five links are in the HTML response rather than behind a script.

## 5. Multiple brands

One plugin, activated on each site of the multisite. Fonts and colours are custom properties the block reads and does not define on itself: `--magazine-category-feature-heading-font`, `--magazine-category-feature-body-font`, `--magazine-category-feature-heading-color`, `--magazine-category-feature-label-color`, `--magazine-category-feature-muted-color`, `--magazine-category-feature-rule`, and `--magazine-category-feature-ad-min-height`. A brand sets them on `body` in its theme, or in Additional CSS. They inherit. `docs/brand-override.example.css` is the pattern.

If the theme already has a serif in `theme.json`, the heading uses that preset before it falls back to Georgia. No brand font is bundled, so the block does not add a download in front of the hero.

A change only one brand wants should stay in that brand’s theme (for example token overrides or block wrapper context) instead of forking the plugin. If all brands need a query behavior change later, I would add one narrow plugin-level hook for that specific case rather than exposing a broad query override.

## 6. Next steps

I would add automated tests around lookup-table writes and purge targeting for pages and posts, plus logging around which URLs/categories were purged on publish. That protects the invalidation behavior as this plugin evolves across fifteen homepages.

I would add field Core Web Vitals for the landing template before calling the block done, and I would check that the hero remains the LCP element once the real ad script is hooked in. The lab run will not show that.

I would also replace the category dropdown if a brand’s term list no longer fits in one request, and I would confirm the heading order on the real brand templates, where the page `h1` is not as predictable as it is in this local theme.

## Lab note

Lighthouse 12.6.1, mobile, against the local gardening page (`http://localhost:8080/?page_id=54`) on 2 November 2026. Performance 100. LCP 1.4s, and the LCP element was the hero image (`fetchpriority="high"`, not lazy). CLS 0. Total blocking time 0ms. Accessibility 100.

This is one quiet lab run on a local page with no ads and no real-reader variability. It does not represent field performance at the 75th percentile across real devices and networks, and it can change once a real ad script is hooked into `magazine_category_feature_ad_slot`.
