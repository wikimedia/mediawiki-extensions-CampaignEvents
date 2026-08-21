$( () => {
	'use strict';
	require( './ParticipantsManager.js' );
	require( './OrganizersLoader.js' );
	if ( mw.config.get( 'wgCampaignEventsShowEmailTab' ) ) {
		require( './EmailManager.js' );
	}
	require( './EventContributions.js' );
	require( './Worklist.js' );
	const tabURLManager = require( './TabURLManager.js' );
	// eslint-disable-next-line no-jquery/no-global-selector
	const tabLayout = OO.ui.IndexLayout.static.infuse( $( '#ext-campaignevents-eventdetails-tabs' ) ),
		tabSelect = tabLayout.getTabs();
	tabSelect.items.forEach( ( header ) => {
		header.$element.on( 'click', ( e ) => {
			// override click event so that OOUI can handle it
			e.preventDefault();
		} );
	} );
	// FIXME Remove when T322271 is resolved: infusion restores which tab is selected, but not
	// the layout's own notion of the current tab panel, so re-apply the server-side selection.
	const selectedTab = tabSelect.findSelectedItem();
	if ( selectedTab ) {
		tabLayout.setTabPanel( selectedTab.getData() );
	}
	tabURLManager.connect( tabLayout );

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
