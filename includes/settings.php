<?php
/**
 * Site-wide defaults for the block: storage, the Settings page and its preview.
 *
 * @package InlineRelatedPost
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'irp_maybe_create_settings', 5 );
add_action( 'init', 'irp_add_custom_css', 20 );
add_action( 'admin_init', 'irp_register_setting' );
add_action( 'admin_menu', 'irp_add_settings_page' );
add_action( 'admin_enqueue_scripts', 'irp_enqueue_settings_assets' );
add_action( 'enqueue_block_editor_assets', 'irp_share_settings_with_editor' );
add_action( 'template_redirect', 'irp_render_settings_preview' );
add_filter( 'plugin_action_links_inline-related-post/inline-related-post.php', 'irp_add_settings_link' );

/**
 * The values a new install starts with.
 *
 * @return array
 */
function irp_default_settings() {
	return array(
		'mode'           => 'single',
		'layout'         => 'stacked',
		'heading'        => __( 'Related', 'inline-related-post' ),
		'showExcerpt'    => true,
		'showAuthor'     => true,
		'showDate'       => true,
		'showImage'      => true,
		'indent'         => 20,
		'accentColor'    => '#d90429',
		'headingColor'   => '',
		'cardBackground' => '',
		'cardText'       => '',
		'fontMode'       => 'system',
		'fontFamily'     => '',
		'customCss'      => '',
	);
}

/**
 * Stores the settings the first time the plugin runs. Sites already using the
 * block had Poppins hardcoded before this page existed, so they keep it.
 */
function irp_maybe_create_settings() {
	global $wpdb;

	if ( false !== get_option( 'irp_settings' ) ) {
		return;
	}

	$settings = irp_default_settings();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$is_in_use = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s LIMIT 1",
			'%' . $wpdb->esc_like( 'wp:inline-related-post/related-post' ) . '%'
		)
	);

	if ( $is_in_use ) {
		$settings['fontMode']   = 'custom';
		$settings['fontFamily'] = 'Poppins';
	}

	add_option( 'irp_settings', $settings );
}

/**
 * The saved settings, with any missing values filled in.
 *
 * @return array
 */
function irp_get_settings() {
	$settings = wp_parse_args( (array) get_option( 'irp_settings', array() ), irp_default_settings() );
	return apply_filters( 'irp_settings', $settings );
}

/**
 * Cleans custom CSS. Only people allowed to post unfiltered HTML can change it,
 * and nothing that could close the style tag gets through.
 *
 * @param array $input Submitted values.
 * @return string
 */
function irp_sanitize_custom_css( $input ) {
	if ( ! current_user_can( 'unfiltered_html' ) ) {
		$saved = (array) get_option( 'irp_settings', array() );
		return $saved['customCss'] ?? '';
	}

	$css = isset( $input['customCss'] ) ? (string) $input['customCss'] : '';
	return trim( str_replace( '</', '', wp_strip_all_tags( $css ) ) );
}

/**
 * Cleans submitted settings. Unticked checkboxes are simply missing.
 *
 * @param mixed $input Submitted values.
 * @return array
 */
function irp_sanitize_settings( $input ) {
	$input    = (array) $input;
	$defaults = irp_default_settings();
	$font     = isset( $input['fontFamily'] ) ? trim( (string) $input['fontFamily'] ) : '';

	return array(
		'mode'           => isset( $input['mode'] ) && 'multiple' === $input['mode'] ? 'multiple' : 'single',
		'layout'         => isset( $input['layout'] ) && in_array( $input['layout'], array( 'grid', 'compact' ), true ) ? $input['layout'] : 'stacked',
		'heading'        => isset( $input['heading'] ) ? sanitize_text_field( $input['heading'] ) : '',
		'showExcerpt'    => ! empty( $input['showExcerpt'] ),
		'showAuthor'     => ! empty( $input['showAuthor'] ),
		'showDate'       => ! empty( $input['showDate'] ),
		'showImage'      => ! empty( $input['showImage'] ),
		'indent'         => isset( $input['indent'] ) ? min( 100, absint( $input['indent'] ) ) : $defaults['indent'],
		'accentColor'    => irp_sanitize_color( $input['accentColor'] ?? '' ) ?: $defaults['accentColor'],
		'headingColor'   => irp_sanitize_color( $input['headingColor'] ?? '' ),
		'cardBackground' => irp_sanitize_color( $input['cardBackground'] ?? '' ),
		'cardText'       => irp_sanitize_color( $input['cardText'] ?? '' ),
		'fontMode'       => isset( $input['fontMode'] ) && 'custom' === $input['fontMode'] ? 'custom' : 'system',
		// A font name is only ever placed inside a quoted CSS value.
		'fontFamily'     => preg_match( '/^[\p{L}\p{N} _-]{1,64}$/u', $font ) ? $font : '',
		'customCss'      => irp_sanitize_custom_css( $input ),
	);
}

