( function () {
	var pageName = mw.config.get( 'wgCanonicalSpecialPageName' );
	switch ( pageName ) {
		case 'EditEventRegistration':
		case 'EnableEventRegistration':
			require( './editeventregistration/index.js' );
			break;
		case 'EventDetails':
			require( './eventdetails/index.js' );
			break;
		case 'AllEvents':
		case 'MyEvents':
			require( './eventlists/index.js' );
			break;
		case null:
			// QUnit tests and the like.
			break;
		default:
			mw.log.error( 'module has been loaded on an unexpected page: ' + pageName );
	}
}() );
