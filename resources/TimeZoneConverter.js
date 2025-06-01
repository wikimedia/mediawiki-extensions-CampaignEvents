( function () {
	'use strict';

	/**
	 * Utility class to convert a time range and time zone to the user browser's timezone, when the
	 * user did not specify a timezone in the MediaWiki preferences. The time is then reformatted
	 * according to user preferences.
	 *
	 * @constructor
	 */
	function TimeZoneConverter() {
		this.rest = new mw.Rest();
	}

	/**
	 * Given a DOM node containing a time range and a time zone, convert them to the user's
	 * preferred timezone, as derived from the browser preferences.
	 *
	 * @param {jQuery} $element This must contain:
	 *   - The time range text in a node with class `ext-campaignevents-time-range`, with attributes
	 *     `data-mw-start` and `data-mw-end` containing ISO 8601 UTC timestamps of the start and end
	 *     time respectively.
	 *   - A node with the `ext-campaignevents-timezone` class, where the timezone is displayed.
	 *     This must contain the timezone only, with no accompanying text.
	 * @param {string} message Key of the message used for the range. Must accept exactly six
	 *   parameters, in order: start datetime, start date, start time, end datetime, end date,
	 *   end time.
	 *   @return {jQuery.Promise}
	 */
	TimeZoneConverter.prototype.convert = function ( $element, message ) {
		const timezonePref = mw.user.options.get( 'timecorrection' );

		if ( timezonePref.lastIndexOf( 'System', 0 ) !== 0 ) {
			// Timezone preference set explicitly, do not convert.
			return $.when();
		}

		let newTimezone;
		try {
			newTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
		} catch ( e ) {
			// Intl unavailable, likely an old browser.
			return $.when();
		}

		const $rangeElement = $element.find( '.ext-campaignevents-time-range' ),
			$timezoneElement = $element.find( '.ext-campaignevents-timezone' );

		const language = mw.config.get( 'wgUserLanguage' ),
			startUTC = $rangeElement.data( 'mw-start' ),
			endUTC = $rangeElement.data( 'mw-end' );

		if ( !startUTC || !endUTC ) {
			throw new Error( 'No timestamp(s) provided' );
		}

		// First, convert the time to the local timezone, but format it in the machine-readable
		// TS_MW format. This will then be passed to the server for the final formatting pass.
		const convertedStart = this.formatMwTimestamp( startUTC, newTimezone ),
			convertedEnd = this.formatMwTimestamp( endUTC, newTimezone );

		// Note, failures are ignored as we're already displaying the time.
		return this.rest.get(
			'/campaignevents/v0/formatted_time/' + language + '/' + convertedStart + '/' + convertedEnd,
			{}
		).then( ( resp ) => {
			// eslint-disable-next-line mediawiki/msg-doc
			const formattedRange = mw.msg(
				message,
				resp.startDateTime,
				resp.startDate,
				resp.startTime,
				resp.endDateTime,
				resp.endDate,
				resp.endTime
			);
			$rangeElement.text( formattedRange );
			$timezoneElement.text( newTimezone );
		} );
	};

	/**
	 * @param {string} ts A UTC timestamp in the ISO 8601 format
	 * @param {string} timezone IANA timezone
	 * @return {string} The timestamp converted to the given timezone and formatted as TS_MW
	 */
	TimeZoneConverter.prototype.formatMwTimestamp = function ( ts, timezone ) {
		const date = Date.parse( ts );
		const intlOptions = {
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
			hour: '2-digit',
			minute: '2-digit',
			second: '2-digit',
			hour12: false,
			timeZone: timezone
		};
		const datePartsList = new Intl.DateTimeFormat( 'en', intlOptions ).formatToParts( date ),
			dateParts = {};
		datePartsList.forEach( ( el ) => {
			// 'literal' and other unused types are harmless.
			dateParts[ el.type ] = el.value;
		} );
		return dateParts.year + dateParts.month + dateParts.day +
			dateParts.hour + dateParts.minute + dateParts.second;
	};

	module.exports = new TimeZoneConverter();
}() );
