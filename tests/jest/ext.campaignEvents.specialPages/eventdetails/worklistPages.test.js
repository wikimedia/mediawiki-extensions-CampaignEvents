'use strict';

const worklistPages = require( '../../../../resources/ext.campaignEvents.specialPages/eventdetails/worklistPages.js' );

const EVENT_ID = 71;
const EXPECTED_PATH = '/campaignevents/v0/event_registration/71/worklist_pages';

// Responses as the endpoint sends them.
const EMPTY_RESPONSE = { pages: [] };
const ONE_ARTICLE_RESPONSE = {
	pages: [ {
		wiki: 'awiki',
		title: 'Beavers',
		url: 'https://a.example.org/wiki/Beavers',
		classes: 'external'
	} ]
};

/**
 * @param {Object} [config] Config vars beyond the event ID
 * @return {jest.Mock} The mocked mw.Rest get()
 */
const mockRest = ( config ) => {
	const vars = Object.assign(
		{ wgCampaignEventsWorklistEventId: EVENT_ID },
		config || {}
	);
	mw.config.get.mockImplementation( ( name ) => vars[ name ] );

	const get = jest.fn().mockResolvedValue( EMPTY_RESPONSE );
	mw.Rest.mockImplementation( () => ( { get } ) );
	return get;
};

/**
 * @param {Object} responseJSON Body of the failed response
 * @return {Object} An error object shaped like the one mw.Rest rejects with
 */
const restError = ( responseJSON ) => ( { xhr: { responseJSON, responseText: 'raw body' } } );

describe( 'worklistPages.errorText', () => {
	beforeEach( () => {
		mw.config.get.mockImplementation(
			( name ) => ( name === 'wgContentLanguage' ? 'zh-hans' : null )
		);
		// Core keys the translations by BCP-47 tag, so the wiki's internal code has to be mapped.
		mw.language.bcp47.mockImplementation( ( code ) => ( code === 'zh-hans' ? 'zh-Hans' : code ) );
	} );

	afterEach( () => {
		jest.resetAllMocks();
	} );

	it( 'uses the translation for the wiki content language', () => {
		const text = worklistPages.errorText( restError( {
			messageTranslations: { 'zh-Hans': '翻译过的消息' },
			message: 'Untranslated message'
		} ) );

		expect( text ).toBe( '翻译过的消息' );
	} );

	it( 'falls back to the untranslated message when no translation matches', () => {
		// A wiki whose internal code and BCP-47 tag differ used to end up with nothing here,
		// leaving the caller's message with an unsubstituted parameter.
		const text = worklistPages.errorText( restError( {
			messageTranslations: { en: 'English only' },
			message: 'Untranslated message'
		} ) );

		expect( text ).toBe( 'Untranslated message' );
	} );

	it( 'falls back to the response body when there is no JSON at all', () => {
		expect( worklistPages.errorText( { xhr: { responseText: 'raw body' } } ) ).toBe( 'raw body' );
	} );

	it( 'returns an empty string when there is no response', () => {
		expect( worklistPages.errorText( {} ) ).toBe( '' );
	} );
} );

describe( 'worklistPages.fetchPages', () => {
	afterEach( () => {
		jest.resetAllMocks();
	} );

	it( 'reads the articles in the event\'s worklist', async () => {
		const get = mockRest();

		await worklistPages.fetchPages();

		expect( get ).toHaveBeenCalledWith( EXPECTED_PATH );
	} );

	it( 'normalises the response for the components', async () => {
		const get = mockRest();
		get.mockResolvedValue( ONE_ARTICLE_RESPONSE );

		await expect( worklistPages.fetchPages() ).resolves.toStrictEqual( {
			pages: [ {
				wiki: 'awiki',
				title: 'Beavers',
				url: 'https://a.example.org/wiki/Beavers',
				classes: 'external'
			} ]
		} );
	} );

	it( 'reads from the hosting wiki when the worklist page is on another wiki', async () => {
		const foreignRestUrl = 'https://foreign.example.org/w/rest.php';
		const restGet = mockRest( { wgCampaignEventsWorklistWikiRestUrl: foreignRestUrl } );
		const foreignGet = jest.fn().mockResolvedValue( EMPTY_RESPONSE );
		mw.ForeignRest = jest.fn().mockImplementation( () => ( { get: foreignGet } ) );

		await worklistPages.fetchPages();

		// The articles come from the worklist page, so the read goes to that page's wiki. A local
		// read could not name that page: its title is formatted by the wiki holding it.
		expect( mw.ForeignRest ).toHaveBeenCalledWith( foreignRestUrl );
		expect( foreignGet ).toHaveBeenCalledWith( EXPECTED_PATH );
		expect( restGet ).not.toHaveBeenCalled();
	} );
} );
