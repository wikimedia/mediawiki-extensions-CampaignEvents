( function () {
	'use strict';

	/**
	 * Quality for worklist articles: the things an article most needs, taken from the
	 * per-element scores behind its quality rating.
	 *
	 * Asked for after the cards have rendered, as a screenful at a time. A score is real work for
	 * the model behind the endpoint, so this never asks about the whole worklist, and answers are
	 * kept against the article they describe: paging back over articles already seen costs
	 * nothing, and a late response can only fill in the articles it was asked about.
	 */

	/** An element counts as a weakness below this. The acceptance criteria say 50%. */
	const THRESHOLD = 0.5;

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

	/** Quality held against `wiki|title`, so a title on two wikis is not confused for one. */
	const cache = new Map();

	function cacheKey( article ) {
		return article.wiki + '|' + article.title;
	}

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
	 * Read the quality of the given articles, filling in any that are not already known.
	 *
	 * Articles the endpoint says nothing about — it could not score them, or they are not in the
	 * worklist — are recorded as having nothing to show, so they are not asked about again.
	 *
	 * @param {Array<{wiki: string, title: string}>} articles
	 * @return {Promise} Resolves with a Map of `wiki|title` to `{ signals }`
	 */
	function fetchQuality( articles ) {
		const unknown = articles.filter( ( article ) => !cache.has( cacheKey( article ) ) );
		if ( !unknown.length ) {
			return Promise.resolve( cache );
		}

		// One request per wiki: the endpoint answers for a single wiki's titles at a time,
		// because a worklist can hold the same title on more than one of them.
		const byWiki = new Map();
		unknown.forEach( ( article ) => {
			if ( !byWiki.has( article.wiki ) ) {
				byWiki.set( article.wiki, [] );
			}
			byWiki.get( article.wiki ).push( article.title );
		} );

		const eventId = mw.config.get( 'wgCampaignEventsWorklistEventId' );
		/**
		 * Ask one wiki, then the next.
		 *
		 * One at a time rather than all at once, as API etiquette asks: each request ends up at
		 * LiftWing, a service outside the wiki. A worklist spans a handful of wikis at most, so
		 * the wait is a handful of round trips.
		 *
		 * @param {Array<Array>} remaining Each entry a wiki and the titles to ask it about
		 * @return {Promise}
		 */
		function askInTurn( remaining ) {
			if ( !remaining.length ) {
				return Promise.resolve();
			}
			const wiki = remaining[ 0 ][ 0 ];
			const titles = remaining[ 0 ][ 1 ];
			return new mw.Rest().get(
				'/campaignevents/v0/event_registration/' + encodeURIComponent( eventId ) +
					'/worklist_pages/quality',
				{ wiki: wiki, titles: titles.join( '|' ) }
			).then( ( response ) => {
				( response.articles || [] ).forEach( ( entry ) => {
					cache.set( cacheKey( entry ), {
						signals: signalsFor( entry.elements || {} )
					} );
				} );
			}, () => {
				// The card is complete without signals, so a failure is not surfaced. The
				// articles it was asked about are recorded as having none, below, along with
				// the ones the endpoint simply had nothing for, so a wiki that fails is not
				// asked about again for the rest of the visit.
			} ).then( () => askInTurn( remaining.slice( 1 ) ) );
		}

		return askInTurn( Array.from( byWiki ) ).then( () => {
			// Whatever came back without a score has nothing to show, whether the endpoint had
			// nothing for it or the request failed. Recording that stops the same articles being
			// asked about on every page turn.
			unknown.forEach( ( article ) => {
				const key = cacheKey( article );
				if ( !cache.has( key ) ) {
					cache.set( key, { signals: [] } );
				}
			} );
			return cache;
		} );
	}

	module.exports = {
		fetchQuality,
		// For tests, which would otherwise carry answers between cases.
		clearCache: () => cache.clear()
	};
}() );
