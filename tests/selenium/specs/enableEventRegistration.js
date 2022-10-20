'use strict';

const assert = require( 'assert' ),
	EnableEventRegistrationPage = require( '../pageobjects/enableEventRegistration.page' ),
	UserLoginPage = require( 'wdio-mediawiki/LoginPage' ),
	event = EnableEventRegistrationPage.getTestString( 'Event:e2e' );

describe( 'Enable Event Registration', function () {

	before( async function () {
		await UserLoginPage.loginAdmin();
	} );

	it( 'is configured correctly', async function () {
		EnableEventRegistrationPage.open();

		assert( await EnableEventRegistrationPage.enableRegistration.isExisting() );
	} );

	it( 'requires event data', async function () {
		await EnableEventRegistrationPage.enableEvent( event );

		assert.deepEqual( await EnableEventRegistrationPage.generalError.getText(), 'There are problems with some of your input.' );
	} );

	it( 'can be enabled', async function () {
		await EnableEventRegistrationPage.createEvent( event );
		await EnableEventRegistrationPage.enableEvent( event );

		assert.deepEqual( await EnableEventRegistrationPage.feedback.getText(), 'Registration is enabled. Participants can now register on the event page.' );
	} );
} );
