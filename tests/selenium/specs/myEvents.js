'use strict';

const assert = require( 'assert' ),
	EventRegistrationPage = require( '../pageobjects/eventRegistration.page' ),
	MyEventsPage = require( '../pageobjects/myEvents.page' ),
	LoginPage = require( 'wdio-mediawiki/LoginPage' ),
	Rest = require( '../pageobjects/rest.page' ),
	Util = require( 'wdio-mediawiki/Util' ),
	event = Util.getTestString( 'Event:Test MyEvents' );

describe( 'MyEvents', function () {

	before( async function () {
		await LoginPage.loginAdmin();
		await EventRegistrationPage.createEventPage( event );
		await Rest.enableEvent( event );
	} );

	beforeEach( async function () {
		MyEventsPage.open();
	} );

	it( 'can allow organizer to close registration of first event in My Events', async function () {
		// XXX This might fail if the first registration in the list is already closed
		await MyEventsPage.closeRegistration();
		assert.deepEqual( await MyEventsPage.notification.getText(), `${await MyEventsPage.firstEvent.getText()} registration closed.` );
	} );

	it( 'can allow organizer to delete registration of first event in My Events', async function () {
		// Save the name of the event now, as the deletion will refresh the page.
		const eventName = await MyEventsPage.firstEvent.getText();
		await MyEventsPage.deleteRegistration();
		assert.deepEqual( await MyEventsPage.notification.getText(), `${eventName} deleted.` );
	} );
} );
