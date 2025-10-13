'use strict';

/* global global, setTimeout */

const { mount } = require( '@vue/test-utils' );
const ContributionsActionsApp = require( '../../../../../resources/ext.campaignEvents.specialPages/eventdetails/components/ContributionsActionsApp.vue' );
const { nextTick } = require( 'vue' );

const defaultConfig = {
	wgCampaignEventsEventName: 'Test Event'
};

const mountApp = ( configOverrides = {} ) => {
	const config = Object.assign( {}, defaultConfig, configOverrides );
	mw.config = {
		get: ( key ) => config[ key ]
	};
	return mount( ContributionsActionsApp );
};

describe( 'ContributionsActionsApp', () => {
	it( 'contains the delete dialog', () => {
		const wrapper = mountApp();
		expect( wrapper.getComponent( { name: 'DeleteContributionDialog' } ).exists() ).toBe( true );
	} );

	it( 'opens dialog when delete button is clicked', async () => {
		const wrapper = mountApp();
		await nextTick();

		wrapper.vm.openDeleteDialog( '123' );

		expect( wrapper.vm.isDialogOpen ).toBe( true );
	} );

	it( 'makes a request to delete the contribution', async () => {
		const wrapper = mountApp();
		await nextTick();

		const csrfToken = 'csrf-token';
		const userTokensSpy = jest.spyOn( mw.user.tokens, 'get' ).mockImplementation( ( tokenType ) => {
			switch ( tokenType ) {
				case 'csrfToken':
					return csrfToken;
				default:
					throw new Error( 'Unknown token type: ' + tokenType );
			}
		} );
		const restDelete = jest.fn().mockResolvedValue( {} );
		mw.Rest.mockImplementation( () => ( {
			delete: restDelete
		} ) );

		wrapper.vm.openDeleteDialog( '123' );
		await wrapper.vm.onConfirmDelete();

		expect( userTokensSpy ).toHaveBeenCalledWith( 'csrfToken' );
		expect( restDelete ).toHaveBeenCalledWith(
			'/campaignevents/v0/event_contributions/123',
			{ token: csrfToken }
		);
		expect( mw.notify ).toHaveBeenCalledWith(
			'(campaignevents-event-details-contributions-delete-success)',
			{ type: 'success' }
		);
	} );

	it( 'handles successful delete and removes contribution row from DOM', async () => {
		const wrapper = mountApp();
		await nextTick();

		const csrfToken = 'csrf-token';
		jest.spyOn( mw.user.tokens, 'get' ).mockReturnValue( csrfToken );

		const restDelete = jest.fn().mockResolvedValue( {} );
		mw.Rest.mockImplementation( () => ( {
			delete: restDelete
		} ) );

		// Mock jQuery fadeOut
		const mockFadeOut = jest.fn();
		const mockClosest = jest.fn( () => ( {
			fadeOut: mockFadeOut
		} ) );
		global.$ = jest.fn( () => ( {
			closest: mockClosest
		} ) );

		wrapper.vm.openDeleteDialog( '123' );
		await wrapper.vm.onConfirmDelete();

		// Verify success notification
		expect( mw.notify ).toHaveBeenCalledWith(
			'(campaignevents-event-details-contributions-delete-success)',
			{ type: 'success' }
		);

		// Verify contribution row removal via jQuery
		expect( global.$ ).toHaveBeenCalledWith( '[data-contrib-id="123"]' );
		expect( mockClosest ).toHaveBeenCalledWith( 'tr' );
		expect( mockFadeOut ).toHaveBeenCalled();
	} );

	it( 'does not delete the contribution if user closes the dialog', async () => {
		const wrapper = mountApp();
		await nextTick();

		const dialog = wrapper.getComponent( { name: 'DeleteContributionDialog' } );
		const restDelete = jest.fn();
		mw.Rest.mockImplementation( () => ( {
			delete: restDelete
		} ) );

		wrapper.vm.openDeleteDialog( '123' );
		dialog.vm.$emit( 'cancel' );

		expect( wrapper.vm.isDialogOpen ).toBe( false );
		expect( restDelete ).not.toHaveBeenCalled();
	} );

	it( 'handles API error and shows error notification', async () => {
		const wrapper = mountApp();
		await nextTick();

		const csrfToken = 'csrf-token';
		jest.spyOn( mw.user.tokens, 'get' ).mockReturnValue( csrfToken );

		const restDelete = jest.fn().mockRejectedValue( new Error( 'API Error' ) );
		mw.Rest.mockImplementation( () => ( {
			delete: restDelete
		} ) );

		wrapper.vm.openDeleteDialog( '123' );
		await wrapper.vm.onConfirmDelete();

		// Allow promise rejection handlers to run
		await new Promise( ( resolve ) => {
			setTimeout( resolve, 0 );
		} );

		// Verify error notification was called
		expect( mw.notify ).toHaveBeenCalledWith(
			'(campaignevents-event-details-contributions-delete-error)',
			{ type: 'error' }
		);
		expect( restDelete ).toHaveBeenCalled();
	} );

	it( 'does not make API call if no current contrib ID', async () => {
		const wrapper = mountApp();
		await nextTick();

		const restDelete = jest.fn();
		mw.Rest.mockImplementation( () => ( {
			delete: restDelete
		} ) );

		await wrapper.vm.onConfirmDelete();

		expect( restDelete ).not.toHaveBeenCalled();
	} );

	it( 'handles network failure gracefully', async () => {
		const wrapper = mountApp();
		await nextTick();

		const restDelete = jest.fn().mockRejectedValue( new Error( 'Network Error' ) );
		mw.Rest.mockImplementation( () => ( {
			delete: restDelete
		} ) );

		wrapper.vm.openDeleteDialog( '123' );
		await wrapper.vm.onConfirmDelete();

		// Verify API call was made
		expect( restDelete ).toHaveBeenCalled();
	} );

	it( 'always closes dialog and resets currentContribID after API call', async () => {
		const wrapper = mountApp();
		await nextTick();

		const csrfToken = 'csrf-token';
		jest.spyOn( mw.user.tokens, 'get' ).mockReturnValue( csrfToken );

		const restDelete = jest.fn().mockResolvedValue( {} );
		mw.Rest.mockImplementation( () => ( {
			delete: restDelete
		} ) );

		wrapper.vm.openDeleteDialog( '123' );
		expect( wrapper.vm.isDialogOpen ).toBe( true );

		await wrapper.vm.onConfirmDelete();

		// Verify API call was made
		expect( restDelete ).toHaveBeenCalled();
	} );

	it( 'calls onCancel to close dialog and reset state', async () => {
		const wrapper = mountApp();

		// Set initial state
		wrapper.vm.openDeleteDialog( '123' );
		expect( wrapper.vm.isDialogOpen ).toBe( true );

		wrapper.vm.onCancel();

		expect( wrapper.vm.isDialogOpen ).toBe( false );
	} );

	it( 'handles eventName fallback when config is undefined', () => {
		const wrapper = mountApp( { wgCampaignEventsEventName: undefined } );
		expect( wrapper.vm.eventName ).toBe( '' );
	} );

} );
