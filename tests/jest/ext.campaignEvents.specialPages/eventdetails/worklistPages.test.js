'use strict';

const worklistPages = require( '../../../../resources/ext.campaignEvents.specialPages/eventdetails/worklistPages.js' );

const EVENT_ID = 71;
const EXPECTED_PATH = '/campaignevents/v0/event_registration/71/worklist_pages';

/* eslint-disable camelcase */
// Responses as the endpoint sends them, in snake_case.
const EMPTY_RESPONSE = { wikis: {}, pages: [] };
const ONE_ARTICLE_RESPONSE = {
	// The wiki's name is sent once for the whole response, not on every page.
	wikis: {
		awiki: { name: 'A Wiki', api_url: 'https://a.example.org/w/api.php' }
	},
	pages: [ {
		wiki: 'awiki',
		is_local: false,
		title: 'Beavers',
		url: 'https://a.example.org/wiki/Beavers',
		classes: 'external'
	} ]
};
/* eslint-enable camelcase */

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
				wikiName: 'A Wiki',
				apiUrl: 'https://a.example.org/w/api.php',
				isLocal: false,
				title: 'Beavers',
				url: 'https://a.example.org/wiki/Beavers',
				classes: 'external'
			} ]
		} );
	} );

	it( 'normalises the metadata response for the components', async () => {
		const get = mockRest();
		/* eslint-disable camelcase */
		get.mockResolvedValue( {
			pages: [ {
				wiki: 'awiki',
				title: 'Beavers',
				added: '14:32, 3 September 2026',
				added_at: '2026-09-03T14:32:00Z'
			} ]
		} );
		/* eslint-enable camelcase */

		expect( await worklistPages.fetchMetadata() ).toEqual( [ {
			wiki: 'awiki',
			title: 'Beavers',
			added: '14:32, 3 September 2026',
			addedAt: '2026-09-03T14:32:00Z'
		} ] );
	} );

	it( 'reads from this wiki even when the worklist page is on another', async () => {
		const foreignRestUrl = 'https://foreign.example.org/w/rest.php';
		const restGet = mockRest( { wgCampaignEventsWorklistWikiRestUrl: foreignRestUrl } );
		mw.ForeignRest = jest.fn();

		await worklistPages.fetchPages();

		// The articles are in the shared tables, so any wiki answers the same. Asking here makes
		// the response's idea of a local page the reader's own, rather than the worklist page's.
		expect( restGet ).toHaveBeenCalledWith( EXPECTED_PATH );
		expect( mw.ForeignRest ).not.toHaveBeenCalled();
	} );

	it( 'still edits on the wiki hosting the worklist page', async () => {
		// The read moved to this wiki, but an edit has to land on the page itself, so the write
		// keeps targeting the wiki holding it.
		const foreignRestUrl = 'https://foreign.example.org/w/rest.php';
		mockRest( {
			wgCampaignEventsWorklistWikiRestUrl: foreignRestUrl,
			wgCampaignEventsWorklistPagePrefixedText: 'Event:Edrinks/Worklist'
		} );
		const ajax = jest.fn().mockResolvedValue( {} );
		mw.ForeignRest = jest.fn().mockImplementation( () => ( { ajax } ) );
		mw.user = { tokens: { get: jest.fn().mockReturnValue( 'token' ) } };

		await worklistPages.removeArticle( 'awiki', 'Beavers' );

		expect( mw.ForeignRest ).toHaveBeenCalledWith( foreignRestUrl );
		expect( ajax ).toHaveBeenCalledWith(
			'/campaignevents/v0/worklist/Event%3AEdrinks%2FWorklist/pages',
			expect.objectContaining( { type: 'PATCH' } )
		);
	} );
} );
