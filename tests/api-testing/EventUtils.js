'use strict';

const { action, assert, clientFactory, utils } = require( 'api-testing' );

module.exports = {
	async enableRegistration( user, reqBody ) {
		const enableRegistrationClient = clientFactory.getRESTClient( 'rest.php/campaignevents/v0/event_registration', user );
		const { status: statusCode, body: sourceBody } = await enableRegistrationClient.post( '', reqBody );
		assert.strictEqual( statusCode, 201 );
		assert.property( sourceBody, 'id' );
		assert.isNumber( sourceBody.id );
		return sourceBody.id;
	},

	async enableRandomRegistration() {
		const eventPage = utils.title( 'Event:Event page ' ),
			organizerUser = await this.getOrganizerUser();
		await organizerUser.edit( eventPage, {} );
		const reqBody = {
			event_page: eventPage,
			timezone: 'UTC',
			start_time: '30200220200220',
			end_time: '30200220200222',
			types: [ 'other' ],
			wikis: [],
			online_meeting: true,
			token: await organizerUser.token()
		};
		return this.enableRegistration( organizerUser, reqBody );
	},

	async getOrganizerUser() {
		return action.root();
	}
};
