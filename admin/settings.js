( function ( config ) {
	const form = document.getElementById( 'irp-settings-form' );
	const stage = document.getElementById( 'irp-preview-stage' );
	const frame = document.getElementById( 'irp-preview-frame' );
	const fontMode = document.getElementById( 'irp-font-mode' );
	const fontRow = document.getElementById( 'irp-font-row' );
	const fontPicker = document.getElementById( 'irp-font-picker' );
	const fontInput = document.getElementById( 'irp-font-family' );
	const fontStatus = document.getElementById( 'irp-font-status' );
	const saveStatus = document.getElementById( 'irp-save-status' );
	const cssBox = document.getElementById( 'irp-custom-css' );
	const admin = document.querySelector( '.irp-admin' );

	const OTHER = '__other';
	// Icon sets also load as fonts, but they aren't usable for text.
	const ICON_FONT = /icon|awesome|glyph|swiper|symbols|dashicons|themify/i;
	// The preview renders at these widths, because the cards change layout in
	// narrow columns. Desktop is scaled down to fit the panel.
	const DEVICE_WIDTHS = { desktop: 860, phone: 375 };

	let detectedFonts = [];
	let detectionStarted = false;
	let previewTimer;
	let previewRequest = 0;
	let device = 'desktop';

	function formValues() {
		const values = {};
		new FormData( form ).forEach( ( value, name ) => {
			const match = name.match( /^irp_settings\[(\w+)\]$/ );
			if ( match ) {
				values[ match[ 1 ] ] = value;
			}
		} );
		return values;
	}

	function fitPreview() {
		const width = DEVICE_WIDTHS[ device ];
		const available = stage.clientWidth;
		const scale = Math.min( 1, available / width );
		const content = frame.contentDocument && frame.contentDocument.getElementById( 'irp-preview' );
		const height = content ? content.offsetHeight + 48 : 400;

		frame.style.width = width + 'px';
		frame.style.height = height + 'px';
		frame.style.transform = 'scale(' + scale + ')';
		frame.style.marginLeft = Math.max( 0, ( available - width * scale ) / 2 ) + 'px';
		frame.style.marginBottom = height * ( scale - 1 ) + 'px';
	}

	function updatePreview() {
		const request = ++previewRequest;
		const url = new URL( config.previewUrl );
		url.searchParams.set( 'irp_settings', JSON.stringify( formValues() ) );

		fetch( url, { credentials: 'same-origin' } )
			.then( ( response ) => response.text() )
			.then( ( html ) => {
				const fresh = new DOMParser().parseFromString( html, 'text/html' ).getElementById( 'irp-preview' );
				const current = frame.contentDocument && frame.contentDocument.getElementById( 'irp-preview' );
				if ( request === previewRequest && fresh && current ) {
					current.innerHTML = fresh.innerHTML;
					fitPreview();
					// Images change the height once they've loaded.
					current.querySelectorAll( 'img' ).forEach( ( image ) => image.addEventListener( 'load', fitPreview ) );
				}
			} )
			.catch( () => {} );
	}

	function schedulePreview() {
		clearTimeout( previewTimer );
		previewTimer = setTimeout( updatePreview, 250 );
	}

	function markUnsaved() {
		saveStatus.textContent = config.text.unsaved;
		saveStatus.classList.add( 'is-unsaved' );
		schedulePreview();
	}

	function fontsOnPage( url ) {
		return new Promise( ( resolve ) => {
			const page = document.createElement( 'iframe' );
			page.setAttribute( 'aria-hidden', 'true' );
			page.tabIndex = -1;
			// Fonts don't load in a display:none frame, so it's kept off screen instead.
			page.style.cssText = 'position:absolute;left:-9999px;width:1200px;height:800px;visibility:hidden;';

			const finish = ( fonts ) => {
				clearTimeout( giveUp );
				page.remove();
				resolve( fonts );
			};
			const giveUp = setTimeout( () => finish( [] ), 20000 );

			page.addEventListener( 'load', () => {
				let doc;
				try {
					doc = page.contentDocument;
				} catch ( error ) {
					doc = null;
				}
				if ( ! doc || ! doc.fonts ) {
					finish( [] );
					return;
				}
				setTimeout( () => {
					finish( Array.from( doc.fonts ).map( ( face ) => face.family.replace( /^["']|["']$/g, '' ) ) );
				}, 2500 );
			} );

			page.src = url;
			document.body.appendChild( page );
		} );
	}

	function renderFontPicker() {
		const saved = fontInput.value.trim();
		const fonts = Array.from( new Set( detectedFonts.concat( saved ? [ saved ] : [] ) ) ).sort( ( a, b ) => a.localeCompare( b ) );

		fontPicker.textContent = '';
		fontPicker.add( new Option( config.text.choose, '' ) );
		fonts.forEach( ( name ) => fontPicker.add( new Option( name, name ) ) );
		fontPicker.add( new Option( config.text.other, OTHER ) );
		fontPicker.value = saved || '';
		fontInput.hidden = true;
	}

	function detectFonts() {
		if ( detectionStarted ) {
			return;
		}
		detectionStarted = true;
		fontStatus.textContent = config.text.searching;

		Promise.all( config.fontPages.map( fontsOnPage ) ).then( ( results ) => {
			detectedFonts = Array.from( new Set( results.flat() ) ).filter( ( name ) => name && ! ICON_FONT.test( name ) );
			if ( OTHER !== fontPicker.value ) {
				renderFontPicker();
			}
			fontStatus.textContent = detectedFonts.length
				? config.text.found.replace( '%d', detectedFonts.length )
				: config.text.notFound;
		} );
	}

	function syncFontRow() {
		const isCustom = 'custom' === fontMode.value;
		fontRow.hidden = ! isCustom;
		if ( isCustom ) {
			detectFonts();
		}
	}

	function setUpColorField( field ) {
		const value = field.querySelector( '.irp-color__value' );
		const picker = field.querySelector( 'input[type="color"]' );
		const swatch = field.querySelector( '.irp-color__swatch' );
		const clear = field.querySelector( '.irp-color__clear' );

		const sync = () => {
			const color = value.value.trim();
			swatch.classList.toggle( 'is-empty', ! color );
			swatch.style.backgroundColor = color;
			clear.hidden = ! color;
			if ( /^#[0-9a-f]{6}$/i.test( color ) ) {
				picker.value = color;
			}
			if ( 'irp-accentColor' === value.id ) {
				admin.style.setProperty( '--irp-ui-accent', color || value.placeholder );
			}
		};

		picker.addEventListener( 'input', () => {
			value.value = picker.value;
			sync();
			markUnsaved();
		} );
		value.addEventListener( 'input', sync );
		clear.addEventListener( 'click', () => {
			value.value = '';
			sync();
			markUnsaved();
		} );
		sync();
	}

	fontPicker.addEventListener( 'change', () => {
		const isOther = OTHER === fontPicker.value;
		fontInput.hidden = ! isOther;
		if ( isOther ) {
			fontInput.value = '';
			fontInput.focus();
		} else {
			fontInput.value = fontPicker.value;
		}
	} );

	document.querySelectorAll( 'input[name="irp-preview-device"]' ).forEach( ( radio ) => {
		radio.addEventListener( 'change', () => {
			device = radio.value;
			fitPreview();
		} );
	} );

	// The preview's device switch isn't a setting, so it doesn't count as a change.
	const isSetting = ( event ) => event.target.name !== 'irp-preview-device';
	form.addEventListener( 'input', ( event ) => isSetting( event ) && markUnsaved() );
	form.addEventListener( 'change', ( event ) => isSetting( event ) && markUnsaved() );
	fontMode.addEventListener( 'change', syncFontRow );
	document.querySelectorAll( '.irp-color' ).forEach( setUpColorField );

	// Layouts only apply to Multiple. Hidden fields still save, so the choice is kept.
	document.querySelectorAll( 'input[name="irp_settings[mode]"]' ).forEach( ( radio ) => {
		radio.addEventListener( 'change', () => {
			document.getElementById( 'irp-layout-row' ).hidden = 'multiple' !== radio.value;
		} );
	} );

	frame.addEventListener( 'load', () => {
		fitPreview();
		frame.contentDocument.querySelectorAll( 'img' ).forEach( ( image ) => image.addEventListener( 'load', fitPreview ) );
	} );
	new ResizeObserver( fitPreview ).observe( stage );

	if ( cssBox && config.codeEditor && window.wp && window.wp.codeEditor ) {
		const editor = window.wp.codeEditor.initialize( cssBox, config.codeEditor ).codemirror;
		editor.on( 'change', () => {
			editor.save();
			markUnsaved();
		} );
	}

	renderFontPicker();
	syncFontRow();
	fitPreview();
} )( window.irpSettingsPage );
