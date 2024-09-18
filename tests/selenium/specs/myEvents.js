'use strict';

const EventRegistrationPage = require( '../pageobjects/eventRegistration.page' ),
	MyEventsPage = require( '../pageobjects/myEvents.page' ),
	LoginPage = require( 'wdio-mediawiki/LoginPage' ),
	Rest = require( '../pageobjects/rest.page' ),
	Util = require( 'wdio-mediawiki/Util' ),
	event = Util.getTestString( 'Event:Test MyEvents' );

describe( 'MyEvents', function () {

	before( async function () {
		// Create a new event to make sure that there's at least an (open) event
		// in the table.
		await LoginPage.loginAdmin();
		await EventRegistrationPage.createEventPage( event );
		await Rest.enableEvent( event );
	} );

	beforeEach( async function () {
		MyEventsPage.open();
	} );

	// Skip it because we temporarily removed this option from the menu.
	// Skipped on 2024-04-15 in 1019807 because of T360051
	it.skip( 'can allow organizer to close registration of first event in My Events', async function () {
		await MyEventsPage.filterByOpenRegistrations();
		await MyEventsPage.closeFirstRegistration();
		await expect( await MyEventsPage.notification ).toHaveText( `${ await MyEventsPage.firstEvent.getText() } registration closed.` );
	} );

	it( 'can allow organizer to delete registration of first event in My Events', async function () {
		// Save the name of the event now, as the deletion will refresh the page.
		const eventName = await MyEventsPage.firstEvent.getText();
		await MyEventsPage.deleteFirstRegistration();
		await expect( await MyEventsPage.notification ).toHaveText( `${ eventName } deleted.` );
	} );
} );
