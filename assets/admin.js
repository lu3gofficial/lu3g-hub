/**
 * LU3G Hub — pagina "LU3G".
 *
 * - Pulsanti (data-op) e interruttori (data-switch) chiamano admin-ajax, azione "lu3g_hub".
 *   Il server risponde con tutte le schede aggiornate, i contatori e lo stato degli interruttori generali:
 *   qui si sostituiscono senza ricaricare la pagina.
 * - Filtri (Tutti, Attivi, …) e ricerca lavorano sulle schede già presenti (data-state, data-update, data-text).
 * - "Aggiorna tutti" aggiorna i plugin uno alla volta, così ogni scheda mostra il suo avanzamento.
 */
( function () {
	'use strict';

	var cfg = window.LU3G_HUB;
	var page = document.querySelector( '.lu3g-hub' );
	if ( ! cfg || ! page ) {
		return;
	}

	var grid = page.querySelector( '[data-grid]' );
	var toasts = page.querySelector( '[data-toasts]' );
	var filter = 'all';
	var query = '';

	// Ingresso delle schede una dopo l'altra, solo al primo caricamento.
	if ( grid ) {
		grid.classList.add( 'is-entering' );
		window.setTimeout( function () {
			grid.classList.remove( 'is-entering' );
		}, 1600 );
	}

	/* ------------------------------------------------------------ chiamata al server */

	function call( data ) {
		var body = new FormData();
		body.append( 'action', 'lu3g_hub' );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.text();
			} )
			.then( function ( text ) {
				var json;
				try {
					json = JSON.parse( text );
				} catch ( e ) {
					// Risposta non JSON: di solito un errore PHP del plugin appena attivato o installato.
					throw new Error( cfg.error );
				}
				if ( ! json || ! json.success ) {
					throw new Error( json && json.data && json.data.message ? json.data.message : cfg.error );
				}
				return json.data;
			}, function () {
				throw new Error( cfg.error );
			} );
	}

	/** Applica la risposta del server: schede, contatori, interruttori generali. */
	function apply( data, changedRepo ) {
		if ( data.cards && grid ) {
			Object.keys( data.cards ).forEach( function ( repo ) {
				var old = grid.querySelector( '.lu3g-hub__card[data-repo="' + cssEscape( repo ) + '"]' );
				if ( ! old ) {
					return;
				}
				var wrap = document.createElement( 'div' );
				wrap.innerHTML = data.cards[ repo ].trim();
				var fresh = wrap.firstElementChild;
				// L'etichetta "Nuovo" resta finché si è sulla pagina.
				var badge = old.querySelector( '.lu3g-hub__badge--new' );
				if ( badge && ! fresh.querySelector( '.lu3g-hub__badge--new' ) ) {
					fresh.querySelector( '.lu3g-hub__badges' ).insertBefore( badge.cloneNode( true ), fresh.querySelector( '.lu3g-hub__badge--state' ) );
				}
				if ( old.outerHTML === fresh.outerHTML ) {
					return;
				}
				fresh.style.setProperty( '--i', old.style.getPropertyValue( '--i' ) );
				old.parentNode.replaceChild( fresh, old );
				if ( repo === changedRepo ) {
					fresh.classList.add( 'is-swapped' );
				}
			} );
			applyFilter();
		}

		if ( data.stats ) {
			Object.keys( data.stats ).forEach( function ( key ) {
				var el = page.querySelector( '[data-stat="' + key + '"]' );
				if ( el ) {
					countTo( el, data.stats[ key ] );
				}
			} );
			var all = page.querySelector( '[data-update-all]' );
			if ( all ) {
				all.hidden = ! data.stats.updates;
			}
		}

		if ( data.switches ) {
			Object.keys( data.switches ).forEach( function ( op ) {
				var sw = page.querySelector( '.lu3g-hub__autobar [data-switch="' + op + '"]' );
				if ( sw ) {
					sw.setAttribute( 'aria-checked', data.switches[ op ] ? 'true' : 'false' );
					sw.classList.remove( 'is-pending' );
				}
			} );
		}
	}

	/* ------------------------------------------------------------ azioni sulle schede */

	function runCard( card, op ) {
		var repo = card.getAttribute( 'data-repo' );
		var button = card.querySelector( '[data-op="' + op + '"]' );
		var label = button ? button.innerHTML : '';

		card.classList.add( 'is-busy' );
		if ( button ) {
			button.disabled = true;
			button.innerHTML = button.querySelector( 'svg' ) ? button.querySelector( 'svg' ).outerHTML + ' ' + ( cfg.busy[ op ] || '…' ) : ( cfg.busy[ op ] || '…' );
		}

		return call( { op: op, repo: repo } ).then( function ( data ) {
			apply( data, repo );
			toast( data.message );
			return true;
		} ).catch( function ( error ) {
			card.classList.remove( 'is-busy' );
			if ( button ) {
				button.disabled = false;
				button.innerHTML = label;
			}
			// Interruttore rimesso com'era.
			card.querySelectorAll( '.lu3g-switch.is-pending' ).forEach( function ( sw ) {
				sw.classList.remove( 'is-pending' );
				sw.setAttribute( 'aria-checked', sw.getAttribute( 'aria-checked' ) === 'true' ? 'false' : 'true' );
			} );
			shake( card );
			toast( error.message, true );
			return false;
		} );
	}

	page.addEventListener( 'click', function ( event ) {
		// Pulsanti delle schede (Installa, Aggiorna).
		var button = event.target.closest( '.lu3g-hub__card [data-op]' );
		if ( button ) {
			runCard( button.closest( '.lu3g-hub__card' ), button.getAttribute( 'data-op' ) );
			return;
		}

		// Interruttori: lo stato cambia subito (risposta immediata), poi conferma o torna indietro.
		var sw = event.target.closest( '.lu3g-switch' );
		if ( sw && ! sw.disabled && ! sw.classList.contains( 'is-pending' ) ) {
			var on = sw.getAttribute( 'aria-checked' ) !== 'true';
			var op = sw.getAttribute( 'data-switch' );
			var card = sw.closest( '.lu3g-hub__card' );
			sw.setAttribute( 'aria-checked', on ? 'true' : 'false' );
			sw.classList.add( 'is-pending' );

			if ( card ) {
				runCard( card, op );
			} else {
				call( { op: op, on: on ? '1' : '' } ).then( function ( data ) {
					apply( data );
					toast( data.message );
				} ).catch( function ( error ) {
					sw.classList.remove( 'is-pending' );
					sw.setAttribute( 'aria-checked', on ? 'false' : 'true' );
					toast( error.message, true );
				} );
			}
			return;
		}

		// "Aggiorna tutti": uno alla volta.
		var all = event.target.closest( '[data-update-all]' );
		if ( all ) {
			all.disabled = true;
			var queue = Array.prototype.slice.call( grid.querySelectorAll( '.lu3g-hub__card[data-update="1"]' ) ).map( function ( c ) {
				return c.getAttribute( 'data-repo' );
			} );
			( function next() {
				var repo = queue.shift();
				if ( ! repo ) {
					all.disabled = false;
					if ( ! grid.querySelector( '.lu3g-hub__card[data-update="1"]' ) ) {
						toast( cfg.allDone );
					}
					return;
				}
				var c = grid.querySelector( '.lu3g-hub__card[data-repo="' + cssEscape( repo ) + '"]' );
				( c ? runCard( c, 'update' ) : Promise.resolve() ).then( next );
			}() );
			return;
		}

		// Filtri.
		var tab = event.target.closest( '[data-filter]' );
		if ( tab ) {
			filter = tab.getAttribute( 'data-filter' );
			page.querySelectorAll( '[data-filter]' ).forEach( function ( t ) {
				var current = t === tab;
				t.classList.toggle( 'is-current', current );
				t.setAttribute( 'aria-selected', current ? 'true' : 'false' );
			} );
			applyFilter( true );
			return;
		}

		// "Aggiorna l'elenco": l'icona gira mentre la pagina si ricarica.
		var refresh = event.target.closest( '.lu3g-hub__refresh' );
		if ( refresh ) {
			refresh.classList.add( 'is-spinning' );
		}
	} );

	var search = page.querySelector( '[data-search]' );
	if ( search ) {
		search.addEventListener( 'input', function () {
			query = search.value.trim().toLowerCase();
			applyFilter( true );
		} );
	}

	function applyFilter( animate ) {
		if ( ! grid ) {
			return;
		}
		var shown = 0;
		grid.querySelectorAll( '.lu3g-hub__card' ).forEach( function ( card ) {
			var ok = 'all' === filter
				|| ( 'update' === filter ? card.getAttribute( 'data-update' ) === '1' : card.getAttribute( 'data-state' ) === filter );
			if ( ok && query ) {
				ok = ( card.getAttribute( 'data-text' ) || '' ).indexOf( query ) !== -1;
			}
			var wasHidden = card.hidden;
			card.hidden = ! ok;
			if ( ok ) {
				shown++;
				if ( animate && wasHidden ) {
					card.classList.remove( 'is-filtered-in' );
					void card.offsetWidth; // riparte l'animazione
					card.classList.add( 'is-filtered-in' );
				}
			}
		} );
		var empty = page.querySelector( '[data-empty]' );
		if ( empty ) {
			empty.hidden = shown > 0;
		}
	}

	/* ------------------------------------------------------------ piccoli effetti */

	function countTo( el, target ) {
		var from = parseInt( el.textContent, 10 ) || 0;
		if ( from === target ) {
			return;
		}
		el.classList.remove( 'is-bump' );
		void el.offsetWidth;
		el.classList.add( 'is-bump' );
		var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		if ( reduce ) {
			el.textContent = target;
			return;
		}
		var start = null;
		window.requestAnimationFrame( function step( t ) {
			start = start || t;
			var p = Math.min( 1, ( t - start ) / 450 );
			el.textContent = Math.round( from + ( target - from ) * p );
			if ( p < 1 ) {
				window.requestAnimationFrame( step );
			}
		} );
	}

	function shake( card ) {
		card.classList.remove( 'is-shake' );
		void card.offsetWidth;
		card.classList.add( 'is-shake' );
	}

	function toast( text, isError ) {
		if ( ! toasts || ! text ) {
			return;
		}
		var el = document.createElement( 'div' );
		el.className = 'lu3g-hub__toast' + ( isError ? ' is-error' : '' );
		el.setAttribute( 'role', isError ? 'alert' : 'status' );
		el.textContent = text;
		toasts.appendChild( el );
		window.setTimeout( function () {
			el.classList.add( 'is-out' );
			window.setTimeout( function () {
				el.remove();
			}, 350 );
		}, isError ? 7000 : 3800 );
	}

	function cssEscape( value ) {
		return window.CSS && window.CSS.escape ? window.CSS.escape( value ) : value.replace( /"/g, '\\"' );
	}
}() );
