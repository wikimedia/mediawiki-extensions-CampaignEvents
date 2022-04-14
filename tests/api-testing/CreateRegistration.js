'use strict';

const { assert, REST } = require( 'api-testing' );

describe( 'POST /campaignevents/v0/event_registration', () => {
	const client = new REST( 'rest.php/campaignevents/v0/event_registration' );

	const reqBody = {
		name: 'Some registration',
		event_page: 'Some page',
		chat_url: 'https://example.org',
		tracking_tool_name: 'Some tracking tool',
		tracking_tool_url: 'htps://example.org',
		start_time: '20200220200220',
		end_time: '20200220200222',
		type: 'generic',
		online_meeting: true
	};

	describe( 'param validation', () => {
		it( 'fails if no parameters were given', async () => {
			const { body: sourceBody } = await client.post( '' );
			assert.strictEqual( sourceBody.httpCode, 400 );
			assert.property( sourceBody, 'messageTranslations' );
			assert.property( sourceBody.messageTranslations, 'en' );
			assert.include( sourceBody.messageTranslations.en, 'Mandatory field' );
		} );
	} );

	describe( 'authentication', () => {
		it( 'fails if not using OAuth', async () => {
			const { body: sourceBody } = await client.post( '', reqBody );
			assert.strictEqual( sourceBody.httpCode, 400 );
			assert.strictEqual( sourceBody.message, 'This endpoint must be used with OAuth' );
		} );
	} );
} );
