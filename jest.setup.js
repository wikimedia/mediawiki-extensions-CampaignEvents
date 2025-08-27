'use strict';
/* global global, jest */

const { config } = require( '@vue/test-utils' );

// Mock Vue plugins in test suites
config.global.mocks = {
	$i18n: ( str ) => ( {
		text: () => str,
		parse: () => str,
		toString: () => str,
		escaped: () => str
	} )
};

config.global.directives = {
	'i18n-html': ( el, binding ) => {
		el.innerHTML = `${ binding.arg } (${ binding.value })`;
	}
};

// Stub the mw global object.
global.mw = {
	user: {
		tokens: {
			get: jest.fn()
		}
	},
	// As seen in CheckUser's jest.setup
	msg: jest.fn( ( ...messageKeyAndParams ) => `(${ messageKeyAndParams.join( ', ' ) })` ),
	// As seen in CodeMirror's jest.setup
	hook: jest.fn( ( name ) => ( {
		fire: jest.fn( ( ...args ) => {
			if ( mw.hook.mockHooks[ name ] ) {
				mw.hook.mockHooks[ name ].forEach( ( callback ) => callback( ...args ) );
			}
		} ),
		add: jest.fn( ( callback ) => {
			if ( !mw.hook.mockHooks[ name ] ) {
				mw.hook.mockHooks[ name ] = [];
			}
			mw.hook.mockHooks[ name ].push( callback );
		} ),
		remove: jest.fn( ( callback ) => {
			if ( mw.hook.mockHooks[ name ] ) {
				mw.hook.mockHooks[ name ] = mw.hook.mockHooks[ name ]
					.filter( ( cb ) => cb !== callback );
			}
		} ),
		deprecate: jest.fn()
	} ) )
};

// Ignore all "teleport" behavior for the purpose of testing Dialog;
// see https://test-utils.vuejs.org/guide/advanced/teleport.html
config.global.stubs = {
	teleport: true
};
