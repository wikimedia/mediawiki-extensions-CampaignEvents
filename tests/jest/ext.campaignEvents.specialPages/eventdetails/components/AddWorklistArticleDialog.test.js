'use strict';

/* global setTimeout */

const { mount } = require( '@vue/test-utils' );
const { nextTick } = require( 'vue' );
const AddWorklistArticleDialog = require( '../../../../../resources/ext.campaignEvents.specialPages/eventdetails/components/AddWorklistArticleDialog.vue' );

const ADD_BUTTON = '.ext-campaignevents-event-details-worklist-add-button';
const DIVIDER = '.ext-campaignevents-event-details-worklist-add-dialog-divider';

const DEFAULT_CONFIG = {
	wgCampaignEventsWorklistPagePrefixedText: 'Event:My Event/Worklist',
	wgWikiID: 'awiki',
	wgSiteName: 'A Wiki',
	wgCampaignEventsWorklistWikiRestUrl: null
};

// The component reads its config in setup(), so it must be in place before mounting.
const mountDialog = ( config = {} ) => {
	const fullConfig = Object.assign( {}, DEFAULT_CONFIG, config );
	mw.config.get = ( key ) => fullConfig[ key ];
	// For the article search, which has its own tests.
	mw.Api = jest.fn( () => ( { get: jest.fn() } ) );
	mw.ForeignApi = jest.fn( () => ( { get: jest.fn() } ) );
	return mount( AddWorklistArticleDialog );
};

const wikiOption = ( wiki ) => ( { value: wiki, label: wiki + ' name' } );

const mockRestAjax = () => {
	jest.spyOn( mw.user.tokens, 'get' ).mockReturnValue( 'csrf-token' );
	const restAjax = jest.fn().mockResolvedValue( {} );
	mw.Rest.mockImplementation( () => ( { ajax: restAjax } ) );
	return restAjax;
};

