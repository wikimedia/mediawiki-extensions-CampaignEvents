'use strict';

const worklistPages = require( '../../../../resources/ext.campaignEvents.specialPages/eventdetails/worklistPages.js' );

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
