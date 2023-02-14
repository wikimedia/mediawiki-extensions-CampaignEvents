'use strict';

const axios = require( 'axios' );

module.exports = {
	/*
	 * Enable an event through the API, to bypass GUI interactions.
	 *
	 * Pass in an an event name, and an event will be created
	 *
	 * @param {string} event a namespaced string beginning with 'Event:'
	 * example: 'Event:Test'
	 *
	 */
	async enableEvent( event ) {
		const csrfToken = await browser.execute( () => {
			return mw.user.tokens.values.csrfToken; // eslint-disable-line no-undef
		} );
		const cookies = await browser.getCookies();
		const cookieString = cookies.map( ( cookie ) => `${cookie.name}=${cookie.value};` ).join( '' ); // phpcs:ignore
		const baseUrl = await browser.options.baseUrl;
		const baseUrlString = await baseUrl.endsWith( '/' ) ? baseUrl.slice( 0, -1 ) : baseUrl;

		// axios
		const data = JSON.stringify( {
			token: csrfToken,
			name: event,
			event_page: event, // eslint-disable-line camelcase
			start_time: '20230414160000', // eslint-disable-line camelcase
			end_time: '20230515170000', // eslint-disable-line camelcase
			type: 'generic',
			online_meeting: true, // eslint-disable-line camelcase
			timezone: 'EST'
		} );

		const config = {
			method: 'post',
			maxBodyLength: Infinity,
			url: `${await baseUrlString}/rest.php/campaignevents/v0/event_registration`, // phpcs:ignore
			headers: {
				'Content-type': 'application/json',
				Cookie: await cookieString // phpcs:ignore
			},
			data: data
		};

		try {
			const response = await axios( config );
			return response.data.id;
		} catch ( error ) {
			return error;
		}

	}
};
