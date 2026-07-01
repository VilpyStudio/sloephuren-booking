/**
 * Sloephuren Booking - frontend stepper.
 *
 * Stuurt het boekformulier in stappen aan: sloep -> pakket -> datum ->
 * tijdslot -> gegevens. Praat via de REST API met de server voor
 * beschikbaarheid en het aanmaken van de boeking + betaling.
 */
( function () {
	'use strict';

	if ( typeof window.SHB_DATA === 'undefined' ) {
		return;
	}

	var D = window.SHB_DATA;
	var root = document.getElementById( 'shb-booking' );
	if ( ! root ) {
		return;
	}

	// Gekozen waarden.
	var state = {
		step: 1,
		boat: null, // { id, name, max_persons }
		product: null, // { id, name, price }
		date: '', // Y-m-d
		timeslot: null, // { id, label }
		maxStep: 5
	};

	// Handige element-referenties.
	var els = {
		steps: root.querySelectorAll( '.shb-step' ),
		boats: document.getElementById( 'shb-boats' ),
		packages: document.getElementById( 'shb-packages' ),
		dateTrigger: document.getElementById( 'shb-date-trigger' ),
		dateTriggerText: document.getElementById( 'shb-date-trigger-text' ),
		calendar: document.getElementById( 'shb-calendar' ),
		slots: document.getElementById( 'shb-slots' ),
		name: document.getElementById( 'shb-name' ),
		email: document.getElementById( 'shb-email' ),
		phone: document.getElementById( 'shb-phone' ),
		persons: document.getElementById( 'shb-persons' ),
		agree: document.getElementById( 'shb-agree' ),
		termsLabel: document.getElementById( 'shb-terms-label' ),
		summary: document.getElementById( 'shb-summary' ),
		error: document.getElementById( 'shb-error' ),
		back: document.getElementById( 'shb-back' ),
		next: document.getElementById( 'shb-next' ),
		submit: document.getElementById( 'shb-submit' ),
		bar: root.querySelector( '.shb-progress-bar' )
	};

	/* ----------------------------------------------------------------- */
	/* Helpers                                                            */
	/* ----------------------------------------------------------------- */

	function euro( value ) {
		return D.currency + ' ' + Number( value ).toFixed( 2 ).replace( '.', ',' );
	}

	function showError( msg ) {
		els.error.textContent = msg;
		els.error.hidden = false;
	}

	function clearError() {
		els.error.hidden = true;
		els.error.textContent = '';
	}

	function api( path, params ) {
		var url = D.rest + path;
		if ( params ) {
			var q = Object.keys( params ).map( function ( k ) {
				return encodeURIComponent( k ) + '=' + encodeURIComponent( params[ k ] );
			} ).join( '&' );
			url += '?' + q;
		}
		return fetch( url, {
			headers: { 'X-WP-Nonce': D.nonce }
		} ).then( parseJson );
	}

	function apiPost( path, body ) {
		return fetch( D.rest + path, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': D.nonce
			},
			body: JSON.stringify( body )
		} ).then( parseJson );
	}

	function parseJson( res ) {
		return res.json().then( function ( data ) {
			return { ok: res.ok, data: data };
		} );
	}

	function markSelected( container, chosen ) {
		container.querySelectorAll( '.shb-card' ).forEach( function ( c ) {
			c.classList.toggle( 'is-selected', c === chosen );
		} );
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str == null ? '' : String( str );
		return div.innerHTML;
	}

	/* ----------------------------------------------------------------- */
	/* Stap-navigatie                                                     */
	/* ----------------------------------------------------------------- */

	function goToStep( n ) {
		state.step = n;
		els.steps.forEach( function ( s ) {
			s.hidden = parseInt( s.getAttribute( 'data-step' ), 10 ) !== n;
		} );

		if ( els.bar ) {
			els.bar.style.width = Math.round( ( n / state.maxStep ) * 100 ) + '%';
		}

		els.back.hidden = n === 1;
		els.summary.hidden = n < 5;

		if ( n === 5 ) {
			renderSummary();
			els.next.hidden = true;
			els.submit.hidden = false;
		} else {
			els.next.hidden = false;
			els.submit.hidden = true;
		}

		closeCalendar();
		clearError();
		updateNextState();
		window.scrollTo( { top: root.getBoundingClientRect().top + window.pageYOffset - 40, behavior: 'smooth' } );
	}

	// Bepaalt of "Volgende" beschikbaar is voor de huidige stap.
	function updateNextState() {
		var ok = false;
		switch ( state.step ) {
			case 1: ok = !! state.boat; break;
			case 2: ok = !! state.product; break;
			case 3: ok = !! state.date; break;
			case 4: ok = !! state.timeslot; break;
			case 5: ok = validateDetails( false ); break;
		}
		els.next.disabled = ! ok;
		els.submit.disabled = ! ok;
	}

	/* ----------------------------------------------------------------- */
	/* Stap 1: sloep-type (statisch, geen datum bekend)                   */
	/* ----------------------------------------------------------------- */

	function renderBoats() {
		els.boats.innerHTML = '';
		D.boats.forEach( function ( b ) {
			var card = document.createElement( 'button' );
			card.type = 'button';
			card.className = 'shb-card shb-boat';
			var maxTxt = D.i18n.perPersons.replace( '%d', b.max_persons );
			card.innerHTML =
				'<span class="shb-card-name">' + escapeHtml( b.name ) + '</span>' +
				'<span class="shb-card-sub">' + maxTxt + '</span>';
			card.addEventListener( 'click', function () {
				state.boat = b;
				state.timeslot = null;
				markSelected( els.boats, card );
				els.persons.max = b.max_persons;
				if ( parseInt( els.persons.value, 10 ) > b.max_persons ) {
					els.persons.value = b.max_persons;
				}
				updateNextState();
			} );
			els.boats.appendChild( card );
		} );
	}

	/* ----------------------------------------------------------------- */
	/* Stap 2: pakketten                                                  */
	/* ----------------------------------------------------------------- */

	function renderPackages() {
		els.packages.innerHTML = '';
		D.products.forEach( function ( p ) {
			var card = document.createElement( 'button' );
			card.type = 'button';
			card.className = 'shb-card shb-package';
			card.innerHTML =
				'<span class="shb-card-name">' + escapeHtml( p.name ) + '</span>' +
				'<span class="shb-card-price">' + euro( p.price ) + '</span>';
			card.addEventListener( 'click', function () {
				state.product = p;
				state.timeslot = null;
				markSelected( els.packages, card );
				updateNextState();
			} );
			els.packages.appendChild( card );
		} );
	}

	/* ----------------------------------------------------------------- */
	/* Stap 3: datumkiezer (custom kalender)                              */
	/* ----------------------------------------------------------------- */

	var MONTHS = [ 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december' ];
	var WEEKDAYS_SHORT = [ 'ma', 'di', 'wo', 'do', 'vr', 'za', 'zo' ];
	var WEEKDAYS_LONG = [ 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag', 'zondag' ];

	var minDateObj = parseIsoDate( D.minDate );
	var calendarView = { year: minDateObj.getFullYear(), month: minDateObj.getMonth() };

	function parseIsoDate( iso ) {
		var parts = iso.split( '-' ).map( Number );
		return new Date( parts[ 0 ], parts[ 1 ] - 1, parts[ 2 ] );
	}

	function pad( n ) {
		return n < 10 ? '0' + n : '' + n;
	}

	function toIsoDate( d ) {
		return d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() );
	}

	function sameDay( a, b ) {
		return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
	}

	function isBeforeMinDate( d ) {
		return toIsoDate( d ) < toIsoDate( minDateObj );
	}

	function capitalize( s ) {
		return s.charAt( 0 ).toUpperCase() + s.slice( 1 );
	}

	function formatDisplayDate( iso ) {
		if ( ! iso ) { return ''; }
		var d = parseIsoDate( iso );
		return capitalize( WEEKDAYS_LONG[ ( d.getDay() + 6 ) % 7 ] ) + ' ' + d.getDate() + ' ' + MONTHS[ d.getMonth() ] + ' ' + d.getFullYear();
	}

	function openCalendar() {
		if ( state.date ) {
			var d = parseIsoDate( state.date );
			calendarView.year = d.getFullYear();
			calendarView.month = d.getMonth();
		}
		renderCalendar();
		els.calendar.hidden = false;
		els.dateTrigger.setAttribute( 'aria-expanded', 'true' );
	}

	function closeCalendar() {
		if ( els.calendar.hidden ) { return; }
		els.calendar.hidden = true;
		els.dateTrigger.setAttribute( 'aria-expanded', 'false' );
	}

	function renderCalendar() {
		var year = calendarView.year;
		var month = calendarView.month;
		var firstOfMonth = new Date( year, month, 1 );
		// Week begint op maandag.
		var startOffset = ( firstOfMonth.getDay() + 6 ) % 7;
		var gridStart = new Date( year, month, 1 - startOffset );
		var today = new Date();
		var canGoPrev = ! ( year === minDateObj.getFullYear() && month === minDateObj.getMonth() );

		var html = '<div class="shb-cal-header">' +
			'<button type="button" class="shb-cal-nav" id="shb-cal-prev"' + ( canGoPrev ? '' : ' disabled' ) + ' aria-label="Vorige maand">&#8249;</button>' +
			'<span class="shb-cal-title">' + MONTHS[ month ] + ' ' + year + '</span>' +
			'<button type="button" class="shb-cal-nav" id="shb-cal-next" aria-label="Volgende maand">&#8250;</button>' +
			'</div>';

		html += '<div class="shb-cal-weekdays">' + WEEKDAYS_SHORT.map( function ( w ) {
			return '<span>' + w + '</span>';
		} ).join( '' ) + '</div>';

		html += '<div class="shb-cal-days">';
		for ( var i = 0; i < 42; i++ ) {
			var cellDate = new Date( gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + i );
			var outside = cellDate.getMonth() !== month;
			var disabled = isBeforeMinDate( cellDate );
			var classes = [ 'shb-cal-day' ];
			if ( outside ) { classes.push( 'is-outside' ); }
			if ( sameDay( cellDate, today ) ) { classes.push( 'is-today' ); }
			if ( state.date && sameDay( cellDate, parseIsoDate( state.date ) ) ) { classes.push( 'is-selected' ); }
			if ( disabled ) { classes.push( 'is-disabled' ); }

			html += '<button type="button" class="' + classes.join( ' ' ) + '"' +
				( disabled ? ' disabled' : ' data-date="' + toIsoDate( cellDate ) + '"' ) +
				'>' + cellDate.getDate() + '</button>';
		}
		html += '</div>';

		els.calendar.innerHTML = html;

		document.getElementById( 'shb-cal-prev' ).addEventListener( 'click', function () {
			shiftMonth( -1 );
		} );
		document.getElementById( 'shb-cal-next' ).addEventListener( 'click', function () {
			shiftMonth( 1 );
		} );
		els.calendar.querySelectorAll( '.shb-cal-day:not(.is-disabled)' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				selectDate( btn.getAttribute( 'data-date' ) );
			} );
		} );
	}

	function shiftMonth( delta ) {
		var d = new Date( calendarView.year, calendarView.month + delta, 1 );
		calendarView.year = d.getFullYear();
		calendarView.month = d.getMonth();
		renderCalendar();
	}

	function selectDate( iso ) {
		state.date = iso;
		state.timeslot = null;
		els.dateTriggerText.textContent = formatDisplayDate( iso );
		els.dateTrigger.classList.add( 'has-value' );
		closeCalendar();
		updateNextState();
	}

	els.dateTrigger.addEventListener( 'click', function ( e ) {
		e.stopPropagation();
		if ( els.calendar.hidden ) {
			openCalendar();
		} else {
			closeCalendar();
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		if ( ! els.calendar.hidden && ! els.calendar.contains( e.target ) && e.target !== els.dateTrigger ) {
			closeCalendar();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			closeCalendar();
		}
	} );

	/* ----------------------------------------------------------------- */
	/* Stap 4: tijdsloten (gefilterd op de gekozen sloep)                 */
	/* ----------------------------------------------------------------- */

	function loadTimeslots() {
		els.slots.innerHTML = '<p class="shb-muted">' + D.i18n.loading + '</p>';
		state.timeslot = null;
		updateNextState();

		api( '/timeslots', {
			product_id: state.product.id,
			date: state.date,
			boat_type_id: state.boat.id
		} ).then( function ( r ) {
			if ( ! r.ok ) {
				els.slots.innerHTML = '<p class="shb-muted">' + ( r.data.error || D.i18n.genericError ) + '</p>';
				return;
			}
			renderTimeslots( r.data.timeslots || [] );
		} ).catch( function () {
			els.slots.innerHTML = '<p class="shb-muted">' + D.i18n.genericError + '</p>';
		} );
	}

	function renderTimeslots( slots ) {
		els.slots.innerHTML = '';
		var anyAvailable = slots.some( function ( s ) { return s.available; } );
		if ( ! slots.length || ! anyAvailable ) {
			els.slots.innerHTML = '<p class="shb-muted">' + D.i18n.noSlots + '</p>';
			return;
		}

		slots.forEach( function ( s ) {
			var card = document.createElement( 'button' );
			card.type = 'button';
			card.className = 'shb-card shb-slot' + ( s.available ? '' : ' is-disabled' );
			card.disabled = ! s.available;
			card.innerHTML =
				'<span class="shb-card-name">' + escapeHtml( s.label ) + '</span>' +
				'<span class="shb-card-sub">' + ( s.available ? D.i18n.available : D.i18n.full ) + '</span>';
			if ( s.available ) {
				card.addEventListener( 'click', function () {
					state.timeslot = { id: s.id, label: s.label };
					markSelected( els.slots, card );
					updateNextState();
				} );
			}
			els.slots.appendChild( card );
		} );
	}

	/* ----------------------------------------------------------------- */
	/* Stap 5: gegevens + samenvatting                                    */
	/* ----------------------------------------------------------------- */

	function validateDetails( showMessages ) {
		var name = els.name.value.trim();
		var email = els.email.value.trim();
		var phone = els.phone.value.trim();
		var persons = parseInt( els.persons.value, 10 );
		var agree = els.agree.checked;
		var maxP = state.boat ? state.boat.max_persons : 8;

		var valid = name && /\S+@\S+\.\S+/.test( email ) && phone &&
			persons >= 1 && persons <= maxP && agree;

		if ( showMessages && ! valid ) {
			showError( D.i18n.genericError );
		}
		return !! valid;
	}

	function renderSummary() {
		var html = '<h4>' + D.i18n.summaryTitle + '</h4><ul class="shb-summary-list">';
		html += row( 'Sloep', state.boat ? state.boat.name : '' );
		html += row( 'Pakket', state.product ? state.product.name : '' );
		html += row( 'Datum', formatDisplayDate( state.date ) );
		html += row( 'Tijdslot', state.timeslot ? state.timeslot.label : '' );
		html += '</ul>';
		html += '<div class="shb-summary-total"><span>' + D.i18n.total + '</span><strong>' +
			euro( state.product ? state.product.price : 0 ) + '</strong></div>';
		els.summary.innerHTML = html;

		if ( D.terms && els.termsLabel && ! els.termsLabel.dataset.linked ) {
			els.termsLabel.innerHTML = 'Ik ga akkoord met de <a href="' + D.terms + '" target="_blank" rel="noopener">voorwaarden</a>.';
			els.termsLabel.dataset.linked = '1';
		}
	}

	function row( label, value ) {
		return '<li><span>' + label + '</span><span>' + escapeHtml( value ) + '</span></li>';
	}

	/* ----------------------------------------------------------------- */
	/* Verzenden                                                          */
	/* ----------------------------------------------------------------- */

	function submitBooking() {
		if ( ! validateDetails( true ) ) {
			return;
		}
		clearError();
		els.submit.disabled = true;
		els.submit.textContent = D.i18n.redirecting;

		apiPost( '/book', {
			product_id: state.product.id,
			boat_type_id: state.boat.id,
			timeslot_id: state.timeslot.id,
			date: state.date,
			name: els.name.value.trim(),
			email: els.email.value.trim(),
			phone: els.phone.value.trim(),
			persons: parseInt( els.persons.value, 10 ),
			agree: els.agree.checked ? 1 : 0,
			return_url: D.return
		} ).then( function ( r ) {
			if ( ! r.ok || ! r.data.checkout_url ) {
				showError( ( r.data && r.data.error ) || D.i18n.genericError );
				els.submit.disabled = false;
				els.submit.innerHTML = D.i18n.book;
				return;
			}
			window.location.href = r.data.checkout_url;
		} ).catch( function () {
			showError( D.i18n.genericError );
			els.submit.disabled = false;
			els.submit.innerHTML = D.i18n.book;
		} );
	}

	/* ----------------------------------------------------------------- */
	/* Events                                                             */
	/* ----------------------------------------------------------------- */

	els.next.addEventListener( 'click', function () {
		var next = state.step + 1;
		if ( next === 4 ) { loadTimeslots(); }
		goToStep( next );
	} );

	els.back.addEventListener( 'click', function () {
		goToStep( Math.max( 1, state.step - 1 ) );
	} );

	els.submit.addEventListener( 'click', submitBooking );

	[ els.name, els.email, els.phone, els.persons ].forEach( function ( el ) {
		el.addEventListener( 'input', updateNextState );
	} );
	els.agree.addEventListener( 'change', updateNextState );

	/* ----------------------------------------------------------------- */
	/* Init                                                               */
	/* ----------------------------------------------------------------- */

	renderBoats();
	renderPackages();
	goToStep( 1 );
} )();
