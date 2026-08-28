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

const page = ( titles ) => ( { pages: titles.map( ( t ) => article( t ) ) } );

const ARTICLES_PER_PAGE = 24;

/**
 * Titles for a worklist of a given size, numbered so a page of them can be identified.
 *
 * @param {number} count
 * @return {string[]}
 */
const articleTitles = ( count ) => Array.from(
	{ length: count },
	( ignored, index ) => 'Article ' + ( index + 1 )
);

/**
 * A response holding a worklist of a given size.
 *
 * @param {number} count
 * @return {Object}
 */
const worklistOf = ( count ) => page( articleTitles( count ) );

const PAGE_BUTTON = '.ext-campaignevents-worklist-pagination__page';

/**
 * The page numbers on offer, with a gap written as the character the markup renders.
 *
 * @param {Object} wrapper
 * @return {string[]}
 */
const offered = ( wrapper ) => wrapper
	.findAll( '.ext-campaignevents-worklist-pagination__item' )
	.map( ( item ) => item.text() );

const currentPage = ( wrapper ) => wrapper.findAll( PAGE_BUTTON )
	.filter( ( button ) => button.attributes( 'aria-current' ) === 'page' )
	.map( ( button ) => button.text() );

const PAGINATION = '.ext-campaignevents-worklist-pagination';
const nextButton = ( wrapper ) => wrapper.get( PAGINATION + '__next' );
const prevButton = ( wrapper ) => wrapper.get( PAGINATION + '__prev' );

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

const SEARCH = '.ext-campaignevents-worklist-search';
/**
 * Type into the search field. Filtering is immediate: the list is already in the client.
 *
 * @param {Object} wrapper
 * @param {string} term
 */
