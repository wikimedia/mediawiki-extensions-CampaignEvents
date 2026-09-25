'use strict';

/* eslint-env node */

const fs = require( 'fs' );
const path = require( 'path' );

const extensionJson = require( '../../extension.json' );

/**
 * Every Codex component a module's code requires has to be listed in that module's
 * `codexComponents`, or ResourceLoader throws as soon as the file is loaded in the browser.
 *
 * Nothing else catches it: jest resolves `codex.js` straight to the package, so a component
 * missing from the list works perfectly in these tests and fails on the wiki. This walks the
 * modules instead, so the list and the code cannot drift apart.
 */

/**
 * @param {string} contents Source of a file the module ships
 * @return {string[]} Cdx names destructured from a require of codex.js
 */
function codexComponentsUsedIn( contents ) {
	const used = new Set();
	const requires = contents.matchAll(
		/(?:const|let|var)\s*\{([^}]*)\}\s*=\s*require\(\s*['"][^'"]*codex\.js['"]\s*\)/g
	);
	for ( const [ , names ] of requires ) {
		names.split( ',' )
			.map( ( name ) => name.trim().split( ':' )[ 0 ].trim() )
			.filter( ( name ) => name.startsWith( 'Cdx' ) )
			.forEach( ( name ) => used.add( name ) );
	}
	return [ ...used ];
}

/**
 * @param {Object} module A ResourceModules entry
 * @return {string[]} Files it ships, relative to its base path
 */
function packageFilesOf( module ) {
	return ( module.packageFiles || [] )
		.map( ( file ) => ( typeof file === 'string' ? file : file.file || file.name ) )
		.filter( ( file ) => file && ( file.endsWith( '.vue' ) || file.endsWith( '.js' ) ) );
}

const modulesWithCodex = Object.entries( extensionJson.ResourceModules || {} )
	.filter( ( [ , module ] ) => Array.isArray( module.codexComponents ) );

describe( 'codexComponents in extension.json', () => {
	it( 'has modules to check', () => {
		expect( modulesWithCodex.length ).toBeGreaterThan( 0 );
	} );

	it.each( modulesWithCodex )( '%s declares every component it uses', ( name, module ) => {
		const base = path.join( __dirname, '../..', module.localBasePath || '' );
		const declared = new Set( module.codexComponents );
		const missing = new Set();

		for ( const file of packageFilesOf( module ) ) {
			// Paths come from extension.json, which is part of the extension, not from input.
			const full = path.join( base, file );
			// eslint-disable-next-line security/detect-non-literal-fs-filename
			if ( !fs.existsSync( full ) ) {
				continue;
			}
			// eslint-disable-next-line security/detect-non-literal-fs-filename
			codexComponentsUsedIn( fs.readFileSync( full, 'utf8' ) )
				.filter( ( component ) => !declared.has( component ) )
				.forEach( ( component ) => missing.add( `${ component } (${ file })` ) );
		}

		expect( [ ...missing ] ).toEqual( [] );
	} );
} );
