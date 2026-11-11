'use strict';

const { ref } = require( 'vue' );
const { flushPromises } = require( '@vue/test-utils' );
const pageDataLoader = require(
	'../../../resources/ext.campaignEvents.cardElements/pageDataLoader.js'
);

const LOCAL = { wiki: 'my_wiki', title: 'Bears', isLocal: true, apiUrl: null };
const FOREIGN = {
	wiki: 'enwiki',
	title: 'Beaver',
	isLocal: false,
	apiUrl: 'https://en.wikipedia.org/w/api.php'
};

/**
 * Daily counts for a run of days, most recent last.
 *
 * @param {Array<number|null>} counts One per day
 * @return {Object} Keyed by date, as the API sends it
 */
function daily( counts ) {
	const views = {};
	counts.forEach( ( count, index ) => {
		const day = new Date( Date.UTC( 2026, 6, 1 + index ) ).toISOString().slice( 0, 10 );
		views[ day ] = count;
	} );
	return views;
}

const flat = ( count, days ) => daily( new Array( days ).fill( count ) );

/** The days the module asks the API for, so fixtures match what would come back. */
const WINDOW_DAYS = 30;

/** @return {jest.Mock} The mocked get(), shared by mw.Api and mw.ForeignApi */
function mockApis() {
	const get = jest.fn();
	mw.Api = jest.fn( () => ( { get: get } ) );
	mw.ForeignApi = jest.fn( () => ( { get: get } ) );
	return get;
}

const pagesResponse = ( entries ) => ( {
	query: {
		pages: entries.map( ( [ title, pageviews, thumbnail, description ] ) => ( {
			title: title,
			pageviews: pageviews,
			thumbnail: thumbnail,
			description: description
		} ) )
	}
} );

beforeEach( () => {
	pageDataLoader.clearCache();
	jest.clearAllMocks();
} );

describe( 'summarise', () => {
	it( 'totals every day the API returned', () => {
		// The window is set on the request, so whatever comes back is what the card counts.
		expect( pageDataLoader.summarise( flat( 10, 30 ) ).count ).toBe( 300 );
	} );

	it( 'counts a day the API has no figure for as zero', () => {
		// The current day is usually null, being incomplete; it must not lose the article.
		const withNull = daily( new Array( 29 ).fill( 10 ).concat( [ null ] ) );
		expect( pageDataLoader.summarise( withNull ).count ).toBe( 290 );
	} );

	it( 'has nothing to report without any days', () => {
		expect( pageDataLoader.summarise( {} ) ).toBeNull();
		expect( pageDataLoader.summarise( undefined ) ).toBeNull();
	} );
} );

describe( 'thumbnailOf', () => {
	it( 'renames the address to what Codex expects', () => {
		// PageImages calls it `source`; Codex's thumbnail reads `url`.
		expect( pageDataLoader.thumbnailOf( {
			thumbnail: { source: 'https://example.org/beaver.jpg', width: 200, height: 150 }
		} ) ).toEqual( { url: 'https://example.org/beaver.jpg', width: 200, height: 150 } );
	} );

	it( 'has no image for an article without one', () => {
		// Also covers a wiki with no PageImages, which sends no thumbnail at all.
		expect( pageDataLoader.thumbnailOf( {} ) ).toBeNull();
		expect( pageDataLoader.thumbnailOf( { thumbnail: {} } ) ).toBeNull();
	} );
} );

