'use strict';

const { ref } = require( 'vue' );
const { flushPromises } = require( '@vue/test-utils' );
const qualityLoader = require(
	'../../../resources/ext.campaignEvents.cardElements/qualityLoader.js'
);

// The caller gives the path. The loader does not read it from the page config.
const PATH = '/campaignevents/v0/worklist/somewiki/1728/My_event%2FWorklist/pages/quality';

const article = ( title, wiki = 'awiki' ) => ( { wiki: wiki, title: title } );

/**
 * An endpoint entry, with only the elements a case cares about.
 *
 * @param {string} title
 * @param {Object} elements
 * @param {string} [wiki]
 * @return {Object}
 */
const entry = ( title, elements, wiki = 'awiki' ) => ( { wiki: wiki, title: title, elements: elements } );

/** @return {jest.Mock} The mocked mw.Rest get() */
function mockRest() {
	const get = jest.fn();
	mw.Rest = jest.fn( () => ( { get: get } ) );
	return get;
}

beforeEach( () => {
	qualityLoader.clearCache();
	jest.clearAllMocks();
} );

describe( 'qualityLoader', () => {
	describe( 'bandFor', () => {
		it.each( [
			[ 0, 'high' ],
			[ 0.4, 'high' ],
			// 40% is in the first band only: the middle one was moved to start at 41 so that the
			// two do not overlap, and so 85 to 86 is not a gap.
			[ 0.41, 'medium' ],
			[ 0.85, 'medium' ],
			[ 0.86, 'low' ],
			[ 1, 'low' ]
		] )( 'puts %s in the %s band', ( score, band ) => {
			expect( qualityLoader.bandFor( score ) ).toBe( band );
		} );

		it( 'rounds to a whole percentage before banding', () => {
			// Without rounding first, 0.854 falls between the bands rather than inside one.
			expect( qualityLoader.bandFor( 0.854 ) ).toBe( 'medium' );
			expect( qualityLoader.bandFor( 0.856 ) ).toBe( 'low' );
			expect( qualityLoader.bandFor( 0.404 ) ).toBe( 'high' );
			expect( qualityLoader.bandFor( 0.406 ) ).toBe( 'medium' );
		} );

		it( 'gives no band without a usable score', () => {
			[ undefined, null, 'lots', NaN ].forEach( ( score ) => {
				expect( qualityLoader.bandFor( score ) ).toBeNull();
			} );
		} );
	} );

	it( 'reports an element scoring below 50% as a signal', async () => {
		const get = mockRest();
		get.mockResolvedValue( { articles: [ entry( 'Beavers', { refs: 0.2, wikilinks: 1 } ) ] } );

		const signals = await qualityLoader.fetchQuality( PATH, [ article( 'Beavers' ) ] );

		expect( signals.get( 'awiki|Beavers' ).signals )
			.toEqual( [ '(campaignevents-card-signal-refs)' ] );
	} );

	it( 'leaves out elements at or above the threshold', async () => {
		const get = mockRest();
		get.mockResolvedValue( { articles: [ entry( 'Beavers', { refs: 0.5, wikilinks: 0.9 } ) ] } );

		// Exactly 0.5 is not below 50%, so it is not a weakness.
		expect( ( await qualityLoader.fetchQuality( PATH, [ article( 'Beavers' ) ] ) ).get( 'awiki|Beavers' ).signals )
			.toEqual( [] );
	} );

	it( 'treats a missing infobox as a signal and a present one as fine', async () => {
		const get = mockRest();
		get.mockResolvedValue( {
			articles: [
				entry( 'Beavers', { infobox: false } ),
				entry( 'Otters', { infobox: true } )
			]
		} );

		const signals = await qualityLoader.fetchQuality( PATH, [ article( 'Beavers' ), article( 'Otters' ) ] );

		// The booleans are the trap here: comparing them numerically would read `true` as 1 and
		// `false` as 0, which happens to work for infobox and would invert any other boolean.
		expect( signals.get( 'awiki|Beavers' ).signals )
			.toEqual( [ '(campaignevents-card-signal-infobox)' ] );
		expect( signals.get( 'awiki|Otters' ).signals ).toEqual( [] );
	} );

	it( 'ignores the elements that are not offered as signals', async () => {
		const get = mockRest();
		get.mockResolvedValue( {
			// `sources` says the same thing as refs to a reader and almost never dips; a
			// `messagebox` means the article carries a banner, which is not a weak element.
			articles: [ entry( 'Beavers', { sources: 0.1, messagebox: true } ) ]
		} );

		expect( ( await qualityLoader.fetchQuality( PATH, [ article( 'Beavers' ) ] ) ).get( 'awiki|Beavers' ).signals )
			.toEqual( [] );
	} );

	it( 'orders signals the same way for every article', async () => {
		const get = mockRest();
		get.mockResolvedValue( {
			articles: [ entry( 'Beavers', { characters: 0.1, refs: 0.1, media: 0.1 } ) ]
		} );

		// The card names the first and counts the rest, so a stable order keeps the same signal
		// on the card from one page view to the next.
		expect( ( await qualityLoader.fetchQuality( PATH, [ article( 'Beavers' ) ] ) ).get( 'awiki|Beavers' ).signals )
			.toEqual( [
				'(campaignevents-card-signal-refs)',
				'(campaignevents-card-signal-media)',
				'(campaignevents-card-signal-characters)'
			] );
	} );

	it( 'asks one wiki at a time', async () => {
		// Each request ends up at LiftWing, so they go in turn rather than all at once.
		const get = mockRest();
		let releaseFirst;
		get.mockImplementationOnce( () => new Promise( ( resolve ) => {
			releaseFirst = () => resolve( { articles: [] } );
		} ) );
		get.mockResolvedValue( { articles: [] } );

		const done = qualityLoader.fetchQuality( PATH, [
			article( 'Beavers' ),
			article( 'Castors', 'bwiki' )
		] );
		await Promise.resolve();

		expect( get ).toHaveBeenCalledTimes( 1 );

		releaseFirst();
		await done;

		expect( get ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'asks the endpoint once per wiki', async () => {
		const get = mockRest();
		get.mockResolvedValue( { articles: [] } );

		await qualityLoader.fetchQuality( PATH, [
			article( 'Beavers' ),
			article( 'Otters' ),
			article( 'Castors', 'bwiki' )
		] );

		expect( get ).toHaveBeenCalledTimes( 2 );
		expect( get ).toHaveBeenCalledWith( PATH, { wiki: 'awiki', titles: 'Beavers|Otters' } );
		expect( get ).toHaveBeenCalledWith( PATH, { wiki: 'bwiki', titles: 'Castors' } );
	} );

	it( 'does not ask again about articles it already knows', async () => {
		const get = mockRest();
		get.mockResolvedValue( { articles: [ entry( 'Beavers', { refs: 0.1 } ) ] } );

		await qualityLoader.fetchQuality( PATH, [ article( 'Beavers' ) ] );
		await qualityLoader.fetchQuality( PATH, [ article( 'Beavers' ) ] );

		// Paging back over articles already seen must cost nothing.
		expect( get ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'remembers that an article the endpoint said nothing about has no signals', async () => {
		const get = mockRest();
		// The model could not score it, so it is absent from the response.
		get.mockResolvedValue( { articles: [] } );

		const signals = await qualityLoader.fetchQuality( PATH, [ article( 'Beavers' ) ] );
		expect( signals.get( 'awiki|Beavers' ).signals ).toEqual( [] );

		await qualityLoader.fetchQuality( PATH, [ article( 'Beavers' ) ] );
		expect( get ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'keeps the cards when the request fails', async () => {
		const get = mockRest();
		get.mockRejectedValue( new Error( 'nope' ) );

		// The card is complete without signals, so a failure is not surfaced to the reader.
		await expect( qualityLoader.fetchQuality( PATH, [ article( 'Beavers' ) ] ) ).resolves.toBeInstanceOf( Map );
	} );

	it( 'tells the same title on two wikis apart', async () => {
		const get = mockRest();
		get.mockImplementation( ( path, params ) => Promise.resolve( {
			articles: [ params.wiki === 'awiki' ?
				entry( 'Beavers', { refs: 0.1 } ) :
				entry( 'Beavers', {}, 'bwiki' )
			]
		} ) );

		const signals = await qualityLoader.fetchQuality( PATH, [
			article( 'Beavers' ),
			article( 'Beavers', 'bwiki' )
		] );

		expect( signals.get( 'awiki|Beavers' ).signals ).toHaveLength( 1 );
		expect( signals.get( 'bwiki|Beavers' ).signals ).toEqual( [] );
	} );

	it( 'combines the calls of one tick into one request for each wiki', async () => {
		const get = mockRest();
		get.mockResolvedValue( { articles: [
			entry( 'Beavers', { refs: 0.1 } ),
			entry( 'Otters', {} )
		] } );

		// For example, one call for each card on the screen.
		const first = qualityLoader.fetchQuality( PATH, [ article( 'Beavers' ) ] );
		const second = qualityLoader.fetchQuality( PATH, [ article( 'Otters' ) ] );
		const [ beavers, otters ] = await Promise.all( [ first, second ] );

		expect( get ).toHaveBeenCalledTimes( 1 );
		expect( get ).toHaveBeenCalledWith( PATH, { wiki: 'awiki', titles: 'Beavers|Otters' } );
		// Each caller gets the articles it asked for.
		expect( [ ...beavers.keys() ] ).toEqual( [ 'awiki|Beavers' ] );
		expect( beavers.get( 'awiki|Beavers' ).signals ).toHaveLength( 1 );
		expect( [ ...otters.keys() ] ).toEqual( [ 'awiki|Otters' ] );
	} );

	it( 'asks for at most 30 titles in one request, one request after the other', async () => {
		// ArticleQualityLookup::MAX_ARTICLES is 30.
		const get = mockRest();
		let releaseFirst;
		get.mockImplementationOnce( () => new Promise( ( resolve ) => {
			releaseFirst = () => resolve( { articles: [] } );
		} ) );
		get.mockResolvedValue( { articles: [] } );
		const titles = Array.from( { length: 31 }, ( ignored, i ) => 'Article ' + i );

		const done = qualityLoader.fetchQuality( PATH, titles.map( ( t ) => article( t ) ) );
		await Promise.resolve();
		await Promise.resolve();

		expect( get ).toHaveBeenCalledTimes( 1 );
		expect( get.mock.calls[ 0 ][ 1 ].titles.split( '|' ) ).toHaveLength( 30 );

		releaseFirst();
		await done;

		expect( get ).toHaveBeenCalledTimes( 2 );
		expect( get.mock.calls[ 1 ][ 1 ].titles ).toBe( 'Article 30' );
	} );

	it( 'shares a request that is in progress', async () => {
		const get = mockRest();
		let release;
		get.mockImplementation( () => new Promise( ( resolve ) => {
			release = () => resolve( { articles: [ entry( 'Beavers', { refs: 0.1 } ) ] } );
		} ) );

		const first = qualityLoader.fetchQuality( PATH, [ article( 'Beavers' ) ] );
		await Promise.resolve();
		await Promise.resolve();
		expect( get ).toHaveBeenCalledTimes( 1 );

		// A second caller asks while the request is in progress.
		const second = qualityLoader.fetchQuality( PATH, [ article( 'Beavers' ) ] );
		release();

		const [ a, b ] = await Promise.all( [ first, second ] );
		expect( get ).toHaveBeenCalledTimes( 1 );
		expect( b.get( 'awiki|Beavers' ) ).toBe( a.get( 'awiki|Beavers' ) );
	} );

	it( 'gives no quality when the request cannot start', async () => {
		// For example, the REST client is not there. The promise must still settle.
		mw.Rest = jest.fn( () => {
			throw new Error( 'no client' );
		} );

		const quality = await qualityLoader.fetchQuality( PATH, [ article( 'Beavers' ) ] );

		expect( quality.get( 'awiki|Beavers' ) ).toEqual( { signals: [], band: null } );
	} );

	it( 'keeps the answers of each endpoint apart', async () => {
		// An article that one list does not hold can have a score in another list.
		const get = mockRest();
		get.mockImplementation( ( path ) => Promise.resolve( {
			articles: path === PATH ? [] : [ entry( 'Beavers', { refs: 0.1 } ) ]
		} ) );

		await qualityLoader.fetchQuality( PATH, [ article( 'Beavers' ) ] );
		const other = await qualityLoader.fetchQuality( '/other/quality', [ article( 'Beavers' ) ] );

		expect( get ).toHaveBeenCalledTimes( 2 );
		expect( other.get( 'awiki|Beavers' ).signals ).toHaveLength( 1 );
	} );
} );

describe( 'useQuality', () => {
	it( 'gives empty quality while it loads, then the quality', async () => {
		const get = mockRest();
		get.mockResolvedValue( { articles: [ entry( 'Beavers', { refs: 0.1, infobox: true } ) ] } );

		const { qualityFor } = qualityLoader.useQuality( PATH, ref( [ article( 'Beavers' ) ] ) );
		expect( qualityFor( article( 'Beavers' ) ) ).toEqual( { signals: [], band: null } );

		await flushPromises();
		expect( qualityFor( article( 'Beavers' ) ).signals ).toHaveLength( 1 );
	} );

	it( 'loads the new articles when the articles change', async () => {
		const get = mockRest();
		get.mockImplementation( ( path, params ) => Promise.resolve( {
			articles: [ entry( params.titles, { refs: 0.1 } ) ]
		} ) );
		const articles = ref( [ article( 'Beavers' ) ] );

		const { qualityFor } = qualityLoader.useQuality( PATH, articles );
		await flushPromises();
		articles.value = [ article( 'Otters' ) ];
		await flushPromises();

		expect( get ).toHaveBeenCalledTimes( 2 );
		expect( qualityFor( article( 'Otters' ) ).signals ).toHaveLength( 1 );
		// The quality of the articles of before stays.
		expect( qualityFor( article( 'Beavers' ) ).signals ).toHaveLength( 1 );
	} );

	it( 'sends one request for many callers in one tick', async () => {
		// For example, one component for each card.
		const get = mockRest();
		get.mockResolvedValue( { articles: [] } );

		qualityLoader.useQuality( PATH, ref( [ article( 'Beavers' ) ] ) );
		qualityLoader.useQuality( PATH, ref( [ article( 'Otters' ) ] ) );
		qualityLoader.useQuality( PATH, () => [ article( 'Beavers' ) ] );
		await flushPromises();

		expect( get ).toHaveBeenCalledTimes( 1 );
		expect( get ).toHaveBeenCalledWith( PATH, { wiki: 'awiki', titles: 'Beavers|Otters' } );
	} );

	it( 'gives empty quality when the request fails', async () => {
		const get = mockRest();
		get.mockRejectedValue( new Error( 'nope' ) );

		const { qualityFor } = qualityLoader.useQuality( PATH, ref( [ article( 'Beavers' ) ] ) );
		await flushPromises();

		expect( qualityFor( article( 'Beavers' ) ) ).toEqual( { signals: [], band: null } );
	} );

	it( 'asks for nothing when there are no articles', async () => {
		const get = mockRest();

		qualityLoader.useQuality( PATH, ref( [] ) );
		await flushPromises();

		expect( get ).not.toHaveBeenCalled();
	} );
} );
