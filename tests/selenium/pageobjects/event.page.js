'use strict';

const Page = require( 'wdio-mediawiki/Page' ),
	Util = require( 'wdio-mediawiki/Util' );

class EventPage extends Page {

	get registerForEventButton() {
		return $( '.ext-campaignevents-eventpage-register-btn' );
	}

	get confirmRegistrationButton() {
		return $( '.ext-campaignevents-registration-dialog .oo-ui-processDialog-actions-primary' ).$( '=Register' );
	}

	get togglePrivate() {
		return $( '.ext-campaignevents-registration-visibility-toggle-field .oo-ui-toggleSwitchWidget' );
	}

	get manageRegistrationButton() {
		return $( '.ext-campaignevents-eventpage-header-buttons .ext-campaignevents-eventpage-manage-registration-menu' );
	}

	get moreDetailsDialogButton() {
		return $( '.ext-campaignevents-eventpage-details-btn' );
	}

	get cancelRegistrationButton() {
		return this.manageRegistrationButton.$( '*=Cancel registration' );
	}

	get confirmCancellation() {
		return $( '.oo-ui-window-active' ).$( '=Yes' );
	}

	get successfulRegistration() {
		return $( '.ext-campaignevents-eventpage-participant-notice' );
	}

	get eventType() {
		return $( '.ext-campaignevents-textwithicon-widget-content' );
	}

	get eventOrganizers() {
		return $( '.ext-campaignevents-detailsdialog-organizers' );
	}

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
		await Util.waitForModuleState( 'ext.campaignEvents.eventpage' );
		await browser.waitUntil(
			() => browser.execute( () => {
				const btn = $( '.ext-campaignevents-eventpage-register-btn' ).get( 0 ),
					// eslint-disable-next-line no-underscore-dangle
					btnEvents = $._data( btn, 'events' );
				return btnEvents && btnEvents.click && btnEvents.click.length >= 1;
			} ),
			{ timeoutMsg: 'Click listener not installed.' }
		);

		await this.registerForEventButton.click();
		// Wait for the dialog to be ready, and the click handlers functional
		await browser.waitUntil(
			() => browser.execute( () => $( '.ext-campaignevents-registration-dialog.oo-ui-window' ).hasClass( 'oo-ui-window-ready' ) ),
			{ timeoutMsg: 'Dialog is not ready' }
		);
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

	/**
	 * Opens the more details dialog.
	 */
	async openMoreDetailsDialog() {
		await this.moreDetailsDialogButton.click();
	}
}

module.exports = new EventPage();
