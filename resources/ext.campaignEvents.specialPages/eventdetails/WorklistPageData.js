( function () {
	'use strict';

	/**
	 * Data about worklist articles that only the wiki holding them can give.
	 *
	 * PageViewInfo's `prop=pageviews` returns the last 60 days of daily counts. The card shows
	 * the most recent 30 as the figure and compares them with the 30 before to set the trend, so
	 * one request answers both halves.
	 *
	 * Read from the wiki holding each article, not this one: a worklist spans wikis by design,
	 * and that wiki's api.php comes back with the article. One request per wiki covers every
	 * piece of data, so adding another costs no extra round trip.
	 */

	/** action=query accepts at most 50 titles per request. */
	const TITLES_PER_REQUEST = 50;

	/** How far back the count reaches. The API returns 60 days; the card shows the last 30. */
	const WINDOW_DAYS = 30;

	/**
	 * Widest the card ever draws a thumbnail, so one image serves every screen density rather
	 * than the wiki being asked again for a larger one.
	 */
	const THUMBNAIL_SIZE = 200;

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
	 * Total views over the window, from a run of daily counts.
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
		const count = days.slice( -WINDOW_DAYS )
			.reduce( ( sum, day ) => sum + ( pageviews[ day ] || 0 ), 0 );
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

		const requests = [];
		byWiki.forEach( ( wikiArticles ) => {
			const api = apiFor( wikiArticles[ 0 ] );
			if ( !api ) {
				return;
			}
			const wiki = wikiArticles[ 0 ].wiki;
			for ( let i = 0; i < wikiArticles.length; i += TITLES_PER_REQUEST ) {
				const batch = wikiArticles.slice( i, i + TITLES_PER_REQUEST );
				requests.push( api.get( {
					action: 'query',
					// Both in one request: each is a separate extension on the wiki, and either may
					// be missing, but neither costs an extra round trip.
					prop: 'pageviews|pageimages',
					piprop: 'thumbnail',
					pithumbsize: THUMBNAIL_SIZE,
					titles: batch.map( ( article ) => article.title ),
					format: 'json',
					formatversion: 2
				} ).then( ( response ) => {
					const pages = ( response.query && response.query.pages ) || [];
					pages.forEach( ( page ) => {
						cache.set( wiki + '|' + page.title, {
							views: summarise( page.pageviews ),
							image: thumbnailOf( page )
						} );
					} );
				}, () => {
					// A wiki without PageViewInfo answers with a warning rather than the data.
					// The card is complete without a view count, so nothing is surfaced.
				} ) );
			}
		} );

		return Promise.all( requests ).then( () => {
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
		// Exported for the tests, which pin the window and the trend edges.
		summarise,
		thumbnailOf,
		clearCache: () => cache.clear()
	};
}() );
