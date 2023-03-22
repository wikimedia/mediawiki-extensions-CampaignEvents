'use strict';

const { action, assert, REST, clientFactory } = require( 'api-testing' );
const EventUtils = require( './EventUtils.js' );

describe( 'PUT /campaignevents/v0/event_registration/{id}/participants/self', function () {
	// Note that you must specify the path suffix when using these clients
	// (see getPathSuffixForEvent)
	let anonClient, participantClient, blockedUserClient,
		anonToken, participantToken, blockedUserToken,
		eventID;

	before( async function () {
		// Increase the timeout, because we need to block a user and edit a page
		this.timeout( 5000 );

		const participantUser = await action.alice();
		participantToken = await participantUser.token();

		const anonUser = action.getAnon();
		anonToken = await anonUser.token();

		const blockedUser = await EventUtils.getBlockedUser();
		blockedUserToken = await blockedUser.token();

		eventID = await EventUtils.enableRandomRegistration( participantUser );

		anonClient = new REST( 'rest.php/campaignevents/v0/event_registration/' );
		participantClient = clientFactory.getRESTClient(
			'rest.php/campaignevents/v0/event_registration/',
			participantUser
		);
		blockedUserClient = clientFactory.getRESTClient(
			'rest.php/campaignevents/v0/event_registration/',
			blockedUser
		);
	} );

	function getBody( token, isPrivate = false ) {
		return {
			token: token,
			is_private: isPrivate
		};
	}

	function getPathSuffix( pathEventID ) {
		pathEventID = pathEventID || eventID;
		return String( pathEventID ) + '/participants/self';
	}

	describe( 'permission error', function () {
		it( 'fails session check for anonymous users', async function () {
			const { body: sourceBody } = await anonClient.put(
				getPathSuffix(),
				getBody( anonToken )
			);
			assert.strictEqual( sourceBody.httpCode, 403 );
			assert.strictEqual( sourceBody.errorKey, 'rest-badtoken' );
			assert.property( sourceBody, 'messageTranslations' );
			assert.property( sourceBody.messageTranslations, 'en' );
			assert.include( sourceBody.messageTranslations.en, 'no session' );
		} );
		it( 'fails for a blocked user', async function () {
			const { body: sourceBody } = await blockedUserClient.put(
				getPathSuffix(),
				getBody( blockedUserToken )
			);
			assert.strictEqual( sourceBody.httpCode, 403 );
			assert.property( sourceBody, 'messageTranslations' );
			assert.property( sourceBody.messageTranslations, 'en' );
			assert.include( sourceBody.messageTranslations.en, 'not allowed' );
		} );
	} );

	describe( 'param validation', function () {
		it( 'fails if the event does not exist', async function () {
			const nonExistentEventID = eventID + 1000;
			const { body: sourceBody } = await participantClient.put(
				getPathSuffix( nonExistentEventID ),
				getBody( participantToken )
			);
			assert.strictEqual( sourceBody.httpCode, 404 );
			assert.property( sourceBody, 'messageTranslations' );
			assert.property( sourceBody.messageTranslations, 'en' );
			assert.include( sourceBody.messageTranslations.en, 'There is no event with this ID' );
		} );
	} );

	describe( 'successful', function () {
		it( 'authorized user can register publicly', async function () {
			const { status: statusCode, body: sourceBody } = await participantClient.put(
				getPathSuffix(),
				getBody( participantToken, false )
			);
			assert.strictEqual( statusCode, 200 );
			assert.property( sourceBody, 'modified' );
			assert.strictEqual( sourceBody.modified, true );
		} );
		it( 'authorized user can switch public registration to private', async function () {
			const { status: statusCode, body: sourceBody } = await participantClient.put(
				getPathSuffix(),
				getBody( participantToken, true )
			);
			assert.strictEqual( statusCode, 200 );
			assert.property( sourceBody, 'modified' );
			assert.strictEqual( sourceBody.modified, true );
		} );
		it( 'attempting to set the registration to private again does not change the resource', async function () {
			const { status: statusCode, body: sourceBody } = await participantClient.put(
				getPathSuffix(),
				getBody( participantToken, true )
			);
			assert.strictEqual( statusCode, 200 );
			assert.property( sourceBody, 'modified' );
			assert.strictEqual( sourceBody.modified, false );
		} );
	} );
} );
