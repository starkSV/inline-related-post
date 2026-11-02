<?php
/**
 * Plugin Name:       Inline Related Post
 * Plugin URI:        https://github.com/starkSV/inline-related-post
 * Description:       A "Related Post" block for placing hand-picked related article cards inside an article.
 * Version:           0.3.2
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Shekhar Vaidya
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       inline-related-post
 *
 * @package InlineRelatedPost
 */

defined( 'ABSPATH' ) || exit;

// Posts listed before anything is typed, and the most a search returns.
const IRP_RECENT_LIMIT = 5;
const IRP_SEARCH_LIMIT = 50;

add_action( 'init', 'irp_register_block' );
add_action( 'rest_api_init', 'irp_register_routes' );

/**
 * Registers the block from its block.json.
 */
function irp_register_block() {
	register_block_type( __DIR__ . '/block' );
}

/**
 * Post types the block can link to.
 *
 * @return string[]
 */
function irp_post_types() {
	return (array) apply_filters( 'irp_post_types', array( 'post' ) );
}

/**
 * Registers the editor's search endpoint.
 */
function irp_register_routes() {
	register_rest_route(
		'inline-related-post/v1',
		'/posts',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'irp_rest_posts',
			'permission_callback' => static function () {
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'search'  => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'ids'     => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'exclude' => array(
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}

/**
 * Returns the posts behind a selection (`ids`) or matching a search.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function irp_rest_posts( $request ) {
	if ( '' !== $request['ids'] ) {
		$items = array_map( 'irp_rest_item_for_id', wp_parse_id_list( $request['ids'] ) );
		return rest_ensure_response( $items );
	}

	$posts = irp_search_posts( trim( $request['search'] ), (int) $request['exclude'] );
	return rest_ensure_response( array_map( 'irp_rest_item', $posts ) );
}

/**
 * Finds published posts by ID, URL or keyword. An empty search returns recent posts.
 *
 * @param string $search  Search text.
 * @param int    $exclude Post ID to leave out.
 * @return WP_Post[]
 */
function irp_search_posts( $search, $exclude ) {
	if ( '' === $search ) {
		return get_posts(
			array(
				'post_type'           => irp_post_types(),
				'post_status'         => 'publish',
				'has_password'        => false,
				'posts_per_page'      => IRP_RECENT_LIMIT,
				'post__not_in'        => array_filter( array( $exclude ) ),
				'ignore_sticky_posts' => true,
			)
		);
	}

	$is_url     = (bool) preg_match( '#^https?://#i', $search );
	$matched_id = 0;

	if ( ctype_digit( $search ) ) {
		$matched_id = (int) $search;
	} elseif ( $is_url ) {
		$matched_id = url_to_postid( $search );
	}

	$posts = array();

	if ( $matched_id && $matched_id !== $exclude && irp_is_available( get_post( $matched_id ) ) ) {
		$posts[] = get_post( $matched_id );
	}

	// A URL points at one post, so there's nothing to search for in its text.
	if ( $is_url ) {
		return $posts;
	}

	$skip_ids = array_filter( array( $exclude, $matched_id ) );
	$limit    = IRP_SEARCH_LIMIT - count( $posts );
	$ids      = irp_rank_titles( $search, irp_title_rows( $skip_ids ), $limit );

	if ( count( $ids ) < $limit ) {
		$ids = array_merge(
			$ids,
			irp_search_content( $search, array_merge( $skip_ids, $ids ), $limit - count( $ids ) )
		);
	}

	if ( ! $ids ) {
		return $posts;
	}

	$found = get_posts(
		array(
			'post__in'            => $ids,
			'orderby'             => 'post__in',
			'post_type'           => irp_post_types(),
			'post_status'         => 'publish',
			'posts_per_page'      => count( $ids ),
			'ignore_sticky_posts' => true,
		)
	);

	return array_merge( $posts, $found );
}

/**
 * Loads the ID and title of every searchable post, newest first.
 *
 * @param int[] $skip_ids Post IDs to leave out.
 * @return object[] Rows with ID and post_title.
 */
function irp_title_rows( $skip_ids ) {
	global $wpdb;

	$types        = irp_post_types();
	$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_title FROM {$wpdb->posts}
			WHERE post_status = 'publish' AND post_password = '' AND post_type IN ( $placeholders )
			ORDER BY post_date DESC",
			$types
		)
	);
	// phpcs:enable

	return array_filter(
		$rows,
		static function ( $row ) use ( $skip_ids ) {
			return ! in_array( (int) $row->ID, $skip_ids, true );
		}
	);
}

/**
 * Ranks posts by how well their titles match a search, tolerating typos.
 * Equally good matches stay newest first.
 *
 * @param string   $search Search text.
 * @param object[] $rows   Rows with ID and post_title, newest first.
 * @param int      $limit  Maximum results.
 * @return int[] Post IDs, best match first.
 */
function irp_rank_titles( $search, $rows, $limit ) {
	$tokens = irp_search_terms( $search );
	$phrase = ' ' . implode( ' ', irp_tokenize( $search ) ) . ' ';

	if ( ! $tokens ) {
		return array();
	}

	// Most words have to match, so one common word can't carry a long search.
	$required = (int) ceil( count( $tokens ) * 0.6 );
	$memo     = array();
	$scored   = array();

	foreach ( array_values( $rows ) as $position => $row ) {
		$words   = irp_tokenize( $row->post_title );
		$unique  = array_unique( $words );
		$total   = 0;
		$matched = 0;

		foreach ( $tokens as $token ) {
			$best = 0;
			foreach ( $unique as $word ) {
				if ( ! isset( $memo[ $token ][ $word ] ) ) {
					$memo[ $token ][ $word ] = irp_word_score( $token, $word );
				}
				$best = max( $best, $memo[ $token ][ $word ] );
			}
			$total   += $best;
			$matched += $best > 0 ? 1 : 0;
		}

		if ( $matched < $required ) {
			continue;
		}

		if ( count( $tokens ) > 1 && false !== strpos( ' ' . implode( ' ', $words ) . ' ', $phrase ) ) {
			$total += 100;
		}

		$scored[] = array( $total, $position, (int) $row->ID );
	}

	usort(
		$scored,
		static function ( $a, $b ) {
			return array( $b[0], $a[1] ) <=> array( $a[0], $b[1] );
		}
	);

	return array_column( array_slice( $scored, 0, $limit ), 2 );
}

/**
 * Scores how well a search word matches a title word. 0 means no match.
 *
 * @param string $token Search word.
 * @param string $word  Title word.
 * @return int
 */
function irp_word_score( $token, $word ) {
	if ( $token === $word ) {
		return 100;
	}

	$length = strlen( $token );

	if ( $length >= 2 && 0 === strpos( $word, $token ) ) {
		return 70;
	}

	// Short words and model numbers ("s24", "2026") are too easy to confuse.
	$allowed  = 0;
	$distance = PHP_INT_MAX;

	if ( $length >= 4 && $length <= 64 && ! preg_match( '/\d/', $token ) ) {
		$allowed = $length < 6 ? 1 : 2;
	}

	if ( $allowed && abs( strlen( $word ) - $length ) <= $allowed && strlen( $word ) <= 64 ) {
		$distance = levenshtein( $token, $word );
	}

	if ( 1 === $distance ) {
		return 50;
	}

	if ( $length >= 3 && false !== strpos( $word, $token ) ) {
		return 40;
	}

	return $distance <= $allowed ? 30 : 0;
}

/**
 * Splits text into lowercase words without accents.
 *
 * @param string $text Text.
 * @return string[]
 */
function irp_tokenize( $text ) {
	$text = remove_accents( $text );
	$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );

	return preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
}

