import EventPage from '../pageobjects/event.page.js';
import EventRegistrationPage from '../pageobjects/eventRegistration.page.js';
import EventUtils from '../EventUtils.js';
import * as Util from 'wdio-mediawiki/Util';

let id;

describe( 'Edit Event Registration', () => {

	before( async () => {
		await EventUtils.loginAsOrganizer();
	} );

	beforeEach( async () => {
		const eventPage = Util.getTestString( 'Event:Test EditEventRegistration' );
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
			.toHaveText( expect.stringContaining( 'The registration information has been updated.' ) );
		await expect( registrationUpdatedNotification )
			.toHaveText( expect.stringContaining( 'This event is included in the Collaboration list.' ) );
	} );

	it( 'can allow organizer to change the event to be in person', async () => {
		await EventRegistrationPage.editEvent( {
			id,
			participationOptions: 'inperson',
			countryCode: 'BS'
		} );

		await expect( await EventPage.headerParticipationOptions ).toHaveText( 'Bahamas' );
	} );

	it( 'can allow organizer to change the event to be online and in-person', async () => {
		await EventRegistrationPage.editEvent( {
			id,
			participationOptions: 'hybrid',
			countryCode: 'BS'
		} );

		await expect( await EventPage.headerParticipationOptions ).toHaveText( 'Online and in-person event' );
	} );

	it( 'can allow organizer to add an additional organizer', async () => {
		const otherOrganizerName = Util.getTestString( 'Another event organizer' );
		await EventUtils.createOrganizerAccount( otherOrganizerName );
		await EventRegistrationPage.editEvent( {
			id,
			organizer: otherOrganizerName
		} );

		await EventPage.openMoreDetailsDialog();
		await expect( await EventPage.eventOrganizers )
			.toHaveText( await expect.stringContaining( otherOrganizerName ) );
	} );

} );
