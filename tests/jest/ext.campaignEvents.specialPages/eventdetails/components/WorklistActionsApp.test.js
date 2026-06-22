'use strict';

/* global global, setTimeout */

const { mount } = require( '@vue/test-utils' );
const WorklistActionsApp = require( '../../../../../resources/ext.campaignEvents.specialPages/eventdetails/components/WorklistActionsApp.vue' );
const { nextTick } = require( 'vue' );

const WORKLIST_PAGE = 'Event:My Event/Worklist';

const defaultConfig = {
	wgCampaignEventsWorklistPagePrefixedText: WORKLIST_PAGE
};

const mountApp = ( configOverrides = {} ) => {
	const config = Object.assign( {}, defaultConfig, configOverrides );
	mw.config = {
		get: ( key ) => config[ key ]
	};
	return mount( WorklistActionsApp );
};

describe( 'WorklistActionsApp', () => {
	beforeEach( () => {
		// Default jQuery stub so the success path's removeArticleRow() never throws in tests that
		// don't assert on it (the successful-removal test overrides global.$ to assert on it).
		global.$ = jest.fn( () => ( {
			closest: jest.fn( () => ( { fadeOut: jest.fn() } ) )
		} ) );
	} );

	it( 'contains the remove dialog', () => {
		const wrapper = mountApp();
		expect( wrapper.getComponent( { name: 'RemoveWorklistArticleDialog' } ).exists() ).toBe( true );
	} );

	it( 'opens dialog when remove button is clicked', async () => {
		const wrapper = mountApp();
		await nextTick();

		wrapper.vm.openDeleteDialog( 'awiki', 'Test Article' );

		expect( wrapper.vm.isDialogOpen ).toBe( true );
	} );

	it( 'sends a PATCH to the worklist pages endpoint to remove the article', async () => {
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
		const restAjax = jest.fn().mockResolvedValue( {} );
		mw.Rest.mockImplementation( () => ( {
			ajax: restAjax
		} ) );

		wrapper.vm.openDeleteDialog( 'awiki', 'Test Article' );
		await wrapper.vm.onConfirmDelete();

		expect( userTokensSpy ).toHaveBeenCalledWith( 'csrfToken' );
		expect( restAjax ).toHaveBeenCalledWith(
			'/campaignevents/v0/worklist/' + encodeURIComponent( WORKLIST_PAGE ) + '/pages',
			{
				type: 'PATCH',
				headers: { 'content-type': 'application/json' },
				data: JSON.stringify( {
					remove: { awiki: [ 'Test Article' ] },
					token: csrfToken
				} )
			}
		);
		expect( mw.notify ).toHaveBeenCalledWith(
			'(campaignevents-worklist-remove-success)',
			{ type: 'success' }
		);
	} );

	it( 'handles successful removal and removes the article row from the DOM', async () => {
		const wrapper = mountApp();
		await nextTick();

		const csrfToken = 'csrf-token';
		jest.spyOn( mw.user.tokens, 'get' ).mockReturnValue( csrfToken );

		const restAjax = jest.fn().mockResolvedValue( {} );
		mw.Rest.mockImplementation( () => ( {
			ajax: restAjax
		} ) );

		// Mock jQuery fadeOut
		const mockFadeOut = jest.fn();
		const mockClosest = jest.fn( () => ( {
			fadeOut: mockFadeOut
		} ) );
		global.$ = jest.fn( () => ( {
			closest: mockClosest
		} ) );

		wrapper.vm.openDeleteDialog( 'awiki', 'Test Article' );
		await wrapper.vm.onConfirmDelete();

		// Verify success notification
		expect( mw.notify ).toHaveBeenCalledWith(
			'(campaignevents-worklist-remove-success)',
			{ type: 'success' }
		);

		// Verify the article row removal via jQuery
		expect( global.$ ).toHaveBeenCalledWith( '[data-wiki="awiki"][data-title="Test Article"]' );
		expect( mockClosest ).toHaveBeenCalledWith( 'tr' );
		expect( mockFadeOut ).toHaveBeenCalled();
	} );

	it( 'uses mw.ForeignRest when the worklist page is on another wiki', async () => {
		const foreignRestUrl = 'https://foreign.example.org/w/rest.php';
		const wrapper = mountApp( {
			wgCampaignEventsWorklistWikiRestUrl: foreignRestUrl
		} );
		await nextTick();

		jest.spyOn( mw.user.tokens, 'get' ).mockReturnValue( 'csrf-token' );

		const foreignAjax = jest.fn().mockResolvedValue( {} );
		mw.ForeignRest = jest.fn().mockImplementation( () => ( {
			ajax: foreignAjax
		} ) );
		const restAjax = jest.fn().mockResolvedValue( {} );
		mw.Rest.mockImplementation( () => ( {
			ajax: restAjax
		} ) );

		wrapper.vm.openDeleteDialog( 'awiki', 'Test Article' );
		await wrapper.vm.onConfirmDelete();

		expect( mw.ForeignRest ).toHaveBeenCalledWith( foreignRestUrl );
		expect( foreignAjax ).toHaveBeenCalled();
		expect( restAjax ).not.toHaveBeenCalled();
	} );

	it( 'does not remove the article if the user closes the dialog', async () => {
		const wrapper = mountApp();
		await nextTick();

		const dialog = wrapper.getComponent( { name: 'RemoveWorklistArticleDialog' } );
		const restAjax = jest.fn();
		mw.Rest.mockImplementation( () => ( {
			ajax: restAjax
		} ) );

		wrapper.vm.openDeleteDialog( 'awiki', 'Test Article' );
		dialog.vm.$emit( 'cancel' );

		expect( wrapper.vm.isDialogOpen ).toBe( false );
		expect( restAjax ).not.toHaveBeenCalled();
	} );

	it( 'handles API error and shows error notification', async () => {
		const wrapper = mountApp();
		await nextTick();

		const csrfToken = 'csrf-token';
		jest.spyOn( mw.user.tokens, 'get' ).mockReturnValue( csrfToken );

		const restAjax = jest.fn().mockImplementation( () => ( {
			then: ( success, failure ) => {
				failure(
					null,
					{
						xhr: {
							responseText: 'API Error',
							responseJSON: {
								message: 'API Error'
							}
						}
					}
				);
			}
		} ) );
		mw.Rest.mockImplementation( () => ( {
			ajax: restAjax
		} ) );

		wrapper.vm.openDeleteDialog( 'awiki', 'Test Article' );
		await wrapper.vm.onConfirmDelete();

		// Allow promise rejection handlers to run
		await new Promise( ( resolve ) => {
			setTimeout( resolve, 0 );
		} );

		expect( mw.notify ).toHaveBeenCalledWith(
			'(campaignevents-worklist-remove-error, API Error)',
			{ type: 'error' }
		);
		expect( restAjax ).toHaveBeenCalled();
		// On error the dialog stays open and the confirm button is re-enabled, so the user
		// can retry.
		expect( wrapper.vm.isDialogOpen ).toBe( true );
		expect( wrapper.vm.isDeleting ).toBe( false );
	} );

	it( 'does not send a second request while one is already in flight', async () => {
		const wrapper = mountApp();
		await nextTick();

		jest.spyOn( mw.user.tokens, 'get' ).mockReturnValue( 'csrf-token' );

		let resolveAjax;
		const restAjax = jest.fn().mockReturnValue( new Promise( ( resolve ) => {
			resolveAjax = resolve;
		} ) );
		mw.Rest.mockImplementation( () => ( {
			ajax: restAjax
		} ) );

		wrapper.vm.openDeleteDialog( 'awiki', 'Test Article' );
		// First confirm starts the request; the second should be ignored while in flight.
		wrapper.vm.onConfirmDelete();
		wrapper.vm.onConfirmDelete();

		expect( restAjax ).toHaveBeenCalledTimes( 1 );

		resolveAjax( {} );
	} );

	it( 'does not make API call if no current article ID', async () => {
		const wrapper = mountApp();
		await nextTick();

		const restAjax = jest.fn();
		mw.Rest.mockImplementation( () => ( {
			ajax: restAjax
		} ) );

		await wrapper.vm.onConfirmDelete();

		expect( restAjax ).not.toHaveBeenCalled();
	} );

	it( 'calls onCancel to close dialog and reset state', () => {
		const wrapper = mountApp();

		wrapper.vm.openDeleteDialog( 'awiki', 'Test Article' );
		expect( wrapper.vm.isDialogOpen ).toBe( true );

		wrapper.vm.onCancel();

		expect( wrapper.vm.isDialogOpen ).toBe( false );
	} );
} );
