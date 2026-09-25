'use strict';

const worklistQuality = require(
	'../../../../resources/ext.campaignEvents.specialPages/eventdetails/WorklistQuality.js'
);

const EVENT_ID = 42;
const PATH = '/campaignevents/v0/event_registration/42/worklist_pages/quality';

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
	mw.config.get = jest.fn( ( key ) => (
		key === 'wgCampaignEventsWorklistEventId' ? EVENT_ID : null
	) );
	return get;
}

beforeEach( () => {
	worklistQuality.clearCache();
	jest.clearAllMocks();
} );

describe( 'WorklistQuality', () => {
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
			expect( worklistQuality.bandFor( score ) ).toBe( band );
		} );

		it( 'rounds to a whole percentage before banding', () => {
			// Without rounding first, 0.854 falls between the bands rather than inside one.
			expect( worklistQuality.bandFor( 0.854 ) ).toBe( 'medium' );
			expect( worklistQuality.bandFor( 0.856 ) ).toBe( 'low' );
			expect( worklistQuality.bandFor( 0.404 ) ).toBe( 'high' );
			expect( worklistQuality.bandFor( 0.406 ) ).toBe( 'medium' );
		} );

		it( 'gives no band without a usable score', () => {
			[ undefined, null, 'lots', NaN ].forEach( ( score ) => {
				expect( worklistQuality.bandFor( score ) ).toBeNull();
			} );
		} );
	} );

	it( 'reports an element scoring below 50% as a signal', async () => {
		const get = mockRest();
		get.mockResolvedValue( { articles: [ entry( 'Beavers', { refs: 0.2, wikilinks: 1 } ) ] } );

		const signals = await worklistQuality.fetchQuality( [ article( 'Beavers' ) ] );

		expect( signals.get( 'awiki|Beavers' ).signals )
			.toEqual( [ '(campaignevents-event-details-worklist-card-signal-refs)' ] );
	} );

	it( 'leaves out elements at or above the threshold', async () => {
		const get = mockRest();
		get.mockResolvedValue( { articles: [ entry( 'Beavers', { refs: 0.5, wikilinks: 0.9 } ) ] } );

		// Exactly 0.5 is not below 50%, so it is not a weakness.
		expect( ( await worklistQuality.fetchQuality( [ article( 'Beavers' ) ] ) ).get( 'awiki|Beavers' ).signals )
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

		const signals = await worklistQuality.fetchQuality( [ article( 'Beavers' ), article( 'Otters' ) ] );

		// The booleans are the trap here: comparing them numerically would read `true` as 1 and
		// `false` as 0, which happens to work for infobox and would invert any other boolean.
		expect( signals.get( 'awiki|Beavers' ).signals )
			.toEqual( [ '(campaignevents-event-details-worklist-card-signal-infobox)' ] );
		expect( signals.get( 'awiki|Otters' ).signals ).toEqual( [] );
	} );

	it( 'ignores the elements that are not offered as signals', async () => {
		const get = mockRest();
		get.mockResolvedValue( {
			// `sources` says the same thing as refs to a reader and almost never dips; a
			// `messagebox` means the article carries a banner, which is not a weak element.
			articles: [ entry( 'Beavers', { sources: 0.1, messagebox: true } ) ]
		} );

		expect( ( await worklistQuality.fetchQuality( [ article( 'Beavers' ) ] ) ).get( 'awiki|Beavers' ).signals )
			.toEqual( [] );
	} );

	it( 'orders signals the same way for every article', async () => {
		const get = mockRest();
		get.mockResolvedValue( {
			articles: [ entry( 'Beavers', { characters: 0.1, refs: 0.1, media: 0.1 } ) ]
		} );

		// The card names the first and counts the rest, so a stable order keeps the same signal
		// on the card from one page view to the next.
		expect( ( await worklistQuality.fetchQuality( [ article( 'Beavers' ) ] ) ).get( 'awiki|Beavers' ).signals )
			.toEqual( [
				'(campaignevents-event-details-worklist-card-signal-refs)',
				'(campaignevents-event-details-worklist-card-signal-media)',
				'(campaignevents-event-details-worklist-card-signal-characters)'
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

		const done = worklistQuality.fetchQuality( [
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

		await worklistQuality.fetchQuality( [
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

		await worklistQuality.fetchQuality( [ article( 'Beavers' ) ] );
		await worklistQuality.fetchQuality( [ article( 'Beavers' ) ] );

		// Paging back over articles already seen must cost nothing.
		expect( get ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'remembers that an article the endpoint said nothing about has no signals', async () => {
		const get = mockRest();
		// The model could not score it, so it is absent from the response.
		get.mockResolvedValue( { articles: [] } );

		const signals = await worklistQuality.fetchQuality( [ article( 'Beavers' ) ] );
		expect( signals.get( 'awiki|Beavers' ).signals ).toEqual( [] );

		await worklistQuality.fetchQuality( [ article( 'Beavers' ) ] );
		expect( get ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'keeps the cards when the request fails', async () => {
		const get = mockRest();
		get.mockRejectedValue( new Error( 'nope' ) );

		// The card is complete without signals, so a failure is not surfaced to the reader.
		await expect( worklistQuality.fetchQuality( [ article( 'Beavers' ) ] ) ).resolves.toBeInstanceOf( Map );
	} );

	it( 'tells the same title on two wikis apart', async () => {
		const get = mockRest();
		get.mockImplementation( ( path, params ) => Promise.resolve( {
			articles: [ params.wiki === 'awiki' ?
				entry( 'Beavers', { refs: 0.1 } ) :
				entry( 'Beavers', {}, 'bwiki' )
			]
		} ) );

		const signals = await worklistQuality.fetchQuality( [
			article( 'Beavers' ),
			article( 'Beavers', 'bwiki' )
		] );

		expect( signals.get( 'awiki|Beavers' ).signals ).toHaveLength( 1 );
		expect( signals.get( 'bwiki|Beavers' ).signals ).toEqual( [] );
	} );
} );
