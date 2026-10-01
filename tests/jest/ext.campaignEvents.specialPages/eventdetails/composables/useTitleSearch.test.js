'use strict';

const useTitleSearch = require( '../../../../../resources/ext.campaignEvents.specialPages/eventdetails/composables/useTitleSearch.js' );
const fakeSearchApi = require( '../fakeSearchApi.js' );

const DELAY = useTitleSearch.SEARCH_DELAY;

const values = ( suggestions ) => suggestions.value.map( ( item ) => item.value );

// Let the search's handlers run once a response has settled.
const flush = () => jest.advanceTimersByTimeAsync( 0 );

/**
 * Search, and let typing pause for long enough that the request is sent.
 *
 * @param {Function} search
 * @param {string} text
 */
const typed = ( search, text ) => {
	search( text );
	jest.advanceTimersByTime( DELAY );
};

describe( 'useTitleSearch', () => {
	beforeEach( () => {
		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'searches article titles starting with the trimmed text, once typing pauses', () => {
		const { api, requests } = fakeSearchApi();
		useTitleSearch( api ).search( '  Mo ' );
		expect( requests ).toHaveLength( 0 );

		jest.advanceTimersByTime( DELAY );

		expect( requests ).toHaveLength( 1 );
		expect( requests[ 0 ].params ).toEqual( {
			action: 'query',
			generator: 'prefixsearch',
			gpssearch: 'Mo',
			gpsnamespace: 0,
			gpslimit: 10,
			redirects: true,
			formatversion: 2
		} );
	} );

	it( 'sends one search for text typed without pausing', () => {
		const { api, requests } = fakeSearchApi();
		const { search } = useTitleSearch( api );
		search( 'M' );
		jest.advanceTimersByTime( DELAY - 1 );
		search( 'Mo' );
		jest.advanceTimersByTime( DELAY - 1 );
		search( 'Moo' );
		jest.advanceTimersByTime( DELAY );

		expect( requests ).toHaveLength( 1 );
		expect( requests[ 0 ].params.gpssearch ).toBe( 'Moo' );
	} );

	it( 'offers the typed text first, then the matching articles', async () => {
		const { api, requests } = fakeSearchApi();
		const { suggestions, search } = useTitleSearch( api );
		typed( search, 'Mo' );
		await requests[ 0 ].respond( [ 'Moon', 'Mozart' ] );
		await flush();
		expect( suggestions.value ).toEqual( [
			{ value: 'Mo', label: 'Mo' },
			{ value: 'Moon', label: 'Moon' },
			{ value: 'Mozart', label: 'Mozart' }
		] );
	} );

	it( 'does not repeat the typed text when an article has that title', async () => {
		const { api, requests } = fakeSearchApi();
		const { suggestions, search } = useTitleSearch( api );
		// The API capitalises the first letter and reads underscores as spaces.
		typed( search, 'moon_landing' );
		await requests[ 0 ].respond( [ 'Moon landing', 'Moon landing conspiracy theories' ] );
		await flush();
		expect( values( suggestions ) )
			.toEqual( [ 'Moon landing', 'Moon landing conspiracy theories' ] );
	} );

	it( 'suggests the articles in the order of the search ranking', async () => {
		const { api, requests } = fakeSearchApi();
		const { suggestions, search } = useTitleSearch( api );
		typed( search, 'Mo' );
		// fakeSearchApi lists the pages in reverse; the ranking is in their index.
		await requests[ 0 ].respond( [ 'Moon', 'Mozart', 'Moscow' ] );
		await flush();
		expect( values( suggestions ) ).toEqual( [ 'Mo', 'Moon', 'Mozart', 'Moscow' ] );
	} );

	it( 'suggests the article a matching redirect leads to, without offering the redirect', async () => {
		const { api, requests } = fakeSearchApi();
		const { suggestions, search } = useTitleSearch( api );
		typed( search, 'USA' );
		// As returned by English Wikipedia: the redirect "USA" ranked first. It exists, so it is
		// not offered as an article to create.
		await requests[ 0 ].respondWith( { query: {
			redirects: [ { index: 1, from: 'USA', to: 'United States' } ],
			pages: [
				{ ns: 0, title: 'USA Today', index: 3 },
				{ ns: 0, title: 'United States', index: 1 },
				{ ns: 0, title: 'Usain Bolt', index: 2 }
			]
		} } );
		await flush();
		expect( values( suggestions ) ).toEqual( [ 'United States', 'Usain Bolt', 'USA Today' ] );
	} );

	it( 'drops redirect targets outside the main namespace', async () => {
		const { api, requests } = fakeSearchApi();
		const { suggestions, search } = useTitleSearch( api );
		typed( search, 'Mo' );
		await requests[ 0 ].respondWith( { query: { pages: [
			{ ns: 0, title: 'Moon', index: 1 },
			{ ns: 4, title: 'Project:Moon', index: 2 }
		] } } );
		await flush();
		expect( values( suggestions ) ).toEqual( [ 'Mo', 'Moon' ] );
	} );

	it( 'offers the typed text when no article matches, so it can be created', async () => {
		const { api, requests } = fakeSearchApi();
		const { suggestions, search } = useTitleSearch( api );
		typed( search, 'Spanish T' );
		// What the API returns when nothing matches: no query part at all.
		await requests[ 0 ].respondWith( { batchcomplete: true } );
		await flush();
		expect( values( suggestions ) ).toEqual( [ 'Spanish T' ] );
	} );

	it( 'still offers the typed text when the search fails', async () => {
		const { api, requests } = fakeSearchApi();
		const { suggestions, search } = useTitleSearch( api );
		typed( search, 'Mo' );
		await requests[ 0 ].reject();
		await flush();
		expect( values( suggestions ) ).toEqual( [ 'Mo' ] );
	} );

	it( 'aborts and ignores a search in flight replaced by a newer one', async () => {
		const { api, requests } = fakeSearchApi();
		const { suggestions, search } = useTitleSearch( api );
		typed( search, 'M' );
		typed( search, 'Mo' );
		expect( requests[ 0 ].promise.abort ).toHaveBeenCalled();

		await requests[ 1 ].respond( [ 'Moon' ] );
		// Even if the older response arrives last.
		await requests[ 0 ].respond( [ 'Mars' ] ).catch( () => {} );
		await flush();

		expect( values( suggestions ) ).toEqual( [ 'Mo', 'Moon' ] );
	} );

	it( 'aborts a search in flight as soon as the user types on, not once typing pauses', () => {
		const { api, requests } = fakeSearchApi();
		const { search } = useTitleSearch( api );
		typed( search, 'M' );
		search( 'Mo' );
		// Its results would be out of date by the time they arrived.
		expect( requests[ 0 ].promise.abort ).toHaveBeenCalled();
	} );

	it( 'ignores an older response that arrived too late to be aborted', async () => {
		const { api, requests } = fakeSearchApi();
		const { suggestions, search } = useTitleSearch( api );
		typed( search, 'M' );
		// The response is in, but its handler has not run yet when the user types on.
		requests[ 0 ].respond( [ 'Mars' ] );
		typed( search, 'Mo' );
		await requests[ 1 ].respond( [ 'Moon' ] );
		await flush();
		expect( values( suggestions ) ).toEqual( [ 'Mo', 'Moon' ] );
	} );

	it( 'keeps the current suggestions while a new search waits or is in flight', async () => {
		const { api, requests } = fakeSearchApi();
		const { suggestions, search } = useTitleSearch( api );
		typed( search, 'Mo' );
		await requests[ 0 ].respond( [ 'Moon' ] );
		await flush();

		search( 'Moo' );
		expect( values( suggestions ) ).toEqual( [ 'Mo', 'Moon' ] );
		jest.advanceTimersByTime( DELAY );
		expect( values( suggestions ) ).toEqual( [ 'Mo', 'Moon' ] );
	} );

	it( 'marks the typed text as new when no article or redirect has that title', async () => {
		const { api, requests } = fakeSearchApi();
		const { newTitle, search } = useTitleSearch( api );
		typed( search, 'Mo' );
		await requests[ 0 ].respond( [ 'Moon', 'Mozart' ] );
		await flush();
		expect( newTitle.value ).toBe( 'Mo' );
	} );

	it( 'does not mark an existing title as new', async () => {
		const { api, requests } = fakeSearchApi();
		const { newTitle, search } = useTitleSearch( api );
		typed( search, 'moon_landing' );
		await requests[ 0 ].respond( [ 'Moon landing' ] );
		await flush();
		expect( newTitle.value ).toBeNull();
	} );

	it( 'does not mark a matching redirect as new', async () => {
		const { api, requests } = fakeSearchApi();
		const { newTitle, search } = useTitleSearch( api );
		typed( search, 'USA' );
		await requests[ 0 ].respondWith( { query: {
			redirects: [ { index: 1, from: 'USA', to: 'United States' } ],
			pages: [ { ns: 0, title: 'United States', index: 1 } ]
		} } );
		await flush();
		expect( newTitle.value ).toBeNull();
	} );

	it( 'does not mark the typed text as new when the search failed', async () => {
		// Offered, but whether the article exists is not known.
		const { api, requests } = fakeSearchApi();
		const { suggestions, newTitle, search } = useTitleSearch( api );
		typed( search, 'Mo' );
		await requests[ 0 ].reject();
		await flush();
		expect( values( suggestions ) ).toEqual( [ 'Mo' ] );
		expect( newTitle.value ).toBeNull();
	} );

	it( 'clear() drops the new title too', async () => {
		const { api, requests } = fakeSearchApi();
		const { newTitle, search, clear } = useTitleSearch( api );
		typed( search, 'Spanish T' );
		await requests[ 0 ].respondWith( { batchcomplete: true } );
		await flush();
		clear();
		expect( newTitle.value ).toBeNull();
	} );

	it( 'empties the suggestions straight away, without searching, for empty text', async () => {
		const { api, requests } = fakeSearchApi();
		const { suggestions, search } = useTitleSearch( api );
		typed( search, 'Mo' );
		await requests[ 0 ].respond( [ 'Moon' ] );
		await flush();

		search( '   ' );

		expect( suggestions.value ).toEqual( [] );
		jest.advanceTimersByTime( DELAY );
		expect( requests ).toHaveLength( 1 );
	} );

	it( 'does not send a waiting search once the text is emptied', () => {
		const { api, requests } = fakeSearchApi();
		const { search } = useTitleSearch( api );
		search( 'Mo' );
		search( '' );
		jest.advanceTimersByTime( DELAY );
		expect( requests ).toHaveLength( 0 );
	} );

	it( 'clear() empties the suggestions and aborts the search in flight', () => {
		const { api, requests } = fakeSearchApi();
		const { suggestions, search, clear } = useTitleSearch( api );
		typed( search, 'Mo' );
		clear();
		expect( requests[ 0 ].promise.abort ).toHaveBeenCalled();
		expect( suggestions.value ).toEqual( [] );
	} );

	it( 'does not search after clear(), even if a search was waiting', () => {
		const { api, requests } = fakeSearchApi();
		const { search, clear } = useTitleSearch( api );
		search( 'Moon' );
		// What picking a title, or closing the dialog, does.
		clear();
		jest.advanceTimersByTime( DELAY );
		expect( requests ).toHaveLength( 0 );
	} );
} );
