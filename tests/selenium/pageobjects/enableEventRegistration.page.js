'use strict';

const Page = require( 'wdio-mediawiki/Page' );

class EnableEventRegistrationPage extends Page {
	get enableRegistration() { return $( '[value="Enable registration"]' ); }
	get eventPage() { return $( '[name="wpEventPage"]' ); }
	get generalError() { return $( '[role=alert]' ); }

	open() {
		super.openTitle( 'Special:EnableEventRegistration' );
	}

	getTestString( prefix = '' ) {
		return prefix + Date.now().toString() + '-Iñtërnâtiônàlizætiøn';
	}

	async createEvent( event ) {
		await this.open();
		await this.eventPage.setValue( event );
		await this.enableRegistration.click();
	}
}

module.exports = new EnableEventRegistrationPage();
