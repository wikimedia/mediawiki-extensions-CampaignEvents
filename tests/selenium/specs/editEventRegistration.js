'use strict';

const assert = require( 'assert' ),
	EventPage = require( '../pageobjects/event.page' ),
	EventRegistrationPage = require( '../pageobjects/eventRegistration.page' ),
	LoginPage = require( 'wdio-mediawiki/LoginPage' ),
	Rest = require( '../pageobjects/rest.page' ),
	event = EventRegistrationPage.getTestString( 'Event:e2e' ),
	updatedEventPage = EventRegistrationPage.getTestString( 'Event:updatede2e' );

describe( 'Edit Event Registration', function () {

	it( 'can allow organizer to update event data', async function () {
		await LoginPage.loginAdmin();
		await EventRegistrationPage.createEvent( event );
		await EventRegistrationPage.createEvent( updatedEventPage );
		const id = await Rest.enableEvent( event );
		await EventRegistrationPage.editEvent( {
			event: updatedEventPage,
			id,
			start: { year: 2099, day: 12 },
			end: { year: 2100, day: 14 }
		} );
		assert.deepEqual( await EventRegistrationPage.feedback.getText(), 'The registration was edited. See event page.' );
	} );

	it( 'can allow organizer to change the event to be in person', async function () {
		await LoginPage.loginAdmin();
		await EventRegistrationPage.createEvent( event );
		const id = await Rest.enableEvent( event );

		await EventRegistrationPage.editEvent( {
			id,
			meetingType: 'inperson'
		} );

		EventPage.open( event );
		assert.deepEqual( await EventPage.eventType.getText(), 'In-person event' );
	} );
} );
