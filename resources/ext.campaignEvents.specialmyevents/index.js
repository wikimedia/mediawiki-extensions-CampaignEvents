/* eslint-disable no-jquery/no-global-selector */
( function () {
	'use strict';

	var FilterEventsWidget = require( './FilterEventsWidget.js' ),
		EventKebabMenu = require( './EventKebabMenu.js' );

	$( function () {
		var $myEventsForm = $( '#ext-campaignevents-myevents-form' );
		if ( $myEventsForm.length ) {
			// Optim: avoid this if we're not on the Special:MyEvents page, since this module can
			// also be used for the pager only.
			var filterWidget = new FilterEventsWidget( {
				$filterElements: $( '.ext-campaignevents-myevents-filter-field' )
			} );
			$myEventsForm.append( filterWidget.$element );
		}

		$( '.ext-campaignevents-eventspager-manage-btn' ).each( function () {
			var $btn = $( this ),
				menu = new EventKebabMenu( {
					eventID: $btn.data( 'event-id' )
				} );
			$btn.replaceWith( menu.$element );
		} );
	} );
}() );
