'use strict';

const assert = require( 'assert' ),
	EventRegistrationPage = require( '../pageobjects/eventRegistration.page' ),
	LoginPage = require( 'wdio-mediawiki/LoginPage' ),
	Util = require( 'wdio-mediawiki/Util' ),
	event = Util.getTestString( 'Event:Test EnableEventRegistration' );

describe( 'Enable Event Registration', function () {

	before( async function () {
		await LoginPage.loginAdmin();
	} );

	it( 'is configured correctly', async function () {
		EventRegistrationPage.open();

		assert( await EventRegistrationPage.enableRegistration.isExisting() );
	} );

	it( 'requires event data', async function () {
		await EventRegistrationPage.enableEvent( event );

		assert.deepEqual( await EventRegistrationPage.generalError.getText(), 'There are problems with some of your input.' );
	} );

	it( 'can be enabled', async function () {
		await EventRegistrationPage.createEventPage( event );
		await EventRegistrationPage.enableEvent( event );

		assert.deepEqual( await EventRegistrationPage.feedback.getText(), 'Registration is enabled. Participants can now register on the event page.' );
	} );
} );