const search = async ( wrapper, term ) => {
	await wrapper.get( SEARCH + ' input' ).setValue( term );
	await settle();
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

	it( 'shows no pagination for a worklist that fits on one page', async () => {
		worklistPages.fetchPages.mockResolvedValue( worklistOf( ARTICLES_PER_PAGE ) );
		const wrapper = mountApp();
		await settle();

		expect( wrapper.findAll( CARD ) ).toHaveLength( ARTICLES_PER_PAGE );
		expect( wrapper.find( '.ext-campaignevents-worklist-pagination' ).exists() ).toBe( false );
	} );

	it( 'pages through the articles it already holds, without asking again', async () => {
		worklistPages.fetchPages.mockResolvedValue( worklistOf( ARTICLES_PER_PAGE + 3 ) );
		const wrapper = mountApp();
		await settle();
		expect( wrapper.findAll( CARD ) ).toHaveLength( ARTICLES_PER_PAGE );
		worklistPages.fetchPages.mockClear();

		await nextButton( wrapper ).trigger( 'click' );
		await settle();

		const shown = wrapper.findAll( CARD ).map( ( c ) => c.text() );
		expect( shown ).toHaveLength( 3 );
		expect( shown[ 0 ] ).toContain( 'Article ' + ( ARTICLES_PER_PAGE + 1 ) );
		// The whole worklist is already in hand, so a page change costs no request.
		expect( worklistPages.fetchPages ).not.toHaveBeenCalled();

		await prevButton( wrapper ).trigger( 'click' );
		await settle();
		expect( wrapper.findAll( CARD ) ).toHaveLength( ARTICLES_PER_PAGE );
	} );

	it( 'offers a numbered button for every page', async () => {
		worklistPages.fetchPages.mockResolvedValue( worklistOf( ARTICLES_PER_PAGE * 3 ) );
		const wrapper = mountApp();
		await settle();

		expect( offered( wrapper ) ).toEqual( [ '1', '2', '3' ] );
		expect( currentPage( wrapper ) ).toEqual( [ '1' ] );
	} );

	it( 'jumps straight to a page the reader picks', async () => {
		worklistPages.fetchPages.mockResolvedValue( worklistOf( ARTICLES_PER_PAGE * 3 ) );
		const wrapper = mountApp();
		await settle();
		worklistPages.fetchPages.mockClear();

		await wrapper.findAll( PAGE_BUTTON )[ 2 ].trigger( 'click' );
		await settle();

		expect( currentPage( wrapper ) ).toEqual( [ '3' ] );
		expect( wrapper.findAll( CARD )[ 0 ].text() )
			.toContain( 'Article ' + ( ARTICLES_PER_PAGE * 2 + 1 ) );
		expect( worklistPages.fetchPages ).not.toHaveBeenCalled();
	} );

	it( 'leaves out a run of pages once there are too many to show', async () => {
		worklistPages.fetchPages.mockResolvedValue( worklistOf( ARTICLES_PER_PAGE * 20 ) );
		const wrapper = mountApp();
		await settle();

		expect( offered( wrapper ) ).toEqual( [ '1', '2', '3', '4', '5', '(ellipsis)', '20' ] );

		await wrapper.findAll( PAGE_BUTTON ).find( ( b ) => b.text() === '5' ).trigger( 'click' );
		await settle();

		// The control count is held steady, so the row does not resize as the reader moves.
		expect( offered( wrapper ) ).toEqual( [ '1', '(ellipsis)', '4', '5', '6', '(ellipsis)', '20' ] );
	} );

	it( 'keeps the gap out of the accessibility tree', async () => {
		worklistPages.fetchPages.mockResolvedValue( worklistOf( ARTICLES_PER_PAGE * 20 ) );
		const wrapper = mountApp();
		await settle();

		expect(
			wrapper.get( '.ext-campaignevents-worklist-pagination__ellipsis' )
				.attributes( 'aria-hidden' )
		).toBe( 'true' );
	} );

	it( 'names the icon-only paging buttons for assistive technology', async () => {
		worklistPages.fetchPages.mockResolvedValue( worklistOf( ARTICLES_PER_PAGE + 3 ) );
		const wrapper = mountApp();
		await settle();

		// The chevrons say nothing on their own, so the name has to come from the label.
		expect( prevButton( wrapper ).attributes( 'aria-label' ) )
			.toContain( 'worklist-previous-page' );
		expect( nextButton( wrapper ).attributes( 'aria-label' ) )
			.toContain( 'worklist-next-page' );
	} );

	it( 'names the pagination landmark', async () => {
		worklistPages.fetchPages.mockResolvedValue( worklistOf( ARTICLES_PER_PAGE + 3 ) );
		const wrapper = mountApp();
		await settle();

		expect( wrapper.get( 'nav.ext-campaignevents-worklist-pagination' )
			.attributes( 'aria-label' ) )
			.toContain( 'worklist-pagination-label' );
	} );

	it( 'stops the reader paging off either end', async () => {
		worklistPages.fetchPages.mockResolvedValue( worklistOf( ARTICLES_PER_PAGE + 3 ) );
		const wrapper = mountApp();
		await settle();

		expect( prevButton( wrapper ).attributes( 'disabled' ) ).toBeDefined();
		await nextButton( wrapper ).trigger( 'click' );
		await settle();
		expect( nextButton( wrapper ).attributes( 'disabled' ) ).toBeDefined();
		expect( prevButton( wrapper ).attributes( 'disabled' ) ).toBeUndefined();
	} );

	it( 'drops back a page when removing an article empties the one shown', async () => {
		worklistPages.fetchPages.mockResolvedValue( worklistOf( ARTICLES_PER_PAGE + 1 ) );
		const wrapper = mountApp();
		await settle();

		await nextButton( wrapper ).trigger( 'click' );
		await settle();
		expect( wrapper.findAll( CARD ) ).toHaveLength( 1 );

		// The one article on the last page is removed, so that page no longer exists.
		worklistPages.fetchPages.mockResolvedValue( worklistOf( ARTICLES_PER_PAGE ) );
		await wrapper.get( '.ext-campaignevents-worklist-card__remove' ).trigger( 'click' );
		wrapper.vm.confirmRemove();
		await settle();

		expect( wrapper.findAll( CARD ) ).toHaveLength( ARTICLES_PER_PAGE );
		expect( wrapper.find( '.ext-campaignevents-worklist-pagination' ).exists() ).toBe( false );
	} );

	it( 'reports a failed load instead of showing an empty worklist', async () => {
		worklistPages.fetchPages.mockRejectedValue( {} );
		const wrapper = mountApp();
		await settle();

		expect( wrapper.find( '.cdx-message--error' ).exists() ).toBe( true );
		expect( wrapper.find( '.ext-campaignevents-worklist-empty-state' ).exists() ).toBe( false );
	} );

	it( 're-reads the current page after removing an article', async () => {
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

	it( 'filters as the reader types, without asking the server', async () => {
		worklistPages.fetchPages.mockResolvedValue( page( [ 'Bears', 'Beavers', 'Chickens' ] ) );
		const wrapper = mountApp();
		await settle();
		worklistPages.fetchPages.mockClear();

		await search( wrapper, 'bea' );

		expect( wrapper.findAll( CARD ).map( ( c ) => c.text() ) ).toEqual( [
			expect.stringContaining( 'Bears' ),
			expect.stringContaining( 'Beavers' )
		] );
		// Paging client-side means the whole worklist is in hand, so filtering costs no request.
		expect( worklistPages.fetchPages ).not.toHaveBeenCalled();
	} );

	it( 'matches any part of a title, whatever the case', async () => {
		worklistPages.fetchPages.mockResolvedValue( page( [ 'Great bustard', 'Chickens' ] ) );
		const wrapper = mountApp();
		await settle();

		await search( wrapper, 'BUST' );

		expect( wrapper.findAll( CARD ).map( ( c ) => c.text() ) )
			.toEqual( [ expect.stringContaining( 'Great bustard' ) ] );
	} );

	it( 'restores the whole worklist when the search field is cleared', async () => {
		worklistPages.fetchPages.mockResolvedValue( page( [ 'Bears', 'Chickens' ] ) );
		const wrapper = mountApp();
		await settle();

		await search( wrapper, 'bea' );
		expect( wrapper.findAll( CARD ) ).toHaveLength( 1 );

		await search( wrapper, '' );

		expect( wrapper.findAll( CARD ) ).toHaveLength( 2 );
	} );

	it( 'says that nothing matched rather than that the worklist is empty', async () => {
		const wrapper = mountApp();
		await settle();

		await search( wrapper, 'nothing matches this' );

		expect( wrapper.get( '.ext-campaignevents-worklist-empty-state' ).text() )
			.toContain( 'worklist-search-no-results' );
	} );

	it( 'ignores a list response that a later load has superseded', async () => {
		const wrapper = mountApp();
		await settle();

		let resolveStale;
		worklistPages.fetchPages
			.mockImplementationOnce( () => new Promise( ( resolve ) => {
				resolveStale = resolve;
			} ) )
			.mockResolvedValue( page( [ 'Beavers' ] ) );

		wrapper.vm.reload();
		wrapper.vm.reload();
		await settle();

		resolveStale( page( [ 'Bears' ] ) );
		await settle();

		expect( wrapper.findAll( CARD ).map( ( c ) => c.text() ) )
			.toEqual( [ expect.stringContaining( 'Beavers' ) ] );
	} );

	it( 'keeps the cards on screen while the list refreshes', async () => {
		const wrapper = mountApp();
		await settle();

		worklistPages.fetchPages.mockReturnValue( new Promise( () => {} ) );
		wrapper.vm.reload();
		await settle();

		// Replacing the list with placeholders on a refresh would make it flicker.
		expect( wrapper.find( '.ext-campaignevents-worklist-skeleton' ).exists() ).toBe( false );
		expect( wrapper.get( '.ext-campaignevents-worklist-cards' ).attributes( 'aria-busy' ) )
			.toBe( 'true' );
	} );

	it( 'returns to the first page when a search is entered', async () => {
		worklistPages.fetchPages.mockResolvedValue( worklistOf( ARTICLES_PER_PAGE + 3 ) );
		const wrapper = mountApp();
		await settle();

		await nextButton( wrapper ).trigger( 'click' );
		await settle();
		expect( wrapper.findAll( CARD ) ).toHaveLength( 3 );

		await search( wrapper, 'article' );

		// Back on the first page of the filtered results, not the second page of the old ones.
		expect( wrapper.findAll( CARD ) ).toHaveLength( ARTICLES_PER_PAGE );
		expect( currentPage( wrapper ) ).toEqual( [ '1' ] );
	} );
} );
