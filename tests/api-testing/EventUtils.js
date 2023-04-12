'use strict';

const { assert, clientFactory, action, utils } = require( 'api-testing' );

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
			rootUser = await action.root();
		await rootUser.edit( eventPage, {} );
		const reqBody = {
			event_page: eventPage,
			timezone: 'UTC',
			start_time: '30200220200220',
			end_time: '30200220200222',
			type: 'generic',
			online_meeting: true,
			token: await rootUser.token()
		};
		return this.enableRegistration( rootUser, reqBody );
	}
};
