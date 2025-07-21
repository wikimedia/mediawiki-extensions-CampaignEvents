import MyEventsPage from '../pageobjects/myEvents.page.js';
import EventUtils from '../EventUtils.js';
import * as Util from 'wdio-mediawiki/Util';

const eventName = Util.getTestString( 'Test MyEvents' ),
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
