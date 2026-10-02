'use strict';

const useWorklistWikiOptions = require( '../../../../../resources/ext.campaignEvents.specialPages/eventdetails/composables/useWorklistWikiOptions.js' );

const wikiOption = ( wiki ) => ( { value: wiki, label: wiki + ' name' } );

const withConfig = ( config ) => {
	const fullConfig = Object.assign( { wgWikiID: 'awiki' }, config );
	mw.config.get = ( key ) => fullConfig[ key ];
	return useWorklistWikiOptions();
};

describe( 'useWorklistWikiOptions', () => {
	it( 'hides the selector when the current wiki is the only choice', () => {
		const { showWikiSelector } = withConfig( {
			wgCampaignEventsWorklistWikiOptions: [ wikiOption( 'awiki' ) ]
		} );
		expect( showWikiSelector ).toBe( false );
	} );

	it( 'falls back to the current wiki when no options are given', () => {
		const { wikiOptions, showWikiSelector, selectedWiki } = withConfig( {} );
		expect( wikiOptions ).toEqual( [ { value: 'awiki', label: 'awiki', apiUrl: null } ] );
		expect( showWikiSelector ).toBe( false );
		expect( selectedWiki.value ).toBe( 'awiki' );
	} );

	it( 'uses wgWikiID as the current wiki, not wgDBname', () => {
		const { currentWiki, selectedWiki } = withConfig( {
			wgWikiID: 'mywiki-prefix_',
			wgDBname: 'mywiki'
		} );
		expect( currentWiki ).toBe( 'mywiki-prefix_' );
		expect( selectedWiki.value ).toBe( 'mywiki-prefix_' );
	} );

	it( 'defaults to the current wiki when the event covers it', () => {
		const { showWikiSelector, selectedWiki } = withConfig( {
			wgCampaignEventsWorklistWikiOptions: [ wikiOption( 'bwiki' ), wikiOption( 'awiki' ) ]
		} );
		expect( showWikiSelector ).toBe( true );
		expect( selectedWiki.value ).toBe( 'awiki' );
	} );

	it( 'defaults to the first wiki when the event does not cover the current one', () => {
		const { showWikiSelector, selectedWiki } = withConfig( {
			wgCampaignEventsWorklistWikiOptions: [ wikiOption( 'bwiki' ), wikiOption( 'cwiki' ) ]
		} );
		expect( showWikiSelector ).toBe( true );
		expect( selectedWiki.value ).toBe( 'bwiki' );
	} );

	it( 'shows the selector for a single wiki that is not the current one', () => {
		const { showWikiSelector, selectedWiki } = withConfig( {
			wgCampaignEventsWorklistWikiOptions: [ wikiOption( 'bwiki' ) ]
		} );
		expect( showWikiSelector ).toBe( true );
		expect( selectedWiki.value ).toBe( 'bwiki' );
	} );

	describe( 'searchApi', () => {
		const LOCAL_API = { local: true };
		const FOREIGN_API = { foreign: true };
		const options = [
			wikiOption( 'awiki' ),
			Object.assign( wikiOption( 'bwiki' ), { apiUrl: 'https://b.example/w/api.php' } ),
			// A wiki whose API URL could not be resolved.
			Object.assign( wikiOption( 'cwiki' ), { apiUrl: null } )
		];

		beforeEach( () => {
			mw.Api = jest.fn( () => LOCAL_API );
			mw.ForeignApi = jest.fn( () => FOREIGN_API );
		} );

		it( 'searches the current wiki locally', () => {
			const { searchApi } = withConfig( { wgCampaignEventsWorklistWikiOptions: options } );
			expect( searchApi.value ).toBe( LOCAL_API );
			expect( mw.ForeignApi ).not.toHaveBeenCalled();
		} );

		it( 'searches another wiki anonymously through its API', () => {
			const { selectedWiki, searchApi } = withConfig( {
				wgCampaignEventsWorklistWikiOptions: options
			} );
			selectedWiki.value = 'bwiki';
			expect( searchApi.value ).toBe( FOREIGN_API );
			expect( mw.ForeignApi ).toHaveBeenCalledWith(
				'https://b.example/w/api.php',
				{ anonymous: true }
			);
		} );

		it( 'does not search a wiki without an API URL, rather than the wrong wiki', () => {
			const { selectedWiki, searchApi } = withConfig( {
				wgCampaignEventsWorklistWikiOptions: options
			} );
			selectedWiki.value = 'cwiki';
			expect( searchApi.value ).toBe( null );
			expect( mw.Api ).not.toHaveBeenCalled();
		} );

		it( 'does not search while no wiki is selected', () => {
			const { selectedWiki, searchApi } = withConfig( {
				wgCampaignEventsWorklistWikiOptions: options
			} );
			selectedWiki.value = null;
			expect( searchApi.value ).toBe( null );
		} );
	} );
} );
