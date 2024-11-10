'use strict';

const EventRegistrationPage = require( '../pageobjects/eventRegistration.page' ),
	EventPage = require( '../pageobjects/event.page' ),
	LoginPage = require( 'wdio-mediawiki/LoginPage' ),
	Util = require( 'wdio-mediawiki/Util' ),
	eventName = Util.getTestString( 'Test EnableEventRegistration' ),
	eventTitle = 'Event:' + eventName;

describe( 'Enable Event Registration @daily', () => {

	before( async () => {
		await LoginPage.loginAdmin();
	} );

	it( 'is configured correctly', async () => {
		EventRegistrationPage.open();

		await expect( await EventRegistrationPage.enableRegistration ).toExist();
	} );

	it( 'requires event data', async () => {
		await EventRegistrationPage.enableEvent( eventTitle );

		await expect( await EventRegistrationPage.generalError ).toHaveText( 'There are problems with some of your input.' );
	} );

	it( 'can be enabled', async () => {
		await EventRegistrationPage.createEventPage( eventTitle );
		await EventRegistrationPage.enableEvent( eventTitle );

		await expect( await EventPage.eventName ).toHaveText( eventName );
		await expect( await EventPage.registrationUpdatedNotification )
			.toHaveText( 'Registration is enabled. Participants can now register on the event page.' );
	} );
} );
