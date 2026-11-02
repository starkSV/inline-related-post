# Inline Related Post

A WordPress block for placing hand-picked related article cards inside an article. You choose the posts yourself, and the search finds them even when you misspell a word.

![A single related article card under a red "Related" heading](docs/single-card.png)

It was built for [TechLatest](https://tech-latest.com), so **the default look is TechLatest's**: a red `#d90429` accent and the Poppins font. See [Changing the look](#changing-the-look) to make it match your site.

## Features

- **Single or multiple posts:** show one card, or several in a stacked, two-column grid or compact list layout.
- **Search that tolerates typos:** find posts by keyword, post ID or URL. `sansung` finds Samsung and `softwre` finds Software.
- **Display options:** turn the excerpt, author, date and image on or off for each block, and edit or remove the heading.
- **Always up to date:** cards read the post when the page loads, so a changed title or image updates everywhere it's linked.
- **Safe with unpublished posts:** if a linked post is deleted or unpublished, readers don't see its card and you get a warning in the editor.
- **Responsive:** cards tighten up in narrow columns, and the excerpt is hidden on phones.
- **No dependencies:** no other plugins, no build step, and no extra fonts or scripts for readers to download.

| Compact list | Search with a typo |
|---|---|
| ![Three related posts in the compact list layout](docs/compact-list.png) | ![Searching "softwre" in the block sidebar and getting Software articles](docs/search-typo.png) |

## Requirements

- WordPress 6.3 or newer, using the block editor
- PHP 7.4 or newer

These are the minimums the plugin declares. See [Testing so far](#testing-so-far) for what has actually been run.

## Installation

1. Download `inline-related-post.zip` from the [latest release](https://github.com/starkSV/inline-related-post/releases/latest). Don't use GitHub's green "Download ZIP" button, because its folder name stops WordPress from recognising updates.
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and upload the zip.
3. Activate the plugin.

**To update**, upload the newer zip the same way and choose **Replace current with uploaded**. Then clear any page cache or CDN. Installs don't update themselves from GitHub.

## Usage

1. In the block editor, click the **+** button and add **Related Post**. The settings open in the right sidebar.
2. Choose **Single** or **Multiple**. In Multiple mode, also pick a layout.
3. Pick posts from the list, or search for them. In Multiple mode you can reorder and remove the ones you've selected.
4. Set the display options, and the colours if you want to override them for this block.

The Compact layout never shows the excerpt, so that option is greyed out there.

## How search works

Before you type, the list shows the 5 most recent published posts. A search returns up to 50, best match first, in a scrolling list. Equally good matches are listed newest first.

| You type | What happens |
|---|---|
| A post ID | That post comes first, followed by any titles containing the number |
| A post URL | Only that post |
| Words | Titles are ranked in this order: exact word, start of a word, one typo, inside a word, two typos |

- **Whole words count most:** `AI` finds titles with the word "AI", and doesn't match "Chair" or "Training".
- **Typos:** words of 4 or 5 letters can have one mistake, and longer words can have two. Shorter words and anything containing a number must match exactly, so `s24` won't bring up S25.
- **Longer searches:** common words like "the" and "for" are ignored, and about 60% of the remaining words must match.
- **Article text:** if titles give fewer than 50 results, search also checks excerpts and article text for the exact words. Typo tolerance only applies to titles.

**On large sites:** each search reads the ID and title of every published post into memory, and only runs for logged-in editors while searching, so readers aren't affected. On generated test data in a slow test environment, ranking took about 40–80 ms for 5,000 titles, 150–230 ms for 20,000 and 550–860 ms for 75,000. That excludes the database query, and it hasn't been tried on a real site that large.

## Changing the look

The red accent and Poppins are hardcoded in `block/style.css`. The plugin doesn't load Poppins itself, so if your theme doesn't provide it, those texts fall back to a generic sans-serif font.

To restyle it, add CSS to your theme or to **Appearance → Customize → Additional CSS**:

```css
/* Accent colour: heading, line, and title on hover */
body .irp {
	--irp-accent: #0a66c2;
}

/* Use your theme's font */
body .irp .irp__heading,
body .irp .irp-card__title {
	font-family: inherit;
}

/* Remove the 20px indent on the cards */
body .irp .irp__list {
	margin-inline: 0;
}
```

To change colours for a single block, use the **Colours** panel in its settings: card background, card text and heading.

## For developers

By default only standard posts can be linked. To allow other post types, use the `irp_post_types` filter:

```php
add_filter( 'irp_post_types', function ( $types ) {
	$types[] = 'page';
	return $types;
} );
```

The block is rendered on the server (`block/render.php`), so articles only store the post IDs and settings. The editor script (`block/editor.js`) uses WordPress's global `wp.*` packages directly, so you can edit it with no build step. The search limits are the `IRP_RECENT_LIMIT` and `IRP_SEARCH_LIMIT` constants in `inline-related-post.php`.

If you remove the plugin, cards stop appearing on your site. The block markup stays in your articles, and the editor will show it as an unsupported block.

## Testing so far

The plugin has been used on one live site (TechLatest) and tested on a local WordPress Playground site running the latest WordPress with PHP 8.3. It has no automated tests, and it hasn't been tried with older WordPress or PHP versions, with other themes beyond those two sites, or in the site editor. Please open an issue if you find a problem.

## Licence

[GPL-2.0-or-later](LICENSE)
