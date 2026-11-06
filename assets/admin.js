/**
 * Pagina Plugin LU3G: i pulsanti delle schede chiamano admin-ajax (azione lu3g_hub) e la scheda
 * viene sostituita con quella aggiornata che rimanda il server, senza ricaricare la pagina.
 */
( function () {
	'use strict';

	var cfg = window.LU3G_HUB;
	if ( ! cfg ) {
		return;
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.lu3g-hub__card button[data-op]' );
		if ( ! button ) {
			return;
		}
		var card = button.closest( '.lu3g-hub__card' );
		if ( card.classList.contains( 'is-busy' ) ) {
			return;
		}
		run( card, button.getAttribute( 'data-op' ), button );
	} );

	function run( card, op, button ) {
		var msg = card.querySelector( '.lu3g-hub__msg' );
		var buttons = card.querySelectorAll( 'button' );
		var body = new FormData();

		body.append( 'action', 'lu3g_hub' );
		body.append( 'nonce', cfg.nonce );
		body.append( 'op', op );
		body.append( 'repo', card.getAttribute( 'data-repo' ) );

		card.classList.add( 'is-busy' );
		Array.prototype.forEach.call( buttons, function ( b ) {
			b.disabled = true;
		} );
		var label = button.textContent;
		button.textContent = cfg.busy[ op ] || '…';
		msg.className = 'lu3g-hub__msg';
		msg.textContent = '';

		fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.text();
			} )
			.then( function ( text ) {
				var json = null;
				try {
					json = JSON.parse( text );
				} catch ( e ) {
					// Risposta non JSON: di solito un errore PHP del plugin appena attivato o installato.
					json = { success: false, data: { message: cfg.error } };
				}
				if ( json && json.success && json.data && json.data.html ) {
					var wrap = document.createElement( 'div' );
					wrap.innerHTML = json.data.html.trim();
					var fresh = wrap.firstElementChild;
					card.parentNode.replaceChild( fresh, card );
					var freshMsg = fresh.querySelector( '.lu3g-hub__msg' );
					freshMsg.className = 'lu3g-hub__msg is-ok';
					freshMsg.textContent = json.data.message || '';
					return;
				}
				fail( json && json.data && json.data.message ? json.data.message : cfg.error );
			} )
			.catch( function () {
				fail( cfg.error );
			} );

		function fail( text ) {
			// Si ricarica solo la scheda: lo stato vero potrebbe essere cambiato a metà.
			card.classList.remove( 'is-busy' );
			Array.prototype.forEach.call( buttons, function ( b ) {
				b.disabled = false;
			} );
			button.textContent = label;
			msg.className = 'lu3g-hub__msg is-error';
			msg.textContent = text;
		}
	}
}() );
