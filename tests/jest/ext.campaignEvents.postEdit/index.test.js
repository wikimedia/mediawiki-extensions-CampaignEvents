'use strict';

/* global global, setTimeout */

const INDEX = '../../../resources/ext.campaignEvents.postEdit/index.js';
const EVENTS_URL = '/campaignevents/v0/participant/self/events_for_edit?title=Foo';

// mw.hook stub backed by a shared per-name listener registry, so add/remove/fire from separate
// mw.hook( name ) calls operate on the same list.
function makeHookFactory() {
	const registry = {};
	const factory = ( name ) => {
		registry[ name ] = registry[ name ] || [];
		return {
			add: ( fn ) => registry[ name ].push( fn ),
			remove: ( fn ) => {
				registry[ name ] = registry[ name ].filter( ( f ) => f !== fn );
			},
			fire: ( ...args ) => registry[ name ].slice().forEach( ( f ) => f( ...args ) )
		};
	};
	factory.registry = registry;
	return factory;
}

function setupMw( configValues ) {
	const store = Object.assign( {}, configValues );
	const restGet = jest.fn().mockResolvedValue( [] );
	const restPut = jest.fn().mockResolvedValue( {} );
	global.mw = {
		config: {
			get: ( key ) => ( key in store ? store[ key ] : null ),
			set: ( key, value ) => {
				store[ key ] = value;
			}
		},
		hook: makeHookFactory(),
		notify: jest.fn(),
		message: jest.fn( ( key, ...params ) => ( { key, params } ) ),
		util: { getUrl: jest.fn( () => '/wiki/url' ) },
		user: { tokens: { get: jest.fn( () => 'csrf-token' ) } },
		Rest: jest.fn().mockImplementation( () => ( { get: restGet, put: restPut } ) )
	};
	return { store, restGet, restPut };
}

// Let pending promises (the fetch and, for auto-association, the association PUT) settle.
const flush = () => new Promise( ( resolve ) => {
	setTimeout( resolve, 0 );
} );

describe( 'ext.campaignEvents.postEdit entry point', () => {
	afterEach( () => {
		delete global.mw;
	} );

	describe( 'server-side auto-association', () => {
		it( 'shows the confirmation and registers a handler that ignores the current reload', () => {
			const { store, restGet } = setupMw( {
				wgCampaignEventsAutoAssociatedEvent: { id: 7, name: 'Test event' },
				wgRevisionId: 100,
				wgNamespaceNumber: 0,
				wgPageName: 'Foo'
			} );

			jest.isolateModules( () => {
				require( INDEX );
			} );

			expect( global.mw.notify ).toHaveBeenCalledTimes( 1 );

			// The signal for the revision handled server-side must not re-trigger a fetch.
			global.mw.hook( 'postEdit' ).fire();
			expect( restGet ).not.toHaveBeenCalled();

			// A genuinely new in-place edit (new revision) is handled.
			store.wgRevisionId = 200;
			global.mw.hook( 'postEdit' ).fire();
			expect( restGet ).toHaveBeenCalledWith( EVENTS_URL );
		} );
	} );

	describe( 'VE plugin (no server-side signal)', () => {
		it( 'auto-associates and confirms when exactly one event is auto-associable', async () => {
			const { restGet, restPut } = setupMw( {
				wgNamespaceNumber: 0,
				wgPageName: 'Foo',
				wgDBname: 'testwiki',
				wgRevisionId: 321
			} );
			restGet.mockResolvedValue( [
				{ id: 5, name: 'Auto event', autoAssociable: true },
				{ id: 6, name: 'Other event', autoAssociable: false }
			] );

			jest.isolateModules( () => {
				require( INDEX );
			} );

			global.mw.hook( 'postEdit' ).fire();
			await flush();

			expect( restPut ).toHaveBeenCalledWith(
				'/campaignevents/v0/event_registration/5/edits/testwiki/321',
				{ token: 'csrf-token' }
			);
			expect( global.mw.notify ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'auto-associates each subsequent in-place edit (listener persists)', async () => {
			const { store, restGet, restPut } = setupMw( {
				wgNamespaceNumber: 0,
				wgPageName: 'Foo',
				wgDBname: 'testwiki',
				wgRevisionId: 321
			} );
			restGet.mockResolvedValue( [ { id: 5, name: 'Auto event', autoAssociable: true } ] );

			jest.isolateModules( () => {
				require( INDEX );
			} );

			global.mw.hook( 'postEdit' ).fire();
			await flush();
			store.wgRevisionId = 322;
			global.mw.hook( 'postEdit' ).fire();
			await flush();

			expect( restPut ).toHaveBeenCalledTimes( 2 );
			expect( restPut ).toHaveBeenNthCalledWith(
				1, '/campaignevents/v0/event_registration/5/edits/testwiki/321', { token: 'csrf-token' }
			);
			expect( restPut ).toHaveBeenNthCalledWith(
				2, '/campaignevents/v0/event_registration/5/edits/testwiki/322', { token: 'csrf-token' }
			);
		} );

		it( 'takes the revision from the Wikibase hook payload, not wgRevisionId', async () => {
			const { restGet, restPut } = setupMw( {
				wgNamespaceNumber: 0,
				wgPageName: 'Foo',
				wgDBname: 'testwiki',
				wgRevisionId: 100
			} );
			restGet.mockResolvedValue( [ { id: 5, name: 'Auto event', autoAssociable: true } ] );

			jest.isolateModules( () => {
				require( INDEX );
			} );

			// wikibase.statement.saved passes the new revision as its last argument.
			global.mw.hook( 'wikibase.statement.saved' ).fire( 'Q1', 'guid', null, {}, 555 );
			await flush();

			expect( restPut ).toHaveBeenCalledWith(
				'/campaignevents/v0/event_registration/5/edits/testwiki/555',
				{ token: 'csrf-token' }
			);
		} );

		it( 'fetches events on the first in-place edit', () => {
			const { restGet } = setupMw( { wgNamespaceNumber: 0, wgPageName: 'Foo', wgRevisionId: 321 } );

			jest.isolateModules( () => {
				require( INDEX );
			} );

			expect( global.mw.notify ).not.toHaveBeenCalled();

			global.mw.hook( 'postEdit' ).fire();
			expect( restGet ).toHaveBeenCalledWith( EVENTS_URL );
		} );

		it( 'does not register hooks in the NS_EVENT namespace', () => {
			const { restGet } = setupMw( { wgNamespaceNumber: 1728 } );

			jest.isolateModules( () => {
				require( INDEX );
			} );

			global.mw.hook( 'postEdit' ).fire();
			expect( restGet ).not.toHaveBeenCalled();
		} );
	} );
} );
