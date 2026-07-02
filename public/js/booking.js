/**
 * Sloephuren Booking - zwevende boekingswidget (launcher + paneel).
 *
 * Vanilla JS. De widget wordt aan <body> gehangen zodat position:fixed
 * altijd t.o.v. de viewport werkt (thema-transforms breken dit anders).
 * Stappen: sloep -> pakket -> datum -> tijdslot -> gegevens. De backend
 * (beschikbaarheid, boeking, betaling) loopt via de bestaande REST-API.
 */
( function () {
	'use strict';

	if ( typeof window.SHB_DATA === 'undefined' || window.__shbInit ) {
		return;
	}
	window.__shbInit = true;

	var D = window.SHB_DATA;

	/* ----------------------------------------------------------------- */
	/* Configuratie afleiden                                              */
	/* ----------------------------------------------------------------- */

	// Sloepen met sub-tekst.
	var BOATS = ( D.boats || [] ).map( function ( b ) {
		return { id: b.id, name: b.name, sub: 'max. ' + b.max_persons + ' personen', max: b.max_persons };
	} );

	// Pakketten met design-subtekst en korte naam.
	var PRODUCTS = ( D.products || [] ).map( function ( p ) {
		var sub = '';
		if ( /halve/i.test( p.name ) ) { sub = '4 uur op de Zaan'; }
		else if ( /hele/i.test( p.name ) ) { sub = '8 uur op de Zaan'; }
		return {
			id: p.id,
			name: p.name,
			price: p.price,
			sub: sub,
			kort: p.name.toLowerCase().replace( ' varen', '' )
		};
	} );

	var MIN_PRICE = PRODUCTS.length ? Math.min.apply( null, PRODUCTS.map( function ( p ) { return p.price; } ) ) : 265;

	// Vaste sloep (koppeling aan één boot): naam -> boot.
	var FIXED_BOAT = null;
	if ( D.fixedSloep && D.fixedSloep !== 'geen' ) {
		FIXED_BOAT = BOATS.filter( function ( b ) { return b.name === D.fixedSloep; } )[ 0 ] || null;
	}
	var AUTO = D.autoAdvance !== false;

	var MONTHS_CAPS = [ 'JANUARI', 'FEBRUARI', 'MAART', 'APRIL', 'MEI', 'JUNI', 'JULI', 'AUGUSTUS', 'SEPTEMBER', 'OKTOBER', 'NOVEMBER', 'DECEMBER' ];
	var MONTHS_FULL = [ 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december' ];
	var MONTHS_SHORT = [ 'jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec' ];
	var DAYS_SHORT = [ 'zo', 'ma', 'di', 'wo', 'do', 'vr', 'za' ];
	var WEEKDAYS = [ 'MA', 'DI', 'WO', 'DO', 'VR', 'ZA', 'ZO' ];

	var today = new Date();
	today.setHours( 0, 0, 0, 0 );

	/* ----------------------------------------------------------------- */
	/* State                                                              */
	/* ----------------------------------------------------------------- */

	var state = {
		open: !! D.startOpen,
		step: FIXED_BOAT ? 2 : 1,
		sloep: FIXED_BOAT ? FIXED_BOAT.id : null,
		pakket: null,
		datum: null,      // ISO Y-m-d
		slot: null,       // timeslot id
		slotsData: [],    // opgehaalde tijdsloten
		loadingSlots: false,
		calY: today.getFullYear(),
		calM: today.getMonth(),
		naam: '', email: '', tel: '', personen: 2, akkoord: false,
		errors: {},
		fase: 'form',     // form | processing | success | failed
		bookingNr: '',
		successData: null,
		isMobile: window.matchMedia( '(max-width: 640px)' ).matches
	};

	var autoTimer = null;

	// Cache van niet-beschikbare dagen per pakket/sloep/maand (ISO-datums).
	var monthCache = {};

	function monthCacheKey( y, m ) {
		return state.pakket + '|' + ( state.sloep || 0 ) + '|' + y + '-' + ( m + 1 );
	}

	function loadMonthAvailability( y, m ) {
		var key = monthCacheKey( y, m );
		if ( monthCache[ key ] !== undefined ) { return; }
		monthCache[ key ] = null; // bezig met laden
		api( '/month', {
			product_id: state.pakket,
			boat_type_id: state.sloep || 0,
			year: y,
			month: m + 1
		} ).then( function ( r ) {
			monthCache[ key ] = ( r.ok && r.data.unavailable ) ? r.data.unavailable : [];
			// Kalender verversen zodra de data binnen is.
			if ( state.open && state.fase === 'form' && effStep() === 3 ) { render(); }
		} ).catch( function () {
			monthCache[ key ] = [];
		} );
	}

	/* ----------------------------------------------------------------- */
	/* Helpers                                                            */
	/* ----------------------------------------------------------------- */

	function euro( n ) { return '€ ' + Number( n ).toFixed( 2 ).replace( '.', ',' ); }

	function el( tag, attrs, kids ) {
		var e = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				if ( k === 'class' ) { e.className = attrs[ k ]; }
				else if ( k === 'html' ) { e.innerHTML = attrs[ k ]; }
				else if ( k === 'text' ) { e.textContent = attrs[ k ]; }
				else if ( k.slice( 0, 2 ) === 'on' && typeof attrs[ k ] === 'function' ) { e.addEventListener( k.slice( 2 ).toLowerCase(), attrs[ k ] ); }
				else if ( attrs[ k ] === true ) { e.setAttribute( k, '' ); }
				else if ( attrs[ k ] !== false && attrs[ k ] != null ) { e.setAttribute( k, attrs[ k ] ); }
			} );
		}
		( kids || [] ).forEach( function ( c ) {
			if ( c == null ) { return; }
			e.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
		} );
		return e;
	}

	function api( path, params ) {
		var url = D.rest + path;
		if ( params ) {
			url += '?' + Object.keys( params ).map( function ( k ) {
				return encodeURIComponent( k ) + '=' + encodeURIComponent( params[ k ] );
			} ).join( '&' );
		}
		return fetch( url, { headers: { 'X-WP-Nonce': D.nonce } } ).then( parseJson );
	}
	function apiPost( path, body ) {
		return fetch( D.rest + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': D.nonce },
			body: JSON.stringify( body )
		} ).then( parseJson );
	}
	function parseJson( res ) { return res.json().then( function ( d ) { return { ok: res.ok, data: d }; } ); }

	function iso( y, m, d ) { return y + '-' + String( m + 1 ).padStart( 2, '0' ) + '-' + String( d ).padStart( 2, '0' ); }

	function fmtDate( isoStr, short ) {
		if ( ! isoStr ) { return ''; }
		var d = new Date( isoStr + 'T12:00:00' );
		if ( short ) { return DAYS_SHORT[ d.getDay() ] + ' ' + d.getDate() + ' ' + MONTHS_SHORT[ d.getMonth() ]; }
		return DAYS_SHORT[ d.getDay() ] + ' ' + d.getDate() + ' ' + MONTHS_FULL[ d.getMonth() ] + ' ' + d.getFullYear();
	}

	function boat() { return BOATS.filter( function ( b ) { return b.id === state.sloep; } )[ 0 ] || null; }
	function product() { return PRODUCTS.filter( function ( p ) { return p.id === state.pakket; } )[ 0 ] || null; }
	function slot() { return state.slotsData.filter( function ( s ) { return s.id === state.slot; } )[ 0 ] || null; }

	function stepList() { return FIXED_BOAT ? [ 2, 3, 4, 5 ] : [ 1, 2, 3, 4, 5 ]; }
	function effStep() { var l = stepList(); return state.step < l[ 0 ] ? l[ 0 ] : state.step; }

	function canNext( s ) {
		if ( s === 1 ) { return !! state.sloep; }
		if ( s === 2 ) { return !! state.pakket; }
		if ( s === 3 ) { return !! state.datum; }
		if ( s === 4 ) { return !! state.slot; }
		return true;
	}

	/* ----------------------------------------------------------------- */
	/* Navigatie                                                          */
	/* ----------------------------------------------------------------- */

	function setState( patch ) { Object.assign( state, patch ); render(); }

	function goToStep( n ) {
		if ( state.fase !== 'form' ) { return; }
		clearTimeout( autoTimer );
		state.step = n;
		state.errors = {};
		render();
		if ( n === 4 ) { loadSlots(); }
	}

	function autoAdvance() {
		if ( ! AUTO ) { return; }
		var s = effStep();
		clearTimeout( autoTimer );
		autoTimer = setTimeout( function () { if ( canNext( s ) && state.fase === 'form' ) { goToStep( s + 1 ); } }, 300 );
	}

	function next() {
		var s = effStep();
		if ( ! canNext( s ) ) { return; }
		if ( s === 5 ) { submit(); return; }
		goToStep( s + 1 );
	}

	function loadSlots() {
		if ( ! state.pakket || ! state.datum ) { return; }
		clearTimeout( autoTimer );
		setState( { loadingSlots: true, slotsData: [] } );
		var params = { product_id: state.pakket, date: state.datum };
		if ( state.sloep ) { params.boat_type_id = state.sloep; }
		api( '/timeslots', params ).then( function ( r ) {
			var slots = ( r.ok && r.data.timeslots ) ? r.data.timeslots.map( function ( s ) {
				return {
					id: s.id,
					name: String( s.label ).replace( /\s*\(.*\)\s*$/, '' ).trim() || s.label,
					tijd: s.start + ' tot ' + s.end,
					available: !! s.available
				};
			} ) : [];
			setState( { loadingSlots: false, slotsData: slots } );
		} ).catch( function () {
			setState( { loadingSlots: false, slotsData: [] } );
		} );
	}

	/* ----------------------------------------------------------------- */
	/* Verzenden                                                          */
	/* ----------------------------------------------------------------- */

	function submit() {
		var errors = {};
		if ( ! state.naam.trim() ) { errors.naam = true; }
		if ( ! /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test( state.email.trim() ) ) { errors.email = true; }
		if ( state.tel.replace( /\D/g, '' ).length < 8 ) { errors.tel = true; }
		if ( ! state.akkoord ) { errors.akkoord = true; }
		if ( Object.keys( errors ).length ) { setState( { errors: errors } ); return; }

		setState( { fase: 'processing', errors: {} } );

		apiPost( '/book', {
			product_id: state.pakket,
			boat_type_id: state.sloep,
			timeslot_id: state.slot,
			date: state.datum,
			name: state.naam.trim(),
			email: state.email.trim(),
			phone: state.tel.trim(),
			persons: state.personen,
			agree: state.akkoord ? 1 : 0,
			return_url: D.return
		} ).then( function ( r ) {
			if ( ! r.ok || ! r.data.checkout_url ) {
				setState( { fase: 'form', errors: { server: ( r.data && r.data.error ) || 'Er ging iets mis. Probeer het opnieuw.' } } );
				return;
			}
			// Samenvatting bewaren zodat het successcherm na de betaling compleet is.
			try {
				sessionStorage.setItem( 'shb_' + r.data.booking_number, JSON.stringify( summaryData().concat( [ { label: '__amount', val: product() ? product().price : 0 } ] ) ) );
			} catch ( e ) {}
			window.location.href = r.data.checkout_url;
		} ).catch( function () {
			setState( { fase: 'form', errors: { server: 'Er ging iets mis. Probeer het opnieuw.' } } );
		} );
	}

	function reset() {
		clearTimeout( autoTimer );
		Object.assign( state, {
			step: FIXED_BOAT ? 2 : 1,
			sloep: FIXED_BOAT ? FIXED_BOAT.id : null,
			pakket: null, datum: null, slot: null, slotsData: [], loadingSlots: false,
			naam: '', email: '', tel: '', personen: 2, akkoord: false,
			errors: {}, fase: 'form', bookingNr: '', successData: null
		} );
		render();
	}

	/* ----------------------------------------------------------------- */
	/* Afgeleide data                                                     */
	/* ----------------------------------------------------------------- */

	function summaryData() {
		var b = boat(), p = product(), s = slot();
		return [
			{ label: 'Sloep', val: b ? b.name : '', edit: FIXED_BOAT ? null : 1 },
			{ label: 'Pakket', val: p ? p.name : '', edit: 2 },
			{ label: 'Datum', val: fmtDate( state.datum ), edit: 3 },
			{ label: 'Tijdslot', val: s ? ( s.name + ' (' + s.tijd.replace( ' tot ', ' - ' ) + ')' ) : '', edit: 4 }
		];
	}

	function summaryParts() {
		var parts = [], b = boat(), p = product(), s = slot();
		if ( b ) { parts.push( b.name ); }
		if ( p ) { parts.push( p.kort ); }
		if ( state.datum ) { parts.push( fmtDate( state.datum, true ) ); }
		if ( s && effStep() >= 4 ) { parts.push( s.name.toLowerCase() ); }
		return parts;
	}

	/* ----------------------------------------------------------------- */
	/* DOM: launcher + paneel                                             */
	/* ----------------------------------------------------------------- */

	var chevSvg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#15324F" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 15 12 9 18 15"></polyline></svg>';

	var launcher = el( 'button', { 'class': 'shb-scope shb-launcher', type: 'button', onclick: function () { setState( { open: true } ); } }, [
		el( 'img', { 'class': 'shb-launcher-logo', src: D.logo, alt: '' } ),
		el( 'span', { 'class': 'shb-launcher-txt' }, [
			el( 'span', { 'class': 'shb-launcher-label', id: 'shb-l-label' } ),
			el( 'span', { 'class': 'shb-launcher-sub', id: 'shb-l-sub' } )
		] ),
		el( 'span', { 'class': 'shb-launcher-chev', html: chevSvg } )
	] );

	var panel = el( 'div', { 'class': 'shb-scope shb-panel', role: 'dialog', 'aria-label': 'Boeken', hidden: true } );

	document.body.appendChild( launcher );
	document.body.appendChild( panel );

	/* ----------------------------------------------------------------- */
	/* Render                                                             */
	/* ----------------------------------------------------------------- */

	function render() {
		// Launcher label/sub + zichtbaarheid.
		var parts = summaryParts();
		var b = boat();
		var lLabel = 'BOEK JE SLOEP';
		var lSub = 'Vanaf ' + euro( MIN_PRICE ) + ' · geen vaarbewijs nodig';
		if ( state.fase === 'success' ) { lLabel = 'JE BOEKING IS BEVESTIGD'; lSub = 'Bekijk je overzicht'; }
		else if ( parts.length ) { lLabel = 'VERDER MET BOEKEN'; lSub = parts.join( ' · ' ); }
		else if ( FIXED_BOAT ) { lLabel = 'BOEK DE ' + FIXED_BOAT.name.toUpperCase(); }
		document.getElementById( 'shb-l-label' ).textContent = lLabel;
		document.getElementById( 'shb-l-sub' ).textContent = lSub;
		launcher.hidden = state.open;

		panel.hidden = ! state.open;
		if ( ! state.open ) { return; }

		panel.innerHTML = '';
		var list = stepList();
		var step = effStep();
		var isForm = state.fase === 'form' || state.fase === 'processing';
		var processing = state.fase === 'processing';
		var p = product();

		// Header.
		var title = state.fase === 'success' ? 'Je boeking' : ( FIXED_BOAT ? FIXED_BOAT.name + ' boeken' : 'Boek je sloep' );
		panel.appendChild( el( 'div', { 'class': 'shb-panel-header' }, [
			el( 'div', { 'class': 'shb-ph-left' }, [
				el( 'img', { 'class': 'shb-ph-logo', src: D.logo, alt: '' } ),
				el( 'div', {}, [
					el( 'div', { 'class': 'shb-ph-title', text: title } ),
					el( 'div', { 'class': 'shb-ph-sub', text: 'SLOEPHUREN ZAANSTAD' } )
				] )
			] ),
			el( 'button', { 'class': 'shb-close', type: 'button', 'aria-label': 'Sluiten', text: '×', onclick: function () { setState( { open: false } ); } } )
		] ) );

		// Voortgangsbalk (alleen tijdens de flow).
		if ( isForm ) {
			var prog = el( 'div', { 'class': 'shb-progress' } );
			list.forEach( function ( i ) {
				var done = i <= step;
				var clickable = i < step && ! processing;
				prog.appendChild( el( 'div', {
					'class': 'shb-seg' + ( done ? ' is-done' : '' ) + ( clickable ? ' is-clickable' : '' ),
					onclick: clickable ? function () { goToStep( i ); } : null
				} ) );
			} );
			panel.appendChild( prog );
		}

		// Body.
		var body = el( 'div', { 'class': 'shb-body' } );

		if ( isForm ) {
			var titles = { 1: 'Kies je sloep', 2: 'Kies je pakket', 3: 'Kies een datum', 4: 'Kies een tijdslot', 5: 'Je gegevens' };
			body.appendChild( el( 'div', { 'class': 'shb-kicker', text: 'STAP ' + ( list.indexOf( step ) + 1 ) + ' VAN ' + list.length } ) );
			body.appendChild( el( 'h2', { 'class': 'shb-title', text: titles[ step ] } ) );
			body.appendChild( renderStep( step ) );
			if ( processing ) {
				body.appendChild( el( 'div', { 'class': 'shb-processing-note', text: 'Je wordt doorgestuurd naar iDEAL om de betaling af te ronden.' } ) );
			}
		} else if ( state.fase === 'success' || state.fase === 'failed' ) {
			body.appendChild( renderResult() );
		}

		body.appendChild( el( 'div', { 'class': 'shb-trust', html: 'Veilig betalen via iDEAL <span>✦</span> Directe bevestiging' } ) );
		panel.appendChild( body );

		// Actiebalk (alleen tijdens de flow).
		if ( isForm ) { panel.appendChild( renderActionBar( step, processing ) ); }
	}

	function checkEl( sel ) { return el( 'div', { 'class': 'shb-check', text: '✓' } ); }

	function renderStep( step ) {
		if ( step === 1 ) { return renderCards( BOATS.map( function ( b ) {
			return { sel: state.sloep === b.id, name: b.name, sub: b.sub, onClick: function () { pickBoat( b ); } };
		} ) ); }

		if ( step === 2 ) { return renderCards( PRODUCTS.map( function ( p ) {
			return { sel: state.pakket === p.id, name: p.name, sub: p.sub, price: euro( p.price ), onClick: function () { setState( { pakket: p.id, slot: null } ); autoAdvance(); } };
		} ) ); }

		if ( step === 3 ) { return renderCalendar(); }

		if ( step === 4 ) { return renderSlots(); }

		return renderForm();
	}

	function pickBoat( b ) {
		state.sloep = b.id;
		state.slot = null;
		if ( state.personen > b.max ) { state.personen = b.max; }
		render();
		autoAdvance();
	}

	function renderCards( items ) {
		var wrap = el( 'div', { 'class': 'shb-step shb-stack' } );
		items.forEach( function ( it ) {
			var right = [];
			if ( it.price ) { right.push( el( 'div', { 'class': 'shb-card-price', text: it.price } ) ); }
			right.push( checkEl() );
			wrap.appendChild( el( 'button', {
				type: 'button',
				'class': 'shb-card' + ( it.sel ? ' is-selected' : '' ) + ( it.disabled ? ' is-disabled' : '' ) + ( it.struck ? ' is-struck' : '' ),
				onclick: it.disabled ? null : it.onClick
			}, [
				el( 'div', { 'class': 'shb-card-row' }, [
					el( 'div', { 'class': 'shb-card-main' }, [
						el( 'div', { 'class': 'shb-card-name', text: it.name } ),
						it.sub ? el( 'div', { 'class': 'shb-card-sub', text: it.sub } ) : null,
						it.time ? el( 'div', { 'class': 'shb-card-time', text: it.time } ) : null,
						it.status ? el( 'div', { 'class': 'shb-card-status' + ( it.statusBooked ? ' is-booked' : '' ), text: it.status } ) : null
					] ),
					el( 'div', { 'class': 'shb-card-right' }, right )
				] )
			] ) );
		} );
		return wrap;
	}

	function renderSlots() {
		var wrap = el( 'div', { 'class': 'shb-step' } );
		if ( state.loadingSlots ) {
			var sk = el( 'div', { 'class': 'shb-stack' } );
			sk.appendChild( el( 'div', { 'class': 'shb-skeleton' } ) );
			sk.appendChild( el( 'div', { 'class': 'shb-skeleton' } ) );
			wrap.appendChild( sk );
			return wrap;
		}
		if ( ! state.slotsData.length ) {
			wrap.appendChild( el( 'div', { 'class': 'shb-card-sub', text: 'Geen tijdsloten beschikbaar op deze dag. Kies een andere datum.' } ) );
			return wrap;
		}
		wrap.appendChild( renderCards( state.slotsData.map( function ( s ) {
			return {
				sel: state.slot === s.id,
				disabled: ! s.available,
				struck: ! s.available,
				name: s.name,
				time: s.tijd,
				status: s.available ? 'Beschikbaar' : 'Volgeboekt',
				statusBooked: ! s.available,
				onClick: function () { setState( { slot: s.id } ); autoAdvance(); }
			};
		} ) ) );
		return wrap;
	}

	function renderCalendar() {
		var y = state.calY, m = state.calM;
		var first = new Date( y, m, 1 );
		var lead = ( first.getDay() + 6 ) % 7;
		var dim = new Date( y, m + 1, 0 ).getDate();
		var prevAllowed = y > today.getFullYear() || ( y === today.getFullYear() && m > today.getMonth() );

		// Niet-beschikbare dagen (blokkades + volgeboekt) voor deze maand ophalen.
		loadMonthAvailability( y, m );
		var unavailable = monthCache[ monthCacheKey( y, m ) ] || [];

		var grid = el( 'div', { 'class': 'shb-cal-grid' } );
		for ( var i = 0; i < lead; i++ ) { grid.appendChild( el( 'div', { 'class': 'shb-cal-day is-empty' } ) ); }
		for ( var d = 1; d <= dim; d++ ) {
			( function ( day ) {
				var dt = new Date( y, m, day );
				var isoD = iso( y, m, day );
				var past = dt < today;
				var booked = ! past && unavailable.indexOf( isoD ) !== -1;
				var selected = state.datum === isoD;
				var isToday = dt.getTime() === today.getTime();
				var clickable = ! past && ! booked;
				var cls = 'shb-cal-day';
				if ( past ) { cls += ' is-past'; }
				if ( booked ) { cls += ' is-booked'; }
				if ( selected ) { cls += ' is-selected'; }
				if ( isToday && ! selected ) { cls += ' is-today'; }
				grid.appendChild( el( 'div', {
					'class': cls,
					text: String( day ),
					onclick: clickable ? function () { setState( { datum: isoD, slot: null } ); autoAdvance(); } : null
				} ) );
			} )( d );
		}

		var wd = el( 'div', { 'class': 'shb-cal-weekdays' } );
		WEEKDAYS.forEach( function ( w ) { wd.appendChild( el( 'div', { 'class': 'shb-cal-wd', text: w } ) ); } );

		return el( 'div', { 'class': 'shb-step' }, [
			el( 'div', { 'class': 'shb-cal' }, [
				el( 'div', { 'class': 'shb-cal-head' }, [
					el( 'button', { 'class': 'shb-cal-nav', type: 'button', 'aria-label': 'Vorige maand', disabled: ! prevAllowed, text: '‹', onclick: prevAllowed ? function () {
						var nm = m === 0 ? 11 : m - 1, ny = m === 0 ? y - 1 : y;
						setState( { calM: nm, calY: ny } );
					} : null } ),
					el( 'div', { 'class': 'shb-cal-month', text: MONTHS_CAPS[ m ] + ' ' + y } ),
					el( 'button', { 'class': 'shb-cal-nav', type: 'button', 'aria-label': 'Volgende maand', text: '›', onclick: function () {
						var nm = m === 11 ? 0 : m + 1, ny = m === 11 ? y + 1 : y;
						setState( { calM: nm, calY: ny } );
					} } )
				] ),
				wd,
				grid,
				el( 'div', { 'class': 'shb-cal-foot', text: 'Doorgestreepte dagen zijn niet beschikbaar.' } )
			] )
		] );
	}

	function renderForm() {
		var wrap = el( 'div', { 'class': 'shb-step shb-fields' } );

		function field( label, type, key, placeholder ) {
			return el( 'div', { 'class': 'shb-field' }, [
				el( 'label', { 'class': 'shb-label', text: label } ),
				el( 'input', {
					'class': 'shb-input' + ( state.errors[ key ] ? ' is-error' : '' ),
					type: type, value: state[ key ], placeholder: placeholder,
					oninput: function ( e ) { state[ key ] = e.target.value; state.errors[ key ] = false; }
				} )
			] );
		}

		wrap.appendChild( field( 'Naam', 'text', 'naam', 'Voor- en achternaam' ) );
		wrap.appendChild( field( 'E-mailadres', 'email', 'email', 'naam@voorbeeld.nl' ) );

		// Telefoon + personen op één rij.
		var b = boat();
		var maxP = b ? b.max : 8;
		wrap.appendChild( el( 'div', { 'class': 'shb-field-row' }, [
			el( 'div', { 'class': 'shb-field' }, [
				el( 'label', { 'class': 'shb-label', text: 'Telefoon' } ),
				el( 'input', { 'class': 'shb-input' + ( state.errors.tel ? ' is-error' : '' ), type: 'tel', value: state.tel, placeholder: '06 12345678',
					oninput: function ( e ) { state.tel = e.target.value; state.errors.tel = false; } } )
			] ),
			el( 'div', { 'class': 'shb-field' }, [
				el( 'label', { 'class': 'shb-label', text: 'Personen' } ),
				el( 'div', { 'class': 'shb-persons' }, [
					el( 'button', { 'class': 'shb-step-btn', type: 'button', 'aria-label': 'Minder', text: '−', onclick: function () { setState( { personen: Math.max( 1, state.personen - 1 ) } ); } } ),
					el( 'div', { 'class': 'shb-persons-val', text: String( state.personen ) } ),
					el( 'button', { 'class': 'shb-step-btn', type: 'button', 'aria-label': 'Meer', text: '+', onclick: function () { setState( { personen: Math.min( maxP, state.personen + 1 ) } ); } } )
				] )
			] )
		] ) );

		// Akkoord.
		var termsTxt = D.terms
			? el( 'div', { 'class': 'shb-terms-txt', html: 'Ik ga akkoord met de <a href="' + D.terms + '" target="_blank" rel="noopener">voorwaarden</a>.' } )
			: el( 'div', { 'class': 'shb-terms-txt', html: 'Ik ga akkoord met de <span class="shb-terms-link">voorwaarden</span>.' } );
		wrap.appendChild( el( 'div', { 'class': 'shb-terms', onclick: function () { setState( { akkoord: ! state.akkoord } ); } }, [
			el( 'div', { 'class': 'shb-checkbox' + ( state.akkoord ? ' is-checked' : '' ) + ( state.errors.akkoord ? ' is-error' : '' ), text: state.akkoord ? '✓' : '' } ),
			termsTxt
		] ) );

		// Foutblok.
		var errorMsg = false;
		if ( state.errors.server ) { errorMsg = state.errors.server; }
		else if ( state.errors.akkoord && Object.keys( state.errors ).length === 1 ) { errorMsg = 'Ga nog even akkoord met de voorwaarden om te kunnen betalen.'; }
		else if ( Object.keys( state.errors ).length ) { errorMsg = 'Controleer de gemarkeerde velden en probeer het opnieuw.'; }
		if ( errorMsg ) { wrap.appendChild( el( 'div', { 'class': 'shb-error', text: errorMsg } ) ); }

		// Samenvatting.
		wrap.appendChild( renderSummary( summaryData(), true ) );
		return wrap;
	}

	function renderSummary( rows, withEdit ) {
		var box = el( 'div', { 'class': 'shb-summary' }, [
			el( 'div', { 'class': 'shb-sum-kicker', text: 'Jouw boeking' } )
		] );
		rows.forEach( function ( r ) {
			if ( r.label === '__amount' ) { return; }
			var right = [ el( 'div', { 'class': 'shb-sum-val', text: r.val } ) ];
			if ( withEdit && r.edit ) {
				right.push( el( 'button', { 'class': 'shb-sum-edit', type: 'button', text: 'WIJZIG', onclick: function () { goToStep( r.edit ); } } ) );
			}
			box.appendChild( el( 'div', { 'class': 'shb-sum-row' }, [
				el( 'div', { 'class': 'shb-sum-label', text: r.label } ),
				el( 'div', { 'class': 'shb-sum-right' }, right )
			] ) );
		} );
		if ( withEdit ) {
			box.appendChild( el( 'div', { 'class': 'shb-sum-foot', text: 'Borg van € 250 voldoe je bij vertrek. Je betaalt nu veilig via iDEAL.' } ) );
		}
		return box;
	}

	function renderResult() {
		var wrap = el( 'div', { 'class': 'shb-step', style: 'display:flex;flex-direction:column;gap:14px' } );
		var success = state.fase === 'success';

		var box = el( 'div', { 'class': 'shb-result-box ' + ( success ? 'is-success' : 'is-fail' ) } );
		if ( success ) {
			box.appendChild( el( 'div', { 'class': 'shb-result-title', text: 'Bedankt, je betaling is gelukt!' } ) );
			box.appendChild( el( 'div', { 'class': 'shb-result-text', text: 'Je boeking is definitief. Je ontvangt direct een bevestiging per e-mail met alle praktische informatie.' } ) );
			if ( state.bookingNr ) { box.appendChild( el( 'div', { 'class': 'shb-result-nr', text: 'Boekingsnummer: ' + state.bookingNr } ) ); }
		} else {
			box.appendChild( el( 'div', { 'class': 'shb-result-title', text: 'De betaling is niet afgerond.' } ) );
			box.appendChild( el( 'div', { 'class': 'shb-result-text', text: 'Je boeking is nog niet definitief. Probeer opnieuw te boeken.' } ) );
		}
		wrap.appendChild( box );

		// Samenvatting + betaald bedrag (uit sessionStorage indien beschikbaar).
		if ( success && state.successData ) {
			var rows = state.successData.filter( function ( r ) { return r.label !== '__amount' && r.val; } );
			var amount = ( state.successData.filter( function ( r ) { return r.label === '__amount'; } )[ 0 ] || {} ).val;
			var sum = renderSummary( rows, false );
			if ( amount ) {
				sum.appendChild( el( 'div', { 'class': 'shb-sum-total' }, [
					el( 'div', { 'class': 'shb-sum-total-label', text: 'BETAALD' } ),
					el( 'div', { 'class': 'shb-sum-total-val', text: euro( amount ) } )
				] ) );
			}
			wrap.appendChild( sum );
		}

		wrap.appendChild( el( 'button', { 'class': 'shb-reset', type: 'button', text: success ? 'PLAN NOG EEN VAARTOCHT' : 'OPNIEUW PROBEREN', onclick: function () { reset(); } } ) );
		return wrap;
	}

	function renderActionBar( step, processing ) {
		var p = product();
		var parts = summaryParts();
		var stickyPrijs = p ? euro( p.price ) : 'Vanaf ' + euro( MIN_PRICE );
		var stickySub = processing ? 'Je wordt doorgestuurd naar iDEAL' : ( parts.length ? parts.join( ' · ' ) : 'Nog niets gekozen' );
		var canN = canNext( step ) && ! processing;
		var nextLabel = step < 5 ? 'Volgende' : ( processing ? 'Bezig met betalen...' : 'Boek & betaal' );
		var canBack = step > stepList()[ 0 ] && ! processing;

		var kids = [
			el( 'div', { 'class': 'shb-ab-price' }, [
				el( 'div', { 'class': 'shb-ab-total', text: stickyPrijs } ),
				el( 'div', { 'class': 'shb-ab-sub', text: stickySub } )
			] )
		];
		if ( canBack ) { kids.push( el( 'button', { 'class': 'shb-back', type: 'button', text: 'TERUG', onclick: function () { goToStep( Math.max( stepList()[ 0 ], step - 1 ) ); } } ) ); }
		kids.push( el( 'button', { 'class': 'shb-next', type: 'button', text: nextLabel, disabled: ! canN, onclick: function () { next(); } } ) );

		return el( 'div', { 'class': 'shb-actionbar' }, kids );
	}

	/* ----------------------------------------------------------------- */
	/* Retour na betaling                                                 */
	/* ----------------------------------------------------------------- */

	function handleReturn() {
		var q = new URLSearchParams( window.location.search );
		var nr = q.get( 'shb_booking' );
		var status = q.get( 'shb_status' );
		if ( ! nr ) { return; }

		if ( status === 'paid' ) {
			var data = null;
			try { data = JSON.parse( sessionStorage.getItem( 'shb_' + nr ) || 'null' ); } catch ( e ) {}
			state.fase = 'success';
			state.bookingNr = nr;
			state.successData = data;
			state.open = true;
		} else {
			state.fase = 'failed';
			state.open = true;
		}
	}

	/* ----------------------------------------------------------------- */
	/* Open-triggers (shortcode, CSS-class en hash-link)                  */
	/* ----------------------------------------------------------------- */

	// Widget openen, optioneel met een voorgeselecteerde sloep (op naam).
	function openWidget( sloepName ) {
		if ( sloepName ) {
			var b = BOATS.filter( function ( x ) { return x.name.toLowerCase() === String( sloepName ).toLowerCase(); } )[ 0 ];
			if ( b ) {
				state.sloep = b.id;
				if ( state.step < 2 ) { state.step = 2; }
			}
		}
		setState( { open: true } );
	}
	// Publiek beschikbaar zodat ook onclick="shbOpenWidget()" kan.
	window.shbOpenWidget = openWidget;

	// Klik op een element met class shb-open of een link naar #sloephuren.
	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target || ! e.target.closest ) { return; }
		var t = e.target.closest( '.shb-open, a[href$="#sloephuren"], a[href$="#boek-sloep"]' );
		if ( ! t ) { return; }
		e.preventDefault();
		openWidget( t.getAttribute( 'data-shb-sloep' ) );
	} );

	// Direct openen wanneer de pagina met #sloephuren wordt geladen.
	if ( window.location.hash === '#sloephuren' || window.location.hash === '#boek-sloep' ) {
		state.open = true;
	}

	// Mobiel/desktop wisselen.
	window.matchMedia( '(max-width: 640px)' ).addEventListener( 'change', function ( e ) {
		setState( { isMobile: e.matches } );
	} );

	handleReturn();
	render();
} )();
