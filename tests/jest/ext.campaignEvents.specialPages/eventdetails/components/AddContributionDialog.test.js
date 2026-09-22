'use strict';

/* global setTimeout */

const { mount } = require( '@vue/test-utils' );
const AddContributionDialog = require( '../../../../../resources/ext.campaignEvents.specialPages/eventdetails/components/AddContributionDialog.vue' );
const { nextTick } = require( 'vue' );
const wgDbName = 123;
const wgDbCampaignEventsEventID = 456;
const wgDbCampaignEventsEventName = 'Test Event';
const defaultConfig = {
	wgCampaignEventsEventName: wgDbCampaignEventsEventName,
	wgCampaignEventsEventID: wgDbCampaignEventsEventID,
	wgDBname: wgDbName
};

const mountDialog = ( configOverrides = {} ) => {
	const config = Object.assign( {}, defaultConfig, configOverrides );
	mw.config = {
		get: ( key ) => config[ key ]
	};
	return mount( AddContributionDialog, { } );
};

describe( 'AddContributionDialog', () => {
	const wrapper = mountDialog(),
		cdxDialog = wrapper.getComponent( { name: 'CdxDialog' } ),
		csrfToken = 'csrf-token',
		userTokensSpy = jest.spyOn( mw.user.tokens, 'get' ).mockImplementation( ( tokenType ) => {
			switch ( tokenType ) {
				case 'csrfToken':
					return csrfToken;
				default:
					throw new Error( 'Unknown token type: ' + tokenType );
			}
		} );
	beforeEach( async () => {
		wrapper.vm.openAddDialog();
		await nextTick();
	} );
	it( 'has correct title with event name', async () => {

		// Test that the title prop receives the message key with the event name parameter
		expect( cdxDialog.props( 'title' ) ).toBe( 'campaignevents-event-details-contributions-add-dialog-title (Test Event)' );

		// Test HTML output to ensure the argument is rendered
		expect( wrapper.html() ).toContain( 'Test Event' );
	} );

	it( 'has correct primary action button', () => {

		const primaryAction = cdxDialog.props( 'primaryAction' );

		expect( primaryAction.label ).toBe( '(campaignevents-event-details-contributions-add-dialog-submit)' );
		expect( primaryAction.actionType ).toBe( 'progressive' );
	} );

	it( 'has correct default action button', () => {

		const defaultAction = cdxDialog.props( 'defaultAction' );

		expect( defaultAction.label ).toBe( '(campaignevents-event-details-contributions-add-dialog-cancel)' );
		expect( defaultAction.actionType ).toBeUndefined();
	} );

	it( 'has use-close-button enabled', () => {

		expect( cdxDialog.props( 'useCloseButton' ) ).toBe( true );
	} );

	it( 'has correct primary action configuration', () => {
		const primaryAction = wrapper.vm.primaryAction;
		expect( primaryAction.label ).toBe( '(campaignevents-event-details-contributions-add-dialog-submit)' );
		expect( primaryAction.actionType ).toBe( 'progressive' );
	} );

	it( 'has correct default action configuration', () => {
		const defaultAction = wrapper.vm.defaultAction;

		expect( defaultAction.label ).toBe( '(campaignevents-event-details-contributions-add-dialog-cancel)' );
		expect( defaultAction.actionType ).toBeUndefined();
	} );
	it( 'opens dialog when button is clicked', async () => {
		expect( wrapper.vm.open ).toBe( true );
	} );

	it( 'shows success message when adding the contribution succeeds', async () => {
		const restAdd = jest.fn().mockResolvedValue( { modified: true } );
		mw.Rest.mockImplementation( () => ( {
			put: restAdd
		} ) );
		wrapper.vm.inputValue = 789;
		await wrapper.vm.onSubmit();

		expect( userTokensSpy ).toHaveBeenCalledWith( 'csrfToken' );
		expect( restAdd ).toHaveBeenCalledWith(
			`/campaignevents/v0/event_registration/${ wgDbCampaignEventsEventID }/edits/${ wgDbName }/789`,
			{ token: csrfToken }
		);
		const hasMessage = wrapper.vm.hasMessage,
			messageType = wrapper.vm.messageType,
			message = wrapper.vm.message;
		expect( hasMessage ).toBe( true );
		expect( messageType ).toBe( 'success' );
		expect( message ).toBe( '(campaignevents-event-details-contributions-add-dialog-success)' );
	} );
	it( 'shows notice message when the contribution is already associated', async () => {
		const restAdd = jest.fn().mockResolvedValue( { modified: false } );
		mw.Rest.mockImplementation( () => ( {
			put: restAdd
		} ) );
		wrapper.vm.inputValue = 789;
		await wrapper.vm.onSubmit();

		expect( userTokensSpy ).toHaveBeenCalledWith( 'csrfToken' );
		expect( restAdd ).toHaveBeenCalledWith(
			`/campaignevents/v0/event_registration/${ wgDbCampaignEventsEventID }/edits/${ wgDbName }/789`,
			{ token: csrfToken }
		);
		const hasMessage = wrapper.vm.hasMessage,
			messageType = wrapper.vm.messageType,
			message = wrapper.vm.message;
		expect( hasMessage ).toBe( true );
		expect( messageType ).toBe( 'notice' );
		expect( message ).toBe( '(campaignevents-event-details-contributions-add-dialog-nochange)' );
	} );
	it( 'displays an error on API failure', async () => {
		const restAdd = jest.fn().mockImplementation( () => ( {
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
			put: restAdd
		} ) );
		wrapper.vm.inputValue = 789;
		await wrapper.vm.onSubmit();

		expect( userTokensSpy ).toHaveBeenCalledWith( 'csrfToken' );
		expect( restAdd ).toHaveBeenCalledWith(
			`/campaignevents/v0/event_registration/${ wgDbCampaignEventsEventID }/edits/${ wgDbName }/789`,
			{ token: csrfToken }
		);
		// Allow promise rejection handlers to run
		await new Promise( ( resolve ) => {
			setTimeout( resolve, 0 );
		} );
		const hasMessage = wrapper.vm.hasMessage,
			messageType = wrapper.vm.messageType,
			message = wrapper.vm.message;
		expect( hasMessage ).toBe( true );
		expect( messageType ).toBe( 'error' );
		expect( message ).toBe( 'API Error' );
	} );

	it( 'displays translated error message using BCP-47 language tag', async () => {
		mw.config.get = jest.fn( ( key ) => ( {
			...defaultConfig,
			wgContentLanguage: 'zh-hans'
		} )[ key ] );
		mw.language.bcp47 = jest.fn( ( lang ) => ( lang === 'zh-hans' ? 'zh-Hans' : lang ) );

		const restAdd = jest.fn().mockImplementation( () => ( {
			then: ( success, failure ) => {
				failure(
					null,
					{
						xhr: {
							responseText: 'API Error',
							responseJSON: {
								messageTranslations: {
									'zh-Hans': '翻译过的错误'
								}
							}
						}
					}
				);
			}
		} ) );
		mw.Rest.mockImplementation( () => ( {
			put: restAdd
		} ) );
		wrapper.vm.inputValue = 789;
		await wrapper.vm.onSubmit();

		await new Promise( ( resolve ) => {
			setTimeout( resolve, 0 );
		} );
		expect( mw.language.bcp47 ).toHaveBeenCalledWith( 'zh-hans' );
		expect( wrapper.vm.message ).toBe( '翻译过的错误' );
	} );

	it( 'falls back to json.message if BCP-47 language key is missing in messageTranslations', async () => {
		mw.config.get = jest.fn( ( key ) => ( {
			...defaultConfig,
			wgContentLanguage: 'sr-ec'
		} )[ key ] );
		mw.language.bcp47 = jest.fn( ( lang ) => ( lang === 'sr-ec' ? 'sr-Cyrl' : lang ) );

		const restAdd = jest.fn().mockImplementation( () => ( {
			then: ( success, failure ) => {
				failure(
					null,
					{
						xhr: {
							responseText: 'Fallback raw response',
							responseJSON: {
								messageTranslations: {
									en: 'English message'
								},
								message: 'Fallback message'
							}
						}
					}
				);
			}
		} ) );
		mw.Rest.mockImplementation( () => ( {
			put: restAdd
		} ) );
		wrapper.vm.inputValue = 789;
		await wrapper.vm.onSubmit();

		await new Promise( ( resolve ) => {
			setTimeout( resolve, 0 );
		} );
		expect( wrapper.vm.message ).toBe( 'Fallback message' );
	} );
} );
