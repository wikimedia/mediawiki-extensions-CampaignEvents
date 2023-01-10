'use strict';

const Page = require( 'wdio-mediawiki/Page' ),
	Api = require( 'wdio-mediawiki/Api' ),
	LoginPage = require( 'wdio-mediawiki/LoginPage' ),
	password = 'aaaaaaaaa!';
class User extends Page {

	get registerForEvent() { return $( '=Register for event' ); }
	get confirmRegistration() { return $( '=Register' ); }
	get togglePrivate() { return $( '.oo-ui-toggleSwitchWidget' ); }
	get cancelRegistrationButton() { return $( '.oo-ui-icon-trash' ); }
	get confirmCancellation() { return $( '=Yes' ); }

	async createAccount( userName ) {
		const bot = await Api.bot();
		await Api.createAccount( bot, userName, password );
	}

	/**
	 * Register for an event.
	 *
	 * Login as a user, register for the event, and confirm registration
	 *
	 * @param {string} userName user that will register for the event
	 * @param {string} event a namespaced string beginning with 'Event:'
	 * example: 'Event:Test'
	 * @param {boolean} isPrivate is user registering privately for the event
	 */
	async register( userName, event, isPrivate = false ) {
		await LoginPage.login( userName, password );
		this.openTitle( event );

		await browser.waitUntil(
			() => browser.execute( () => document.readyState === 'complete' ), // eslint-disable-line no-undef
			{
				timeout: 6000,
				timeoutMsg: 'Register user broke'
			}
		);
		await this.registerForEvent.click();
		if ( isPrivate ) {
			await this.togglePrivate.click();
		}
		await this.confirmRegistration.click();
	}

	/**
	 * Cancel registration for an event.
	 *
	 * Cancel a user's registration for an event and confirm that cancellation
	 *
	 */
	async cancelRegistration() {
		await this.cancelRegistrationButton.click();
		await this.confirmCancellation.click();
	}

}

module.exports = new User();
