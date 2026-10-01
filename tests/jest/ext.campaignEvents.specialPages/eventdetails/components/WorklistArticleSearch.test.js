'use strict';

const { mount } = require( '@vue/test-utils' );
const { nextTick } = require( 'vue' );
const WorklistArticleSearch = require( '../../../../../resources/ext.campaignEvents.specialPages/eventdetails/components/WorklistArticleSearch.vue' );
const useTitleSearch = require( '../../../../../resources/ext.campaignEvents.specialPages/eventdetails/composables/useTitleSearch.js' );
const fakeSearchApi = require( '../fakeSearchApi.js' );

const flush = () => jest.advanceTimersByTimeAsync( 0 );

/**
 * Type into the search and let typing pause for long enough that the request is sent.
 *
 * @param {Object} wrapper
 * @param {string} text
 */
const type = async ( wrapper, text ) => {
	await wrapper.find( 'input' ).setValue( text );
	jest.advanceTimersByTime( useTitleSearch.SEARCH_DELAY );
};

const mountSearch = () => {
	const fake = fakeSearchApi();
	const wrapper = mount( WorklistArticleSearch, { props: { api: fake.api } } );
	return Object.assign( { wrapper }, fake );
};

const lookup = ( wrapper ) => wrapper.getComponent( { name: 'CdxLookup' } );

const pressEnter = async ( wrapper ) => {
	await wrapper.find( 'input' ).trigger( 'keydown', { key: 'Enter' } );
	// One tick to learn whether the lookup picked anything, one for the reset.
	await nextTick();
	await nextTick();
};

