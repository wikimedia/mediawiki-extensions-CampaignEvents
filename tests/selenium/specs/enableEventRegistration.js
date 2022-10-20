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
		await EnableEventRegistrationPage.open();

		assert( await EnableEventRegistrationPage.enableRegistration.isExisting() );
	} );

	it( 'requires event data', async function () {
		await EnableEventRegistrationPage.createEvent( event );

		assert.deepEqual( await EnableEventRegistrationPage.generalError.getText(), 'There are problems with some of your input.' );
	} );
} );
