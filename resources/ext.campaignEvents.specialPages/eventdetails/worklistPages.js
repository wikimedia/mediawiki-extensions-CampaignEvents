( function () {
	'use strict';

	/**
	 * Requests against an event's worklist, shared by the card view and the table view.
	 */

	/**
	 * The REST client for editing the worklist. The worklist page may live on another wiki, in
	 * which case the server hands us that wiki's rest.php and we target it directly, because an
	 * edit has to land on that page. Reads do not need it: they answer from the shared
	 * CampaignEvents tables, so any wiki can serve them.
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
	 * The whole list comes back in one response, for the caller to search and paginate.
	 *
	 * Read from this wiki rather than the one hosting the worklist page. The articles live in the
	 * shared CampaignEvents tables, so the answer is the same either way, but which pages count as
	 * local is decided by the wiki answering: asking here makes that the reader's own wiki, so a
	 * page on it is named as theirs and links to it carry its red-link status.
	 *
	 * @return {jQuery.Promise} Resolves with { pages }
	 */
	function fetchPages() {
		const eventId = mw.config.get( 'wgCampaignEventsWorklistEventId' );
		return new mw.Rest().get(
			'/campaignevents/v0/event_registration/' + encodeURIComponent( eventId ) +
				'/worklist_pages'
		).then( ( response ) => ( {
			// The endpoint speaks snake_case, like the extension's other endpoints; the rest of the
			// frontend does not, so the shape is normalised here rather than in every component.
			pages: response.pages.map( ( page ) => ( {
				wiki: page.wiki,
				// The name is sent once per wiki rather than once per page; flatten it back onto
				// each page so components do not have to carry the lookup around.
				wikiName: ( response.wikis[ page.wiki ] || {} ).name,
				// The wiki's own api.php, so data about an article can be read from the wiki
				// holding it rather than only from this one. Null where the server could not
				// resolve that wiki.
				apiUrl: ( response.wikis[ page.wiki ] || {} ).api_url || null,
				isLocal: page.is_local,
				title: page.title,
				url: page.url,
				classes: page.classes
			} ) )
		} ) );
	}

	/**
	 * Read when the articles in the worklist were added.
	 *
	 * A second request, made once the articles are on screen: the dates live only in the shared
	 * CampaignEvents tables, and the list is what the reader is waiting for.
	 *
	 * @return {jQuery.Promise} Resolves with the articles, each with `wiki`, `title`, `added` (a
	 *   date formatted for the reader) and `addedAt` (an ISO 8601 timestamp)
	 */
	function fetchMetadata() {
		const eventId = mw.config.get( 'wgCampaignEventsWorklistEventId' );
		return new mw.Rest().get(
			'/campaignevents/v0/event_registration/' + encodeURIComponent( eventId ) +
				'/worklist_pages/metadata'
		).then( ( response ) => response.pages.map( ( page ) => ( {
			wiki: page.wiki,
			title: page.title,
			added: page.added,
			addedAt: page.added_at
		} ) ) );
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
		fetchMetadata,
		removeArticle,
		errorText
	};
}() );
