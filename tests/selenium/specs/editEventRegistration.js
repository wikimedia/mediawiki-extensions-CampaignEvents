'use strict';

const assert = require( 'assert' ),
	EventPage = require( '../pageobjects/event.page' ),
	EventRegistrationPage = require( '../pageobjects/eventRegistration.page' ),
	LoginPage = require( 'wdio-mediawiki/LoginPage' ),
	Rest = require( '../pageobjects/rest.page' );

let event,
	id;

describe( 'Edit Event Registration', function () {

	beforeEach( async function () {
		event = EventRegistrationPage.getTestString( 'Event:e2e' );
		await LoginPage.loginAdmin();
		await EventRegistrationPage.createEvent( event );
		id = await Rest.enableEvent( event );
	} );

	it( 'can allow organizer to update event data', async function () {
		const updatedEventPage = EventRegistrationPage.getTestString( 'Event:updatede2e' );
		await EventRegistrationPage.createEvent( updatedEventPage );

		await EventRegistrationPage.editEvent( {
			event: updatedEventPage,
			id,
			start: { year: 2099, day: 12 },
			end: { year: 2100, day: 14 }
		} );

		assert.deepEqual( await EventRegistrationPage.feedback.getText(), 'The registration was edited. See event page.' );
	} );

	it( 'can allow organizer to change the event to be in person', async function () {
		await EventRegistrationPage.editEvent( {
			id,
			meetingType: 'inperson'
		} );

		EventPage.open( event );
		assert.deepEqual( await EventPage.eventType.getText(), 'In-person event' );
	} );

	it( 'can allow organizer to change the event to be online and in-person', async function () {
		await EventRegistrationPage.editEvent( {
			id,
			meetingType: 'hybrid'
		} );

		EventPage.open( event );
		assert.deepEqual( await EventPage.eventType.getText(), 'Online and in-person event' );
	} );
} );
