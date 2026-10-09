/**
 * Probe Site Doctor — dashboard.
 *
 * Progressive enhancement: without JavaScript the "Run scan" form posts to
 * admin-post.php and the whole scan runs in one request. With JavaScript the
 * scan runs one check per REST request, with a progress bar.
 *
 * @package ProbeSiteDoctor
 */
( function () {
	'use strict';

	const { __, sprintf } = wp.i18n;
	const apiFetch = wp.apiFetch;
	const config = window.probeSiteDoctorDashboard || {};
	const base = '/' + ( config.namespace || 'probe-site-doctor/v1' );

	/* ---------- Scan runner ---------- */

	const form = document.getElementById( 'probesd-run-form' );
	const button = document.getElementById( 'probesd-run-button' );
	const progress = document.getElementById( 'probesd-progress' );
	const bar = document.getElementById( 'probesd-progress-bar' );
	const status = document.getElementById( 'probesd-progress-status' );

	let running = false;

	const setStatus = ( text, percent ) => {
		status.textContent = text;
		if ( typeof percent === 'number' ) {
			bar.value = Math.max( 0, Math.min( 100, percent ) );
		}
	};

	const fail = ( error ) => {
		running = false;
		button.disabled = false;
		button.removeAttribute( 'aria-busy' );
		progress.classList.add( 'is-error' );
		const message = error && error.message ? error.message : __( 'The scan could not be completed.', 'probe-site-doctor' );
		setStatus( message );
		window.removeEventListener( 'beforeunload', warnOnLeave );
	};

	const warnOnLeave = ( event ) => {
		event.preventDefault();
		event.returnValue = '';
	};

	const runScan = async () => {
		running = true;
		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );
		progress.hidden = false;
		progress.classList.remove( 'is-error' );
		setStatus( __( 'Starting scan…', 'probe-site-doctor' ), 0 );
		window.addEventListener( 'beforeunload', warnOnLeave );

		const started = await apiFetch( { path: base + '/scans', method: 'POST' } );
		const checks = started.checks || [];
		let failedRequests = 0;

		for ( let i = 0; i < checks.length; i++ ) {
			const check = checks[ i ];
			setStatus(
				/* translators: 1: Check name, 2: Current check number, 3: Total number of checks. */
				sprintf( __( 'Checking %1$s (%2$d of %3$d)…', 'probe-site-doctor' ), check.label, i + 1, checks.length ),
				( i / checks.length ) * 100
			);
			try {
				await apiFetch( {
					path: base + '/scans/' + started.scan_id + '/checks/' + encodeURIComponent( check.id ),
					method: 'POST',
				} );
			} catch ( e ) {
				// A single failed request (e.g. a timeout) must not abort the scan; it is left out of the report.
				failedRequests++;
			}
		}

		setStatus( __( 'Building report…', 'probe-site-doctor' ), 100 );
		const done = await apiFetch( { path: base + '/scans/' + started.scan_id + '/complete', method: 'POST' } );

		window.removeEventListener( 'beforeunload', warnOnLeave );
		setStatus(
			failedRequests
				? sprintf(
					/* translators: %d: Number of checks. */
					__( 'Scan complete, but %d checks could not be reached. Loading report…', 'probe-site-doctor' ),
					failedRequests
				)
				: __( 'Scan complete. Loading report…', 'probe-site-doctor' ),
			100
		);

		const url = new URL( done.report_url, window.location.href );
		url.searchParams.set( 'probesd_notice', 'scan_done' );
		window.location.assign( url.toString() );
	};

	if ( form && button && progress && bar && status && apiFetch ) {
		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			if ( running ) {
				return;
			}
			runScan().catch( fail );
		} );
	}

	/* ---------- Copy buttons for manual cleanup commands ---------- */

	// Copying only puts text on the clipboard; nothing is ever executed.
	if ( navigator.clipboard && window.isSecureContext ) {
		document.querySelectorAll( '.probesd-command' ).forEach( ( block ) => {
			const copy = block.querySelector( '.probesd-copy' );
			const code = block.querySelector( 'code' );
			if ( ! copy || ! code ) {
				return;
			}
			copy.hidden = false;
			copy.addEventListener( 'click', () => {
				navigator.clipboard.writeText( code.textContent ).then(
					() => {
						copy.textContent = __( 'Copied', 'probe-site-doctor' );
						window.setTimeout( () => ( copy.textContent = __( 'Copy', 'probe-site-doctor' ) ), 2000 );
					},
					() => {
						copy.textContent = __( 'Copy failed', 'probe-site-doctor' );
					}
				);
			} );
		} );
	}

	/* ---------- Severity filter ---------- */

	const filter = document.querySelector( '.probesd-filter' );
	if ( filter ) {
		const buttons = Array.from( filter.querySelectorAll( '[data-probesd-filter]' ) );
		const groups = Array.from( document.querySelectorAll( '[data-probesd-group]' ) );
		const empty = document.querySelector( '.probesd-filter-empty' );

		const apply = ( value ) => {
			let visibleTotal = 0;
			groups.forEach( ( group ) => {
				let visible = 0;
				group.querySelectorAll( '.probesd-finding' ).forEach( ( item ) => {
					const show = value === 'all' || item.dataset.severity === value;
					item.hidden = ! show;
					visible += show ? 1 : 0;
				} );
				group.hidden = visible === 0;
				visibleTotal += visible;
			} );
			buttons.forEach( ( b ) => b.setAttribute( 'aria-pressed', String( b.dataset.probesdFilter === value ) ) );
			if ( empty ) {
				empty.hidden = visibleTotal > 0;
			}
		};

		buttons.forEach( ( b ) => b.addEventListener( 'click', () => apply( b.dataset.probesdFilter ) ) );
		filter.hidden = false;
	}
}() );
