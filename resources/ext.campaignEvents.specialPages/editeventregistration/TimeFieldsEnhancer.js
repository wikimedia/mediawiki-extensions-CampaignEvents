( function () {
	'use strict';

	function TimeFieldsEnhancer() {
		this.dateWidgets = {
			start: '.ext-campaignevents-time-input-event-start',
			end: '.ext-campaignevents-time-input-event-end'
		};
		this.isPastEvent = mw.config.get( 'wgCampaignEventsIsPastEvent' );
		this.eventHasAnswers = mw.config.get( 'wgCampaignEventsEventHasAnswers' );
		// Same as '@default' but without the timezone and seconds (T317542)
		this.dateFormat = '$!{dow|short} ${day|#} ${month|short} ${year|#} ${hour|0}:${minute|0}';
		this.lastOffset = 0;
	}

	/**
	 * @param {jQuery} $field
	 * @return {Object}
	 */
	TimeFieldsEnhancer.prototype.getInfusedFieldWidget = function ( $field ) {
		try {
			return OO.ui.infuse(
				$field,
				{ formatter: { format: this.dateFormat } }
			);
		} catch ( error ) {
			throw new Error( 'Unexpected time field', error );
		}
	};

	/**
	 * @param {jQuery} $formRoot
	 */
	TimeFieldsEnhancer.prototype.init = function ( $formRoot ) {
		const tzInput = $formRoot.find( '.ext-campaignevents-timezone-input' ).get( 0 );
		if ( !tzInput ) {
			return;
		}
		this.tzInputWidget = OO.ui.infuse( tzInput ).fieldWidget;

		// Infuse the time selectors, setting some options that we need in order to support
		// non-UTC time zones.
		// XXX This is quite hacky, shamelessly messing up with the DateTimeInputWidget internals.
		// The proper solution would be for the widget to natively support customizing the
		// timezone (T315874).
		for ( const dateWidgetKey in this.dateWidgets ) {
			this.dateWidgets[ dateWidgetKey ] = this.getInfusedFieldWidget(
				$formRoot.find( this.dateWidgets[ dateWidgetKey ] )
			);
			// Dates selected through the widget should always have seconds set to 00.
			// Round up the minutes if necessary to ensure that the initial value is valid.
			if (
				this.dateWidgets[ dateWidgetKey ].fieldWidget
					.formatter.defaultDate.getSeconds() !== 0
			) {
				this.dateWidgets[ dateWidgetKey ].fieldWidget
					.formatter.defaultDate.setSeconds( 0 );
				// Note that this will also increase the hour, day etc if needed.
				this.dateWidgets[ dateWidgetKey ].fieldWidget.formatter.defaultDate.setMinutes(
					this.dateWidgets[ dateWidgetKey ].fieldWidget
						.formatter.defaultDate.getMinutes() + 1
				);
			}

			this.tzInputWidget.on(
				'change',
				this.updateWidgetOnTimezoneChange.bind( this, dateWidgetKey )
			);
			this.updateWidgetOnTimezoneChange( dateWidgetKey );
		}
		this.dateWidgets.start.fieldWidget.on( 'change', this.updateEndDate.bind( this ) );
		this.dateWidgets.end.fieldWidget.on(
			'change', this.checkNewSelectedEndDateIsPast.bind( this )
		);
		this.updateEndDate();
		this.checkNewSelectedEndDateIsPast();
	};

	/**
	 * This function udates default, min and max date of time inputs so that they match
	 * the selected timezone.
	 *
	 * @param {string} dateWidgetKey
	 */
	TimeFieldsEnhancer.prototype.updateWidgetOnTimezoneChange = function ( dateWidgetKey ) {
		const tz = this.tzInputWidget.getValue();

		let offset;
		if ( tz.includes( '|' ) ) {
			// Preset value
			const tzParts = tz.split( '|' );
			if ( tzParts.length <= 1 || isNaN( tzParts[ 1 ] ) ) {
				// Unexpected.
				return;
			}
			offset = parseInt( tzParts[ 1 ], 10 );
		} else {
			// Offset specified manually.
			offset = this.hoursToMinutes( tz );
		}

		// Update default date (used when the widget is empty), min and max
		this.dateWidgets[ dateWidgetKey ].fieldWidget.formatter.defaultDate.setTime(
			this.dateWidgets[ dateWidgetKey ].fieldWidget.formatter.defaultDate.getTime() +
				( offset - this.lastOffset ) * 60 * 1000
		);
		this.dateWidgets[ dateWidgetKey ].fieldWidget.min.setTime(
			this.dateWidgets[ dateWidgetKey ].fieldWidget.min.getTime() +
				( offset - this.lastOffset ) * 60 * 1000
		);
		this.dateWidgets[ dateWidgetKey ].fieldWidget.max.setTime(
			this.dateWidgets[ dateWidgetKey ].fieldWidget.max.getTime() +
				( offset - this.lastOffset ) * 60 * 1000
		);

		// Let the widget update its fields to recompute validity of the data
		// XXX We're calling a @private method here...
		this.dateWidgets[ dateWidgetKey ].fieldWidget.updateFieldsFromValue();
		this.lastOffset = offset;
	};

	// Dynamically update the minimum end date to match the current value of
	// the start date.
	TimeFieldsEnhancer.prototype.updateEndDate = function () {
		const newMin = this.dateWidgets.start.fieldWidget.getValueAsDate();
		this.dateWidgets.end.fieldWidget.min.setTime( newMin );
		if ( newMin > this.dateWidgets.end.fieldWidget.formatter.defaultDate ) {
			this.dateWidgets.end.fieldWidget.formatter.defaultDate = newMin;
		}
		// Let the widget update its fields to recompute validity of the data,
		// as well as update the selectable dates in the calendar.
		// XXX We're calling a @private method here...
		this.dateWidgets.end.fieldWidget.onChange();
	};

	/**
	 * FIXME Shamelessly stolen from mediawiki.special.preferences.ooui/timezone.js
	 *
	 * @param {string} hour
	 * @return {number}
	 */
	TimeFieldsEnhancer.prototype.hoursToMinutes = function ( hour ) {
		const arr = hour.split( ':' );

		arr[ 0 ] = parseInt( arr[ 0 ], 10 );

		let minutes;
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
	};

	TimeFieldsEnhancer.prototype.checkNewSelectedEndDateIsPast = function () {
		if ( this.isPastEvent && this.eventHasAnswers ) {
			this.dateWidgets.end.setNotices( [
				mw.msg( 'campaignevents-event-dates-cannot-be-changed' )
			] );
		} else if (
			!this.isPastEvent &&
			this.checkDateIsPast( this.dateWidgets.end.fieldWidget.getValue() )
		) {
			this.dateWidgets.end.setWarnings( [
				mw.msg( 'campaignevents-warning-change-event-end-date-past' )
			] );
		} else {
			this.dateWidgets.end.setWarnings( [] );
		}
	};

	TimeFieldsEnhancer.prototype.checkDateIsPast = function ( date ) {
		const selectedDate = new Date( date ),
			currentTimestamp = Math.floor( Date.now() / 1000 ),
			selectedTimestamp = Math.floor( selectedDate.getTime() / 1000 ),
			timezoneAdjustedCurrentTimestamp = currentTimestamp + this.lastOffset * 60;

		return selectedTimestamp < timezoneAdjustedCurrentTimestamp;
	};

	module.exports = new TimeFieldsEnhancer();
}() );
