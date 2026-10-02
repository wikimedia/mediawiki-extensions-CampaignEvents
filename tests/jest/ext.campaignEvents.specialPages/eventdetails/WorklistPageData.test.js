'use strict';

const worklistPageData = require(
	'../../../../resources/ext.campaignEvents.specialPages/eventdetails/WorklistPageData.js'
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

/** @return {jest.Mock} The mocked get(), shared by mw.Api and mw.ForeignApi */
function mockApis() {
	const get = jest.fn();
	mw.Api = jest.fn( () => ( { get: get } ) );
	mw.ForeignApi = jest.fn( () => ( { get: get } ) );
	return get;
}

const pagesResponse = ( entries ) => ( {
	query: {
		pages: entries.map( ( [ title, pageviews, thumbnail ] ) => ( {
			title: title,
			pageviews: pageviews,
			thumbnail: thumbnail
		} ) )
	}
} );

beforeEach( () => {
	worklistPageData.clearCache();
	jest.clearAllMocks();
} );

describe( 'summarise', () => {
	it( 'totals the most recent 30 days', () => {
		// 60 days of 10 views: the figure is one window's worth, not the whole run.
		expect( worklistPageData.summarise( flat( 10, 60 ) ).count ).toBe( 300 );
	} );

	it( 'counts only the window, however many days came back', () => {
		expect( worklistPageData.summarise( flat( 10, 40 ) ).count ).toBe( 300 );
	} );

	it( 'counts a day the API has no figure for as zero', () => {
		// The current day is usually null, being incomplete; it must not lose the article.
		const withNull = daily( new Array( 59 ).fill( 10 ).concat( [ null ] ) );
		expect( worklistPageData.summarise( withNull ).count ).toBe( 290 );
	} );

	it( 'has nothing to report without any days', () => {
		expect( worklistPageData.summarise( {} ) ).toBeNull();
		expect( worklistPageData.summarise( undefined ) ).toBeNull();
	} );
} );

describe( 'thumbnailOf', () => {
	it( 'renames the address to what Codex expects', () => {
		// PageImages calls it `source`; Codex's thumbnail reads `url`.
		expect( worklistPageData.thumbnailOf( {
			thumbnail: { source: 'https://example.org/beaver.jpg', width: 200, height: 150 }
		} ) ).toEqual( { url: 'https://example.org/beaver.jpg', width: 200, height: 150 } );
	} );

	it( 'has no image for an article without one', () => {
		// Also covers a wiki with no PageImages, which sends no thumbnail at all.
		expect( worklistPageData.thumbnailOf( {} ) ).toBeNull();
		expect( worklistPageData.thumbnailOf( { thumbnail: {} } ) ).toBeNull();
	} );
} );

describe( 'fetchPageData', () => {
	it( 'asks for the image and the view count apart', async () => {
		const get = mockApis();
		get.mockResolvedValue( pagesResponse( [ [ 'Beaver', flat( 2, 60 ) ] ] ) );

		await worklistPageData.fetchPageData( [ FOREIGN ] );

		// PageImages answers for every title given; PageViewInfo answers for five. Sharing a
		// request would mean asking for images five at a time as well.
		expect( get.mock.calls.map( ( call ) => call[ 0 ].prop ) )
			.toEqual( [ 'pageimages', 'pageviews' ] );
	} );

	it( 'reads an article from the wiki that holds it', async () => {
		const get = mockApis();
		get.mockResolvedValue( pagesResponse( [
			[ 'Beaver', flat( 2, 60 ), { source: 'https://example.org/b.jpg', width: 200, height: 200 } ]
		] ) );

		const views = await worklistPageData.fetchPageData( [ FOREIGN ] );
		expect( views.get( 'enwiki|Beaver' ).image.url ).toBe( 'https://example.org/b.jpg' );

		expect( mw.ForeignApi ).toHaveBeenCalledWith(
			'https://en.wikipedia.org/w/api.php',
			{ anonymous: true }
		);
		expect( views.get( 'enwiki|Beaver' ).views.count ).toBe( 60 );
	} );

	it( 'uses this wiki for a local article', async () => {
		const get = mockApis();
		get.mockResolvedValue( pagesResponse( [ [ 'Bears', flat( 1, 60 ) ] ] ) );

		await worklistPageData.fetchPageData( [ LOCAL ] );

		expect( mw.Api ).toHaveBeenCalled();
		expect( mw.ForeignApi ).not.toHaveBeenCalled();
	} );

	it( 'skips a wiki the server could not resolve', async () => {
		const get = mockApis();
		const unresolved = Object.assign( {}, FOREIGN, { apiUrl: null } );

		const views = await worklistPageData.fetchPageData( [ unresolved ] );

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
			( params.titles || [] ).map( ( title ) => [ title, flat( 3, 60 ) ] )
		) ) );

		const data = await worklistPageData.fetchPageData( articles );

		const viewRequests = get.mock.calls.filter( ( call ) => call[ 0 ].prop === 'pageviews' );
		expect( viewRequests ).toHaveLength( 3 );
		expect( viewRequests.every( ( call ) => call[ 0 ].titles.length <= 5 ) ).toBe( true );
		// The images come back in one request, not five.
		expect( get.mock.calls.filter( ( call ) => call[ 0 ].prop === 'pageimages' ) )
			.toHaveLength( 1 );
		titles.forEach( ( title ) => {
			expect( data.get( 'enwiki|' + title ).views.count ).toBe( 90 );
		} );
	} );

	it( 'asks each wiki separately', async () => {
		const get = mockApis();
		get.mockResolvedValue( pagesResponse( [] ) );

		await worklistPageData.fetchPageData( [ LOCAL, FOREIGN ] );

		// Two kinds of request per wiki, each against that wiki's own API.
		expect( get ).toHaveBeenCalledTimes( 4 );
	} );

	it( 'does not ask again about articles it already knows', async () => {
		const get = mockApis();
		get.mockResolvedValue( pagesResponse( [ [ 'Bears', flat( 1, 60 ) ] ] ) );

		await worklistPageData.fetchPageData( [ LOCAL ] );
		await worklistPageData.fetchPageData( [ LOCAL ] );

		// The second call is answered from the cache, so only the first asks.
		expect( get ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'keeps the cards when a wiki has no pageview data', async () => {
		const get = mockApis();
		// A wiki without PageViewInfo answers with a warning rather than the data.
		get.mockRejectedValue( new Error( 'unrecognized value for parameter "prop"' ) );

		const views = await worklistPageData.fetchPageData( [ LOCAL ] );

		expect( views.get( 'my_wiki|Bears' ).views ).toBeNull();
	} );
} );
