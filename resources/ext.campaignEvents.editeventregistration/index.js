( function () {
	'use strict';

	var OrganizerSelectionFieldEnhancer = require( './OrganizerSelectionFieldEnhancer.js' );
	var TimeFieldsEnhancer = require( './TimeFieldsEnhancer.js' );

	mw.hook( 'htmlform.enhance' ).add( function ( $root ) {
		// NOTE: This module has a dependency on mediawiki.widgets.UsersMultiselectWidget
		// because autoinfusion is also handled in a htmlform.enhance callback, so there's no
		// guarantee on which handler runs first. In fact, it throws when using debug=1.
		OrganizerSelectionFieldEnhancer.init( $root.find( '.ext-campaignevents-organizers-multiselect-input' ) );
		TimeFieldsEnhancer.init( $root );
	} );
}() );
