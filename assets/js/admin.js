/**
 * Shows attempt times in the browser time zone.
 *
 * The server prints each time as <time datetime="…Z"> with the site time zone
 * as text. This script replaces that text with the browser's local time.
 */
( function () {
	'use strict';

	function run() {
		if ( typeof Intl === 'undefined' || typeof Intl.DateTimeFormat !== 'function' ) {
			return;
		}

		var formatter;
		try {
			formatter = new Intl.DateTimeFormat( undefined, { dateStyle: 'medium', timeStyle: 'long' } );
		} catch ( e ) {
			formatter = new Intl.DateTimeFormat( undefined, {
				year: 'numeric', month: 'short', day: 'numeric',
				hour: '2-digit', minute: '2-digit', second: '2-digit', timeZoneName: 'short'
			} );
		}

		var elements = document.querySelectorAll( 'time.flac-time[datetime]' );
		for ( var i = 0; i < elements.length; i++ ) {
			var date = new Date( elements[ i ].getAttribute( 'datetime' ) );
			if ( isNaN( date.getTime() ) ) {
				continue;
			}
			elements[ i ].textContent = formatter.format( date );
		}

		var notice = document.querySelector( '.flac-timezone-notice' );
		var zone   = Intl.DateTimeFormat().resolvedOptions().timeZone;
		if ( notice && zone && window.flacAdmin && window.flacAdmin.timeZoneNotice ) {
			notice.textContent = window.flacAdmin.timeZoneNotice.replace( '%s', zone );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}
} )();
