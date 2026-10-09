/**
 * Probe Site Doctor — Core Web Vitals collector.
 *
 * Reads the browser's own performance entries for this page view and sends
 * them to this site once, when the page is hidden. It stores nothing in the
 * browser, sets no cookie, identifies nobody and contacts no third party.
 *
 * Deliberately small and dependency-free: it must not itself be a performance
 * problem on the site it measures.
 */
( function () {
	'use strict';

	var config = window.probeSiteDoctorVitals;

	if ( ! config || ! config.endpoint || ! window.PerformanceObserver ) {
		return;
	}

	// Sampling: a share of page views report, so a busy site does not write a
	// row for every visitor.
	if ( config.sampling < 100 && Math.random() * 100 >= config.sampling ) {
		return;
	}

	var metrics = {};
	var sent = false;

	function observe( type, handler, options ) {
		try {
			var observer = new PerformanceObserver( function ( list ) {
				list.getEntries().forEach( handler );
			} );
			observer.observe( Object.assign( { type: type, buffered: true }, options || {} ) );
			return observer;
		} catch ( error ) {
			// An unsupported entry type throws; that metric is simply not reported.
			return null;
		}
	}

	// Largest Contentful Paint: keep the latest candidate.
	observe( 'largest-contentful-paint', function ( entry ) {
		metrics.lcp = entry.startTime;
	} );

	// First Contentful Paint.
	observe( 'paint', function ( entry ) {
		if ( 'first-contentful-paint' === entry.name ) {
			metrics.fcp = entry.startTime;
		}
	} );

	// Cumulative Layout Shift: sum of shifts the user did not cause.
	var cls = 0;
	observe( 'layout-shift', function ( entry ) {
		if ( ! entry.hadRecentInput ) {
			cls += entry.value;
			metrics.cls = cls;
		}
	} );

	// Interaction to Next Paint: the worst interaction is what the metric reports.
	var worstInteraction = 0;
	observe(
		'event',
		function ( entry ) {
			if ( entry.interactionId && entry.duration > worstInteraction ) {
				worstInteraction = entry.duration;
				metrics.inp = worstInteraction;
			}
		},
		{ durationThreshold: 40 }
	);

	// Time to first byte, from the navigation entry.
	try {
		var navigation = performance.getEntriesByType( 'navigation' )[ 0 ];
		if ( navigation && navigation.responseStart > 0 ) {
			metrics.ttfb = navigation.responseStart;
		}
	} catch ( error ) {}

	function send() {
		if ( sent ) {
			return;
		}

		var names = Object.keys( metrics );
		if ( ! names.length ) {
			return;
		}
		sent = true;

		var payload = JSON.stringify( {
			path: config.path,
			device: window.innerWidth < 768 ? 'mobile' : 'desktop',
			metrics: metrics
		} );

		// sendBeacon survives the page going away; fetch with keepalive is the fallback.
		try {
			if ( navigator.sendBeacon ) {
				navigator.sendBeacon( config.endpoint, new Blob( [ payload ], { type: 'application/json' } ) );
				return;
			}
		} catch ( error ) {}

		try {
			fetch( config.endpoint, {
				method: 'POST',
				keepalive: true,
				headers: { 'Content-Type': 'application/json' },
				body: payload
			} );
		} catch ( error ) {}
	}

	// Report when the page is hidden, which is the last reliable moment, and
	// again on pagehide for browsers that skip visibilitychange.
	document.addEventListener(
		'visibilitychange',
		function () {
			if ( 'hidden' === document.visibilityState ) {
				send();
			}
		},
		{ once: false }
	);
	window.addEventListener( 'pagehide', send, { once: true } );
}() );
