'use strict';

const { action, assert, REST } = require( 'api-testing' );

describe( 'POST /campaignevents/v0/event_registration', () => {
	const client = new REST( 'rest.php/campaignevents/v0/event_registration' );
	let reqBody;

	before( async () => {
		reqBody = {
			name: 'Some registration',
			event_page: 'Some page',
			chat_url: 'https://example.org',
			timezone: 'UTC',
			start_time: '20200220200220',
			end_time: '20200220200222',
			type: 'generic',
			online_meeting: true,
			token: await action.getAnon().token()
		};
	} );

	describe( 'param validation', () => {
		it( 'fails if no parameters were given', async () => {
			const { body: sourceBody } = await client.post( '' );
			assert.strictEqual( sourceBody.httpCode, 400 );
			assert.property( sourceBody, 'messageTranslations' );
			assert.property( sourceBody.messageTranslations, 'en' );
			assert.include( sourceBody.messageTranslations.en, 'Mandatory field' );
		} );
	} );

	describe( 'permission error', () => {
		it( 'fails for anonymous users', async () => {
			const { body: sourceBody } = await client.post( '', reqBody );
			assert.strictEqual( sourceBody.httpCode, 403 );
			assert.property( sourceBody, 'messageTranslations' );
			assert.property( sourceBody.messageTranslations, 'en' );
			assert.include( sourceBody.messageTranslations.en, 'not allowed' );
		} );
	} );
} );
