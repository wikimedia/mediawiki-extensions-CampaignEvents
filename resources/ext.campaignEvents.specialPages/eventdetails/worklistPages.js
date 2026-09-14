( function () {
	'use strict';

	/**
	 * Requests against an event's worklist, and the error handling they share.
	 */

	/**
	 * The REST client for editing the worklist. The worklist page may live on another wiki, in
	 * which case the server hands us that wiki's rest.php and we target it directly.
	 *
	 * @return {mw.Rest|mw.ForeignRest}
	 */
	function editApi() {
		const foreignRestUrl = mw.config.get( 'wgCampaignEventsWorklistWikiRestUrl' );
		return foreignRestUrl ? new mw.ForeignRest( foreignRestUrl ) : new mw.Rest();
	}

	/**
	 * Remove one article from the worklist.
	 *
	 * The endpoint takes a delta, so this is a PATCH. mw.Rest has no patch() helper, so ajax() is
	 * called with the verb directly.
	 *
	 * @param {string} wiki Wiki ID the article belongs to
	 * @param {string} title Prefixed title
	 * @return {jQuery.Promise}
	 */
	function removeArticle( wiki, title ) {
		const worklistPage = mw.config.get( 'wgCampaignEventsWorklistPagePrefixedText' );
		const remove = {};
		remove[ wiki ] = [ title ];
		return editApi().ajax(
			'/campaignevents/v0/worklist/' + encodeURIComponent( worklistPage ) + '/pages',
			{
				type: 'PATCH',
				headers: { 'content-type': 'application/json' },
				data: JSON.stringify( {
					remove: remove,
					token: mw.user.tokens.get( 'csrfToken' )
				} )
			}
		);
	}

	/**
	 * Pull a human-readable message out of a failed mw.Rest request, preferring the API's own
	 * message in the wiki's content language (T269492) over the raw response body.
	 *
	 * @param {Object} errObj The second argument mw.Rest rejects with
	 * @return {string}
	 */
	function errorText( errObj ) {
		const xhr = errObj && errObj.xhr;
		const json = xhr && xhr.responseJSON;
		if ( json && json.messageTranslations ) {
			// Core keys these by BCP-47 tag, which is not the wiki's internal code on every wiki
			// (zh-hans is zh-Hans, sr-ec is sr-Cyrl). A miss falls through to the untranslated
			// message rather than leaving the caller with nothing to substitute.
			const translated = json.messageTranslations[
				mw.language.bcp47( mw.config.get( 'wgContentLanguage' ) )
			];
			if ( translated ) {
				return translated;
			}
		}
		if ( json && json.message ) {
			return json.message;
		}
		return xhr ? xhr.responseText : '';
	}

	module.exports = {
		removeArticle,
		errorText
	};
}() );
