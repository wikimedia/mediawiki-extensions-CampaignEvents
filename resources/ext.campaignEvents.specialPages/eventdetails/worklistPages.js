( function () {
	'use strict';

	/**
	 * Requests against an event's worklist, shared by the card view and the table view.
	 */

	/**
	 * The REST client for the worklist. The worklist page may live on another wiki, in which case
	 * the server hands us that wiki's rest.php and we target it directly. Both reading and editing
	 * go through it, because both act on that page.
	 *
	 * @return {mw.Rest|mw.ForeignRest}
	 */
	function worklistApi() {
		const foreignRestUrl = mw.config.get( 'wgCampaignEventsWorklistWikiRestUrl' );
		return foreignRestUrl ? new mw.ForeignRest( foreignRestUrl ) : new mw.Rest();
	}

	/**
	 * Read the articles in the worklist.
	 *
	 * The whole list comes back in one response, for the caller to search and paginate. The
	 * request goes to the wiki hosting the worklist page, because that is where the articles are.
	 *
	 * @return {jQuery.Promise} Resolves with { pages }
	 */
	function fetchPages() {
		const eventId = mw.config.get( 'wgCampaignEventsWorklistEventId' );
		return worklistApi().get(
			'/campaignevents/v0/event_registration/' + encodeURIComponent( eventId ) +
				'/worklist_pages'
		).then( ( response ) => ( {
			// The endpoint speaks snake_case, like the extension's other endpoints; the rest of the
			// frontend does not, so the shape is normalised here rather than in every component.
			pages: response.pages.map( ( page ) => ( {
				wiki: page.wiki,
				title: page.title,
				url: page.url,
				classes: page.classes
			} ) )
		} ) );
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
		return worklistApi().ajax(
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
		fetchPages,
		removeArticle,
		errorText
	};
}() );
