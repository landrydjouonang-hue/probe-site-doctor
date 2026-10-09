/**
 * Probe Site Doctor — Developer screen.
 *
 * One enhancement: copying the diagnostic report to the clipboard. The report
 * is already on the page in a textarea, so this works without it too.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var button = document.getElementById( 'probesd-copy-diagnostics' );
		var source = document.getElementById( 'probesd-diagnostics' );

		if ( ! button || ! source ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var done = function () {
				var original = button.textContent;
				button.textContent = button.dataset.copied || 'Copied';
				window.setTimeout( function () {
					button.textContent = original;
				}, 2000 );
			};

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( source.value ).then( done, function () {
					source.select();
				} );
				return;
			}

			// Older browsers: select the text so the user can copy it.
			source.closest( 'details' ).open = true;
			source.select();
		} );
	} );
}() );
