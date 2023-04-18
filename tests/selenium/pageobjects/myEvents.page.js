'use strict';

const Page = require( 'wdio-mediawiki/Page' );

class MyEventsPage extends Page {

	get manageEventMenuButton() { return $( '.ext-campaignevents-eventspager-cell-manage .oo-ui-icon-ellipsis' ); }
	get closeRegistrationButton() { return $( '.ext-campaignevents-eventspager-cell-manage' ).$( '*=Close' ); }
	get deleteRegistrationButton() { return $( '.ext-campaignevents-eventspager-cell-manage' ).$( '*=Delete' ); }
	get notification() { return $( '.mw-notification' ); }
	get firstEvent() { return $( '.ext-campaignevents-eventspager-eventpage-link' ); }
	get deleteConfirmationButton() { return $( '.oo-ui-messageDialog' ).$( '.oo-ui-buttonElement-button=Delete' ); }

	open() {
		super.openTitle( 'Special:MyEvents' );
	}

	async closeRegistration() {
		await this.manageEventMenuButton.click();
		await this.closeRegistrationButton.click();
	}

	async deleteRegistration() {
		await this.manageEventMenuButton.click();
		await this.deleteRegistrationButton.click();
		await this.deleteConfirmationButton.click();

	}
}

module.exports = new MyEventsPage();
