/* eslint-disable no-jquery/no-global-selector */
( function () {
	'use strict';

	var FilterEventsWidget = require( './FilterEventsWidget.js' );

	$( function () {
		var filterWidget = new FilterEventsWidget( {
			$filterElements: $( '.ext-campaignevents-myevents-filter-field' )
		} );
		$( '#ext-campaignevents-myevents-form' ).append( filterWidget.$element );
	} );
}() );