/**
 * The words of a search that matter, ignoring filler unless it's all there is.
 *
 * @param string $search Search text.
 * @return string[]
 */
function irp_search_terms( $search ) {
	$filler = array( 'a', 'an', 'and', 'are', 'at', 'by', 'for', 'from', 'how', 'in', 'is', 'it', 'of', 'on', 'or', 's', 'the', 'to', 'vs', 'what', 'when', 'where', 'which', 'why', 'with', 'you', 'your' );
	$tokens = array_values( array_unique( irp_tokenize( $search ) ) );
	$terms  = array_values( array_diff( $tokens, $filler ) );

	return $terms ?: $tokens;
}

/**
 * Finds posts mentioning every search word in their text as a whole word.
 * WordPress's search also matches inside words ("ai" in "chair"), so its
 * results are checked again here.
 *
 * @param string $search   Search text.
 * @param int[]  $skip_ids Post IDs to leave out.
 * @param int    $limit    Maximum results.
 * @return int[] Post IDs.
 */
function irp_search_content( $search, $skip_ids, $limit ) {
	$patterns = array();

	foreach ( irp_search_terms( $search ) as $term ) {
		$patterns[] = '/(?<![\p{L}\p{N}])' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}])/iu';
	}

	$candidates = get_posts(
		array(
			's'                   => $search,
			'post_type'           => irp_post_types(),
			'post_status'         => 'publish',
			'has_password'        => false,
			'posts_per_page'      => 40,
			'post__not_in'        => $skip_ids,
			'ignore_sticky_posts' => true,
		)
	);

	$ids = array();

	foreach ( $candidates as $post ) {
		$text = wp_strip_all_tags( $post->post_excerpt . ' ' . $post->post_content );
		foreach ( $patterns as $pattern ) {
			if ( ! preg_match( $pattern, $text ) ) {
				continue 2;
			}
		}
		$ids[] = $post->ID;
	}

	return array_slice( $ids, 0, $limit );
}

