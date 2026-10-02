'use strict';

const { ref, computed } = require( 'vue' );

/**
 * @typedef {Object} WorklistWikiOptions
 * @property {string} currentWiki ID of the wiki the page is viewed on
 * @property {Array<{value: string, label: string, apiUrl: string|null}>} wikiOptions The
 *   event's wikis, or all wikis for an event covering every wiki; apiUrl is null for the current
 *   wiki and for wikis that can't be searched
 * @property {boolean} showWikiSelector False when the current wiki is the only choice, which
 *   keeps the single-wiki UI
 * @property {Object} selectedWiki Ref to the ID of the selected wiki, null while none is
 * @property {Object} searchApi Computed ref to the API for searching articles on the selected
 *   wiki, or null when it can't be searched
 */

/**
 * The wikis that articles can be added to the worklist for, and which of them is selected.
 *
 * @return {WorklistWikiOptions}
 */
function useWorklistWikiOptions() {
	// The wiki ID the server validates worklist keys against (WikiMap::getCurrentWikiId()), which
	// differs from wgDBname on wikis with a table prefix.
	const currentWiki = mw.config.get( 'wgWikiID' );
	const wikiOptions = mw.config.get( 'wgCampaignEventsWorklistWikiOptions' ) ||
		[ { value: currentWiki, label: currentWiki, apiUrl: null } ];
	const showWikiSelector = wikiOptions.length > 1 || wikiOptions[ 0 ].value !== currentWiki;
	// Default to the current wiki when the event covers it, else the event's first wiki.
	const defaultWikiOption = wikiOptions.find( ( option ) => option.value === currentWiki ) ||
		wikiOptions[ 0 ];
	const selectedWiki = ref( defaultWikiOption.value );
	const searchApi = computed( () => {
		if ( selectedWiki.value === currentWiki ) {
			return new mw.Api();
		}
		const option = wikiOptions.find( ( item ) => item.value === selectedWiki.value );
		// Never fall back to the current wiki, which would suggest articles from the wrong wiki.
		if ( !option || !option.apiUrl ) {
			return null;
		}
		// Searching needs no login. Anonymous requests also skip the CentralAuth token that would
		// otherwise be fetched before each one.
		return new mw.ForeignApi( option.apiUrl, { anonymous: true } );
	} );

	return {
		currentWiki,
		wikiOptions,
		showWikiSelector,
		selectedWiki,
		searchApi
	};
}

module.exports = useWorklistWikiOptions;
