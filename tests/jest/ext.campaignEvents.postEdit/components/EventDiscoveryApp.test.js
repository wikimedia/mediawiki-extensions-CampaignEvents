'use strict';

const { mount } = require( '@vue/test-utils' );
const App = require( '../../../../resources/ext.campaignEvents.postEdit/components/EventDiscoveryApp.vue' );

const defaultConfig = {
	wgCampaignEventsDiscoveryEvents: [
		{ id: 1, name: 'Test event 1' }
	]
};

const mountApp = ( configOverrides = {} ) => {
	const config = Object.assign( {}, defaultConfig, configOverrides );
	mw.config = {
		get: ( key ) => config[ key ]
	};
	mw.message = jest.fn( ( key, ...params ) => ( {
		text: jest.fn( () => params.length > 0 ? `(${ key }, ${ params.join( ', ' ) })` : `(${ key })` )
	} ) );
	return mount( App, {
		global: {
			// Stub the dialog to avoid triggering CdxDialog's focus-trap
			// code in jsdom, which crashes via unhandled promise rejection.
			stubs: { EventDiscoveryDialog: true }
		}
	} );
};

describe( 'App', () => {
	it( 'exists', () => {
		const wrapper = mountApp();
		expect( wrapper.exists() ).toBe( true );
	} );

	it( 'contains the dialog', () => {
		const wrapper = mountApp();
		expect( wrapper.getComponent( { name: 'EventDiscoveryDialog' } ).exists() ).toBe( true );
	} );

	it( 'the dialog starts open', () => {
		const wrapper = mountApp();
		expect( wrapper.vm.isOpen ).toBe( true );
	} );

	it( 'closes the dialog when the default event fires', async () => {
		const wrapper = mountApp();
		wrapper.getComponent( { name: 'EventDiscoveryDialog' } ).vm.$emit( 'default' );
		await wrapper.vm.$nextTick();
		expect( wrapper.vm.isOpen ).toBe( false );
	} );
} );