/**
 * Whether a post can be shown to readers.
 *
 * @param WP_Post|null $post Post.
 * @return bool
 */
function irp_is_available( $post ) {
	return $post
		&& 'publish' === $post->post_status
		&& in_array( $post->post_type, irp_post_types(), true );
}

/**
 * Shapes a selected post for the editor, flagging any readers can't see.
 *
 * @param int $id Post ID.
 * @return array
 */
function irp_rest_item_for_id( $id ) {
	$post = get_post( $id );

	if ( irp_is_available( $post ) ) {
		return irp_rest_item( $post );
	}

	return array(
		'id'        => $id,
		/* translators: %d: post ID. */
		'title'     => sprintf( __( 'Post #%d', 'inline-related-post' ), $id ),
		'available' => false,
	);
}

/**
 * Shapes a post for the editor.
 *
 * @param WP_Post $post Post.
 * @return array
 */
function irp_rest_item( $post ) {
	return array(
		'id'        => $post->ID,
		'title'     => html_entity_decode( get_the_title( $post ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
		'available' => true,
	);
}

/**
 * Makes a colour from the block settings safe to place in a style attribute.
 *
 * @param mixed $color Colour value.
 * @return string Empty when the value isn't a plain colour.
 */
function irp_sanitize_color( $color ) {
	$pattern = '/^(#[0-9a-f]{3,8}|(rgb|hsl)a?\([\d\s.,%\/]+\)|var\(--[\w-]+\))$/i';
	return is_string( $color ) && preg_match( $pattern, $color ) ? $color : '';
}

/**
 * Renders one related post card.
 *
 * @param WP_Post $post       Post.
 * @param array   $attributes Block attributes.
 * @return string
 */
function irp_render_card( $post, $attributes ) {
	$is_compact = 'multiple' === $attributes['mode'] && 'compact' === $attributes['layout'];
	$image      = '';
	$meta       = array();

	if ( $attributes['showImage'] && has_post_thumbnail( $post ) ) {
		$image = sprintf(
			'<div class="irp-card__image">%s</div>',
			get_the_post_thumbnail(
				$post,
				$is_compact ? 'thumbnail' : 'medium_large',
				array(
					'alt'     => '',
					'loading' => 'lazy',
					'sizes'   => $is_compact ? '96px' : '(max-width: 600px) 40vw, 400px',
				)
			)
		);
	}

	if ( $attributes['showAuthor'] ) {
		$meta[] = sprintf(
			/* translators: %s: linked author name. */
			esc_html__( 'By %s', 'inline-related-post' ),
			sprintf(
				'<a class="irp-card__author" href="%s">%s</a>',
				esc_url( get_author_posts_url( $post->post_author ) ),
				esc_html( get_the_author_meta( 'display_name', $post->post_author ) )
			)
		);
	}

	if ( $attributes['showDate'] ) {
		$meta[] = sprintf(
			'<time datetime="%s">%s</time>',
			esc_attr( get_the_date( 'c', $post ) ),
			esc_html( get_the_date( '', $post ) )
		);
	}

	$excerpt = '';

	if ( $attributes['showExcerpt'] && ! $is_compact && ! $post->post_password ) {
		$excerpt = wp_trim_words( get_the_excerpt( $post ), 25 );
	}

	return sprintf(
		'<article class="irp-card">%s<div class="irp-card__body"><div class="irp-card__title"><a href="%s">%s</a></div>%s%s</div></article>',
		$image,
		esc_url( get_permalink( $post ) ),
		esc_html( wp_strip_all_tags( get_the_title( $post ) ) ),
		$excerpt ? '<div class="irp-card__excerpt">' . esc_html( $excerpt ) . '</div>' : '',
		$meta ? '<div class="irp-card__meta">' . implode( ' <span aria-hidden="true">&middot;</span> ', $meta ) . '</div>' : ''
	);
}
