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

	/**
	 * Shows the "remove all" confirmation box only when "all entries" is selected.
	 */
	function setupClearForm() {
		var select  = document.getElementById( 'flac-older-than' );
		var confirm = document.querySelector( '.flac-confirm-all' );
		if ( ! select || ! confirm ) {
			return;
		}

		function toggle() {
			var all = select.value === 'all';
			confirm.classList.toggle( 'flac-hidden', ! all );
			if ( ! all ) {
				var box = confirm.querySelector( 'input[type="checkbox"]' );
				if ( box ) {
					box.checked = false;
				}
			}
		}

		select.addEventListener( 'change', toggle );
		toggle();
	}

	/**
	 * On small screens, expands or collapses a row when any part of it is
	 * clicked. Clicks on links and on the core toggle button are left alone:
	 * WordPress itself toggles the row for the button.
	 */
	function setupRowToggle() {
		var table = document.querySelector( '.flac-wrap .wp-list-table' );
		if ( ! table ) {
			return;
		}

		table.addEventListener( 'click', function ( event ) {
			var row = event.target.closest( 'tbody tr' );
			if ( ! row || row.classList.contains( 'no-items' ) ) {
				return;
			}

			var button = row.querySelector( '.toggle-row' );
			if ( ! button || window.getComputedStyle( button ).display === 'none' ) {
				return; // Wide screen: every column is visible.
			}

			if ( event.target.closest( 'a, button, input, select, label' ) ) {
				return;
			}

			row.classList.toggle( 'is-expanded' );
		} );
	}

	function init() {
		run();
		setupClearForm();
		setupRowToggle();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
