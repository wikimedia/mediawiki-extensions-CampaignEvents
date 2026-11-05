'use strict';

/* eslint-env browser */

const vue = require( 'vue' );
const { CdxCard } = require( '@wikimedia/codex' );

// createMwApp is a MediaWiki addition to Vue.
vue.createMwApp = vue.createApp;

const mockQualityFor = jest.fn();
const mockUseQuality = jest.fn( () => ( { qualityFor: mockQualityFor } ) );
const mockPageDataFor = jest.fn();
const mockUsePageData = jest.fn( () => ( { pageDataFor: mockPageDataFor } ) );
jest.mock( 'ext.campaignEvents.cardElements', () => ( {
	ImpactChip: jest.requireActual( 'ext.campaignEvents.cardElements' ).ImpactChip,
	ViewCount: jest.requireActual( 'ext.campaignEvents.cardElements' ).ViewCount,
	useQuality: ( ...args ) => mockUseQuality( ...args ),
	usePageData: ( ...args ) => mockUsePageData( ...args )
} ) );

const { init } = require( '../../../resources/ext.campaignEvents.readingLists/index.js' );

const SETTLE_TIME = 100;
const CHIP = '.ext-campaignevents-readinglists-impact__chip';
const HOST = '.ext-campaignevents-readinglists-impact';
const COUNT = '.ext-campaignevents-readinglists-views__count';
const VIEWS_HOST = '.ext-campaignevents-readinglists-views';

/** The impact band of each page, as the quality endpoint gives it. */
const BANDS = { Beavers: 'high', Otters: 'low', Stoats: 'medium' };

/** The view count of each page, as usePageData() gives it. */
const VIEWS = { Beavers: 1234, Otters: 56 };

/**
 * A small app with the same tree as ReadingLists: a list of items, each with a Codex card that
 * links to the page.
 *
 * @param {Element} mountPoint
 * @param {Object[]} initialItems Each item has the fields id, url and title, and can have a
 *  description
 * @return {{items: Array, unmount: Function}}
 */
function mountReadingLists( mountPoint, initialItems ) {
	const items = vue.reactive( initialItems );
	const app = vue.createApp( {
		render: () => vue.h( 'div', { class: 'reading-lists-container' }, items.map( ( item ) => vue.h(
			'div',
			{ key: item.id, class: 'reading-lists-item' },
			[ vue.h( CdxCard, { url: item.url }, Object.assign(
				{ title: () => item.title },
				item.description ? { description: () => item.description } : {}
			) ) ]
		) ) )
	} );
	app.mount( mountPoint );
	return { items, unmount: () => app.unmount() };
}

const localItem = ( id, title, extra = {} ) => Object.assign(
	{ id, url: 'https://local.example/wiki/' + title, title },
	extra
);

/**
 * The chips on screen, as the title of the card each one is in, in DOM order.
 *
 * @param {Element} root
 * @return {string[]}
 */
function chipTitles( root ) {
	return Array.from( root.querySelectorAll( CHIP ) ).map(
		( chip ) => chip.closest( 'a.cdx-card' ).querySelector( '.cdx-card__text__title' ).textContent
	);
}

/** Lets Vue render, and then the MutationObserver and the debounce of the module run. */
async function settle() {
	await vue.nextTick();
	// The MutationObserver gives its records in a microtask.
	await Promise.resolve();
	jest.advanceTimersByTime( SETTLE_TIME );
	await vue.nextTick();
	await vue.nextTick();
}

