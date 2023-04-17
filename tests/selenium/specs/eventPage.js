'use strict';

const EventRegistrationPage = require( '../pageobjects/eventRegistration.page' ),
	UserPage = require( '../pageobjects/user.page' ),
	LoginPage = require( 'wdio-mediawiki/LoginPage' ),
	Rest = require( '../pageobjects/rest.page' ),
	Util = require( 'wdio-mediawiki/Util' ),
	event = Util.getTestString( 'Event:Test event page' ),
	userName = Util.getTestString();

describe( 'Event page', function () {
	before( async function () {
		await LoginPage.loginAdmin();
		await EventRegistrationPage.createEventPage( event );
		await Rest.enableEvent( event );
	} );

	it( 'can have one user register publicly', async function () {
		await UserPage.createAccount( userName );
		await UserPage.register( userName, event );
		await expect( await EventRegistrationPage.successfulRegistration ).toBeDisplayed();
	} );

	it( 'can have one user register privately', async function () {
		await UserPage.createAccount( 'Private' + userName );
		await UserPage.register( 'Private' + userName, event, true );
		await expect( await EventRegistrationPage.successfulRegistration ).toBeDisplayed();
	} );

	it( 'can have a user cancel registration', async function () {
		await UserPage.createAccount( 'TestCancel' + userName );
		await UserPage.register( 'TestCancel' + userName, event );
		await UserPage.cancelRegistration();
		await expect( await UserPage.registerForEvent ).toBeDisplayed();
	} );
} );
