'use strict';

const MyEventsPage = require( '../pageobjects/myEvents.page' ),
	EventUtils = require( '../EventUtils.js' ),
	Util = require( 'wdio-mediawiki/Util' ),
	eventName = Util.getTestString( 'Test MyEvents' ),
	eventTitle = 'Event:' + eventName;

describe( 'MyEvents', () => {

	before( async () => {
		await EventUtils.loginAsOrganizer();
		await EventUtils.createEvent( eventTitle );
	} );

	beforeEach( async () => {
		await MyEventsPage.open();
	} );

	it( 'can allow organizer to search events by name', async () => {
		await MyEventsPage.filterByName( eventName );
		await expect( await MyEventsPage.firstRegistrationNameCell ).toHaveText( eventName );
	} );

	it( 'can allow organizer to delete registration of first event in My Events', async () => {
		await MyEventsPage.filterByName( eventName );
		await MyEventsPage.deleteFirstRegistration();
		await expect( await MyEventsPage.notification ).toHaveText( `${ eventName } deleted.` );
	} );
} );