describe( 'ext.campaignEvents.readingLists', () => {
	let root, appRoot;

	beforeEach( () => {
		jest.useFakeTimers();
		mw.config.get.mockImplementation( ( key ) => ( {
			wgWikiID: 'localwiki',
			wgCanonicalServer: 'https://local.example',
			wgArticlePath: '/wiki/$1'
		} )[ key ] );
		mw.Title = {
			newFromText: ( text ) => ( { getPrefixedText: () => text.replace( /_/g, ' ' ) } )
		};
		mockQualityFor.mockImplementation(
			( article ) => ( { band: BANDS[ article.title ] || null } )
		);
		mockPageDataFor.mockImplementation( ( article ) => ( {
			views: article.title in VIEWS ? { count: VIEWS[ article.title ] } : null
		} ) );

		root = document.createElement( 'div' );
		root.id = 'mw-content-text';
		appRoot = document.createElement( 'div' );
		root.appendChild( appRoot );
		document.body.appendChild( root );
	} );

	afterEach( () => {
		root.remove();
		jest.useRealTimers();
		delete mw.Title;
	} );

	it( 'shows a chip above the title of each card that is added after load', async () => {
		init( root );
		// ReadingLists adds its cards after its API requests.
		mountReadingLists( appRoot, [ localItem( 1, 'Beavers' ), localItem( 2, 'Otters' ) ] );
		await settle();

		expect( chipTitles( root ) ).toEqual( [ 'Beavers', 'Otters' ] );
		expect( root.querySelector( CHIP ).textContent ).toBe( '(campaignevents-card-impact-high)' );
		const host = root.querySelector( HOST );
		expect( host.nextElementSibling.classList.contains( 'cdx-card__text__title' ) ).toBe( true );
	} );

	it( 'shows a view count below the title of a card without a description', async () => {
		init( root );
		mountReadingLists( appRoot, [ localItem( 1, 'Otters' ) ] );
		await settle();

		expect( root.querySelector( COUNT ).textContent ).toBe( '(campaignevents-card-views, 56, 56, 30)' );
		const host = root.querySelector( VIEWS_HOST );
		expect( host.previousElementSibling.classList.contains( 'cdx-card__text__title' ) ).toBe( true );
	} );

	it( 'shows no view count for a page without views', async () => {
		init( root );
		mountReadingLists( appRoot, [ localItem( 1, 'Stoats' ) ] );
		await settle();

		expect( root.querySelectorAll( COUNT ) ).toHaveLength( 0 );
		// The chip of the same card is not affected.
		expect( chipTitles( root ) ).toEqual( [ 'Stoats' ] );
	} );

	it( 'asks useQuality() for the pages of this wiki only', async () => {
		init( root );
		mountReadingLists( appRoot, [
			localItem( 1, 'Beavers' ),
			{ id: 2, url: 'https://other.example/wiki/Otters', title: 'Otters' }
		] );
		await settle();

		const [ path, articles ] = mockUseQuality.mock.calls[ 0 ];
		expect( path ).toBe( '/campaignevents/v0/pages/quality' );
		expect( articles.value ).toEqual(
			[ { wiki: 'localwiki', title: 'Beavers', isLocal: true, apiUrl: null } ]
		);
		// usePageData() gets the same pages.
		expect( mockUsePageData.mock.calls[ 0 ][ 0 ] ).toBe( articles );
		// The card of a page on another wiki gets nothing.
		expect( chipTitles( root ) ).toEqual( [ 'Beavers' ] );
	} );

	it( 'keeps the chip above the title and the view count below the description', async () => {
		init( root );
		const { items } = mountReadingLists( appRoot, [ localItem( 1, 'Beavers' ) ] );
		await settle();

		items[ 0 ].description = 'Large rodents';
		await settle();

		expect( root.querySelector( '.cdx-card__text__description' ).textContent ).toBe( 'Large rodents' );
		expect( chipTitles( root ) ).toEqual( [ 'Beavers' ] );
		expect( root.querySelectorAll( HOST ) ).toHaveLength( 1 );
		expect( root.querySelector( HOST ).nextElementSibling.classList.contains( 'cdx-card__text__title' ) )
			.toBe( true );
		// The view count moves below the description.
		expect( root.querySelectorAll( COUNT ) ).toHaveLength( 1 );
		expect( root.querySelector( VIEWS_HOST ).previousElementSibling.classList.contains( 'cdx-card__text__description' ) )
			.toBe( true );
		expect( root.querySelector( COUNT ).textContent )
			.toBe( '(campaignevents-card-views, (campaignevents-card-views-thousands, 1), 1234, 30)' );
	} );

	it( 'follows the cards that are added and removed', async () => {
		init( root );
		const { items } = mountReadingLists( appRoot, [ localItem( 1, 'Beavers' ), localItem( 2, 'Otters' ) ] );
		await settle();

		items.splice( 0, 1 );
		items.push( localItem( 3, 'Stoats' ) );
		await settle();

		expect( chipTitles( root ) ).toEqual( [ 'Otters', 'Stoats' ] );
		expect( root.querySelectorAll( HOST ) ).toHaveLength( 2 );
		expect( root.querySelectorAll( VIEWS_HOST ) ).toHaveLength( 2 );
		// Only Otters has views.
		expect( root.querySelectorAll( COUNT ) ).toHaveLength( 1 );
	} );

	it( 'shows the chips of the new list after a route change', async () => {
		init( root );
		const first = mountReadingLists( appRoot, [ localItem( 1, 'Beavers' ) ] );
		await settle();

		// The router of ReadingLists changes the view without a page load.
		first.unmount();
		mountReadingLists( appRoot, [ localItem( 10, 'Otters' ), localItem( 11, 'Stoats' ) ] );
		await settle();

		expect( chipTitles( root ) ).toEqual( [ 'Otters', 'Stoats' ] );
	} );

	it( 'removes the chip when a card shows a page on another wiki', async () => {
		init( root );
		const { items } = mountReadingLists( appRoot, [ localItem( 1, 'Beavers' ) ] );
		await settle();

		items[ 0 ].url = 'https://other.example/wiki/Beavers';
		await settle();

		expect( root.querySelectorAll( HOST ) ).toHaveLength( 0 );
		expect( root.querySelectorAll( VIEWS_HOST ) ).toHaveLength( 0 );
	} );

	it( 'shows no chip for a page without a band', async () => {
		init( root );
		mountReadingLists( appRoot, [ localItem( 1, 'Unknown' ) ] );
		await settle();

		expect( root.querySelectorAll( CHIP ) ).toHaveLength( 0 );
	} );
} );
