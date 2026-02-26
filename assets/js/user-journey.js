/**
 * GF User Journey - Frontend tracking
 *
 * Tracks page visits in localStorage and injects journey data
 * into Gravity Forms as hidden fields before submission.
 */
const GfUserJourney = ( function( document, window ) {
	const app = {
		init() {
			// Bail if localStorage is not available (private browsing, disabled storage).
			try {
				localStorage.getItem( '__gf_uj_test' );
			} catch {
				return;
			}

			app.checkCleanupCookie();

			// Don't track the thank-you/redirect page after cleanup.
			if ( app.cleaned ) {
				return;
			}

			app.captureUtm();
			app.trackPageVisit();

			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', () => {
					app.injectIntoForms();
					app.observeDOM();
				} );
			} else {
				app.injectIntoForms();
				app.observeDOM();
			}

			// Refresh hidden input value right before form submission (capture phase)
			document.addEventListener( 'submit', app.onFormSubmit, true );
		},

		/**
		 * Check cleanup cookie and clear localStorage if set.
		 * Cookie is set server-side after successful form processing.
		 */
		checkCleanupCookie() {
			const name = gf_user_journey.cleanup_cookie_name + '=';
			const cookies = document.cookie.split( ';' );

			for ( const c of cookies ) {
				const trimmed = c.trim();

				if ( trimmed.indexOf( name ) === 0 ) {
					const value = trimmed.substring( name.length );

					if ( value === '1' ) {
						localStorage.removeItem( gf_user_journey.storage_name );
						localStorage.removeItem( gf_user_journey.storage_name + '_utm' );

						// Delete the cleanup cookie
						document.cookie = gf_user_journey.cleanup_cookie_name +
							'=;expires=Thu, 01 Jan 1970 00:00:01 GMT;path=/;samesite=strict' +
							( gf_user_journey.is_ssl ? ';secure' : '' );

						// Skip tracking on this page (e.g. thank you redirect).
						app.cleaned = true;
					}

					break;
				}
			}
		},

		/**
		 * Capture UTM parameters from URL on first visit (first-touch attribution).
		 */
		captureUtm() {
			const params = new URLSearchParams( window.location.search );
			const utmKeys = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ];
			const utm = {};

			for ( const key of utmKeys ) {
				if ( params.has( key ) ) {
					utm[ key ] = params.get( key );
				}
			}

			if ( Object.keys( utm ).length === 0 ) {
				return;
			}

			// Keep first-touch UTM only.
			const storageKey = gf_user_journey.storage_name + '_utm';

			if ( ! localStorage.getItem( storageKey ) ) {
				try {
					localStorage.setItem( storageKey, JSON.stringify( utm ) );
				} catch {
					// Ignore storage errors.
				}
			}
		},

		/**
		 * Record current page visit in localStorage.
		 */
		trackPageVisit() {
			let data = app.getData();
			const ts = Math.round( Date.now() / 1000 );

			// First visit + external referrer: record it
			if (
				Object.keys( data ).length === 0 &&
				document.referrer !== '' &&
				! document.referrer.startsWith( window.location.origin )
			) {
				data[ ts - 2 ] = encodeURIComponent( document.referrer + '|#|External Referrer' );
			}

			const url = window.location.href + '|#|' + document.title;
			const encoded = encodeURIComponent( url );

			// Deduplicate on page reload: remove previous entry with same URL
			const latestTs = app.getLatestTimestamp( data );

			if ( data[ latestTs ] === encoded ) {
				delete data[ latestTs ];
			}

			data[ ts ] = encoded;
			data = app.trimData( data );

			app.setData( data );
		},

		/**
		 * Capture-phase submit handler to refresh journey data before submission.
		 */
		onFormSubmit( e ) {
			const form = e.target;

			if ( ! form.id || ! form.id.startsWith( 'gform_' ) ) {
				return;
			}

			app.injectIntoForm( form );
		},

		/**
		 * Inject hidden inputs into all Gravity Forms on the page.
		 */
		injectIntoForms() {
			const forms = document.querySelectorAll( '.gform_wrapper form' );

			forms.forEach( ( form ) => app.injectIntoForm( form ) );
		},

		/**
		 * Get or create a hidden input in a form.
		 */
		hiddenInput( form, name ) {
			let input = form.querySelector( `input[name="${ name }"]` );

			if ( ! input ) {
				input = document.createElement( 'input' );
				input.type = 'hidden';
				input.name = name;
				form.appendChild( input );
			}

			return input;
		},

		/**
		 * Inject hidden inputs into a single form (journey data, nonce, UTM).
		 */
		injectIntoForm( form ) {
			const name = gf_user_journey.storage_name;

			app.hiddenInput( form, name ).value = JSON.stringify( app.getData() );
			app.hiddenInput( form, name + '_nonce' ).value = gf_user_journey.nonce;
			app.hiddenInput( form, name + '_utm' ).value = localStorage.getItem( name + '_utm' ) || '';
		},

		/**
		 * Watch for dynamically added Gravity Forms (e.g. popups, AJAX-loaded content).
		 */
		observeDOM() {
			const observer = new MutationObserver( ( mutations ) => {
				for ( const mutation of mutations ) {
					for ( const node of mutation.addedNodes ) {
						if ( node.nodeType !== 1 ) {
							continue;
						}

						if ( node.matches && node.matches( '.gform_wrapper form' ) ) {
							app.injectIntoForm( node );
						}

						if ( node.matches && node.matches( '.gform_wrapper' ) ) {
							const forms = node.querySelectorAll( 'form' );

							if ( forms ) {
								forms.forEach( ( form ) => app.injectIntoForm( form ) );
							}
						}

						const wrappers = node.querySelectorAll && node.querySelectorAll( '.gform_wrapper form' );

						if ( wrappers ) {
							wrappers.forEach( ( form ) => app.injectIntoForm( form ) );
						}
					}
				}
			} );

			observer.observe( document.body, { childList: true, subtree: true } );
		},

		/**
		 * Read journey data from localStorage.
		 */
		getData() {
			try {
				const parsed = JSON.parse( localStorage.getItem( gf_user_journey.storage_name ) );

				if ( parsed && typeof parsed === 'object' && ! Array.isArray( parsed ) ) {
					return parsed;
				}
			} catch {
				// Ignore parse errors
			}

			return {};
		},

		/**
		 * Write journey data to localStorage.
		 */
		setData( data ) {
			try {
				localStorage.setItem( gf_user_journey.storage_name, JSON.stringify( data ) );
			} catch {
				// Ignore storage errors (quota exceeded, etc.)
			}
		},

		/**
		 * Get the latest timestamp key from the data object.
		 */
		getLatestTimestamp( data ) {
			const keys = Object.keys( data ).map( ( k ) => parseInt( k, 10 ) );

			return keys.length ? Math.max( ...keys ).toString() : '0';
		},

		/**
		 * Trim data to stay within size and item count limits.
		 * Keeps the most recent entries, discards anything older than 1 year.
		 */
		trimData( data ) {
			const entries = Object.entries( data ).sort( ( [ a ], [ b ] ) => Number( a ) - Number( b ) );
			const cutoff = Math.floor( Date.now() / 1000 ) - 365 * 24 * 60 * 60;
			const result = [];
			let total = 2; // JSON outer braces {}

			for ( let i = entries.length - 1; i >= 0; i-- ) {
				const [ key, value ] = entries[ i ];

				if ( Number( key ) < cutoff ) {
					break;
				}

				if ( result.length >= gf_user_journey.max_items ) {
					break;
				}

				const pairSize = String( key ).length + String( value ).length + 6;

				if ( total + pairSize > gf_user_journey.max_size ) {
					break;
				}

				total += pairSize;
				result.push( [ key, value ] );
			}

			return Object.fromEntries( result );
		},
	};

	return app;
} )( document, window );

GfUserJourney.init();