describe( 'fetchPageData', () => {
	it( 'asks for the image and the description together, the views apart', async () => {
		const get = mockApis();
		get.mockResolvedValue( pagesResponse( [ [ 'Beaver', flat( 2, WINDOW_DAYS ) ] ] ) );

		await pageDataLoader.fetchPageData( [ FOREIGN ] );

		// PageImages and the description answer for every title given; PageViewInfo answers for
		// five. Sharing one request would mean asking for all of it five at a time.
		expect( get.mock.calls.map( ( call ) => call[ 0 ].prop ) )
			.toEqual( [ 'pageimages|description', 'pageviews' ] );
	} );

	it( 'asks the API for only the days the card counts', async () => {
		// Trimming server-side halves the response; the default would be sixty days.
		const get = mockApis();
		get.mockResolvedValue( pagesResponse( [] ) );

		await pageDataLoader.fetchPageData( [ FOREIGN ] );

		const views = get.mock.calls.find( ( call ) => call[ 0 ].prop === 'pageviews' );
		expect( views[ 0 ].pvipdays ).toBe( 30 );
	} );

	it( 'asks for the lead image at a standard width', async () => {
		// MediaWiki rounds a non-standard width up to the next standard one and keeps what it
		// rendered indefinitely, so asking for one that is not on the list stores it for good.
		const standard = [ 20, 40, 60, 120, 250, 330, 500, 960, 1280, 1920, 3840 ];
		const get = mockApis();
		get.mockResolvedValue( pagesResponse( [] ) );

		await pageDataLoader.fetchPageData( [ FOREIGN ] );

		const images = get.mock.calls.find( ( call ) => call[ 0 ].prop === 'pageimages|description' );
		expect( standard ).toContain( images[ 0 ].pithumbsize );
	} );

	it( 'reads the description of an article from the wiki that holds it', async () => {
		const get = mockApis();
		get.mockResolvedValue( pagesResponse( [
			[ 'Beaver', flat( 2, 60 ), null, 'semiaquatic rodent' ]
		] ) );

		const data = await pageDataLoader.fetchPageData( [ FOREIGN ] );
		expect( data.get( 'enwiki|Beaver' ).description ).toBe( 'semiaquatic rodent' );
	} );

	it( 'has no description for an article without one', async () => {
		// Also covers a wiki with no Wikibase client, which sends no description at all.
		const get = mockApis();
		get.mockResolvedValue( pagesResponse( [ [ 'Beaver', flat( 2, 60 ) ] ] ) );

		const data = await pageDataLoader.fetchPageData( [ FOREIGN ] );
		expect( data.get( 'enwiki|Beaver' ).description ).toBeNull();
	} );

	it( 'lets a shared cache keep the answers', async () => {
		// Every participant in an event reads the same worklist, so the same screenful is asked
		// for across readers. All the props used report `public` from getCacheMode.
		const get = mockApis();
		get.mockResolvedValue( pagesResponse( [] ) );

		await pageDataLoader.fetchPageData( [ FOREIGN ] );

		expect( get.mock.calls.length ).toBeGreaterThan( 0 );
		get.mock.calls.forEach( ( call ) => {
			expect( call[ 0 ].smaxage ).toBe( 3600 );
			// Not maxage: within a page the module's own cache answers, and a browser cache
			// would only delay a reload meant to pick up a change.
			expect( call[ 0 ].maxage ).toBeUndefined();
			// Left to the reader's own language, the API answers privately and drops the
			// smaxage above, which would leave this doing nothing for a logged-in reader.
			expect( call[ 0 ].uselang ).toBe( 'content' );
		} );
	} );

	it( 'reads an article from the wiki that holds it', async () => {
		const get = mockApis();
		get.mockResolvedValue( pagesResponse( [
			[ 'Beaver', flat( 2, WINDOW_DAYS ), { source: 'https://example.org/b.jpg', width: 200, height: 200 } ]
		] ) );

		const views = await pageDataLoader.fetchPageData( [ FOREIGN ] );
		expect( views.get( 'enwiki|Beaver' ).image.url ).toBe( 'https://example.org/b.jpg' );

		expect( mw.ForeignApi ).toHaveBeenCalledWith(
			'https://en.wikipedia.org/w/api.php',
			{ anonymous: true }
		);
		expect( views.get( 'enwiki|Beaver' ).views.count ).toBe( 60 );
	} );

	it( 'uses this wiki for a local article', async () => {
		const get = mockApis();
		get.mockResolvedValue( pagesResponse( [ [ 'Bears', flat( 1, WINDOW_DAYS ) ] ] ) );

		await pageDataLoader.fetchPageData( [ LOCAL ] );

		expect( mw.Api ).toHaveBeenCalled();
		expect( mw.ForeignApi ).not.toHaveBeenCalled();
	} );

	it( 'skips a wiki the server could not resolve', async () => {
		const get = mockApis();
		const unresolved = Object.assign( {}, FOREIGN, { apiUrl: null } );

		const views = await pageDataLoader.fetchPageData( [ unresolved ] );

		expect( get ).not.toHaveBeenCalled();
		// Recorded as having no figure, so the card shows none and does not ask again.
		expect( views.get( 'enwiki|Beaver' ).views ).toBeNull();
	} );

	it( 'gets a count for every article, past PageViewInfo\'s own lookup limit', async () => {
		// PageViewInfo looks up five titles per request and leaves the rest to a continuation
		// that nothing follows, so asking for more than five at a time lost the remainder.
		const titles = Array.from( { length: 12 }, ( ignored, i ) => 'Article ' + i );
		const articles = titles.map( ( title ) => ( {
			wiki: 'enwiki', title: title, isLocal: false, apiUrl: FOREIGN.apiUrl
		} ) );
		const get = mockApis();
		get.mockImplementation( ( params ) => Promise.resolve( pagesResponse(
			( params.titles || [] ).map( ( title ) => [ title, flat( 3, WINDOW_DAYS ) ] )
		) ) );

		const data = await pageDataLoader.fetchPageData( articles );

		const viewRequests = get.mock.calls.filter( ( call ) => call[ 0 ].prop === 'pageviews' );
		expect( viewRequests ).toHaveLength( 3 );
		expect( viewRequests.every( ( call ) => call[ 0 ].titles.length <= 5 ) ).toBe( true );
		// The images come back in one request, not five.
		expect( get.mock.calls.filter( ( call ) => call[ 0 ].prop === 'pageimages|description' ) )
			.toHaveLength( 1 );
		titles.forEach( ( title ) => {
			expect( data.get( 'enwiki|' + title ).views.count ).toBe( 90 );
		} );
	} );

	it( 'asks each wiki separately', async () => {
		const get = mockApis();
		get.mockResolvedValue( pagesResponse( [] ) );

		await pageDataLoader.fetchPageData( [ LOCAL, FOREIGN ] );

		// Two kinds of request per wiki, each against that wiki's own API.
		expect( get ).toHaveBeenCalledTimes( 4 );
	} );

	it( 'does not ask again about articles it already knows', async () => {
		const get = mockApis();
		get.mockResolvedValue( pagesResponse( [ [ 'Bears', flat( 1, WINDOW_DAYS ) ] ] ) );

		await pageDataLoader.fetchPageData( [ LOCAL ] );
		await pageDataLoader.fetchPageData( [ LOCAL ] );

		// The second call is answered from the cache, so only the first asks.
		expect( get ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'keeps the cards when a wiki has no pageview data', async () => {
		const get = mockApis();
		// A wiki without PageViewInfo answers with a warning rather than the data.
		get.mockRejectedValue( new Error( 'unrecognized value for parameter "prop"' ) );

		const views = await pageDataLoader.fetchPageData( [ LOCAL ] );

		expect( views.get( 'my_wiki|Bears' ).views ).toBeNull();
	} );

	it( 'combines the calls of one tick into the same batches', async () => {
		// Twelve titles from twelve callers, for example one for each card on the screen.
		const titles = Array.from( { length: 12 }, ( ignored, i ) => 'Article ' + i );
		const get = mockApis();
		get.mockImplementation( ( params ) => Promise.resolve( pagesResponse(
			( params.titles || [] ).map( ( title ) => [ title, flat( 1, WINDOW_DAYS ) ] )
		) ) );

		const results = await Promise.all( titles.map(
			( title ) => pageDataLoader.fetchPageData( [ {
				wiki: 'enwiki', title: title, isLocal: false, apiUrl: FOREIGN.apiUrl
			} ] )
		) );

		// The same batches as one call with all twelve: 5 titles for the views, 50 for the rest.
		expect( get.mock.calls.filter( ( call ) => call[ 0 ].prop === 'pageviews' ) )
			.toHaveLength( 3 );
		expect( get.mock.calls.filter( ( call ) => call[ 0 ].prop === 'pageimages|description' ) )
			.toHaveLength( 1 );
		results.forEach( ( result, i ) => {
			expect( [ ...result.keys() ] ).toEqual( [ 'enwiki|' + titles[ i ] ] );
			expect( result.get( 'enwiki|' + titles[ i ] ).views.count ).toBe( 30 );
		} );
	} );

	it( 'shares a request that is in progress', async () => {
		const get = mockApis();
		const releases = [];
		get.mockImplementation( () => new Promise( ( resolve ) => {
			releases.push( () => resolve( pagesResponse( [ [ 'Bears', flat( 1, WINDOW_DAYS ) ] ] ) ) );
		} ) );

		const first = pageDataLoader.fetchPageData( [ LOCAL ] );
		await Promise.resolve();
		await Promise.resolve();
		expect( get ).toHaveBeenCalledTimes( 2 );

		// A second caller asks while the requests are in progress.
		const second = pageDataLoader.fetchPageData( [ LOCAL ] );
		releases.forEach( ( release ) => release() );

		const [ a, b ] = await Promise.all( [ first, second ] );
		expect( get ).toHaveBeenCalledTimes( 2 );
		expect( b.get( 'my_wiki|Bears' ) ).toBe( a.get( 'my_wiki|Bears' ) );
		expect( a.get( 'my_wiki|Bears' ).views.count ).toBe( 30 );
	} );
} );

describe( 'usePageData', () => {
	it( 'gives empty data while it loads, then the data', async () => {
		const get = mockApis();
		get.mockResolvedValue( pagesResponse( [
			[ 'Bears', flat( 1, WINDOW_DAYS ), null, 'Large mammals' ]
		] ) );

		const { pageDataFor } = pageDataLoader.usePageData( ref( [ LOCAL ] ) );
		expect( pageDataFor( LOCAL ) ).toEqual( { views: null, image: null, description: null } );

		await flushPromises();
		expect( pageDataFor( LOCAL ).views.count ).toBe( 30 );
		expect( pageDataFor( LOCAL ).description ).toBe( 'Large mammals' );
	} );

	it( 'loads the new articles when the articles change', async () => {
		const get = mockApis();
		get.mockImplementation( ( params ) => Promise.resolve( pagesResponse(
			params.titles.map( ( title ) => [ title, flat( 1, WINDOW_DAYS ) ] )
		) ) );
		const articles = ref( [ LOCAL ] );

		const { pageDataFor } = pageDataLoader.usePageData( articles );
		await flushPromises();
		articles.value = [ FOREIGN ];
		await flushPromises();

		expect( pageDataFor( FOREIGN ).views.count ).toBe( 30 );
		// The data of the articles of before stays.
		expect( pageDataFor( LOCAL ).views.count ).toBe( 30 );
	} );

	it( 'gives empty data when the requests fail', async () => {
		const get = mockApis();
		get.mockRejectedValue( new Error( 'nope' ) );

		const { pageDataFor } = pageDataLoader.usePageData( ref( [ LOCAL ] ) );
		await flushPromises();

		expect( pageDataFor( LOCAL ) ).toEqual( { views: null, image: null, description: null } );
	} );
} );
