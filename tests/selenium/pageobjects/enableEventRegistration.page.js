'use strict';

const Page = require( 'wdio-mediawiki/Page' );

class EnableEventRegistrationPage extends Page {
	get enableRegistration() { return $( '#ooui-php-20' ); }
	open() {
		super.openTitle( 'Special:EnableEventRegistration' );
	}
}

module.exports = new EnableEventRegistrationPage();
