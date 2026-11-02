<?php
/**
 * Script dependencies for the block editor. The script uses WordPress's own
 * globals directly, so the plugin has no build step.
 *
 * @package InlineRelatedPost
 */

return array(
	'dependencies' => array(
		'wp-api-fetch',
		'wp-block-editor',
		'wp-blocks',
		'wp-components',
		'wp-data',
		'wp-element',
		'wp-i18n',
		'wp-server-side-render',
		'wp-url',
	),
	'version'      => '0.3.2',
);
