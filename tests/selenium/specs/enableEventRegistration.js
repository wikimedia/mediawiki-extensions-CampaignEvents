'use strict';

const EventRegistrationPage = require( '../pageobjects/eventRegistration.page' ),
	LoginPage = require( 'wdio-mediawiki/LoginPage' ),
	Util = require( 'wdio-mediawiki/Util' ),
	event = Util.getTestString( 'Event:Test EnableEventRegistration' );

describe( 'Enable Event Registration @daily', () => {

	before( async () => {
		await LoginPage.loginAdmin();
	} );

	it( 'is configured correctly', async () => {
		EventRegistrationPage.open();

		await expect( await EventRegistrationPage.enableRegistration ).toExist();
	} );

	it( 'requires event data', async () => {
		await EventRegistrationPage.enableEvent( event );

		await expect( await EventRegistrationPage.generalError ).toHaveText( 'There are problems with some of your input.' );
	} );

	it( 'can be enabled', async () => {
		await EventRegistrationPage.createEventPage( event );
		await EventRegistrationPage.enableEvent( event );

		await expect( await EventRegistrationPage.successNotice ).toHaveText( 'Registration is enabled. Participants can now register on the event page.' );
	} );
} );