describe( 'WorklistArticleSearch', () => {
	beforeEach( () => {
		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'searches the given API once typing pauses', async () => {
		const { wrapper, requests } = mountSearch();
		await wrapper.find( 'input' ).setValue( 'Mo' );
		expect( requests ).toHaveLength( 0 );

		jest.advanceTimersByTime( useTitleSearch.SEARCH_DELAY );

		expect( requests ).toHaveLength( 1 );
		expect( requests[ 0 ].params.gpssearch ).toBe( 'Mo' );
	} );

	it( 'shows the typed text and the suggestions in the lookup', async () => {
		const { wrapper, requests } = mountSearch();
		await type( wrapper, 'Mo' );
		await requests[ 0 ].respond( [ 'Moon' ] );
		await flush();
		expect( lookup( wrapper ).props( 'menuItems' ).map( ( item ) => item.value ) )
			.toEqual( [ 'Mo', 'Moon' ] );
	} );

	it( 'shows a title that does not exist yet in the red-link colour, and only that one', async () => {
		const { wrapper, requests } = mountSearch();
		await type( wrapper, 'Mo' );
		await requests[ 0 ].respond( [ 'Moon' ] );
		await flush();

		const NEW = '.ext-campaignevents-event-details-worklist-add-dialog-search__new-title';
		const marked = wrapper.findAll( NEW );
		expect( marked.map( ( item ) => item.text() ) ).toEqual( [ 'Mo' ] );
		expect( wrapper.findAll( '.cdx-menu-item' ).map( ( item ) => item.text() ) )
			.toEqual( [ 'Mo', 'Moon' ] );
	} );

	it( 'searches the current wiki when no API is given', () => {
		const localApi = fakeSearchApi().api;
		mw.Api = jest.fn( () => localApi );
		mount( WorklistArticleSearch );
		expect( mw.Api ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'emits the picked title and clears the input for the next one', async () => {
		const { wrapper, requests } = mountSearch();
		await type( wrapper, 'Mo' );
		await requests[ 0 ].respond( [ 'Moon' ] );
		await flush();

		await lookup( wrapper ).vm.$emit( 'update:selected', 'Moon' );
		// One tick for the reset, one for it to reach the lookup.
		await nextTick();
		await nextTick();

		expect( wrapper.emitted( 'choose' ) ).toEqual( [ [ 'Moon' ] ] );
		expect( lookup( wrapper ).props( 'selected' ) ).toBe( null );
		expect( lookup( wrapper ).props( 'inputValue' ) ).toBe( '' );
		expect( lookup( wrapper ).props( 'menuItems' ) ).toEqual( [] );
	} );

	it( 'adds the typed title on Enter, even one that does not exist yet', async () => {
		const { wrapper, requests } = mountSearch();
		await type( wrapper, 'Spanish T' );
		await requests[ 0 ].respondWith( { batchcomplete: true } );
		await flush();

		await pressEnter( wrapper );

		expect( wrapper.emitted( 'choose' ) ).toEqual( [ [ 'Spanish T' ] ] );
		expect( lookup( wrapper ).props( 'inputValue' ) ).toBe( '' );
	} );

	it( 'adds the typed title on Enter without waiting for the suggestions', async () => {
		const { wrapper, requests } = mountSearch();
		await wrapper.find( 'input' ).setValue( 'Spanish T' );

		await pressEnter( wrapper );

		expect( wrapper.emitted( 'choose' ) ).toEqual( [ [ 'Spanish T' ] ] );
		// The search that was waiting is no longer needed.
		jest.advanceTimersByTime( useTitleSearch.SEARCH_DELAY );
		expect( requests ).toHaveLength( 0 );
	} );

	it( 'adds a highlighted suggestion picked with Enter only once', async () => {
		const { wrapper, requests } = mountSearch();
		await type( wrapper, 'Mo' );
		await requests[ 0 ].respond( [ 'Moon' ] );
		await flush();

		// What the lookup does on Enter over a highlighted suggestion: select it, on the same tick.
		wrapper.find( 'input' ).trigger( 'keydown', { key: 'Enter' } );
		lookup( wrapper ).vm.$emit( 'update:selected', 'Moon' );
		await nextTick();
		await nextTick();

		expect( wrapper.emitted( 'choose' ) ).toEqual( [ [ 'Moon' ] ] );
	} );

	it( 'adds a suggestion highlighted with the arrow keys and picked with Enter only once', async () => {
		// Through the lookup's own keyboard handling, which runs after this component's listener.
		const { wrapper, requests } = mountSearch();
		await type( wrapper, 'Mo' );
		await requests[ 0 ].respond( [ 'Moon' ] );
		await flush();

		const input = wrapper.find( 'input' );
		await input.trigger( 'focus' );
		// The first row is the typed text; the second is the article found.
		await input.trigger( 'keydown', { key: 'ArrowDown' } );
		await input.trigger( 'keydown', { key: 'ArrowDown' } );
		await pressEnter( wrapper );

		expect( wrapper.emitted( 'choose' ) ).toEqual( [ [ 'Moon' ] ] );
	} );

	it( 'does nothing on Enter in an empty search', async () => {
		const { wrapper } = mountSearch();
		await pressEnter( wrapper );
		expect( wrapper.emitted( 'choose' ) ).toBeUndefined();
	} );

	it( 'does not emit anything when the lookup clears its selection', async () => {
		const { wrapper } = mountSearch();
		await lookup( wrapper ).vm.$emit( 'update:selected', null );
		expect( wrapper.emitted( 'choose' ) ).toBeUndefined();
	} );

	it( 'does not search for the label of the picked title', async () => {
		const { wrapper, requests } = mountSearch();
		await type( wrapper, 'Mo' );
		await requests[ 0 ].respond( [ 'Moon' ] );
		await flush();

		// What the lookup does when an item is picked: select it, then report its label as input.
		await lookup( wrapper ).vm.$emit( 'update:selected', 'Moon' );
		lookup( wrapper ).vm.$emit( 'input', 'Moon' );
		jest.advanceTimersByTime( useTitleSearch.SEARCH_DELAY );

		expect( requests ).toHaveLength( 1 );
	} );

	it( 'does not send a search still waiting for typing to pause when removed', async () => {
		const { wrapper, requests } = mountSearch();
		await wrapper.find( 'input' ).setValue( 'Mo' );
		// The dialog closes before the delay is up.
		wrapper.unmount();
		jest.advanceTimersByTime( useTitleSearch.SEARCH_DELAY );
		expect( requests ).toHaveLength( 0 );
	} );

	it( 'aborts a search still in flight when removed', async () => {
		const { wrapper, requests } = mountSearch();
		await type( wrapper, 'Mo' );
		wrapper.unmount();
		expect( requests[ 0 ].promise.abort ).toHaveBeenCalled();
	} );
} );
