'use strict';

/* global window */
const { mount } = require( '@vue/test-utils' );
const EventDiscoveryDialog = require( '../../../../resources/ext.campaignEvents.postEdit/components/EventDiscoveryDialog.vue' );

const singleEvent = { id: 1, name: 'Test event 1', url: '/wiki/Event:Test event 1' };
const multipleEvents = [
	{ id: 1, name: 'Test event 1', url: '/wiki/Event:Test event 1' },
	{ id: 2, name: 'Test event 2', url: '/wiki/Event:Test event 2' },
	{ id: 3, name: 'Test event 3', url: '/wiki/Event:Test event 3' }
];

const mountDialog = ( events = [ singleEvent ] ) => {
	mw.config = {
		get: ( key ) => key === 'wgCampaignEventsDiscoveryEvents' ? events : undefined
	};
	mw.message = jest.fn( ( key, ...params ) => {
		const str = params.length > 0 ? `(${ key }, ${ params.join( ', ' ) })` : `(${ key })`;
		return { text: jest.fn( () => str ), toString: () => str };
	} );
	return mount( EventDiscoveryDialog, {
		props: { open: true }
	} );
};

describe( 'EventDiscoveryDialog', () => {
	let windowOpenSpy;

	beforeEach( () => {
		windowOpenSpy = jest.spyOn( window, 'open' ).mockImplementation( () => {} );
	} );

	afterEach( () => {
		windowOpenSpy.mockRestore();
	} );

	it( 'exists', () => {
		expect( mountDialog().exists() ).toBe( true );
	} );

	it( 'emits default when Skip is clicked', () => {
		const wrapper = mountDialog();
		wrapper.getComponent( { name: 'CdxDialog' } ).vm.$emit( 'default' );
		expect( wrapper.emitted( 'default' ) ).toHaveLength( 1 );
	} );

	it( 'forwards update:open (close button/Esc/backdrop) so the parent can close it', () => {
		const wrapper = mountDialog();
		wrapper.getComponent( { name: 'CdxDialog' } ).vm.$emit( 'update:open', false );
		expect( wrapper.emitted( 'update:open' ) ).toBeTruthy();
		expect( wrapper.emitted( 'update:open' )[ 0 ] ).toEqual( [ false ] );
	} );

	it( 'shows the preferences footer link', () => {
		const wrapper = mountDialog();
		const footer = wrapper.find( '.cdx-dialog__footer' );
		expect( footer.html() ).toContain( 'Special:Preferences' );
		expect( footer.html() ).toContain( 'mw-prefsection-personal-campaignevents-event-discovery' );
	} );

	describe( 'single event', () => {
		it( 'includes the event name in the description', () => {
			const wrapper = mountDialog();
			expect( wrapper.find( '.cdx-dialog__body' ).html() )
				.toContain( `campaignevents-eventdiscovery-dialog-description, ${ singleEvent.name }` );
		} );

		it( 'has a primary action button', () => {
			const wrapper = mountDialog();
			const cdxDialog = wrapper.getComponent( { name: 'CdxDialog' } );
			expect( cdxDialog.props( 'primaryAction' ) ).not.toBeNull();
		} );

		it( 'does not render an event list', () => {
			const wrapper = mountDialog();
			expect( wrapper.find( '.cdx-card' ).exists() ).toBe( false );
		} );

		it( 'opens the event page in a new tab when the primary button is clicked', () => {
			const wrapper = mountDialog();
			wrapper.getComponent( { name: 'CdxDialog' } ).vm.$emit( 'primary' );
			expect( windowOpenSpy ).toHaveBeenCalledWith(
				singleEvent.url,
				'_blank',
				'noopener,noreferrer'
			);
		} );

		it( 'emits default to close the dialog after visiting the event page', () => {
			const wrapper = mountDialog();
			wrapper.getComponent( { name: 'CdxDialog' } ).vm.$emit( 'primary' );
			expect( wrapper.emitted( 'default' ) ).toHaveLength( 1 );
		} );
	} );

	describe( 'multiple events', () => {
		it( 'includes the event count in the description', () => {
			const wrapper = mountDialog( multipleEvents );
			expect( wrapper.find( '.cdx-dialog__body' ).html() )
				.toContain( `campaignevents-eventdiscovery-dialog-description-multiple, ${ multipleEvents.length }` );
		} );

		it( 'has no primary action button', () => {
			const wrapper = mountDialog( multipleEvents );
			const cdxDialog = wrapper.getComponent( { name: 'CdxDialog' } );
			expect( cdxDialog.props( 'primaryAction' ) ).toBeNull();
		} );

		it( 'renders a card for each event', () => {
			const wrapper = mountDialog( multipleEvents );
			const cards = wrapper.findAll( '.cdx-card' );
			expect( cards ).toHaveLength( multipleEvents.length );
			multipleEvents.forEach( ( event, i ) => {
				expect( cards[ i ].find( '.cdx-card__text__title' ).text() ).toBe( event.name );
				expect( cards[ i ].attributes( 'href' ) ).toBe( event.url );
				expect( cards[ i ].attributes( 'target' ) ).toBe( '_blank' );
			} );
		} );

		it( 'emits default when an event card is clicked', async () => {
			const wrapper = mountDialog( multipleEvents );
			await wrapper.find( '.cdx-card' ).trigger( 'click' );
			expect( wrapper.emitted( 'default' ) ).toHaveLength( 1 );
		} );
	} );
} );
