'use strict';

const { action, assert, REST, clientFactory, utils } = require( 'api-testing' ),
	EventUtils = require( './EventUtils.js' );

describe( 'POST /campaignevents/v0/event_registration', () => {
	let anonClient, organizerClient, blockedUserClient,
		anonToken, organizerToken, blockedUserToken,
		eventPage;

	before( async function () {
		// Increase the timeout, because we need to block a user and edit a page
		this.timeout( 5000 );

		const organizerUser = await EventUtils.getOrganizerUser();
		organizerToken = await organizerUser.token();

		const anonUser = action.getAnon();
		anonToken = await anonUser.token();

		const blockedUser = await action.blockedUser();
		blockedUserToken = await blockedUser.token();

		anonClient = new REST( 'rest.php/campaignevents/v0/event_registration' );
		organizerClient = clientFactory.getRESTClient( 'rest.php/campaignevents/v0/event_registration', organizerUser );
		blockedUserClient = clientFactory.getRESTClient( 'rest.php/campaignevents/v0/event_registration', blockedUser );

		eventPage = utils.title( 'Event:Event page ' );
		await organizerUser.edit( eventPage, {} );
	} );

	function getBody( token ) {
		return {
			event_page: eventPage,
			timezone: 'UTC',
			start_time: '30200220200220',
			end_time: '30200220200222',
			types: [ 'other' ],
			wikis: [],
			online_meeting: true,
			chat_url: 'https://example.org',
			token: token
		};
	}

	describe( 'permission error', () => {
		it( 'fails session check for anonymous users', async () => {
			const { body: sourceBody } = await anonClient.post( '', getBody( anonToken ) );
			assert.strictEqual( sourceBody.errorKey, 'rest-badtoken' );
			assert.strictEqual( sourceBody.httpCode, 403 );
			assert.property( sourceBody, 'messageTranslations' );
			assert.property( sourceBody.messageTranslations, 'en' );
			assert.include( sourceBody.messageTranslations.en, 'no session' );
		} );
		it( 'fails for a blocked user', async () => {
			const { body: sourceBody } = await blockedUserClient.post( '', getBody( blockedUserToken ) );
			assert.strictEqual( sourceBody.errorKey, 'campaignevents-rest-enable-registration-permission-denied' );
			assert.strictEqual( sourceBody.httpCode, 403 );
		} );
	} );

	describe( 'param validation', () => {
		it( 'fails if no parameters were given', async () => {
			const { body: sourceBody } = await organizerClient.post( '' );
			assert.property( sourceBody, 'failureCode' );
			assert.equal( sourceBody.failureCode, 'missingparam' );
			assert.strictEqual( sourceBody.httpCode, 400 );
		} );
	} );

	describe( 'successful', () => {
		it( 'succeeds for an authorized user if the request body is valid', async () => {
			const { status: statusCode, body: sourceBody } = await organizerClient.post( '', getBody( organizerToken ) );
			assert.strictEqual( statusCode, 201, 'Got error: ' + sourceBody.errorKey );
			assert.property( sourceBody, 'id' );
			assert.isNumber( sourceBody.id );
		} );
	} );
} );
