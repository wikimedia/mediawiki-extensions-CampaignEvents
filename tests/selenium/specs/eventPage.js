import LoginPage from 'wdio-mediawiki/LoginPage';
import { createApiClient } from 'wdio-mediawiki/Api';
import * as Util from 'wdio-mediawiki/Util';
import EventPage from '../pageobjects/event.page.js';
import EventUtils from '../EventUtils.js';

const event = Util.getTestString( 'Event:Test event page' );

describe( 'Event page', () => {
	before( async () => {
		await EventUtils.loginAsOrganizer();
		await EventUtils.createEvent( event );
	} );

	async function loginWithNewAccount( userName ) {
		const password = 'aaaaaaaaa!';
		const bot = await createApiClient();
		await bot.createAccount( userName, password );
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
