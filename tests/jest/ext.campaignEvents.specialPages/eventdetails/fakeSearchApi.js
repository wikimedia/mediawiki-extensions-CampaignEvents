'use strict';

/**
 * A stand-in for mw.Api whose get() requests stay pending until a test settles them, so tests
 * control the order responses arrive in.
 *
 * @return {{api: Object, requests: Array<Object>}}
 */
module.exports = function fakeSearchApi() {
	const requests = [];
	const api = {
		get: jest.fn( ( params ) => {
			let resolve, reject;
			const promise = new Promise( ( res, rej ) => {
				resolve = res;
				reject = rej;
			} );
			promise.abort = jest.fn( () => reject( 'http' ) );
			const request = {
				params,
				promise,
				// Resolve with a prefixsearch generator response ranking the given articles in
				// order. Pages are listed in reverse, as the API does not order them either.
				respond: ( titles ) => {
					const pages = titles.map( ( title, i ) => ( { ns: 0, title, index: i + 1 } ) );
					resolve( { query: { pages: pages.reverse() } } );
					return promise;
				},
				// Resolve with the given raw response.
				respondWith: ( response ) => {
					resolve( response );
					return promise;
				},
				reject: () => {
					reject( 'http' );
					return promise.catch( () => {} );
				}
			};
			requests.push( request );
			return promise;
		} )
	};
	return { api, requests };
};
