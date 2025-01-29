'use strict';

const { action, assert, REST, clientFactory, utils } = require( 'api-testing' );
const EventUtils = require( './EventUtils.js' );

describe( 'PUT /campaignevents/v0/event_registration/{id}', () => {
	// Note that the event ID must be specified when using these clients
	let anonClient, organizerClient, blockedUserClient,
		anonToken, organizerToken, blockedUserToken,
		eventPage, eventID;

	before( async function () {
		// Increase the timeout, because we need to block a user and edit a page
		this.timeout( 5000 );

		const organizerUser = await action.root();
		organizerToken = await organizerUser.token();

		const anonUser = action.getAnon();
		anonToken = await anonUser.token();

		const blockedUser = await action.blockedUser();
		blockedUserToken = await blockedUser.token();

		eventPage = utils.title( 'Event:Event page ' );
		await organizerUser.edit( eventPage, {} );
		const creationBody = getBody( organizerToken );
		creationBody.meeting_url = 'https://example.org';
		delete creationBody.status;
		eventID = await EventUtils.enableRegistration( organizerUser, creationBody );

		anonClient = new REST( 'rest.php/campaignevents/v0/event_registration/' );
		organizerClient = clientFactory.getRESTClient(
			'rest.php/campaignevents/v0/event_registration/',
			organizerUser
		);
		blockedUserClient = clientFactory.getRESTClient(
			'rest.php/campaignevents/v0/event_registration/',
			blockedUser
		);
	} );

	function getBody( token ) {
		return {
			event_page: eventPage,
			chat_url: 'https://example.org',
			timezone: 'UTC',
			start_time: '30200220200220',
			end_time: '30200220200222',
			// TODO: Add this when the feature is implemented
			// type: 'generic',
			status: 'open',
			wikis: [],
			online_meeting: true,
			token: token
		};
	}

	describe( 'permission error', () => {
		it( 'fails session check for anonymous users', async () => {
			const { body: sourceBody } = await anonClient.put( eventID, getBody( anonToken ) );
			assert.strictEqual( sourceBody.errorKey, 'rest-badtoken' );
			assert.strictEqual( sourceBody.httpCode, 403 );
			assert.property( sourceBody, 'messageTranslations' );
			assert.property( sourceBody.messageTranslations, 'en' );
			assert.include( sourceBody.messageTranslations.en, 'no session' );
		} );
		it( 'fails for a blocked user', async () => {
			const { body: sourceBody } = await blockedUserClient.put(
				eventID,
				getBody( blockedUserToken )
			);
			assert.strictEqual( sourceBody.errorKey, 'campaignevents-edit-not-allowed-registration' );
			assert.strictEqual( sourceBody.httpCode, 403 );
		} );
	} );

	describe( 'param validation', () => {
		it( 'fails if no parameters were given', async () => {
			const { body: sourceBody } = await organizerClient.put( eventID );
			assert.property( sourceBody, 'failureCode' );
			assert.equal( sourceBody.failureCode, 'missingparam' );
			assert.strictEqual( sourceBody.httpCode, 400 );
		} );
		it( 'cannot be used to create a new event', async () => {
			const nonExistentEventID = eventID + 1000;
			const { body: sourceBody } = await organizerClient.put(
				nonExistentEventID,
				getBody( organizerToken )
			);
			assert.strictEqual( sourceBody.errorKey, 'campaignevents-rest-event-not-found' );
			assert.strictEqual( sourceBody.httpCode, 404 );
		} );
	} );

	describe( 'successful', () => {
		it( 'succeeds for an authorized user if the request body is valid', async () => {
			const { status: statusCode, body: sourceBody } = await organizerClient.put(
				eventID,
				getBody( organizerToken )
			);
			assert.strictEqual( statusCode, 204, 'Got error: ' + sourceBody.errorKey );
		} );
	} );
} );
