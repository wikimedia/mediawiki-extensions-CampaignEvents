'use strict';

const EventRegistrationPage = require( '../pageobjects/eventRegistration.page' ),
	EventPage = require( '../pageobjects/event.page' ),
	Api = require( 'wdio-mediawiki/Api' ),
	LoginPage = require( 'wdio-mediawiki/LoginPage' ),
	Rest = require( '../pageobjects/rest.page' ),
	Util = require( 'wdio-mediawiki/Util' ),
	event = Util.getTestString( 'Event:Test event page' );

describe( 'Event page', function () {
	before( async function () {
		await LoginPage.loginAdmin();
		await EventRegistrationPage.createEventPage( event );
		await Rest.enableEvent( event );
	} );

	async function loginWithNewAccount( userName ) {
		const password = 'aaaaaaaaa!';
		const bot = await Api.bot();
		await Api.createAccount( bot, userName, password );
		await LoginPage.login( userName, password );
	}

	it( 'can have one user register publicly', async function () {
		const userName = Util.getTestString( 'Public' );
		await loginWithNewAccount( userName );
		await EventPage.open( event );
		await EventPage.register();
		await expect( await EventPage.successfulRegistration ).toBeDisplayed();
	} );

	it( 'can have one user register privately', async function () {
		const userName = Util.getTestString( 'Private' );
		await loginWithNewAccount( userName );
		await EventPage.open( event );
		await EventPage.register( true );
		await expect( await EventPage.successfulRegistration ).toBeDisplayed();
	} );

	it( 'can have a user cancel registration', async function () {
		const userName = Util.getTestString( 'Cancel' );
		await loginWithNewAccount( userName );
		await EventPage.open( event );
		await EventPage.register();
		await EventPage.cancelRegistration();
		await expect( await EventPage.registerForEventButton ).toBeDisplayed();
	} );
} );
