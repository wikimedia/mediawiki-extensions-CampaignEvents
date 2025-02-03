'use strict';

const Api = require( 'wdio-mediawiki/Api' ),
	EventPage = require( '../pageobjects/event.page' ),
	EventRegistrationPage = require( '../pageobjects/eventRegistration.page' ),
	EventUtils = require( '../EventUtils.js' ),
	Util = require( 'wdio-mediawiki/Util' ),
	userName = Util.getTestString();

let id;

describe( 'Edit Event Registration', () => {

	beforeEach( async () => {
		const eventPage = Util.getTestString( 'Event:Test EditEventRegistration' );
		await EventUtils.loginAsOrganizer();
		id = await EventUtils.createEvent( eventPage );
	} );

	it( 'can allow organizer to update event page and dates', async () => {
		const updatedEventPage = Util.getTestString( 'Event:New page for EditEventRegistration' );
		await EventUtils.createEventPage( updatedEventPage );

		await EventRegistrationPage.editEvent( {
			eventPage: updatedEventPage,
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
