<?php
/**
 * Front-end output of the Related Post block.
 *
 * @package InlineRelatedPost
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$irp_is_multiple = 'multiple' === $attributes['mode'];
$irp_ids         = wp_parse_id_list( $attributes['postIds'] );
$irp_ids         = $irp_is_multiple ? $irp_ids : array_slice( $irp_ids, 0, 1 );
$irp_posts       = array_filter( array_map( 'get_post', $irp_ids ), 'irp_is_available' );

// Deleted or unpublished posts are dropped, and an empty block outputs nothing.
if ( ! $irp_posts ) {
	return;
}

$irp_layout = $irp_is_multiple && in_array( $attributes['layout'], array( 'grid', 'compact' ), true )
	? $attributes['layout']
	: 'stacked';

$irp_style = '';
foreach (
	array(
		'--irp-card-bg'   => 'cardBackground',
		'--irp-card-text' => 'cardText',
		'--irp-heading'   => 'headingColor',
	) as $irp_property => $irp_attribute
) {
	$irp_color = irp_sanitize_color( $attributes[ $irp_attribute ] ?? '' );
	if ( $irp_color ) {
		$irp_style .= $irp_property . ':' . $irp_color . ';';
	}
}

$irp_wrapper = get_block_wrapper_attributes( array( 'class' => 'irp irp--' . $irp_layout ) );

// Added separately because the wrapper helper strips rgb() and hsl() values.
if ( $irp_style ) {
	$irp_wrapper .= ' style="' . esc_attr( $irp_style ) . '"';
}
?>
<aside <?php echo $irp_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( '' !== trim( $attributes['heading'] ) ) : ?>
		<div class="irp__heading"><?php echo esc_html( $attributes['heading'] ); ?></div>
	<?php endif; ?>
	<div class="irp__list">
		<?php
		foreach ( $irp_posts as $irp_post ) {
			echo irp_render_card( $irp_post, $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
	</div>
</aside>
