'use strict';

const { ref } = require( 'vue' );

// Most matching titles requested per query, as many as a lookup menu comfortably shows.
const MAX_SUGGESTIONS = 10;

// How long typing has to pause before a search is sent, so that a word typed quickly costs one
// request rather than one per keystroke.
const SEARCH_DELAY = 500;

/**
 * @typedef {Object} TitleSearch
 * @property {Object} suggestions Ref to the menu items for a Codex lookup, as
 *   { value: title, label: title }
 * @property {Object} newTitle Ref to the typed text when it is among the suggestions as an
 *   article that does not exist yet, or null; not set when the search failed, as it is then not
 *   known whether the article exists
 * @property {function(string): void} search Look up titles starting with the given text, once
 *   typing has paused; the suggestions are updated when the results arrive
 * @property {function(): void} clear Drop the suggestions and any search waiting or in flight
 */

/**
 * Whether two titles name the same article, as far as can be told without asking the wiki: the
 * API reads underscores as spaces and capitalises the first letter.
 *
 * @param {string} a
 * @param {string} b
 * @return {boolean}
 */
function isSameTitle( a, b ) {
	const normalize = ( title ) => {
		const spaced = title.replace( /_/g, ' ' );
		return spaced.charAt( 0 ).toUpperCase() + spaced.slice( 1 );
	};
	return normalize( a ) === normalize( b );
}

/**
 * Suggestions of existing articles whose titles start with what the user typed, after the typed
 * text itself. A matching redirect is suggested as the article it leads to, which is what belongs
 * in the worklist. The typed text is offered unless an existing article or redirect already has
 * that title, so that articles which do not exist yet can be queued for creation during the
 * event.
 *
 * @param {mw.Api} api API of the wiki to search
 * @return {TitleSearch}
 */
function useTitleSearch( api ) {
	const suggestions = ref( [] );
	const newTitle = ref( null );
	let pendingRequest = null;
	// What the latest search was for, or null once cleared. A debounced search is only sent if
	// it is still for this: mw.util.debounce offers no way to cancel one that is waiting.
	let latestQuery = null;

	function abortPendingRequest() {
		if ( pendingRequest ) {
			pendingRequest.abort();
			pendingRequest = null;
		}
	}

	function clear() {
		latestQuery = null;
		abortPendingRequest();
		suggestions.value = [];
		newTitle.value = null;
	}

	/**
	 * @param {Object} response Response to a prefixsearch generator query with redirects resolved
	 * @return {string[]} Titles of the matching articles, best match first
	 */
	function getMatchingTitles( response ) {
		const pages = ( response.query && response.query.pages ) || [];
		return pages
			// A redirect may lead out of the main namespace.
			.filter( ( page ) => page.ns === 0 )
			// Pages come unordered; their index is their rank in the search, and a redirect
			// target takes the rank of the redirect that matched.
			.sort( ( a, b ) => a.index - b.index )
			.map( ( page ) => page.title );
	}

	/**
	 * @param {string} query What was typed, trimmed
	 * @param {string[]} titles Titles of the matching articles
	 * @param {boolean} offerQuery Whether to offer the typed text as well
	 * @return {Array<{value: string, label: string}>}
	 */
	function toMenuItems( query, titles, offerQuery ) {
		const items = titles.map( ( title ) => ( { value: title, label: title } ) );
		// First, as the title widget this replaces did, so it is the same row however many
		// matches follow.
		return offerQuery ? [ { value: query, label: query } ].concat( items ) : items;
	}

	/**
	 * @param {string} query Trimmed, and not empty
	 */
	function sendSearch( query ) {
		// Cleared, or replaced by a newer search, while waiting for typing to pause.
		if ( query !== latestQuery ) {
			return;
		}
		const request = api.get( {
			action: 'query',
			generator: 'prefixsearch',
			gpssearch: query,
			gpsnamespace: 0,
			gpslimit: MAX_SUGGESTIONS,
			redirects: true,
			formatversion: 2
		} );
		pendingRequest = request;
		request.then( ( response ) => {
			// A newer search has replaced this one.
			if ( pendingRequest !== request ) {
				return;
			}
			pendingRequest = null;
			const redirectSources = ( ( response.query && response.query.redirects ) || [] )
				.map( ( redirect ) => redirect.from );
			const titles = getMatchingTitles( response );
			// The search ranks an article with exactly this title first, so it would be among
			// the results if it existed. A matching redirect exists too, though it is suggested
			// as its target rather than by name.
			const exists = titles.concat( redirectSources )
				.some( ( title ) => isSameTitle( title, query ) );
			suggestions.value = toMenuItems( query, titles, !exists );
			newTitle.value = exists ? null : query;
		}, () => {
			if ( pendingRequest !== request ) {
				return;
			}
			pendingRequest = null;
			// Searching is only a convenience: on failure the typed text can still be picked.
			suggestions.value = toMenuItems( query, [], true );
			// Offered, but not marked as new: without an answer it is not known to be.
			newTitle.value = null;
		} );
	}

	// Made once for this search, so every call shares one timer.
	const debouncedSendSearch = mw.util.debounce( sendSearch, SEARCH_DELAY );

	/**
	 * @param {string} text
	 */
	function search( text ) {
		// The current suggestions stay until the new ones arrive: emptying them would end the
		// lookup's pending state, and it would then not open its menu for the results.
		abortPendingRequest();
		const query = text.trim();
		if ( !query ) {
			clear();
			return;
		}
		latestQuery = query;
		debouncedSendSearch( query );
	}

	return {
		suggestions,
		newTitle,
		search,
		clear
	};
}

useTitleSearch.SEARCH_DELAY = SEARCH_DELAY;

module.exports = useTitleSearch;
