'use strict';

const assert = require( 'assert' ),
	EnableEventRegistrationPage = require( '../pageobjects/enableEventRegistration.page' ),
	MyEventsPage = require( '../pageobjects/myEvents.page' ),
	LoginPage = require( 'wdio-mediawiki/LoginPage' ),
	Rest = require( '../pageobjects/rest.page' ),
	event = EnableEventRegistrationPage.getTestString( 'Event:e2e' );

describe( 'MyEvents', function () {

	before( async function () {
		await LoginPage.loginAdmin();
		await EnableEventRegistrationPage.createEvent( event );
		await Rest.enableEvent( event );
	} );

	it( 'can allow organizer to close registration of first event in My Events', async function () {
		await MyEventsPage.closeRegistration();
		assert.deepEqual( await MyEventsPage.notification.getText(), `${await MyEventsPage.firstEvent.getText()} registration closed.` );
	} );

	it( 'can allow organizer to delete registration of first event in My Events', async function () {
		await MyEventsPage.deleteRegistration();
		assert.deepEqual( `${await MyEventsPage.firstEvent.getText()} deleted.`, await MyEventsPage.notification.getText() );
	} );
} );
