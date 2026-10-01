'use strict';

/* global setTimeout */

const { mount } = require( '@vue/test-utils' );
const { nextTick } = require( 'vue' );
const AddWorklistArticleDialog = require( '../../../../../resources/ext.campaignEvents.specialPages/eventdetails/components/AddWorklistArticleDialog.vue' );

const ADD_BUTTON = '.ext-campaignevents-event-details-worklist-add-button';

const mountDialog = () => {
	// For the article search, which has its own tests.
	mw.Api = jest.fn( () => ( { get: jest.fn() } ) );
	return mount( AddWorklistArticleDialog );
};

// The dialog content, including the article search, is only rendered while open.
const openDialog = async ( wrapper ) => {
	wrapper.vm.open = true;
	await nextTick();
};

const chooseTitle = ( wrapper, title ) => wrapper
	.getComponent( { name: 'WorklistArticleSearch' } )
	.vm.$emit( 'choose', title );

describe( 'AddWorklistArticleDialog', () => {
	it( 'is closed initially', () => {
		const wrapper = mountDialog();
		expect( wrapper.vm.open ).toBe( false );
	} );

	it( 'renders the add button with an icon', () => {
		const wrapper = mountDialog();
		expect( wrapper.find( ADD_BUTTON ).exists() ).toBe( true );
		expect( wrapper.getComponent( { name: 'CdxIcon' } ).exists() ).toBe( true );
	} );

	it( 'opens the dialog when the add button is clicked', async () => {
		const wrapper = mountDialog();
		await wrapper.find( ADD_BUTTON ).trigger( 'click' );
		expect( wrapper.vm.open ).toBe( true );
	} );

	it( 'has use-close-button enabled and a progressive submit action', () => {
		const wrapper = mountDialog();
		const cdxDialog = wrapper.getComponent( { name: 'CdxDialog' } );
		expect( cdxDialog.props( 'useCloseButton' ) ).toBe( true );
		expect( wrapper.vm.primaryAction.label ).toBe( '(campaignevents-event-details-worklist-add-dialog-submit)' );
		expect( wrapper.vm.primaryAction.actionType ).toBe( 'progressive' );
	} );

	it( 'renders the article search when opened', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );
		expect( wrapper.findComponent( { name: 'WorklistArticleSearch' } ).exists() ).toBe( true );
	} );

	it( 'appends a chosen title to the textarea', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );

		chooseTitle( wrapper, 'Moon' );

		expect( wrapper.vm.articlesText ).toBe( 'Moon' );
	} );

	it( 'appends multiple titles one per line', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );

		chooseTitle( wrapper, 'Moon' );
		chooseTitle( wrapper, 'Sun' );

		expect( wrapper.vm.articlesText ).toBe( 'Moon\nSun' );
	} );

	it( 'de-duplicates repeated titles', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );

		chooseTitle( wrapper, 'Moon' );
		chooseTitle( wrapper, 'Moon' );

		expect( wrapper.vm.articlesText ).toBe( 'Moon' );
	} );

	it( 'trims whitespace from chosen titles', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );

		chooseTitle( wrapper, '  Moon  ' );

		expect( wrapper.vm.articlesText ).toBe( 'Moon' );
	} );

	it( 'ignores empty or whitespace-only titles', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );

		chooseTitle( wrapper, '   ' );

		expect( wrapper.vm.articlesText ).toBe( '' );
	} );

	it( 'does not call the API on submit when no titles are entered', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );
		const restAjax = jest.fn();
		mw.Rest.mockImplementation( () => ( { ajax: restAjax } ) );

		wrapper.vm.onSubmit();

		expect( restAjax ).not.toHaveBeenCalled();
	} );

	it( 'sends a PATCH with the entered titles on submit', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );

		mw.config.get = ( key ) => ( {
			wgCampaignEventsWorklistPagePrefixedText: 'Event:My Event/Worklist',
			wgDBname: 'awiki',
			wgCampaignEventsWorklistWikiRestUrl: null
		} )[ key ];
		jest.spyOn( mw.user.tokens, 'get' ).mockReturnValue( 'csrf-token' );
		const restAjax = jest.fn().mockResolvedValue( {} );
		mw.Rest.mockImplementation( () => ( { ajax: restAjax } ) );

		wrapper.vm.articlesText = 'Moon\nSun';
		await wrapper.vm.onSubmit();

		expect( restAjax ).toHaveBeenCalledWith(
			'/campaignevents/v0/worklist/' + encodeURIComponent( 'Event:My Event/Worklist' ) + '/pages',
			{
				type: 'PATCH',
				headers: { 'content-type': 'application/json' },
				data: JSON.stringify( {
					add: { awiki: [ 'Moon', 'Sun' ] },
					token: 'csrf-token'
				} )
			}
		);
	} );
	it( 'closes the dialog and clears the input after a successful submit', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );

		mw.config.get = ( key ) => ( {
			wgCampaignEventsWorklistPagePrefixedText: 'Event:My Event/Worklist',
			wgDBname: 'awiki',
			wgCampaignEventsWorklistWikiRestUrl: null
		} )[ key ];
		jest.spyOn( mw.user.tokens, 'get' ).mockReturnValue( 'csrf-token' );
		mw.Rest.mockImplementation( () => ( { ajax: jest.fn().mockResolvedValue( {} ) } ) );

		wrapper.vm.articlesText = 'Moon';
		await wrapper.vm.onSubmit();
		// Allow the save promise's success handler to run.
		await new Promise( ( resolve ) => {
			setTimeout( resolve, 0 );
		} );

		expect( wrapper.vm.open ).toBe( false );
		expect( wrapper.vm.articlesText ).toBe( '' );
	} );

	it( 'shows error formatted with BCP-47 messageTranslation on API rejection', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );

		mw.config.get = ( key ) => ( {
			wgCampaignEventsWorklistPagePrefixedText: 'Event:My Event/Worklist',
			wgDBname: 'awiki',
			wgCampaignEventsWorklistWikiRestUrl: null,
			wgContentLanguage: 'sr-ec'
		} )[ key ];
		mw.language.bcp47 = jest.fn( ( lang ) => ( lang === 'sr-ec' ? 'sr-Cyrl' : lang ) );
		jest.spyOn( mw.user.tokens, 'get' ).mockReturnValue( 'csrf-token' );

		mw.Rest.mockImplementation( () => ( {
			ajax: jest.fn().mockImplementation( () => ( {
				then: ( success, failure ) => {
					failure(
						null,
						{
							xhr: {
								responseJSON: {
									messageTranslations: {
										'sr-Cyrl': 'Грешка'
									}
								}
							}
						}
					);
				}
			} ) )
		} ) );

		wrapper.vm.articlesText = 'Moon';
		await wrapper.vm.onSubmit();

		await new Promise( ( resolve ) => {
			setTimeout( resolve, 0 );
		} );

		expect( mw.language.bcp47 ).toHaveBeenCalledWith( 'sr-ec' );
		expect( wrapper.vm.hasMessage ).toBe( true );
		expect( wrapper.vm.message ).toBe( '(campaignevents-event-details-worklist-add-dialog-error, Грешка)' );
	} );
} );
