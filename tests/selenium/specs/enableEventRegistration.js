'use strict';

const EventRegistrationPage = require( '../pageobjects/eventRegistration.page' ),
	EventPage = require( '../pageobjects/event.page' ),
	EventUtils = require( '../EventUtils.js' ),
	Util = require( 'wdio-mediawiki/Util' ),
	eventName = Util.getTestString( 'Test EnableEventRegistration' ),
	eventTitle = 'Event:' + eventName;

describe( 'Enable Event Registration @daily', () => {

	before( async () => {
		await EventUtils.loginAsOrganizer();
	} );

	it( 'is configured correctly', async () => {
		await EventRegistrationPage.open();

		await expect( await EventRegistrationPage.enableRegistration ).toExist();
	} );

	it( 'requires event data', async () => {
		await EventRegistrationPage.enableEvent( eventTitle );

		await expect( await EventRegistrationPage.generalError ).toHaveText( 'There are problems with some of your input.' );
	} );

	it( 'can be enabled', async () => {
		await EventUtils.createEventPage( eventTitle );
		await EventRegistrationPage.enableEvent( eventTitle );

		await expect( await EventPage.eventName ).toHaveText( eventName );
		const registrationUpdatedNotification = await EventPage.registrationUpdatedNotification;
		await expect( registrationUpdatedNotification )
			.toHaveText( expect.stringContaining( 'Registration is enabled. Participants can now register on your event page.' ) );
		await expect( registrationUpdatedNotification )
			.toHaveText( expect.stringContaining( 'This event is included in the Collaboration list.' ) );
	} );
} );
