'use strict';

/* global global */

const { mount } = require( '@vue/test-utils' );
const { nextTick } = require( 'vue' );
const WorklistApp = require( '../../../../../resources/ext.campaignEvents.specialPages/eventdetails/components/WorklistApp.vue' );
const worklistPages = require( '../../../../../resources/ext.campaignEvents.specialPages/eventdetails/worklistPages.js' );

const LOCAL_WIKI = 'my_wiki';
const CARD = '.ext-campaignevents-worklist-card';

const article = ( title, overrides = {} ) => Object.assign( {
	wiki: LOCAL_WIKI,
	title: title,
	url: '/wiki/' + title,
	classes: ''
}, overrides );

const page = ( titles ) => ( {
	pages: titles.map( ( t ) => article( t ) )
} );

/**
 * @param {Object} [config]
 * @return {Object}
 */
const mountApp = ( config = {} ) => {
	mw.config = {
		get: ( key ) => Object.assign( {
			wgDBname: LOCAL_WIKI,
			wgCampaignEventsWorklistEventId: 73,
			wgCampaignEventsWorklistPageHistoryUrl: '/w/index.php?title=X&action=history'
		}, config )[ key ]
	};
	return mount( WorklistApp );
};

/** Let the mount request and its follow-up description request settle. */
const settle = async () => {
	await nextTick();
	await nextTick();
	await nextTick();
};

describe( 'WorklistApp', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		jest.spyOn( mw.user, 'isNamed' ).mockReturnValue( true );
		jest.spyOn( worklistPages, 'fetchPages' ).mockResolvedValue( page( [ 'Bears' ] ) );
		jest.spyOn( worklistPages, 'removeArticle' ).mockResolvedValue( {} );
		global.$ = jest.fn();
	} );

	afterEach( () => {
		jest.useRealTimers();
		jest.restoreAllMocks();
	} );

	it( 'loads and renders the worklist on mount', async () => {
		const wrapper = mountApp();
		await settle();

		expect( worklistPages.fetchPages ).toHaveBeenCalledTimes( 1 );
		expect( wrapper.findAll( CARD ) ).toHaveLength( 1 );
		expect( wrapper.find( '.ext-campaignevents-worklist-skeleton' ).exists() ).toBe( false );
	} );

	it( 'shows the placeholder cards until the worklist arrives', () => {
		const wrapper = mountApp();
		expect( wrapper.find( '.ext-campaignevents-worklist-skeleton' ).exists() ).toBe( true );
		expect( wrapper.findAll( CARD ) ).toHaveLength( 0 );
	} );

	it( 'says so when the worklist is empty', async () => {
		worklistPages.fetchPages.mockResolvedValue( page( [] ) );
		const wrapper = mountApp();
		await settle();

		expect( wrapper.get( '.ext-campaignevents-worklist-empty-state' ).text() )
			.toContain( 'worklist-empty-state' );
	} );

	it( 'renders the whole worklist, with no paging of its own', async () => {
		worklistPages.fetchPages.mockResolvedValue( page( [ 'Bears', 'Chickens', 'Beavers' ] ) );
		const wrapper = mountApp();
		await settle();

		expect( wrapper.findAll( CARD ) ).toHaveLength( 3 );
		// Paging the list is the card view's own business, added separately; the request asks for
		// no page of its own.
		expect( worklistPages.fetchPages ).toHaveBeenCalledWith();
		expect( wrapper.find( '.ext-campaignevents-worklist-pagination' ).exists() ).toBe( false );
	} );

	it( 'reports a failed load instead of showing an empty worklist', async () => {
		worklistPages.fetchPages.mockRejectedValue( {} );
		const wrapper = mountApp();
		await settle();

		expect( wrapper.find( '.cdx-message--error' ).exists() ).toBe( true );
		expect( wrapper.find( '.ext-campaignevents-worklist-empty-state' ).exists() ).toBe( false );
	} );

	it( 're-reads the worklist after removing an article', async () => {
		const wrapper = mountApp();
		await settle();

		await wrapper.get( '.ext-campaignevents-worklist-card__remove' ).trigger( 'click' );
		worklistPages.fetchPages.mockClear();
		wrapper.vm.confirmRemove();
		await settle();

		expect( worklistPages.removeArticle ).toHaveBeenCalledWith( LOCAL_WIKI, 'Bears' );
		expect( worklistPages.fetchPages ).toHaveBeenCalled();
	} );

	it( 'links to the worklist page below the list', async () => {
		const wrapper = mountApp( { wgCampaignEventsWorklistPageUrl: '/wiki/Event:Foo/Worklist' } );
		await settle();

		const link = wrapper.get( '.ext-campaignevents-worklist-page-link' );
		expect( link.attributes( 'href' ) ).toBe( '/wiki/Event:Foo/Worklist' );
	} );

	it( 'omits the worklist page link when the page cannot be resolved', async () => {
		// Empty for an event on another wiki, where the subpage is not resolvable locally.
		const wrapper = mountApp( { wgCampaignEventsWorklistPageUrl: '' } );
		await settle();

		expect( wrapper.find( '.ext-campaignevents-worklist-page-link' ).exists() ).toBe( false );
	} );

	it( 'points the history control at the worklist page history', async () => {
		const wrapper = mountApp();
		await settle();

		const history = wrapper.get( '.ext-campaignevents-worklist-toolbar__history' );
		// A real link, not a button styled as one: a <button href> navigates nowhere.
		expect( history.element.tagName ).toBe( 'A' );
		expect( history.attributes( 'href' ) ).toBe( '/w/index.php?title=X&action=history' );
	} );

	it( 'hides the history control when the worklist page is on another wiki', async () => {
		const wrapper = mountApp( { wgCampaignEventsWorklistPageHistoryUrl: '' } );
		await settle();
		expect( wrapper.find( '.ext-campaignevents-worklist-toolbar__history' ).exists() )
			.toBe( false );
	} );
} );
