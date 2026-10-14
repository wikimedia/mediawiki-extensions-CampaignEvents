'use strict';

/* global global */

const { mount } = require( '@vue/test-utils' );
const { CdxCard } = require( '@wikimedia/codex' );
const { nextTick } = require( 'vue' );
const WorklistApp = require( '../../../../../resources/ext.campaignEvents.specialPages/eventdetails/components/WorklistApp.vue' );
const worklistPages = require( '../../../../../resources/ext.campaignEvents.specialPages/eventdetails/worklistPages.js' );
const worklistPageData = require( '../../../../../resources/ext.campaignEvents.specialPages/eventdetails/WorklistPageData.js' );
const worklistQuality = require( '../../../../../resources/ext.campaignEvents.specialPages/eventdetails/WorklistQuality.js' );

const LOCAL_WIKI = 'my_wiki';
const CARD = '.ext-campaignevents-worklist-card';

const article = ( title, overrides = {} ) => Object.assign( {
	wiki: LOCAL_WIKI,
	wikiName: 'My wiki',
	title: title,
	timestamp: '2026-09-03T14:32:00Z',
	timestampFormatted: '14:32, 3 September 2026',
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

const PAGINATION = '.ext-campaignevents-worklist-pagination';
const firstButton = ( wrapper ) => wrapper.get( PAGINATION + '__first' );
const prevButton = ( wrapper ) => wrapper.get( PAGINATION + '__prev' );
const nextButton = ( wrapper ) => wrapper.get( PAGINATION + '__next' );
const lastButton = ( wrapper ) => wrapper.get( PAGINATION + '__last' );

/**
 * @param {Object} [config]
 * @return {Object}
 */
const mountApp = ( config = {} ) => {
	mw.config = {
		get: ( key ) => Object.assign( {
			wgDBname: LOCAL_WIKI,
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
		jest.spyOn( worklistPageData, 'fetchPageData' ).mockResolvedValue( new Map() );
		jest.spyOn( worklistQuality, 'fetchQuality' ).mockResolvedValue( new Map() );
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

	it( 'offers fixed pagination buttons', async () => {
		worklistPages.fetchPages.mockResolvedValue( worklistOf( ARTICLES_PER_PAGE * 3 ) );
		const wrapper = mountApp();
		await settle();

		expect( firstButton( wrapper ).attributes( 'disabled' ) ).toBeDefined();
		expect( prevButton( wrapper ).attributes( 'disabled' ) ).toBeDefined();
		expect( nextButton( wrapper ).attributes( 'disabled' ) ).toBeUndefined();
		expect( lastButton( wrapper ).attributes( 'disabled' ) ).toBeUndefined();
	} );

	it( 'can go to the next page', async () => {
		worklistPages.fetchPages.mockResolvedValue( worklistOf( ARTICLES_PER_PAGE * 3 ) );
		const wrapper = mountApp();
		await settle();
		worklistPages.fetchPages.mockClear();

		expect( prevButton( wrapper ).attributes( 'disabled' ) ).toBeDefined();
		expect( nextButton( wrapper ).attributes( 'disabled' ) ).toBeUndefined();

		await nextButton( wrapper ).trigger( 'click' );
		await settle();

		expect( prevButton( wrapper ).attributes( 'disabled' ) ).toBeUndefined();
		expect( nextButton( wrapper ).attributes( 'disabled' ) ).toBeUndefined();

		expect( wrapper.findAll( CARD )[ 0 ].text() )
			.toContain( 'Article ' + ( ARTICLES_PER_PAGE + 1 ) );
		expect( worklistPages.fetchPages ).not.toHaveBeenCalled();
	} );

	it( 'names the icon-only paging buttons for assistive technology', async () => {
		worklistPages.fetchPages.mockResolvedValue( worklistOf( ARTICLES_PER_PAGE + 3 ) );
		const wrapper = mountApp();
		await settle();

		// The chevrons say nothing on their own, so the name has to come from the label.
		expect( firstButton( wrapper ).attributes( 'aria-label' ) )
			.toContain( 'worklist-first-page' );
		expect( prevButton( wrapper ).attributes( 'aria-label' ) )
			.toContain( 'worklist-previous-page' );
		expect( nextButton( wrapper ).attributes( 'aria-label' ) )
			.toContain( 'worklist-next-page' );
		expect( lastButton( wrapper ).attributes( 'aria-label' ) )
			.toContain( 'worklist-last-page' );
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

		expect( firstButton( wrapper ).attributes( 'disabled' ) ).toBeDefined();
		expect( prevButton( wrapper ).attributes( 'disabled' ) ).toBeDefined();
		expect( nextButton( wrapper ).attributes( 'disabled' ) ).toBeUndefined();
		expect( lastButton( wrapper ).attributes( 'disabled' ) ).toBeUndefined();

		await nextButton( wrapper ).trigger( 'click' );
		await settle();

		expect( firstButton( wrapper ).attributes( 'disabled' ) ).toBeUndefined();
		expect( prevButton( wrapper ).attributes( 'disabled' ) ).toBeUndefined();
		expect( nextButton( wrapper ).attributes( 'disabled' ) ).toBeDefined();
		expect( lastButton( wrapper ).attributes( 'disabled' ) ).toBeDefined();
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
		expect( firstButton( wrapper ).attributes( 'disabled' ) ).toBeDefined();
	} );
	it( 'shows the description of each article once it arrives', async () => {
		worklistPageData.fetchPageData.mockResolvedValue( new Map( [
			[ LOCAL_WIKI + '|Bears', { views: null, image: null,
				description: 'Large omnivorous mammals' } ]
		] ) );

		const wrapper = mountApp();
		await settle();

		expect( wrapper.get( '.cdx-card__text__description' ).text() )
			.toBe( 'Large omnivorous mammals' );
	} );

	it( 'shows the description of an article on another wiki', async () => {
		// The description is read from the wiki holding the article, so a worklist whose pages
		// live elsewhere — as those on metawiki do — still gets one.
		worklistPages.fetchPages.mockResolvedValue( {
			pages: [ article( 'Foreign article', {
				wiki: 'otherwiki', isLocal: false, classes: 'external'
			} ) ]
		} );
		worklistPageData.fetchPageData.mockResolvedValue( new Map( [
			[ 'otherwiki|Foreign article', { views: null, image: null,
				description: 'an article elsewhere' } ]
		] ) );

		const wrapper = mountApp();
		await settle();

		expect( wrapper.get( '.cdx-card__text__description' ).text() )
			.toBe( 'an article elsewhere' );
	} );

	it( 'shows added time for each article', async () => {
		const wrapper = mountApp();
		await settle();

		const time = wrapper.get( 'time.ext-campaignevents-worklist-card__added' );
		expect( time.attributes( 'datetime' ) ).toBe( '2026-09-03T14:32:00Z' );
		expect( time.text() ).toContain( '14:32, 3 September 2026' );
	} );

	it( 'fills the view counts in after the cards have rendered', async () => {
		worklistPageData.fetchPageData.mockResolvedValue(
			new Map( [ [ LOCAL_WIKI + '|Bears', {
				views: { count: 20437 },
				image: { url: 'https://example.org/bears.jpg', width: 200, height: 200 }
			} ] ] )
		);

		const wrapper = mountApp();
		await settle();

		expect( wrapper.get( '.ext-campaignevents-worklist-card__views' ).text() )
			.toContain( 'views-thousands, 20' );
		// The image arrives on the same request, so it reaches the card at the same moment.
		expect( wrapper.getComponent( CdxCard ).props( 'thumbnail' ).url )
			.toBe( 'https://example.org/bears.jpg' );
	} );

	it( 'only asks about the articles on screen', async () => {
		worklistPages.fetchPages.mockResolvedValue( worklistOf( ARTICLES_PER_PAGE * 3 ) );

		mountApp();
		await settle();

		// A worklist can hold thousands; the reader is looking at a page of them.
		expect( worklistPageData.fetchPageData ).toHaveBeenCalled();
		worklistPageData.fetchPageData.mock.calls.forEach( ( [ asked ] ) => {
			expect( asked ).toHaveLength( ARTICLES_PER_PAGE );
		} );
	} );

	it( 'still shows the cards when the view counts cannot be fetched', async () => {
		worklistPageData.fetchPageData.mockRejectedValue( new Error( 'nope' ) );

		const wrapper = mountApp();
		await settle();

		expect( wrapper.findAll( CARD ) ).toHaveLength( 1 );
		expect( wrapper.find( '.ext-campaignevents-worklist-card__views' ).exists() ).toBe( false );
	} );

	it( 'fills the signals in after the cards have rendered', async () => {
		worklistPages.fetchPages.mockResolvedValue( page( [ 'Bears' ] ) );
		worklistQuality.fetchQuality.mockResolvedValue(
			new Map( [ [ LOCAL_WIKI + '|Bears', { signals: [ 'Needs images' ], band: null } ] ] )
		);

		const wrapper = mountApp();
		await settle();

		expect( wrapper.get( '.ext-campaignevents-worklist-card__signals' ).text() )
			.toContain( 'Needs images' );
	} );

	it( 'still shows the cards when the signals cannot be fetched', async () => {
		worklistQuality.fetchQuality.mockRejectedValue( new Error( 'nope' ) );

		const wrapper = mountApp();
		await settle();

		expect( wrapper.findAll( CARD ) ).toHaveLength( 1 );
		expect( wrapper.find( '.ext-campaignevents-worklist-card__signals' ).exists() ).toBe( false );
	} );

	it( 'puts the impact chip on the card it belongs to', async () => {
		worklistPages.fetchPages.mockResolvedValue( page( [ 'Bears', 'Otters' ] ) );
		worklistQuality.fetchQuality.mockResolvedValue( new Map( [
			[ LOCAL_WIKI + '|Bears', { signals: [], band: 'high' } ],
			[ LOCAL_WIKI + '|Otters', { signals: [], band: null } ]
		] ) );

		const wrapper = mountApp();
		await settle();

		const cards = wrapper.findAll( CARD );
		expect( cards[ 0 ].find( '.ext-campaignevents-worklist-card__impact' ).exists() ).toBe( true );
		// An article the model could not score keeps a complete card, without a chip.
		expect( cards[ 1 ].find( '.ext-campaignevents-worklist-card__impact' ).exists() ).toBe( false );
	} );

} );
