'use strict';

const { mount } = require( '@vue/test-utils' );
const App = require( '../../../../resources/ext.campaignEvents.postEdit/components/App.vue' );
const { nextTick } = require( 'vue' );

const defaultConfig = {
	wgCampaignEventsEventsForAssociation: [
		{ id: 1234, name: 'Test event 1234' }
	]
};

const mountApp = ( configOverrides = {} ) => {
	const config = Object.assign( {}, defaultConfig, configOverrides );
	mw.config = {
		get: ( key ) => config[ key ]
	};
	return mount( App );
};

describe( 'App', () => {
	beforeEach( () => {
		mw.hook.mockHooks = {};
	} );
	afterEach( () => {
		mw.hook.mockHooks = {};
	} );

	it( 'exists', () => {
		const wrapper = mountApp();
		expect( wrapper.exists() ).toBe( true );
	} );
	it( 'contains the dialog', () => {
		const wrapper = mountApp();
		expect( wrapper.getComponent( { name: 'EditAssociationDialog' } ).exists() ).toBe( true );
	} );
	it( 'the dialog starts off as open', () => {
		const wrapper = mountApp();
		expect( wrapper.vm.isOpen ).toBe( true );
	} );
	it( 'makes a request to associate the edit and then closes', async () => {
		const wiki = 'some_wiki',
			revID = 987;
		const wrapper = mountApp( {
			wgDBname: wiki,
			wgRevisionId: revID
		} );

		// Adapted from ReportIncident's reportIncidentDialog.test.js
		const csrfToken = 'csrf-token';
		const userTokensSpy = jest.spyOn( mw.user.tokens, 'get' ).mockImplementation( ( tokenType ) => {
			switch ( tokenType ) {
				case 'csrfToken':
					return csrfToken;
				default:
					throw new Error( 'Unknown token type: ' + tokenType );
			}
		} );
		mw.Rest = () => {};
		const restPut = jest.fn();
		jest.spyOn( mw, 'Rest' ).mockImplementation( () => ( {
			put: restPut
		} ) );

		const dialog = wrapper.getComponent( { name: 'EditAssociationDialog' } );

		await nextTick();
		const eventID = 42;
		dialog.vm.$emit( 'associate-edit', eventID );

		expect( userTokensSpy ).toHaveBeenCalledWith( 'csrfToken' );
		expect( restPut ).toHaveBeenCalledWith(
			`/campaignevents/v0/event_registration/${ eventID }/edits/${ wiki }/${ revID }`,
			{ token: csrfToken }
		);
		expect( wrapper.vm.isOpen ).toBe( false );
	} );
	it( 'does not associate the edit if user closes the dialog', async () => {
		const wrapper = mountApp(),
			cdxDialog = wrapper.getComponent( { name: 'CdxDialog' } );

		await nextTick();

		cdxDialog.vm.$emit( 'default' );
		expect( wrapper.vm.isOpen ).toBe( false );
	} );
	it( 'is reopened when the postEdit hook fires again', async () => {
		const wrapper = mountApp(),
			cdxDialog = wrapper.getComponent( { name: 'CdxDialog' } );

		await nextTick();

		cdxDialog.vm.$emit( 'default' );
		mw.hook( 'postEdit' ).fire();
		expect( wrapper.vm.isOpen ).toBe( true );
	} );
} );
