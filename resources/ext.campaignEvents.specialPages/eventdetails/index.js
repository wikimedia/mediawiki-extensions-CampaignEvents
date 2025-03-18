$( () => {
	'use strict';
	require( './ParticipantsManager.js' );
	require( './OrganizersLoader.js' );
	if ( mw.config.get( 'wgCampaignEventsShowEmailTab' ) ) {
		require( './EmailManager.js' );
	}
	// eslint-disable-next-line no-jquery/no-global-selector
	const tabLayout = OO.ui.IndexLayout.static.infuse( $( '#ext-campaignevents-eventdetails-tabs' ) ),
		tabs = tabLayout.getTabs().items;
	tabs.forEach( ( header ) => {
		header.$element.on( 'click', ( e ) => {
			// override click event so that OOUI can handle it
			e.preventDefault();
		} );
	} );
	// FIXME Remove when T322271 is resolved
	const tab = mw.util.getParamValue( 'tab' ) ?
		mw.util.getParamValue( 'tab' ) :
		'EventDetailsPanel';
	if ( tabLayout.getTabPanel( tab ) ) {
		tabLayout.setTabPanel( tab );
	}

	// Enable collapsible stats section explicitly, for skins that disable it by
	// default (like Minerva)
	// eslint-disable-next-line no-jquery/no-global-selector
	const $statsSections = $( '.ext-campaignevents-eventdetails-stats-question-container.mw-collapsible' );
	if ( $statsSections.length ) {
		$statsSections.makeCollapsible();
	}

	// eslint-disable-next-line no-jquery/no-global-selector
	const $eventTime = $( '.ext-campaignevents-eventdetails-section-content' );
	if ( $eventTime.length ) {
		const timeZoneConverter = require( '../../TimeZoneConverter.js' );
		timeZoneConverter.convert( $eventTime, 'campaignevents-event-details-dates' );
	}
} );
