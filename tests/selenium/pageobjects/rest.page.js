'use strict';

const Util = require( 'wdio-mediawiki/Util' );

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
		/* eslint-disable camelcase */
		const data = {
			event_page: event,
			start_time: '29990414160000',
			end_time: '29990515170000',
			// TODO: Add this when the feature is implemented
			// type: 'generic',
			wikis: [],
			online_meeting: true,
			timezone: 'EST'
		};
		/* eslint-enable camelcase */

		await Util.waitForModuleState( 'mediawiki.base' );
		return await browser.execute( async ( baseData ) => {
			const reqData = {
				...baseData,
				token: mw.user.tokens.get( 'csrfToken' )
			};
			await mw.loader.using( 'mediawiki.api' );
			const rest = new mw.Rest();
			const response = await rest.post( '/campaignevents/v0/event_registration', reqData )
				.catch( ( err, errData ) => {
					const errorText = errData.xhr.responseText || err;
					throw new Error( `Enable registration API request failed with error ${ errorText }` );
				} );
			return response.id;
		}, data );
	}
};
