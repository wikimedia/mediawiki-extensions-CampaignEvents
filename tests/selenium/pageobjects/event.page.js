'use strict';

const Page = require( 'wdio-mediawiki/Page' );

class EventPage extends Page {

	get eventType() { return $( '.ext-campaignevents-textwithicon-widget-content' ); }

	open( event ) {
		super.openTitle( event );
	}

}

module.exports = new EventPage();
