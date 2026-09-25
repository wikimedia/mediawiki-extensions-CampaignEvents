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
		pages: entries.map(
			( [ title, pageviews ] ) => ( { title: title, pageviews: pageviews } )
		)
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

describe( 'fetchPageData', () => {
	it( 'reads an article from the wiki that holds it', async () => {
		const get = mockApis();
		get.mockResolvedValue( pagesResponse( [ [ 'Beaver', flat( 2, 60 ) ] ] ) );

		const views = await worklistPageData.fetchPageData( [ FOREIGN ] );

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

	it( 'asks each wiki separately', async () => {
		const get = mockApis();
		get.mockResolvedValue( pagesResponse( [] ) );

		await worklistPageData.fetchPageData( [ LOCAL, FOREIGN ] );

		expect( get ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'does not ask again about articles it already knows', async () => {
		const get = mockApis();
		get.mockResolvedValue( pagesResponse( [ [ 'Bears', flat( 1, 60 ) ] ] ) );

		await worklistPageData.fetchPageData( [ LOCAL ] );
		await worklistPageData.fetchPageData( [ LOCAL ] );

		expect( get ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'keeps the cards when a wiki has no pageview data', async () => {
		const get = mockApis();
		// A wiki without PageViewInfo answers with a warning rather than the data.
		get.mockRejectedValue( new Error( 'unrecognized value for parameter "prop"' ) );

		const views = await worklistPageData.fetchPageData( [ LOCAL ] );

		expect( views.get( 'my_wiki|Bears' ).views ).toBeNull();
	} );
} );
