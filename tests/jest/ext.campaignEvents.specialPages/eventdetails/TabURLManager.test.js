'use strict';

/* global global, window, URL */

const tabURLManager = require( '../../../../resources/ext.campaignEvents.specialPages/eventdetails/TabURLManager.js' );

const BASE_URL = window.location.origin + '/wiki/Special:EventDetails/1';

// Minimal stand-in for OO.ui.IndexLayout, which only implements what TabURLManager uses.
function makeTabLayout( tabNames, currentTab = tabNames[ 0 ] ) {
	const handlers = [];
	return {
		currentTab,
		on: ( eventName, handler ) => handlers.push( handler ),
		getTabPanel: ( name ) => tabNames.includes( name ) ?
			{ getName: () => name } :
			undefined,
		getCurrentTabPanelName: function () {
			return this.currentTab;
		},
		setTabPanel: function ( name ) {
			this.currentTab = name;
			handlers.forEach( ( handler ) => handler( { getName: () => name } ) );
		}
	};
}

// Connects the manager and returns its popstate handler, so that browser navigation can be
// simulated without dispatching events to the handlers left over by other test cases.
function connect( tabLayout ) {
	const addEventListener = jest.spyOn( window, 'addEventListener' );
	tabURLManager.connect( tabLayout );
	const popstateHandler = addEventListener.mock.calls
		.find( ( [ eventName ] ) => eventName === 'popstate' )[ 1 ];
	addEventListener.mockRestore();
	return popstateHandler;
}

describe( 'TabURLManager.connect', () => {
	beforeEach( () => {
		global.mw = {
			util: {
				// Stand-in for mw.util.getParamValue, including its last-occurrence-wins
				// behaviour, so that tests can follow the URL as the manager rewrites it.
				getParamValue: jest.fn( ( param ) => {
					const values = new URL( window.location.href ).searchParams.getAll( param );
					return values.length ? values[ values.length - 1 ] : null;
				} )
			}
		};
		window.history.replaceState( null, '', BASE_URL );
	} );

	afterEach( () => {
		delete global.mw;
	} );

	it( 'adds the tab parameter when a tab is opened', () => {
		const tabLayout = makeTabLayout( [ 'EventDetailsPanel', 'ParticipantsPanel' ] );
		connect( tabLayout );

		tabLayout.setTabPanel( 'ParticipantsPanel' );

		expect( window.location.href ).toBe( BASE_URL + '?tab=ParticipantsPanel' );
	} );

	it( 'updates an existing tab parameter, preserving other parameters', () => {
		window.history.replaceState( null, '', BASE_URL + '?tab=EmailPanel&foo=bar' );
		const tabLayout = makeTabLayout( [ 'EmailPanel', 'ParticipantsPanel' ] );
		connect( tabLayout );

		tabLayout.setTabPanel( 'ParticipantsPanel' );

		expect( window.location.href ).toBe( BASE_URL + '?tab=ParticipantsPanel&foo=bar' );
	} );

	it( 'does not add a parameter for the tab the page was opened with', () => {
		const tabLayout = makeTabLayout( [ 'EventDetailsPanel' ] );
		connect( tabLayout );

		tabLayout.setTabPanel( 'EventDetailsPanel' );

		expect( window.location.href ).toBe( BASE_URL );
	} );

	it( 'names the initial tab once the URL has the parameter', () => {
		const tabLayout = makeTabLayout( [ 'EventDetailsPanel', 'ParticipantsPanel' ] );
		connect( tabLayout );

		tabLayout.setTabPanel( 'ParticipantsPanel' );
		tabLayout.setTabPanel( 'EventDetailsPanel' );

		expect( window.location.href ).toBe( BASE_URL + '?tab=EventDetailsPanel' );
	} );

	it( 'keeps the previous tab in the browser history', () => {
		const pushState = jest.spyOn( window.history, 'pushState' );
		const tabLayout = makeTabLayout( [ 'EventDetailsPanel', 'ParticipantsPanel' ] );
		connect( tabLayout );

		tabLayout.setTabPanel( 'ParticipantsPanel' );

		expect( pushState ).toHaveBeenCalledTimes( 1 );
		pushState.mockRestore();
	} );

	it( 'opens the tab of the URL navigated to, without touching the history', () => {
		const tabLayout = makeTabLayout(
			[ 'EventDetailsPanel', 'ParticipantsPanel' ], 'ParticipantsPanel'
		);
		const popstateHandler = connect( tabLayout );
		window.history.replaceState( null, '', BASE_URL + '?tab=EventDetailsPanel' );
		const pushState = jest.spyOn( window.history, 'pushState' );

		popstateHandler();

		expect( tabLayout.currentTab ).toBe( 'EventDetailsPanel' );
		expect( pushState ).not.toHaveBeenCalled();
		pushState.mockRestore();
	} );

	it( 'reopens the initial tab when navigating back to a URL without the parameter', () => {
		const tabLayout = makeTabLayout( [ 'EventDetailsPanel', 'ParticipantsPanel' ] );
		const popstateHandler = connect( tabLayout );
		tabLayout.setTabPanel( 'ParticipantsPanel' );
		window.history.replaceState( null, '', BASE_URL );

		popstateHandler();

		expect( tabLayout.currentTab ).toBe( 'EventDetailsPanel' );
		expect( window.location.href ).toBe( BASE_URL );
	} );

	it( 'keeps the URL in sync after a failed navigation', () => {
		const tabLayout = makeTabLayout( [ 'EventDetailsPanel', 'ParticipantsPanel' ] );
		const popstateHandler = connect( tabLayout );
		const setTabPanel = tabLayout.setTabPanel;
		window.history.replaceState( null, '', BASE_URL + '?tab=ParticipantsPanel' );
		tabLayout.setTabPanel = () => {
			throw new Error( 'Navigation failed' );
		};

		expect( popstateHandler ).toThrow();

		tabLayout.setTabPanel = setTabPanel;
		window.history.replaceState( null, '', BASE_URL );
		tabLayout.setTabPanel( 'ParticipantsPanel' );

		expect( window.location.href ).toBe( BASE_URL + '?tab=ParticipantsPanel' );
	} );

	it( 'ignores navigation to an unknown tab', () => {
		const tabLayout = makeTabLayout( [ 'EventDetailsPanel' ] );
		const popstateHandler = connect( tabLayout );
		window.history.replaceState( null, '', BASE_URL + '?tab=NoSuchPanel' );

		popstateHandler();

		expect( tabLayout.currentTab ).toBe( 'EventDetailsPanel' );
	} );
} );
