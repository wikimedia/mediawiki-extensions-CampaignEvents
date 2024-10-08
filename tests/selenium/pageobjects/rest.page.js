'use strict';

const axios = require( 'axios' ),
	assert = require( 'assert' );

module.exports = {
	/**
	 * Enable an event through the API, to bypass GUI interactions.
	 *
	 * Pass in an an event name, and an event will be created
	 *
	 * @param {string} event a namespaced string beginning with 'Event:'
	 * example: 'Event:Test'
	 * @return {Promise<number>}
	 */
	async enableEvent( event ) {
		// eslint-disable-next-line no-undef
		const csrfToken = await browser.execute( () => mw.user.tokens.values.csrfToken );
		const cookies = await browser.getCookies();
		const cookieString = cookies.map( ( cookie ) => `${ cookie.name }=${ cookie.value };` ).join( '' );
		const baseUrl = await browser.options.baseUrl;
		const baseUrlString = baseUrl.endsWith( '/' ) ? baseUrl.slice( 0, -1 ) : baseUrl;

		/* eslint-disable camelcase */
		const data = JSON.stringify( {
			token: csrfToken,
			event_page: event,
			start_time: '29990414160000',
			end_time: '29990515170000',
			// TODO: Add this when the feature is implemented
			// type: 'generic',
			online_meeting: true,
			timezone: 'EST'
		} );
		/* eslint-enable camelcase */

		const config = {
			method: 'post',
			maxBodyLength: Infinity,
			url: `${ await baseUrlString }/rest.php/campaignevents/v0/event_registration`,
			headers: {
				'Content-type': 'application/json',
				Cookie: await cookieString
			},
			data: data
		};

		try {
			const response = await axios( config );
			return response.data.id;
		} catch ( error ) {
			const errorDetails = JSON.stringify( error.response.data );
			assert.fail( `Enable registration API request failed with status code ${ error.response.status }:\n${ errorDetails }` );
		}

	}
};
