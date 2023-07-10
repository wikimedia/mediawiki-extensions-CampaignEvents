$( function () {
	'use strict';
	require( './ParticipantsManager.js' );
	require( './OrganizersLoader.js' );
	if ( mw.config.get( 'wgCampaignEventsShowEmailTab' ) ) {
		require( './EmailManager.js' );
	}
	// eslint-disable-next-line no-jquery/no-global-selector
	var tabLayout = OO.ui.IndexLayout.static.infuse( $( '#ext-campaignevents-eventdetails-tabs' ) ),
		tabs = tabLayout.getTabs().items;
	tabs.forEach( function ( header ) {
		header.$element.on( 'click', function ( e ) {
			// override click event so that OOUI can handle it
			e.preventDefault();
		} );
	} );
	// FIXME Remove when T322271 is resolved
	var tab = mw.util.getParamValue( 'tab' ) ?
		mw.util.getParamValue( 'tab' ) :
		'EventDetailsPanel';
	if ( tabLayout.getTabPanel( tab ) ) {
		tabLayout.setTabPanel( tab );
	}
} );
