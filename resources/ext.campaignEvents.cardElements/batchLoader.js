const { ref, watch } = require( 'vue' );

/**
 * A cache of data about pages, that combines the calls of one tick into one batch.
 *
 * Many callers can ask for pages at the same time, for example one component for each card.
 * The calls that are made in the same tick go into one batch, so the page sends one request
 * for each batch, not one for each caller. The cache keeps the promise of each page, against
 * `wiki|title`. Thus a caller that asks for a page while its request is in progress gets the
 * same promise, and the page causes only one request.
 *
 * A page that the request gives no data for, or a page whose request fails, gets the empty
 * value. The cache keeps it, so the page is not asked for again.
 *
 * @param {Function} fetchData Gets the data for an array of pages. Returns a promise of a Map
 *  of `wiki|title` to the data. If it throws or rejects, all the pages get the empty value.
 * @param {Function} empty Returns the value for a page without data
 * @return {{load: Function, use: Function, clear: Function}}
 */
function createBatchLoader( fetchData, empty ) {
	/** Promise of the data of each page, against `wiki|title`. */
	const cache = new Map();

	/** Pages of the next batch, against `wiki|title`, with the functions that settle them. */
	let pending = null;

	function flush() {
		const batch = pending;
		pending = null;
		const pages = Array.from( batch.values() ).map( ( entry ) => entry.page );
		let request;
		try {
			// Called in this microtask, not in a later one, so the requests start at once.
			request = Promise.resolve( fetchData( pages ) );
		} catch ( e ) {
			// Settle the promises of the batch, or the pages stay in loading for ever.
			request = Promise.resolve( new Map() );
		}
		request.then( null, () => new Map() ).then( ( found ) => {
			batch.forEach( ( entry, key ) => {
				entry.resolve( found.has( key ) ? found.get( key ) : empty() );
			} );
		} );
	}

	/**
	 * Get the data of the given pages.
	 *
	 * @param {Array<{wiki: string, title: string}>} pages
	 * @return {Promise} Resolves with a Map of `wiki|title` to the data, for the given pages
	 */
	function load( pages ) {
		const keys = pages.map( ( page ) => {
			const key = page.wiki + '|' + page.title;
			if ( !cache.has( key ) ) {
				if ( !pending ) {
					pending = new Map();
					// A microtask runs after the synchronous code of this tick. Vue mounts all
					// the components of one render synchronously, so their calls are in one
					// batch.
					Promise.resolve().then( flush );
				}
				cache.set( key, new Promise( ( resolve ) => {
					pending.set( key, { page, resolve } );
				} ) );
			}
			return key;
		} );
		return Promise.all( keys.map( ( key ) => cache.get( key ) ) )
			.then( ( values ) => new Map( keys.map( ( key, i ) => [ key, values[ i ] ] ) ) );
	}

	/**
	 * Composable: the data of the given pages, as reactive state.
	 *
	 * When the pages change, it loads the data of the new pages. The data of the pages that
	 * it loaded before stays, so a response that arrives late cannot remove data from the
	 * screen.
	 *
	 * @param {Object|Function} pages Ref or getter of an array of `{ wiki, title }`
	 * @return {Function} Gives the data of a page, or the empty value while it loads
	 */
	function use( pages ) {
		const known = ref( new Map() );
		watch( pages, ( current ) => {
			if ( !current || !current.length ) {
				return;
			}
			load( current ).then( ( found ) => {
				// A new Map, so that Vue sees the change and redraws.
				known.value = new Map( [ ...known.value, ...found ] );
			} );
		}, { immediate: true } );
		return ( page ) => known.value.get( page.wiki + '|' + page.title ) || empty();
	}

	return {
		load,
		use,
		// For tests, which would otherwise carry answers between cases.
		clear: () => cache.clear()
	};
}

module.exports = createBatchLoader;