const sentAdditions = ( restAjax ) => JSON.parse( restAjax.mock.calls[ 0 ][ 1 ].data ).add;

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
		const wrapper = mountDialog( { wgContentLanguage: 'sr-ec' } );
		await openDialog( wrapper );

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

	it( 'hides the wiki selector when the current wiki is the only choice', async () => {
		const wrapper = mountDialog( { wgCampaignEventsWorklistWikiOptions: [ wikiOption( 'awiki' ) ] } );
		await openDialog( wrapper );
		// The dialog is rendered: only the selector is missing.
		expect( wrapper.find( '.ext-campaignevents-event-details-worklist-add-dialog-textarea' ).exists() ).toBe( true );
		expect( wrapper.findComponent( { name: 'WorklistWikiSelector' } ).exists() ).toBe( false );
	} );

	it( 'shows the wiki selector with the event wikis, defaulting to the current wiki', async () => {
		const options = [ wikiOption( 'awiki' ), wikiOption( 'bwiki' ) ];
		const wrapper = mountDialog( { wgCampaignEventsWorklistWikiOptions: options } );
		await openDialog( wrapper );
		const selector = wrapper.getComponent( { name: 'WorklistWikiSelector' } );
		expect( selector.props( 'options' ) ).toEqual( options );
		expect( selector.props( 'selected' ) ).toBe( 'awiki' );
	} );

	it( 'adds the titles to the selected wiki', async () => {
		const wrapper = mountDialog( {
			wgCampaignEventsWorklistWikiOptions: [ wikiOption( 'awiki' ), wikiOption( 'bwiki' ) ]
		} );
		await openDialog( wrapper );
		const restAjax = mockRestAjax();

		wrapper.vm.selectedWiki = 'bwiki';
		wrapper.vm.articlesText = 'Moon';
		wrapper.vm.onSubmit();

		expect( sentAdditions( restAjax ) ).toEqual( { bwiki: [ 'Moon' ] } );
	} );

	it( 'replaces underscores with spaces in the titles sent', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );
		const restAjax = mockRestAjax();

		wrapper.vm.articlesText = 'Solar_System';
		wrapper.vm.onSubmit();

		expect( sentAdditions( restAjax ) ).toEqual( { awiki: [ 'Solar System' ] } );
	} );

	describe( 'article search', () => {
		const options = [
			wikiOption( 'awiki' ),
			Object.assign( wikiOption( 'bwiki' ), { apiUrl: 'https://b.example/w/api.php' } ),
			Object.assign( wikiOption( 'cwiki' ), { apiUrl: null } )
		];
		const search = ( wrapper ) => wrapper.findComponent( { name: 'WorklistArticleSearch' } );

		it( 'searches the selected wiki', async () => {
			const wrapper = mountDialog( { wgCampaignEventsWorklistWikiOptions: options } );
			await openDialog( wrapper );
			const localSearch = search( wrapper ).vm;

			wrapper.vm.selectedWiki = 'bwiki';
			await nextTick();

			expect( mw.ForeignApi ).toHaveBeenCalledWith( 'https://b.example/w/api.php', { anonymous: true } );
			expect( search( wrapper ).props( 'api' ) ).toBe( mw.ForeignApi.mock.results[ 0 ].value );
			// A new search, so nothing typed for the previous wiki carries over.
			expect( search( wrapper ).vm ).not.toBe( localSearch );
		} );

		it( 'is not offered for a wiki that cannot be searched', async () => {
			const wrapper = mountDialog( { wgCampaignEventsWorklistWikiOptions: options } );
			await openDialog( wrapper );

			wrapper.vm.selectedWiki = 'cwiki';
			await nextTick();

			expect( search( wrapper ).exists() ).toBe( false );
			// Nor is its label, or the divider that sets it apart from the list.
			expect( wrapper.text() ).not.toContain( 'campaignevents-event-details-worklist-add-dialog-search-label' );
			expect( wrapper.find( DIVIDER ).exists() ).toBe( false );
			// The textarea is still there for typing titles in.
			expect( wrapper.find( '.ext-campaignevents-event-details-worklist-add-dialog-textarea' ).exists() ).toBe( true );
		} );
	} );

	it( 'names the current wiki in the search label and placeholder', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );

		expect( wrapper.text() ).toContain( 'campaignevents-event-details-worklist-add-dialog-search-label (A Wiki)' );
		expect( wrapper.getComponent( { name: 'WorklistArticleSearch' } ).props( 'placeholder' ) )
			.toBe( '(campaignevents-event-details-worklist-add-dialog-search-input-placeholder, A Wiki)' );
	} );

	it( 'names the selected wiki in the search label and placeholder when there is a choice', async () => {
		const wrapper = mountDialog( {
			wgCampaignEventsWorklistWikiOptions: [
				wikiOption( 'awiki' ),
				// Searchable, or there is no search to label.
				Object.assign( wikiOption( 'bwiki' ), { apiUrl: 'https://b.example/w/api.php' } )
			]
		} );
		await openDialog( wrapper );

		wrapper.vm.selectedWiki = 'bwiki';
		await nextTick();

		expect( wrapper.text() ).toContain( 'campaignevents-event-details-worklist-add-dialog-search-label (bwiki name)' );
		expect( wrapper.getComponent( { name: 'WorklistArticleSearch' } ).props( 'placeholder' ) )
			.toBe( '(campaignevents-event-details-worklist-add-dialog-search-input-placeholder, bwiki name)' );
	} );

	it( 'separates the search from the list of titles with a divider', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );

		const html = wrapper.html();
		const divider = wrapper.find( DIVIDER );
		expect( divider.text() ).toBe( 'campaignevents-event-details-worklist-add-dialog-or' );
		expect( html.indexOf( 'ext-campaignevents-event-details-worklist-add-dialog-search' ) )
			.toBeLessThan( html.indexOf( 'ext-campaignevents-event-details-worklist-add-dialog-divider' ) );
		expect( html.indexOf( 'ext-campaignevents-event-details-worklist-add-dialog-divider' ) )
			.toBeLessThan( html.indexOf( 'ext-campaignevents-event-details-worklist-add-dialog-textarea' ) );
	} );

	it( 'labels the list of titles and explains its format', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );

		expect( wrapper.text() ).toContain( 'campaignevents-event-details-worklist-add-dialog-list-label' );
		expect( wrapper.text() ).toContain( 'campaignevents-event-details-worklist-add-dialog-list-description' );
	} );

	it( 'leaves room for several titles in the list', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );

		expect( wrapper.find( '.ext-campaignevents-event-details-worklist-add-dialog-textarea textarea' )
			.attributes( 'rows' ) ).toBe( '5' );
	} );

	it( 'stacks the dialog actions, so the add button spans the dialog', () => {
		const wrapper = mountDialog();
		expect( wrapper.getComponent( { name: 'CdxDialog' } ).props( 'stackedActions' ) ).toBe( true );
	} );

	it( 'disables submitting once the user types over the selected wiki', async () => {
		const manyWikis = Array.from( { length: 30 }, ( _, i ) => wikiOption( 'wiki' + i ) );
		const wrapper = mountDialog( { wgCampaignEventsWorklistWikiOptions: manyWikis } );
		await openDialog( wrapper );
		const restAjax = mockRestAjax();
		expect( wrapper.vm.primaryAction.disabled ).toBe( false );

		// Typing without picking a suggestion must not leave the previous wiki selected.
		await wrapper.find( '.ext-campaignevents-event-details-worklist-add-dialog-wiki input' )
			.setValue( 'wiki1' );
		wrapper.vm.articlesText = 'Moon';
		wrapper.vm.onSubmit();

		expect( wrapper.vm.selectedWiki ).toBe( null );
		expect( wrapper.vm.primaryAction.disabled ).toBe( true );
		expect( restAjax ).not.toHaveBeenCalled();
	} );

	it( 'does not warn a participant who is not private', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );

		expect( wrapper.vm.isPrivateParticipant ).toBe( false );
		expect( wrapper.html() ).not.toContain( 'campaignevents-worklist-private-participant-warning' );
	} );

	it( 'warns a private participant that their username will be public', async () => {
		const wrapper = mountDialog( { wgCampaignEventsWorklistIsPrivateParticipant: true } );
		await openDialog( wrapper );

		const warning = wrapper.findComponent( { name: 'CdxMessage' } );
		expect( warning.exists() ).toBe( true );
		expect( warning.props( 'type' ) ).toBe( 'warning' );
		expect( warning.text() ).toContain( 'campaignevents-worklist-private-participant-warning' );
	} );

	it( 'shows the private participant warning before the article fields', async () => {
		const wrapper = mountDialog( { wgCampaignEventsWorklistIsPrivateParticipant: true } );
		await openDialog( wrapper );

		const html = wrapper.html();
		expect( html.indexOf( 'ext-campaignevents-event-details-worklist-private-warning' ) )
			.toBeGreaterThan( -1 );
		expect( html.indexOf( 'ext-campaignevents-event-details-worklist-private-warning' ) )
			.toBeLessThan( html.indexOf( 'cdx-field' ) );
	} );
} );
