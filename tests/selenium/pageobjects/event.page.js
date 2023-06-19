'use strict';

const Page = require( 'wdio-mediawiki/Page' ),
	Util = require( 'wdio-mediawiki/Util' );

class EventPage extends Page {
	get registerForEventButton() { return $( '.ext-campaignevents-eventpage-register-btn' ); }
	get confirmRegistrationButton() { return $( '.ext-campaignevents-registration-dialog .oo-ui-processDialog-actions-primary' ).$( '=Register' ); }
	get togglePrivate() { return $( '.ext-campaignevents-registration-ack-fieldset .oo-ui-toggleSwitchWidget' ); }
	get manageRegistrationButton() { return $( '.ext-campaignevents-eventpage-header-buttons .ext-campaignevents-eventpage-manage-registration-menu' ); }
	get cancelRegistrationButton() { return this.manageRegistrationButton.$( '*=Cancel registration' ); }
	get confirmCancellation() { return $( '.oo-ui-window-active' ).$( '=Yes' ); }
	get successfulRegistration() { return $( '.ext-campaignevents-eventpage-participant-notice' ); }

	get eventType() { return $( '.ext-campaignevents-textwithicon-widget-content' ); }

	open( event ) {
		super.openTitle( event );
	}

	/**
	 * Register for an event.
	 *
	 * Login as a user, register for the event, and confirm registration
	 *
	 * @param {boolean} isPrivate is user registering privately for the event
	 */
	async register( isPrivate = false ) {
		// Wait until the click handlers have been installed
		Util.waitForModuleState( 'ext.campaignEvents.eventpage' );
		await browser.waitUntil(
			// eslint-disable-next-line no-underscore-dangle
			() => browser.execute( () => $._data( $( '.ext-campaignevents-eventpage-register-btn' ).get( 0 ), 'events' ).click.length >= 1 ),
			1000,
			'Click listener not installed.'
		);

		await this.registerForEventButton.click();
		if ( isPrivate ) {
			await this.togglePrivate.click();
		}
		await this.confirmRegistrationButton.click();
	}

	/**
	 * Cancel registration for an event.
	 *
	 * Cancel a user's registration for an event and confirm that cancellation
	 *
	 */
	async cancelRegistration() {
		await this.manageRegistrationButton.click();
		await this.cancelRegistrationButton.click();
		await this.confirmCancellation.click();
	}
}

module.exports = new EventPage();