/**
 * Works out what a block should show: its own choices first, then the site's.
 * Mode and layout don't follow the site, so changing those defaults can't
 * rearrange cards already placed in articles.
 *
 * @param array $attributes Block attributes.
 * @return array
 */
function irp_resolve_attributes( $attributes ) {
	$settings = irp_get_settings();
	$resolved = $attributes;

	$resolved['mode']   = $attributes['mode'] ?? 'single';
	$resolved['layout'] = $attributes['layout'] ?? 'stacked';

	foreach ( array( 'heading', 'showExcerpt', 'showAuthor', 'showDate', 'showImage' ) as $key ) {
		$resolved[ $key ] = $attributes[ $key ] ?? $settings[ $key ];
	}

	foreach ( array( 'accentColor', 'headingColor', 'cardBackground', 'cardText' ) as $key ) {
		$resolved[ $key ] = irp_sanitize_color( $attributes[ $key ] ?? '' ) ?: $settings[ $key ];
	}

	$resolved['indent']     = $settings['indent'];
	$resolved['fontFamily'] = 'custom' === $settings['fontMode'] ? $settings['fontFamily'] : '';

	return $resolved;
}

/**
 * Builds the CSS variables for a block's wrapper.
 *
 * @param array $resolved Attributes from irp_resolve_attributes().
 * @return string
 */
function irp_block_style( $resolved ) {
	$style = '--irp-indent:' . absint( $resolved['indent'] ) . 'px;';

	foreach (
		array(
			'--irp-accent'    => 'accentColor',
			'--irp-heading'   => 'headingColor',
			'--irp-card-bg'   => 'cardBackground',
			'--irp-card-text' => 'cardText',
		) as $property => $key
	) {
		$color = irp_sanitize_color( $resolved[ $key ] );
		if ( $color ) {
			$style .= $property . ':' . $color . ';';
		}
	}

	if ( $resolved['fontFamily'] ) {
		$style .= '--irp-font:"' . $resolved['fontFamily'] . '",sans-serif;';
	}

	return $style;
}

/**
 * Attaches the custom CSS to the block's stylesheet, so it loads alongside it.
 * The preview prints its own copy, to show unsaved edits.
 */
function irp_add_custom_css() {
	$css = irp_get_settings()['customCss'];

	if ( '' !== $css && empty( $_GET['irp_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_add_inline_style( generate_block_asset_handle( 'inline-related-post/related-post', 'style' ), $css );
	}
}

/**
 * Registers the settings with WordPress.
 */
function irp_register_setting() {
	register_setting(
		'irp_settings_group',
		'irp_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'irp_sanitize_settings',
		)
	);
}

/**
 * Adds Settings → Related Post.
 */
function irp_add_settings_page() {
	add_options_page(
		__( 'Related Post', 'inline-related-post' ),
		__( 'Related Post', 'inline-related-post' ),
		'manage_options',
		'inline-related-post',
		'irp_render_settings_page'
	);
}

/**
 * Adds a Settings link on the Plugins screen.
 *
 * @param string[] $links Plugin action links.
 * @return string[]
 */
function irp_add_settings_link( $links ) {
	array_unshift(
		$links,
		sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=inline-related-post' ) ),
			esc_html__( 'Settings', 'inline-related-post' )
		)
	);
	return $links;
}

/**
 * The address of the preview, which only works for the logged-in admin.
 *
 * @return string
 */
