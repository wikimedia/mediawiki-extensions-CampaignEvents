( function () {
	'use strict';

	/**
	 * Data about worklist articles that only the wiki holding them can give.
	 *
	 * PageViewInfo's prop=pageviews returns daily counts for the days asked for
	 * (see WINDOW_DAYS), which the card shows totalled.
	 *
	 * Read from the wiki holding each article, not this one: a worklist spans wikis by design,
	 * and that wiki's api.php comes back with the article.
	 */

	/**
	 * Titles per request when asking for view counts.
	 *
	 * PageViewInfo looks up only
	 * $wgPageViewInfoWikimediaRequestLimit of the titles it is given — five, as Wikimedia
	 * configures it — and leaves the rest to a continuation it is on the caller to follow.
	 * Asking in batches that small gets every count in one round of parallel requests, where
	 * following the continuation would serialise them, each token depending on the response
	 * before it. Counts are asked for on their own because of it; see TITLES_PER_REQUEST.
	 */
	const VIEWS_PER_REQUEST = 5;

	/**
	 * Titles per request for everything else.
	 *
	 * `action=query` takes fifty, and PageImages answers for all of them, so asking in the
	 * fives that PageViewInfo needs would be ten times the requests for nothing.
	 */
	const TITLES_PER_REQUEST = 50;

	/**
	 * How far back the count reaches, asked for as `pvipdays`.
	 *
	 * The API would return sixty days by default. It caches whatever its own configuration
	 * says regardless of what is asked for, and trims the answer to the days requested, so
	 * asking for thirty costs nothing and halves what comes back.
	 */
	const WINDOW_DAYS = 30;

	/**
	 * Width to ask for the lead image at.
	 *
	 * Codex draws the card's thumbnail at @size-search-figure, 40px, and CdxThumbnail takes no
	 * size, so that is the only width it is ever shown at. 120px covers a three-times display
	 * without asking the wiki for a larger one later.
	 *
	 * One of the standard widths, which matters: MediaWiki rounds anything else up to the next
	 * standard width and keeps the thumbnail it rendered indefinitely, so an invented size is
	 * stored for good. See https://www.mediawiki.org/wiki/Common_thumbnail_sizes
	 */
	const THUMBNAIL_SIZE = 120;

	/** Data held against `wiki|title`, so a title on two wikis is not confused for one. */
	const cache = new Map();

	function cacheKey( article ) {
		return article.wiki + '|' + article.title;
	}

	/**
	 * The API client for one wiki: its own when the article is elsewhere, this one otherwise.
	 *
	 * @param {Object} article
	 * @return {mw.Api|mw.ForeignApi|null} Null when the wiki could not be resolved
	 */
	function apiFor( article ) {
		if ( article.isLocal ) {
			return new mw.Api();
		}
		if ( !article.apiUrl ) {
			return null;
		}
		// Read-only and unauthenticated, so the request can be anonymous and needs no token.
		return new mw.ForeignApi( article.apiUrl, { anonymous: true } );
	}

	/**
	 * Total views over the window, from the run of daily counts the API returned.
	 *
	 * Days the API has no figure for come back as null — the current day usually is one, being
	 * incomplete — and count as zero rather than discarding the article.
	 *
	 * @param {Object} pageviews Daily counts keyed by date
	 * @return {{count: number}|null} Null when there is nothing to show
	 */
	function summarise( pageviews ) {
		const days = Object.keys( pageviews || {} ).sort();
		if ( !days.length ) {
			return null;
		}
		// Every day returned counts: the window is set by `pvipdays` on the request.
		const count = days.reduce( ( sum, day ) => sum + ( pageviews[ day ] || 0 ), 0 );
		return { count: count };
	}

	/**
	 * The article's lead image, in the shape Codex's thumbnail wants.
	 *
	 * PageImages calls the address `source`; Codex calls it `url`. An article without one — or a
	 * wiki without the extension — simply has none, and the card draws its placeholder.
	 *
	 * @param {Object} page One page of the API response
	 * @return {?{url: string, width: ?number, height: ?number}}
	 */
	function thumbnailOf( page ) {
		const thumbnail = page.thumbnail;
		if ( !thumbnail || !thumbnail.source ) {
			return null;
		}
		return {
			url: thumbnail.source,
			width: thumbnail.width || null,
			height: thumbnail.height || null
		};
	}

	/**
	 * Read the data for the given articles, filling in any that are not already known.
	 *
	 * Articles the API says nothing about, or on a wiki missing the extension behind a piece of
	 * data, are recorded as having none of it, so they are not asked about again.
	 *
	 * @param {Array<{wiki: string, title: string, isLocal: boolean, apiUrl: ?string}>} articles
	 * @return {Promise} Resolves with a Map of `wiki|title` to `{ views, image }`
	 */
	function fetchPageData( articles ) {
		const unknown = articles.filter( ( article ) => !cache.has( cacheKey( article ) ) );
		if ( !unknown.length ) {
			return Promise.resolve( cache );
		}

		// One request per wiki per batch of titles, against that wiki's own API.
		const byWiki = new Map();
		unknown.forEach( ( article ) => {
			if ( !byWiki.has( article.wiki ) ) {
				byWiki.set( article.wiki, [] );
			}
			byWiki.get( article.wiki ).push( article );
		} );

		// Answers are collected here rather than written straight to the cache, because the two
		// kinds of request each carry part of what a card needs and either may fail on its own.
		const found = new Map();
		const requests = [];

		/**
		 * One request per batch of titles, against the wiki's own API.
		 *
		 * A failure leaves the cards as they are: a card is complete without any of this, so
		 * nothing is surfaced to the reader.
		 *
		 * @param {mw.Api|mw.ForeignApi} api
		 * @param {string} wiki
		 * @param {string[]} titles
		 * @param {number} perRequest
		 * @param {Object} params Query parameters beyond the titles
		 * @param {Function} take Called with each page of the response, returning what to keep
		 */
		function ask( api, wiki, titles, perRequest, params, take ) {
			for ( let i = 0; i < titles.length; i += perRequest ) {
				requests.push( api.get( Object.assign( {
					action: 'query',
					titles: titles.slice( i, i + perRequest ),
					format: 'json',
					formatversion: 2
				}, params ) ).then( ( response ) => {
					const pages = ( response.query && response.query.pages ) || [];
					pages.forEach( ( page ) => {
						const key = wiki + '|' + page.title;
						// Merged field by field, so one kind of request cannot blank what the
						// other found.
						found.set( key, Object.assign( {}, found.get( key ), take( page ) ) );
					} );
				}, () => {} ) );
			}
		}

		byWiki.forEach( ( wikiArticles ) => {
			const api = apiFor( wikiArticles[ 0 ] );
			if ( !api ) {
				return;
			}
			const wiki = wikiArticles[ 0 ].wiki;
			const titles = wikiArticles.map( ( article ) => article.title );

			ask( api, wiki, titles, TITLES_PER_REQUEST, {
				prop: 'pageimages',
				piprop: 'thumbnail',
				pithumbsize: THUMBNAIL_SIZE
			}, ( page ) => ( { image: thumbnailOf( page ) } ) );

			ask( api, wiki, titles, VIEWS_PER_REQUEST, {
				prop: 'pageviews',
				pvipdays: WINDOW_DAYS
			}, ( page ) => ( { views: summarise( page.pageviews ) } ) );
		} );

		return Promise.all( requests ).then( () => {
			found.forEach( ( data, key ) => {
				cache.set( key, {
					views: data.views || null,
					image: data.image || null
				} );
			} );
			// Whatever the API did not answer for has nothing to show. Recording that stops the
			// same articles being asked about on every page turn.
			unknown.forEach( ( article ) => {
				const key = cacheKey( article );
				if ( !cache.has( key ) ) {
					cache.set( key, { views: null, image: null } );
				}
			} );
			return cache;
		} );
	}

	module.exports = {
		fetchPageData,
		// Exported for the tests, which pin the window the figure covers.
		summarise,
		thumbnailOf,
		clearCache: () => cache.clear()
	};
}() );
