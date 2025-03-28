'use strict';

const EventPage = require( '../pageobjects/event.page' ),
	Api = require( 'wdio-mediawiki/Api' ),
	LoginPage = require( 'wdio-mediawiki/LoginPage' ),
	EventUtils = require( '../EventUtils.js' ),
	Util = require( 'wdio-mediawiki/Util' ),
	event = Util.getTestString( 'Event:Test event page' );

describe( 'Event page', () => {
	before( async () => {
		await EventUtils.loginAsOrganizer();
		await EventUtils.createEvent( event );
	} );

	async function loginWithNewAccount( userName ) {
		const password = 'aaaaaaaaa!';
		const bot = await Api.bot();
		await Api.createAccount( bot, userName, password );
		await LoginPage.login( userName, password );
	}

	it( 'can have one user register publicly', async () => {
		const userName = Util.getTestString( 'Public' );
		await loginWithNewAccount( userName );
		await EventPage.open( event );
		await EventPage.register();
		await expect( await EventPage.successfulRegistration ).toBeDisplayed();
	} );

	it( 'can have one user register privately', async () => {
		const userName = Util.getTestString( 'Private' );
		await loginWithNewAccount( userName );
		await EventPage.open( event );
		await EventPage.register( true );
		await expect( await EventPage.successfulRegistration ).toBeDisplayed();
	} );

	it( 'can have a user cancel registration', async () => {
		const userName = Util.getTestString( 'Cancel' );
		await loginWithNewAccount( userName );
		await EventPage.open( event );
		await EventPage.register();
		await expect( await EventPage.successfulRegistration ).toBeDisplayed();
		await EventPage.cancelRegistration( true );
		await expect( await EventPage.registerForEventButton ).toBeDisplayed();
	} );
} );
