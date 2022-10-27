'use strict';

const assert = require( 'assert' ),
	EnableEventRegistrationPage = require( '../pageobjects/enableEventRegistration.page' ),
	UserPage = require( '../pageobjects/user.page' ),
	LoginPage = require( 'wdio-mediawiki/LoginPage' ),
	event = EnableEventRegistrationPage.getTestString( 'Event:e2e' ),
	userName = EnableEventRegistrationPage.getTestString();

describe( 'Enable Event Registration', function () {

	before( async function () {
		await LoginPage.loginAdmin();
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

	it( 'can have one user register publicly', async function () {
		await EnableEventRegistrationPage.createEvent( event );
		await EnableEventRegistrationPage.enableEvent( event );
		await UserPage.createAccount( userName );
		await UserPage.register( userName, event );
		await expect( await EnableEventRegistrationPage.successfulRegistration ).toBeDisplayed();
	} );

	it( 'can have one user register privately', async function () {
		await EnableEventRegistrationPage.createEvent( event );
		await EnableEventRegistrationPage.enableEvent( event );
		await UserPage.createAccount( 'Private' + userName );
		await UserPage.register( 'Private' + userName, event, true );
		await expect( await EnableEventRegistrationPage.successfulRegistration ).toBeDisplayed();
	} );
} );
