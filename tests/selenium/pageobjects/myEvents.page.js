'use strict';

const Page = require( 'wdio-mediawiki/Page' );

class MyEventsPage extends Page {

	get ellipsis() { return $( '.oo-ui-icon-ellipsis' ); }
	get closeRegistrationButton() { return $( '*=Close' ); }
	get deleteRegistrationButton() { return $( '*=Delete' ); }
	get notification() { return $( '.mw-notification' ); }
	get firstEvent() { return $( '.ext-campaignevents-eventspager-eventpage-link' ); }
	get deleteConfirmationButton() { return $( '=Delete' ); }

	open() {
		super.openTitle( 'Special:MyEvents' );
	}

	async closeRegistration() {
		this.open();
		await this.ellipsis.click();
		await this.closeRegistrationButton.click();
	}

	async deleteRegistration() {
		this.open();
		await this.ellipsis.click();
		await this.deleteRegistrationButton.click();
		await this.deleteConfirmationButton.click();

	}
}

module.exports = new MyEventsPage();
