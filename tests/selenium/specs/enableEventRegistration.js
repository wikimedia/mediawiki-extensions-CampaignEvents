'use strict';

const assert = require( 'assert' ),
	EventRegistrationPage = require( '../pageobjects/eventRegistration.page' ),
	UserPage = require( '../pageobjects/user.page' ),
	LoginPage = require( 'wdio-mediawiki/LoginPage' ),
	event = EventRegistrationPage.getTestString( 'Event:e2e' ),
	userName = EventRegistrationPage.getTestString();

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
		await EventRegistrationPage.createEvent( event );
		await EventRegistrationPage.enableEvent( event );

		assert.deepEqual( await EventRegistrationPage.feedback.getText(), 'Registration is enabled. Participants can now register on the event page.' );
	} );

	it( 'can have one user register publicly', async function () {
		await EventRegistrationPage.createEvent( event );
		await EventRegistrationPage.enableEvent( event );
		await UserPage.createAccount( userName );
		await UserPage.register( userName, event );
		await expect( await EventRegistrationPage.successfulRegistration ).toBeDisplayed();
	} );

	it( 'can have one user register privately', async function () {
		await EventRegistrationPage.createEvent( event );
		await EventRegistrationPage.enableEvent( event );
		await UserPage.createAccount( 'Private' + userName );
		await UserPage.register( 'Private' + userName, event, true );
		await expect( await EventRegistrationPage.successfulRegistration ).toBeDisplayed();
	} );

	it( 'can have a user cancel registration', async function () {
		await EventRegistrationPage.createEvent( event );
		await EventRegistrationPage.enableEvent( event );
		await UserPage.createAccount( 'TestCancel' + userName );
		await UserPage.register( 'TestCancel' + userName, event );
		await UserPage.cancelRegistration();
		await expect( await UserPage.registerForEvent ).toBeDisplayed();
	} );
} );