function irp_preview_url() {
	return add_query_arg(
		array(
			'irp_preview' => 1,
			'_wpnonce'    => wp_create_nonce( 'irp_preview' ),
		),
		home_url( '/' )
	);
}

/**
 * Loads the Settings page's styles and script.
 *
 * @param string $hook Current admin page.
 */
function irp_enqueue_settings_assets( $hook ) {
	if ( 'settings_page_inline-related-post' !== $hook ) {
		return;
	}

	$root   = dirname( __DIR__ );
	$url    = plugin_dir_url( __DIR__ );
	$latest = get_posts(
		array(
			'post_type'      => irp_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);

	$font_pages = array( home_url( '/' ) );
	if ( $latest ) {
		$font_pages[] = get_permalink( $latest[0] );
	}

	// False when the user has turned syntax highlighting off, leaving a plain box.
	$code_editor = wp_enqueue_code_editor( array( 'type' => 'text/css' ) );

	wp_enqueue_style( 'irp-settings', $url . 'admin/settings.css', array(), filemtime( $root . '/admin/settings.css' ) );
	wp_enqueue_script( 'irp-settings', $url . 'admin/settings.js', array(), filemtime( $root . '/admin/settings.js' ), true );
	wp_localize_script(
		'irp-settings',
		'irpSettingsPage',
		array(
			'previewUrl' => irp_preview_url(),
			'fontPages'  => $font_pages,
			'codeEditor' => $code_editor,
			'text'       => array(
				'searching' => __( 'Looking for the fonts your site loads…', 'inline-related-post' ),
				/* translators: %d: number of fonts found. */
				'found'     => __( 'Found %d fonts on your site.', 'inline-related-post' ),
				'notFound'  => __( 'No fonts could be found automatically. Choose Other and type the name.', 'inline-related-post' ),
				'other'     => __( 'Other (type a name)', 'inline-related-post' ),
				'choose'    => __( 'Choose a font', 'inline-related-post' ),
				'saved'     => __( 'All changes saved', 'inline-related-post' ),
				'unsaved'   => __( 'Unsaved changes', 'inline-related-post' ),
			),
		)
	);
}

/**
 * Tells the block editor the site's defaults.
 */
function irp_share_settings_with_editor() {
	wp_add_inline_script(
		generate_block_asset_handle( 'inline-related-post/related-post', 'editorScript' ),
		'window.irpSettings = ' . wp_json_encode( irp_get_settings() ) . ';',
		'before'
	);
}

/**
 * Outputs one toggle switch.
 *
 * @param string $key      Setting name.
 * @param string $label    Label.
 * @param bool   $checked  Whether it's on.
 */
function irp_toggle_field( $key, $label, $checked ) {
	printf(
		'<label class="irp-field"><span class="irp-field__label">%1$s</span><span class="irp-toggle"><input type="checkbox" name="irp_settings[%2$s]" value="1" %3$s><i aria-hidden="true"></i></span></label>',
		esc_html( $label ),
		esc_attr( $key ),
		checked( $checked, true, false )
	);
}

/**
 * Outputs one colour control: a swatch, an editable value and a clear button.
 *
 * @param string $key         Setting name.
 * @param string $label       Label.
 * @param string $value       Current colour.
 * @param string $placeholder Shown when no colour is set.
 */
function irp_color_field( $key, $label, $value, $placeholder ) {
	?>
	<div class="irp-field">
		<label class="irp-field__label" for="irp-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
		<span class="irp-color">
			<input id="irp-<?php echo esc_attr( $key ); ?>" class="irp-color__value irp-input" type="text" name="irp_settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>">
			<span class="irp-color__swatch"><input type="color" tabindex="-1" aria-label="<?php echo esc_attr( $label ); ?>"></span>
			<button type="button" class="irp-color__clear" aria-label="<?php esc_attr_e( 'Clear colour', 'inline-related-post' ); ?>">&times;</button>
		</span>
	</div>
	<?php
}

/**
 * Outputs the Settings page.
 */
function irp_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = irp_get_settings();
	$layouts  = array(
		'stacked' => __( 'Stacked', 'inline-related-post' ),
		'grid'    => __( 'Grid', 'inline-related-post' ),
		'compact' => __( 'Compact', 'inline-related-post' ),
	);
	?>
	<div class="wrap irp-admin" style="--irp-ui-accent:<?php echo esc_attr( $settings['accentColor'] ); ?>">
		<h1>
			<?php esc_html_e( 'Related Post', 'inline-related-post' ); ?>
			<small class="irp-admin__by">
				<?php
				printf(
					/* translators: %s: linked author name. */
					esc_html__( 'by %s', 'inline-related-post' ),
					'<a href="https://github.com/starkSV" target="_blank" rel="noopener">Shekhar Vaidya</a>'
				);
				?>
			</small>
		</h1>
		<p class="irp-admin__intro"><?php esc_html_e( 'Defaults for every block on your site. You can still change most of them on a single block in its sidebar.', 'inline-related-post' ); ?></p>

		<form method="post" action="options.php" id="irp-settings-form" class="irp-admin__columns">
			<?php settings_fields( 'irp_settings_group' ); ?>

			<div class="irp-admin__main">
				<section class="irp-panel">
					<h2><?php esc_html_e( 'Cards', 'inline-related-post' ); ?></h2>

					<div class="irp-field">
						<span class="irp-field__label">
							<?php esc_html_e( 'Mode', 'inline-related-post' ); ?>
							<small><?php esc_html_e( 'Applies to new blocks. Cards already in your articles keep theirs.', 'inline-related-post' ); ?></small>
						</span>
						<span class="irp-segmented" role="radiogroup" aria-label="<?php esc_attr_e( 'Mode', 'inline-related-post' ); ?>">
							<label><input type="radio" name="irp_settings[mode]" value="single" <?php checked( $settings['mode'], 'single' ); ?>><span><?php esc_html_e( 'Single', 'inline-related-post' ); ?></span></label>
							<label><input type="radio" name="irp_settings[mode]" value="multiple" <?php checked( $settings['mode'], 'multiple' ); ?>><span><?php esc_html_e( 'Multiple', 'inline-related-post' ); ?></span></label>
						</span>
					</div>

					<div class="irp-field irp-field--stacked" id="irp-layout-row" <?php echo 'multiple' === $settings['mode'] ? '' : 'hidden'; ?>>
						<span class="irp-field__label"><?php esc_html_e( 'Multiple layout', 'inline-related-post' ); ?></span>
						<div class="irp-layouts" role="radiogroup" aria-label="<?php esc_attr_e( 'Multiple layout', 'inline-related-post' ); ?>">
							<?php foreach ( $layouts as $value => $label ) : ?>
								<label class="irp-layout irp-layout--<?php echo esc_attr( $value ); ?>">
									<input type="radio" name="irp_settings[layout]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $settings['layout'], $value ); ?>>
									<span><i aria-hidden="true"></i><?php echo esc_html( $label ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>

					<?php
					irp_toggle_field( 'showExcerpt', __( 'Excerpt', 'inline-related-post' ), $settings['showExcerpt'] );
					irp_toggle_field( 'showAuthor', __( 'Author', 'inline-related-post' ), $settings['showAuthor'] );
					irp_toggle_field( 'showDate', __( 'Date', 'inline-related-post' ), $settings['showDate'] );
					irp_toggle_field( 'showImage', __( 'Image', 'inline-related-post' ), $settings['showImage'] );
					?>

					<div class="irp-field">
						<label class="irp-field__label" for="irp-indent">
							<?php esc_html_e( 'Card indent', 'inline-related-post' ); ?>
							<small><?php esc_html_e( 'Always 0 on phones and in narrow columns.', 'inline-related-post' ); ?></small>
						</label>
						<span class="irp-number"><input id="irp-indent" class="irp-input" type="number" min="0" max="100" name="irp_settings[indent]" value="<?php echo esc_attr( $settings['indent'] ); ?>"><em>px</em></span>
					</div>
				</section>

				<section class="irp-panel">
					<h2><?php esc_html_e( 'Style', 'inline-related-post' ); ?></h2>

					<div class="irp-field">
						<label class="irp-field__label" for="irp-heading">
							<?php esc_html_e( 'Heading text', 'inline-related-post' ); ?>
							<small><?php esc_html_e( 'Leave empty to hide the heading.', 'inline-related-post' ); ?></small>
						</label>
						<input id="irp-heading" class="irp-input" type="text" name="irp_settings[heading]" value="<?php echo esc_attr( $settings['heading'] ); ?>">
					</div>

					<?php
					irp_color_field( 'accentColor', __( 'Accent', 'inline-related-post' ), $settings['accentColor'], '#d90429' );
					irp_color_field( 'headingColor', __( 'Heading colour', 'inline-related-post' ), $settings['headingColor'], __( 'Same as accent', 'inline-related-post' ) );
					irp_color_field( 'cardBackground', __( 'Card background', 'inline-related-post' ), $settings['cardBackground'], __( 'Neutral grey', 'inline-related-post' ) );
					irp_color_field( 'cardText', __( 'Card text', 'inline-related-post' ), $settings['cardText'], __( 'Theme colour', 'inline-related-post' ) );
					?>

					<div class="irp-field">
						<label class="irp-field__label" for="irp-font-mode">
							<?php esc_html_e( 'Font', 'inline-related-post' ); ?>
							<small><?php esc_html_e( 'For the heading and card titles.', 'inline-related-post' ); ?></small>
						</label>
						<select id="irp-font-mode" class="irp-input" name="irp_settings[fontMode]">
							<option value="system" <?php selected( $settings['fontMode'], 'system' ); ?>><?php esc_html_e( 'System (your theme’s font)', 'inline-related-post' ); ?></option>
							<option value="custom" <?php selected( $settings['fontMode'], 'custom' ); ?>><?php esc_html_e( 'Custom', 'inline-related-post' ); ?></option>
						</select>
					</div>

					<div class="irp-field irp-field--stacked" id="irp-font-row">
						<label class="irp-field__label" for="irp-font-picker"><?php esc_html_e( 'Custom font', 'inline-related-post' ); ?></label>
						<select id="irp-font-picker" class="irp-input"></select>
						<input id="irp-font-family" class="irp-input" type="text" name="irp_settings[fontFamily]" value="<?php echo esc_attr( $settings['fontFamily'] ); ?>" placeholder="<?php esc_attr_e( 'Font name', 'inline-related-post' ); ?>" aria-label="<?php esc_attr_e( 'Font name', 'inline-related-post' ); ?>">
						<p class="irp-hint" id="irp-font-status" aria-live="polite"></p>
						<p class="irp-hint"><?php esc_html_e( 'The plugin doesn’t load fonts. It lists the ones your site already loads.', 'inline-related-post' ); ?></p>
					</div>
				</section>

				<section class="irp-panel">
					<h2><?php esc_html_e( 'Custom CSS', 'inline-related-post' ); ?></h2>
					<p class="irp-hint"><?php esc_html_e( 'Added after the plugin’s styles, for every block on your site. Cards use classes like .irp, .irp__heading, .irp-card and .irp-card__title.', 'inline-related-post' ); ?></p>
					<?php if ( current_user_can( 'unfiltered_html' ) ) : ?>
						<textarea id="irp-custom-css" class="irp-code" name="irp_settings[customCss]" rows="10" spellcheck="false" aria-label="<?php esc_attr_e( 'Custom CSS', 'inline-related-post' ); ?>"><?php echo esc_textarea( $settings['customCss'] ); ?></textarea>
					<?php else : ?>
						<textarea class="irp-code" rows="6" readonly aria-label="<?php esc_attr_e( 'Custom CSS', 'inline-related-post' ); ?>"><?php echo esc_textarea( $settings['customCss'] ); ?></textarea>
						<p class="irp-hint"><?php esc_html_e( 'Your account doesn’t have permission to change this.', 'inline-related-post' ); ?></p>
					<?php endif; ?>
				</section>

				<p class="irp-credit">
					<?php
					echo esc_html( 'Inline Related Post ' . get_file_data( dirname( __DIR__ ) . '/inline-related-post.php', array( 'Version' => 'Version' ) )['Version'] );
					echo ' &middot; ';
					printf(
						/* translators: %s: linked author name. */
						esc_html__( 'Made by %s', 'inline-related-post' ),
						'<a href="https://github.com/starkSV" target="_blank" rel="noopener">Shekhar Vaidya</a>'
					);
					echo ' &middot; ';
					?>
					<a href="https://github.com/starkSV/inline-related-post" target="_blank" rel="noopener"><?php esc_html_e( 'GitHub', 'inline-related-post' ); ?></a>
					&middot;
					<a href="https://github.com/starkSV/inline-related-post/issues" target="_blank" rel="noopener"><?php esc_html_e( 'Report an issue', 'inline-related-post' ); ?></a>
				</p>
			</div>

			<aside class="irp-admin__side">
				<div class="irp-preview">
					<div class="irp-preview__bar">
						<strong><?php esc_html_e( 'Preview', 'inline-related-post' ); ?></strong>
						<span class="irp-segmented" role="radiogroup" aria-label="<?php esc_attr_e( 'Preview size', 'inline-related-post' ); ?>">
							<label><input type="radio" name="irp-preview-device" value="desktop" checked><span><?php esc_html_e( 'Desktop', 'inline-related-post' ); ?></span></label>
							<label><input type="radio" name="irp-preview-device" value="phone"><span><?php esc_html_e( 'Phone', 'inline-related-post' ); ?></span></label>
						</span>
					</div>
					<div class="irp-preview__stage" id="irp-preview-stage">
						<iframe id="irp-preview-frame" title="<?php esc_attr_e( 'Preview of the related post cards', 'inline-related-post' ); ?>" src="<?php echo esc_url( irp_preview_url() ); ?>"></iframe>
					</div>
				</div>
				<div class="irp-savebar">
					<span class="irp-savebar__status" id="irp-save-status"><?php esc_html_e( 'All changes saved', 'inline-related-post' ); ?></span>
					<button type="submit" class="irp-savebar__button"><?php esc_html_e( 'Save changes', 'inline-related-post' ); ?></button>
				</div>
			</aside>
		</form>
	</div>
	<?php
}

/**
 * Serves the preview page, inside the site's theme so its fonts and styles apply.
 */
function irp_render_settings_preview() {
	if ( empty( $_GET['irp_preview'] ) ) {
		return;
	}

	$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

	if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, 'irp_preview' ) ) {
		wp_die( esc_html__( 'You can’t view this preview.', 'inline-related-post' ), 403 );
	}

	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	show_admin_bar( false );

	// Shows unsaved choices from the form. They're cleaned the same way as a save.
	$submitted = isset( $_GET['irp_settings'] ) ? json_decode( wp_unslash( $_GET['irp_settings'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	if ( is_array( $submitted ) ) {
		$unsaved = irp_sanitize_settings( $submitted );
		add_filter(
			'irp_settings',
			static function () use ( $unsaved ) {
				return $unsaved;
			}
		);
	}

	$settings = irp_get_settings();
	$ids      = get_posts(
		array(
			'post_type'      => irp_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => 'multiple' === $settings['mode'] ? 3 : 1,
			'fields'         => 'ids',
		)
	);

	add_action(
		'wp_enqueue_scripts',
		static function () {
			wp_enqueue_style( generate_block_asset_handle( 'inline-related-post/related-post', 'style' ) );
		}
	);

	// Stand-ins for article text, to show how the block lines up with it.
	$text_line = '<div style="height:12px;margin:0 0 14px;border-radius:6px;background:rgba(127,127,127,.18);"></div>';
	?>
	<!doctype html>
	<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="robots" content="noindex">
		<?php wp_head(); ?>
	</head>
	<body <?php body_class(); ?> style="margin:0;padding:24px;min-height:0;">
		<div id="irp-preview">
			<style><?php echo $settings['customCss']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>
			<?php
			if ( $ids ) {
				echo $text_line . $text_line; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo render_block(
					array(
						'blockName'    => 'inline-related-post/related-post',
						'attrs'        => array(
							'postIds' => $ids,
							'mode'    => $settings['mode'],
							'layout'  => $settings['layout'],
						),
						'innerBlocks'  => array(),
						'innerHTML'    => '',
						'innerContent' => array(),
					)
				);
				echo $text_line . $text_line; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				esc_html_e( 'Publish a post to see the preview.', 'inline-related-post' );
			}
			?>
		</div>
		<?php wp_footer(); ?>
	</body>
	</html>
	<?php
	exit;
}
