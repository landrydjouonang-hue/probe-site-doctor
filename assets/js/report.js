/**
 * Probe Site Doctor — report screen.
 *
 * Only enhancement here is the print button: the report itself is plain HTML
 * and prints (or saves to PDF) without any JavaScript.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var button = document.getElementById( 'probesd-print' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			window.print();
		} );
	} );
}() );
