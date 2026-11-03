<?php
/**
 * Front-end output of the Related Post block.
 *
 * @package InlineRelatedPost
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$irp_attributes  = irp_resolve_attributes( $attributes );
$irp_is_multiple = 'multiple' === $irp_attributes['mode'];
$irp_ids         = wp_parse_id_list( $irp_attributes['postIds'] );
$irp_ids         = $irp_is_multiple ? $irp_ids : array_slice( $irp_ids, 0, 1 );
$irp_posts       = array_filter( array_map( 'get_post', $irp_ids ), 'irp_is_available' );

// Deleted or unpublished posts are dropped, and an empty block outputs nothing.
if ( ! $irp_posts ) {
	return;
}

$irp_layout = $irp_is_multiple && in_array( $irp_attributes['layout'], array( 'grid', 'compact' ), true )
	? $irp_attributes['layout']
	: 'stacked';

$irp_classes = 'irp irp--' . $irp_layout . ( $irp_attributes['cardBackground'] ? ' irp--has-card-bg' : '' );
$irp_wrapper = get_block_wrapper_attributes( array( 'class' => $irp_classes ) );

// Added separately because the wrapper helper strips rgb() and hsl() values.
$irp_wrapper .= ' style="' . esc_attr( irp_block_style( $irp_attributes ) ) . '"';
?>
<aside <?php echo $irp_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( '' !== trim( $irp_attributes['heading'] ) ) : ?>
		<div class="irp__heading"><?php echo esc_html( $irp_attributes['heading'] ); ?></div>
	<?php endif; ?>
	<div class="irp__list">
		<?php
		foreach ( $irp_posts as $irp_post ) {
			echo irp_render_card( $irp_post, $irp_attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
	</div>
</aside>
