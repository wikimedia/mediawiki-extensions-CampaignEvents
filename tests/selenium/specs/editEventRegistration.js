'use strict';

const Api = require( 'wdio-mediawiki/Api' ),
	EventPage = require( '../pageobjects/event.page' ),
	EventRegistrationPage = require( '../pageobjects/eventRegistration.page' ),
	LoginPage = require( 'wdio-mediawiki/LoginPage' ),
	Rest = require( '../pageobjects/rest.page' ),
	Util = require( 'wdio-mediawiki/Util' ),
	userName = Util.getTestString();

let event,
	id;

describe( 'Edit Event Registration', () => {

	beforeEach( async () => {
		event = Util.getTestString( 'Event:Test EditEventRegistration' );
		await LoginPage.loginAdmin();
		await EventRegistrationPage.createEventPage( event );
		id = await Rest.enableEvent( event );
	} );

	it( 'can allow organizer to update event data', async () => {
		const updatedEventPage = Util.getTestString( 'Event:New page for EditEventRegistration' );
		await EventRegistrationPage.createEventPage( updatedEventPage );

		await EventRegistrationPage.editEvent( {
			event: updatedEventPage,
			id,
			start: { year: 2099, day: 12 },
			end: { year: 2100, day: 14 }
		} );

		const registrationUpdatedNotification = await EventPage.registrationUpdatedNotification;
		await expect( registrationUpdatedNotification )
			.toHaveTextContaining( 'The registration information has been updated.' );
		await expect( registrationUpdatedNotification )
			.toHaveTextContaining( 'This event is included in the Collaboration list.' );
	} );

	it( 'can allow organizer to change the event to be in person', async () => {
		await EventRegistrationPage.editEvent( {
			id,
			meetingType: 'inperson'
		} );

		await expect( await EventPage.eventType ).toHaveText( 'In-person event' );
	} );

	it( 'can allow organizer to change the event to be online and in-person', async () => {
		await EventRegistrationPage.editEvent( {
			id,
			meetingType: 'hybrid'
		} );

		await expect( await EventPage.eventType ).toHaveText( 'Online and in-person event' );
	} );

	it( 'can allow organizer to add an additional organizer', async () => {
		const bot = await Api.bot();
		const password = 'aaaaaaaaa!';
		await Api.createAccount( bot, userName, password );
		await EventRegistrationPage.editEvent( {
			id,
			organizer: userName
		} );

		await EventPage.openMoreDetailsDialog();
		await expect( await EventPage.eventOrganizers ).toHaveTextContaining( userName );
	} );

} );
