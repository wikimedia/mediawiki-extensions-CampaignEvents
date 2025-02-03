'use strict';

const Util = require( 'wdio-mediawiki/Util' ),
	LoginPage = require( 'wdio-mediawiki/LoginPage' ),
	Api = require( 'wdio-mediawiki/Api' );

module.exports = {
	/** Credentials for the default organizer account */
	organizerName: browser.config.mwUser,
	organizerPassword: browser.config.mwPwd,

	/**
	 * Logs in as an event organizer.
	 */
	async loginAsOrganizer() {
		await LoginPage.login( this.organizerName, this.organizerPassword );
	},

	/**
	 * Creates an event page and enables registration for that page through the API.
	 *
	 * @param {string} eventPage Prefixed title of the event page, such as 'Event:Test'
	 * @return {Promise<number>} Event ID
	 */
	async createEvent( eventPage ) {
		await this.createEventPage( eventPage );
		return await this.enableRegistration( eventPage );
	},

	/**
	 * Creates an event page using the API, while logged in as the default organizer user.
	 *
	 * @param {string} title
	 */
	async createEventPage( title ) {
		const bot = await Api.bot( this.organizerName, this.organizerPassword );
		await bot.edit(
			title,
			'Selenium test page (createEventPage)',
			'Selenium test page (createEventPage)'
		);
	},

	/**
	 * Enable an event through the API, to bypass GUI interactions.
	 *
	 * @param {string} eventPage Prefixed title of the event page, such as 'Event:Test'
	 * @return {Promise<number>} Event ID
	 */
	async enableRegistration( eventPage ) {
		/* eslint-disable camelcase */
		const data = {
			event_page: eventPage,
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
