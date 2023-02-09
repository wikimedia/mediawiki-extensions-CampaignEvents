'use strict';

const Page = require( 'wdio-mediawiki/Page' );

class MyEventsPage extends Page {

	get ellipsis() { return $( '.oo-ui-icon-ellipsis' ); }
	get closeRegistrationButton() { return $( '*=Close' ); }
	get notification() { return $( '.mw-notification' ); }
	get firstEvent() { return $( '.ext-campaignevents-eventspager-eventpage-link' ); }

	open() {
		super.openTitle( 'Special:MyEvents' );
	}

	async closeRegistration() {
		this.open();
		await this.ellipsis.click();
		await this.closeRegistrationButton.click();
	}

}

module.exports = new MyEventsPage();
