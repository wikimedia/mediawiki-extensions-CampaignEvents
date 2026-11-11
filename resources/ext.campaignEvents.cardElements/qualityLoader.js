( function () {
	'use strict';

	/**
	 * Quality for articles on cards: the impact band, and the things an article most needs,
	 * taken from the per-element scores behind its quality rating.
	 *
	 * The caller gives the path of the endpoint, for example the quality endpoint of an event
	 * worklist. A score is real work for the model behind the endpoint, so callers ask only
	 * about the articles on screen, and answers are kept against the article they describe:
	 * paging back over articles already seen costs nothing.
	 */

	const createBatchLoader = require( './batchLoader.js' );

	/** An element counts as a weakness below this. The acceptance criteria say 50%. */
	const THRESHOLD = 0.5;

	/**
	 * Highest whole percentage in each impact band, largest opportunity first.
	 *
	 * The bands are contiguous only because the figure being banded is a whole percentage: the
	 * score is rounded to two decimal places first, as the acceptance criteria say, so 0.854
	 * becomes 85 and falls in the middle band rather than through the gap between 85 and 86.
	 */
	const BANDS = [
		{ band: 'high', upTo: 40 },
		{ band: 'medium', upTo: 85 },
		{ band: 'low', upTo: 100 }
	];

	/**
	 * Which impact band a score falls in, or null when there is no usable score.
	 *
	 * @param {number|undefined} score Between 0 and 1
	 * @return {string|null}
	 */
	function bandFor( score ) {
		if ( typeof score !== 'number' || !isFinite( score ) ) {
			return null;
		}
		const percent = Math.round( score * 100 );
		const match = BANDS.find( ( candidate ) => percent <= candidate.upTo );
		return match ? match.band : null;
	}

	/**
	 * Elements that become a signal, in the order they are offered to the reader, each with the
	 * message describing what the article needs.
	 *
	 * Two of the model's nine elements are deliberately absent. `sources` saturates almost at
	 * once — a stub with five of them already scores 1 — so it would practically never fall below
	 * the threshold, and it says the same thing as `refs` to a reader. `messagebox` is not a
	 * weak element at all: it is true when the article *carries* a maintenance banner, which is a
	 * different statement and outside what the criteria ask for.
	 */
	const SIGNALS = [
		{ element: 'refs', message: 'campaignevents-event-details-worklist-card-signal-refs' },
		{ element: 'wikilinks', message: 'campaignevents-event-details-worklist-card-signal-wikilinks' },
		{ element: 'headings', message: 'campaignevents-event-details-worklist-card-signal-headings' },
		{ element: 'media', message: 'campaignevents-event-details-worklist-card-signal-media' },
		{ element: 'infobox', message: 'campaignevents-event-details-worklist-card-signal-infobox' },
		{ element: 'categories', message: 'campaignevents-event-details-worklist-card-signal-categories' },
		{ element: 'characters', message: 'campaignevents-event-details-worklist-card-signal-characters' }
	];

	/**
	 * Titles in one request. The endpoint takes at most ArticleQualityLookup::MAX_ARTICLES.
	 */
	const TITLES_PER_REQUEST = 30;

	/** A loader for each endpoint path, as each endpoint can know different articles. */
	const loaders = new Map();

	/**
	 * Whether an element counts as a weakness.
	 *
	 * `infobox` is a boolean rather than a score: false means the article has none. Comparing it
	 * numerically would work by accident for that one and silently invert any other boolean, so
	 * the two kinds are tested apart.
	 *
	 * @param {number|boolean|undefined} value
	 * @return {boolean}
	 */
	function isWeak( value ) {
		if ( typeof value === 'boolean' ) {
			return !value;
		}
		return typeof value === 'number' && value < THRESHOLD;
	}

	/**
	 * The signals for one article, in the order they are offered.
	 *
	 * @param {Object} elements The endpoint's per-element scores
	 * @return {string[]} Messages, empty when nothing is below the threshold
	 */
	function signalsFor( elements ) {
		return SIGNALS
			.filter( ( signal ) => isWeak( elements[ signal.element ] ) )
			.map( ( signal ) => mw.msg( signal.message ) );
	}

	/**
	 * Ask the endpoint about one batch of articles.
	 *
	 * @param {string} path REST path of the quality endpoint
	 * @param {Array<{wiki: string, title: string}>} articles
	 * @return {Promise} Resolves with a Map of `wiki|title` to `{ signals, band }`
	 */
	function fetchQualityBatch( path, articles ) {
		// The endpoint answers for the titles of one wiki at a time, because a worklist can
		// hold the same title on more than one wiki.
		const byWiki = new Map();
		articles.forEach( ( article ) => {
			if ( !byWiki.has( article.wiki ) ) {
				byWiki.set( article.wiki, [] );
			}
			byWiki.get( article.wiki ).push( article.title );
		} );
		const requests = [];
		byWiki.forEach( ( titles, wiki ) => {
			for ( let i = 0; i < titles.length; i += TITLES_PER_REQUEST ) {
				requests.push( { wiki, titles: titles.slice( i, i + TITLES_PER_REQUEST ) } );
			}
		} );

		const found = new Map();

		/**
		 * Send one request, then the next.
		 *
		 * One at a time rather than all at once, as API etiquette asks: each request ends up at
		 * LiftWing, a service outside the wiki. A worklist spans a handful of wikis at most, so
		 * the wait is a handful of round trips.
		 *
		 * @param {Array<{wiki: string, titles: string[]}>} remaining
		 * @return {Promise}
		 */
		function askInTurn( remaining ) {
			if ( !remaining.length ) {
				return Promise.resolve();
			}
			return new mw.Rest().get(
				path,
				{ wiki: remaining[ 0 ].wiki, titles: remaining[ 0 ].titles.join( '|' ) }
			).then( ( response ) => {
				( response.articles || [] ).forEach( ( entry ) => {
					found.set( entry.wiki + '|' + entry.title, {
						signals: signalsFor( entry.elements || {} ),
						band: bandFor( entry.score )
					} );
				} );
			}, () => {
				// The card is complete without signals, so a failure is not surfaced. The
				// articles of the request get no data, and the batch loader records that.
			} ).then( () => askInTurn( remaining.slice( 1 ) ) );
		}

		return askInTurn( requests ).then( () => found );
	}

	/**
	 * Read the quality of the given articles, filling in any that are not already known.
	 *
	 * Calls in the same tick are combined. Articles the endpoint says nothing about — it could
	 * not score them, or they are not in the list the endpoint is for — or whose request
	 * failed, are recorded as having nothing to show, so they are not asked about again.
	 *
	 * @param {string} path REST path of the quality endpoint, for example the one of a worklist
	 * @param {Array<{wiki: string, title: string}>} articles
	 * @return {Promise} Resolves with a Map of `wiki|title` to `{ signals, band }`
	 */
	function fetchQuality( path, articles ) {
		return loaderFor( path ).load( articles );
	}

	/**
	 * @param {string} path
	 * @return {Object} The batch loader of the endpoint
	 */
	function loaderFor( path ) {
		if ( !loaders.has( path ) ) {
			loaders.set( path, createBatchLoader(
				( batch ) => fetchQualityBatch( path, batch ),
				() => ( { signals: [], band: null } )
			) );
		}
		return loaders.get( path );
	}

	/**
	 * Composable: the quality of the given articles, as reactive state.
	 *
	 * It loads the quality when the articles change, for example when the reader goes to the
	 * next page of cards. Calls from many components in one tick go into one batch.
	 *
	 * @param {string} path REST path of the quality endpoint
	 * @param {Object|Function} articles Ref or getter of an array of `{ wiki, title }`
	 * @return {{qualityFor: Function}} `qualityFor( article )` gives `{ signals, band }`. Both
	 *  are empty while the quality loads, when there is no score, or when the request failed.
	 */
	function useQuality( path, articles ) {
		return { qualityFor: loaderFor( path ).use( articles ) };
	}

	module.exports = {
		fetchQuality,
		useQuality,
		// Exported for the tests, which pin the band edges the criteria specify.
		bandFor,
		// For tests, which would otherwise carry answers between cases.
		clearCache: () => loaders.clear()
	};
}() );
