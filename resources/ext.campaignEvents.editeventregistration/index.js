( function () {
	'use strict';

	var OrganizerSelectionFieldEnhancer = require( './OrganizerSelectionFieldEnhancer.js' );

	/**
	 * FIXME Shamelessly stolen from mediawiki.special.preferences.ooui/timezone.js
	 *
	 * @param {string} hour
	 * @return {number}
	 */
	function hoursToMinutes( hour ) {
		var arr = hour.split( ':' );

		arr[ 0 ] = parseInt( arr[ 0 ], 10 );

		var minutes;
		if ( arr.length === 1 ) {
			// Specification is of the form [-]XX
			minutes = arr[ 0 ] * 60;
		} else {
			// Specification is of the form [-]XX:XX
			minutes = Math.abs( arr[ 0 ] ) * 60 + parseInt( arr[ 1 ], 10 );
			if ( arr[ 0 ] < 0 ) {
				minutes *= -1;
			}
		}
		// Gracefully handle non-numbers.
		if ( isNaN( minutes ) ) {
			return 0;
		} else {
			return minutes;
		}
	}

	mw.hook( 'htmlform.enhance' ).add( function ( $root ) {
		// NOTE: This module has a dependency on mediawiki.widgets.UsersMultiselectWidget
		// because autoinfusion is also handled in a htmlform.enhance callback, so there's no
		// guarantee on which handler runs first. In fact, it throws when using debug=1.
		OrganizerSelectionFieldEnhancer.init( $root.find( '.ext-campaignevents-organizers-multiselect-input' ) );

		var tzInput = $root.find( '.ext-campaignevents-timezone-input' ).get( 0 );
		if ( !tzInput ) {
			return;
		}
		var tzInputWidget = OO.ui.infuse( tzInput ).fieldWidget;
		// Infuse the time selectors, setting some options that we need in order to support
		// non-UTC time zones.
		// XXX This is quite hacky, shamelessly messing up with the DateTimeInputWidget internals.
		// The proper solution would be for the widget to natively support customizing the
		// timezone (T315874).
		$root.find( '.ext-campaignevents-time-input' ).each( function () {
			// Same as '@default' but without the timezone and seconds (T317542)
			var dateFormat = '$!{dow|short} ${day|#} ${month|short} ${year|#} ${hour|0}:${minute|0}';

			var widget = OO.ui.infuse(
				$( this ),
				{ formatter: { format: dateFormat } }
			).fieldWidget;

			// Dates selected through the widget should always have seconds set to 00
			widget.formatter.defaultDate.setSeconds( 0 );

			// The initial values are always UTC; make sure to clone them.
			var utcDefaultDate = new Date( widget.formatter.defaultDate.getTime() ),
				utcMin = new Date( widget.min.getTime() ),
				utcMax = new Date( widget.max.getTime() );

			/**
			 * This function udates default, min and max date of time inputs so that they match
			 * the selected timezone.
			 *
			 * @param {string} tz
			 */
			var updateWidgetDates = function ( tz ) {
				var offset;
				if ( tz.indexOf( '|' ) > -1 ) {
					// Preset value
					var tzParts = tz.split( '|' );
					if ( tzParts.length <= 1 || isNaN( tzParts[ 1 ] ) ) {
						// Unexpected.
						return;
					}
					offset = parseInt( tzParts[ 1 ], 10 );
				} else {
					// Offset specified manually.
					offset = hoursToMinutes( tz );
				}

				// Update default date (used when the widget is empty), min and max
				widget.formatter.defaultDate.setTime(
					utcDefaultDate.getTime() + offset * 60 * 1000
				);
				widget.min.setTime( utcMin.getTime() + offset * 60 * 1000 );
				widget.max.setTime( utcMax.getTime() + offset * 60 * 1000 );

				// Let the widget update its fields to recompute validity of the data
				widget.updateFieldsFromValue();
			};

			tzInputWidget.on( 'change', updateWidgetDates );
			updateWidgetDates( tzInputWidget.getValue() );
		} );
	} );
}() );
