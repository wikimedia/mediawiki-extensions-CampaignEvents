'use strict';

const EventRegistrationPage = require( '../pageobjects/eventRegistration.page' ),
	MyEventsPage = require( '../pageobjects/myEvents.page' ),
	LoginPage = require( 'wdio-mediawiki/LoginPage' ),
	Rest = require( '../pageobjects/rest.page' ),
	Util = require( 'wdio-mediawiki/Util' ),
	eventName = Util.getTestString( 'Test MyEvents' ),
	eventTitle = 'Event:' + eventName;

describe( 'MyEvents', () => {

	before( async () => {
		await LoginPage.loginAdmin();
		await EventRegistrationPage.createEventPage( eventTitle );
		await Rest.enableEvent( eventTitle );
	} );

	beforeEach( async () => {
		await MyEventsPage.open();
	} );

	it( 'can allow organizer to search events by name', async () => {
		await MyEventsPage.filterByName( eventName );
		await expect( await MyEventsPage.firstRegistrationNameCell ).toHaveText( eventName );
	} );

	// Skip it because we temporarily removed this option from the menu.
	// Skipped on 2024-04-15 in 1019807 because of T360051
	it.skip( 'can allow organizer to close registration of first event in My Events', async () => {
		await MyEventsPage.filterByName( eventName );
		await MyEventsPage.closeFirstRegistration();
		await expect( await MyEventsPage.notification ).toHaveText( `${ await MyEventsPage.firstEvent.getText() } registration closed.` );
	} );

	it( 'can allow organizer to delete registration of first event in My Events', async () => {
		await MyEventsPage.filterByName( eventName );
		await MyEventsPage.deleteFirstRegistration();
		await expect( await MyEventsPage.notification ).toHaveText( `${ eventName } deleted.` );
	} );
} );
