( function ( wp ) {
	const { createElement: h, Fragment, useEffect, useState } = wp.element;
	const { __, sprintf } = wp.i18n;
	const { registerBlockType } = wp.blocks;
	const { InspectorControls, PanelColorSettings, useBlockProps } = wp.blockEditor;
	const { Button, CheckboxControl, Disabled, Notice, PanelBody, Placeholder, SearchControl, Spinner, TextControl } = wp.components;
	const ServerSideRender = wp.serverSideRender.ServerSideRender || wp.serverSideRender;
	const apiFetch = wp.apiFetch;
	const { addQueryArgs } = wp.url;

	const API_PATH = '/inline-related-post/v1/posts';
	const TEXT_DOMAIN = 'inline-related-post';

	function Segmented( { label, value, options, onChange } ) {
		return h(
			'div',
			{ className: 'irp-segmented', role: 'group', 'aria-label': label },
			options.map( ( option ) =>
				h(
					Button,
					{
						key: option.value,
						variant: option.value === value ? 'primary' : 'secondary',
						isPressed: option.value === value,
						onClick: () => onChange( option.value ),
					},
					option.label
				)
			)
		);
	}

	function PostSearch( { excludeIds, onPick } ) {
		const [ term, setTerm ] = useState( '' );
		const [ results, setResults ] = useState( [] );
		const [ loading, setLoading ] = useState( true );
		const [ failed, setFailed ] = useState( false );
		const currentPostId = wp.data.select( 'core/editor' )?.getCurrentPostId();

		useEffect( () => {
			let stale = false;
			setLoading( true );
			const timer = setTimeout( () => {
				apiFetch( {
					path: addQueryArgs( API_PATH, { search: term, exclude: currentPostId || 0 } ),
				} )
					.then( ( posts ) => {
						if ( ! stale ) {
							setResults( posts );
							setFailed( false );
							setLoading( false );
						}
					} )
					.catch( () => {
						if ( ! stale ) {
							setResults( [] );
							setFailed( true );
							setLoading( false );
						}
					} );
			}, 300 );
			return () => {
				stale = true;
				clearTimeout( timer );
			};
		}, [ term, currentPostId ] );

		const visible = results.filter( ( post ) => ! excludeIds.includes( post.id ) );
		let body;

		if ( loading ) {
			body = h( 'div', { className: 'irp-empty' }, h( Spinner ) );
		} else if ( failed ) {
			body = h( 'p', { className: 'irp-empty' }, __( 'Search failed. Please try again.', TEXT_DOMAIN ) );
		} else if ( ! visible.length ) {
			body = h( 'p', { className: 'irp-empty' }, __( 'No posts found.', TEXT_DOMAIN ) );
		} else {
			body = h(
				'div',
				{ className: 'irp-results' },
				visible.map( ( post ) =>
					h(
						Button,
						{
							key: post.id,
							onClick: () => {
								onPick( post.id );
								setTerm( '' );
							},
						},
						h( 'span', null, post.title ),
						h( 'small', null, '#' + post.id )
					)
				)
			);
		}

		return h(
			Fragment,
			null,
			h( SearchControl, {
				__nextHasNoMarginBottom: true,
				label: __( 'Search posts', TEXT_DOMAIN ),
				placeholder: __( 'Keyword, post ID or URL', TEXT_DOMAIN ),
				value: term,
				onChange: setTerm,
			} ),
			body
		);
	}

	function SelectedPosts( { ids, posts, canReorder, onChange } ) {
		const move = ( index, offset ) => {
			const next = ids.slice();
			next.splice( index + offset, 0, next.splice( index, 1 )[ 0 ] );
			onChange( next );
		};

		return h(
			'div',
			{ className: 'irp-selected' },
			ids.map( ( id, index ) => {
				const post = posts.find( ( item ) => item.id === id );
				const unavailable = post && ! post.available;
				return h(
					'div',
					{ key: id, className: 'irp-selected__item' + ( unavailable ? ' is-unavailable' : '' ) },
					h(
						'span',
						{ className: 'irp-selected__title' },
						post ? post.title : sprintf( __( 'Post #%d', TEXT_DOMAIN ), id ),
						unavailable ? ' — ' + __( 'unavailable', TEXT_DOMAIN ) : ''
					),
					canReorder &&
						h( Button, {
							icon: 'arrow-up-alt2',
							size: 'small',
							label: __( 'Move up', TEXT_DOMAIN ),
							disabled: index === 0,
							accessibleWhenDisabled: true,
							onClick: () => move( index, -1 ),
						} ),
					canReorder &&
						h( Button, {
							icon: 'arrow-down-alt2',
							size: 'small',
							label: __( 'Move down', TEXT_DOMAIN ),
							disabled: index === ids.length - 1,
							accessibleWhenDisabled: true,
							onClick: () => move( index, 1 ),
						} ),
					h( Button, {
						icon: 'no-alt',
						size: 'small',
						label: __( 'Remove', TEXT_DOMAIN ),
						onClick: () => onChange( ids.filter( ( other ) => other !== id ) ),
					} )
				);
			} )
		);
	}

	function Edit( { attributes, setAttributes, isSelected } ) {
		const { mode, layout, postIds, heading, showExcerpt, showAuthor, showDate, showImage, cardBackground, cardText, headingColor } = attributes;
		const isMultiple = mode === 'multiple';
		const isCompact = isMultiple && layout === 'compact';
		const activeIds = isMultiple ? postIds : postIds.slice( 0, 1 );
		const activeKey = activeIds.join( ',' );
		const [ selectedPosts, setSelectedPosts ] = useState( [] );
		const blockProps = useBlockProps();

		// Opens the settings sidebar when the block is first inserted.
		useEffect( () => {
			if ( isSelected && ! postIds.length ) {
				wp.data.dispatch( 'core/edit-post' )?.openGeneralSidebar( 'edit-post/block' );
			}
		}, [] );

		useEffect( () => {
			if ( ! activeKey ) {
				setSelectedPosts( [] );
				return undefined;
			}
			let stale = false;
			apiFetch( { path: addQueryArgs( API_PATH, { ids: activeKey } ) } )
				.then( ( posts ) => {
					if ( ! stale ) {
						// Keeps known titles on screen while the list changes.
						setSelectedPosts( ( current ) =>
							current.filter( ( post ) => ! posts.some( ( item ) => item.id === post.id ) ).concat( posts )
						);
					}
				} )
				.catch( () => {} );
			return () => {
				stale = true;
			};
		}, [ activeKey ] );

		const pickPost = ( id ) => setAttributes( { postIds: isMultiple ? postIds.concat( id ) : [ id ] } );
		const hasUnavailable = selectedPosts.some( ( post ) => ! post.available && activeIds.includes( post.id ) );

		const emptyPlaceholder = ( message ) =>
			h( Placeholder, { icon: 'excerpt-view', label: __( 'Related Post', TEXT_DOMAIN ), instructions: message } );

		const checkbox = ( label, key, extra ) =>
			h(
				CheckboxControl,
				Object.assign(
					{
						__nextHasNoMarginBottom: true,
						label,
						checked: attributes[ key ],
						onChange: ( value ) => setAttributes( { [ key ]: value } ),
					},
					extra
				)
			);

		return h(
			Fragment,
			null,
			h(
				InspectorControls,
				null,
				h(
					PanelBody,
					{ title: __( 'Related posts', TEXT_DOMAIN ) },
					h( Segmented, {
						label: __( 'Mode', TEXT_DOMAIN ),
						value: mode,
						options: [
							{ value: 'single', label: __( 'Single', TEXT_DOMAIN ) },
							{ value: 'multiple', label: __( 'Multiple', TEXT_DOMAIN ) },
						],
						onChange: ( value ) => setAttributes( { mode: value } ),
					} ),
					isMultiple &&
						h( Segmented, {
							label: __( 'Layout', TEXT_DOMAIN ),
							value: layout,
							options: [
								{ value: 'stacked', label: __( 'Stacked', TEXT_DOMAIN ) },
								{ value: 'grid', label: __( 'Grid', TEXT_DOMAIN ) },
								{ value: 'compact', label: __( 'Compact', TEXT_DOMAIN ) },
							],
							onChange: ( value ) => setAttributes( { layout: value } ),
						} ),
					activeIds.length > 0 &&
						h( SelectedPosts, {
							ids: activeIds,
							posts: selectedPosts,
							canReorder: isMultiple,
							onChange: ( ids ) => setAttributes( { postIds: ids } ),
						} ),
					h( PostSearch, { excludeIds: activeIds, onPick: pickPost } )
				),
				h(
					PanelBody,
					{ title: __( 'Display options', TEXT_DOMAIN ) },
					h( TextControl, {
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
						label: __( 'Heading', TEXT_DOMAIN ),
						help: __( 'Leave empty to hide the heading.', TEXT_DOMAIN ),
						value: heading,
						onChange: ( value ) => setAttributes( { heading: value } ),
					} ),
					h( 'div', { style: { height: 16 } } ),
					checkbox( __( 'Show Excerpt', TEXT_DOMAIN ), 'showExcerpt', {
						disabled: isCompact,
						help: isCompact ? __( 'Not shown in the Compact layout.', TEXT_DOMAIN ) : undefined,
					} ),
					checkbox( __( 'Show Author', TEXT_DOMAIN ), 'showAuthor' ),
					checkbox( __( 'Show Date', TEXT_DOMAIN ), 'showDate' ),
					checkbox( __( 'Show Image', TEXT_DOMAIN ), 'showImage' )
				),
				h( PanelColorSettings, {
					title: __( 'Colours', TEXT_DOMAIN ),
					initialOpen: false,
					colorSettings: [
						{
							label: __( 'Card background', TEXT_DOMAIN ),
							value: cardBackground,
							onChange: ( value ) => setAttributes( { cardBackground: value } ),
						},
						{
							label: __( 'Card text', TEXT_DOMAIN ),
							value: cardText,
							onChange: ( value ) => setAttributes( { cardText: value } ),
						},
						{
							label: __( 'Heading', TEXT_DOMAIN ),
							value: headingColor,
							onChange: ( value ) => setAttributes( { headingColor: value } ),
						},
					],
				} )
			),
			h(
				'div',
				blockProps,
				hasUnavailable &&
					h(
						Notice,
						{ className: 'irp-editor-notice', status: 'warning', isDismissible: false },
						__( 'A selected post was deleted or unpublished, so readers won’t see its card.', TEXT_DOMAIN )
					),
				activeIds.length
					? h(
							Disabled,
							null,
							h( ServerSideRender, {
								block: 'inline-related-post/related-post',
								attributes,
								EmptyResponsePlaceholder: () =>
									emptyPlaceholder( __( 'Nothing to show. Choose a published post in the block settings.', TEXT_DOMAIN ) ),
							} )
					  )
					: emptyPlaceholder( __( 'Search for a post in the block settings.', TEXT_DOMAIN ) )
			)
		);
	}

	registerBlockType( 'inline-related-post/related-post', {
		edit: Edit,
		save: () => null,
	} );
} )( window.wp );
