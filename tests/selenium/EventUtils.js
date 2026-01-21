import * as Util from 'wdio-mediawiki/Util';
import LoginPage from 'wdio-mediawiki/LoginPage';
import * as Api from 'wdio-mediawiki/Api';

const EventUtils = {
	/** Credentials for the default organizer account */
	organizerName: Util.getTestString( 'Event organizer' ),
	organizerPassword: 'correct horse battery staple',

	/**
	 * Logs in as an event organizer. Creates the organizer account if necessary (i.e., unless it
	 * was already done in this test run).
	 */
	async loginAsOrganizer() {
		await this.createOrganizerAccount( this.organizerName, this.organizerPassword );
		await LoginPage.login( this.organizerName, this.organizerPassword );
	},

	/**
	 * Create an event organizer account.
	 *
	 * @param {string} username
	 * @param {string} password
	 */
	async createOrganizerAccount( username, password = this.organizerPassword ) {
		try {
			const adminBot = await Api.createApiClient();
			await adminBot.createAccount( username, password );
			await adminBot.addUserToGroup( username, 'event-organizer' );
		} catch ( error ) {
			console.error( 'Full error:', error );

			// Logging everything I can get from https://github.com/gesinn-it-pub/mwbot/blob/master/src/index.js#L254-L271
			if ( error.code ) {
				console.error( 'Error Code:', error.code );
			}
			if ( error.info ) {
				console.error( 'Error Info:', error.info );
			}
			if ( error.response ) {
				console.error( 'Response:', error.response );
			}
			if ( error.request ) {
				console.error( 'Request:', error.request );
			}
			if ( error.errorResponse ) {
				console.error( 'Is API Error Response:', error.errorResponse );
			}

			throw error;
		}
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
		const bot = await Api.createApiClient( {
			username: this.organizerName,
			password: this.organizerPassword
		} );
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
			timezone: 'EST',
			start_time: '29990414160000',
			end_time: '29990515170000',
			types: [ 'editing-event' ],
			wikis: [ '*' ],
			tracks_contributions: true,
			online_meeting: true
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

export default EventUtils;
