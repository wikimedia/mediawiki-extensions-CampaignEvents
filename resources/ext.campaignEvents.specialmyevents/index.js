/* eslint-disable no-jquery/no-global-selector */
( function () {
	'use strict';

	var FilterEventsWidget = require( './FilterEventsWidget.js' ),
		EventKebabMenu = require( './EventKebabMenu.js' ),
		deletedEventParam = 'deletedEvent';

	function checkEventJustDeleted() {
		var deletedName = mw.util.getParamValue( deletedEventParam );
		if ( deletedName ) {
			mw.notify(
				mw.message( 'campaignevents-eventslist-delete-success', deletedName ),
				{ type: 'success' }
			);
		}
	}

	$( function () {
		checkEventJustDeleted();
		var $myEventsForm = $( '#ext-campaignevents-myevents-form' );
		if ( $myEventsForm.length ) {
			// Optim: avoid this if we're not on the Special:MyEvents page, since this module can
			// also be used for the pager only.
			var filterWidget = new FilterEventsWidget( {
				$filterElements: $( '.ext-campaignevents-myevents-filter-field' )
			} );
			$myEventsForm.append( filterWidget.$element );
		}

		var windowManager = new OO.ui.WindowManager();
		$( document.body ).append( windowManager.$element );

		$( '.ext-campaignevents-eventspager-manage-btn' ).each( function () {
			var $btn = $( this ),
				menu = new EventKebabMenu( {
					eventID: $btn.data( 'event-id' ),
					eventName: $btn.data( 'event-name' ),
					isEventClosed: $btn.data( 'is-closed' ),
					eventPageURL: $btn.data( 'event-page-url' ),
					windowManager: windowManager
				} );
			menu.on( 'deleted', function ( eventName ) {
				var currentUri = new mw.Uri();
				delete currentUri.query[ deletedEventParam ];
				delete currentUri.query.title;

				var newParams = currentUri.query;
				newParams[ deletedEventParam ] = eventName;

				window.location.href = mw.util.getUrl(
					mw.config.get( 'wgPageName' ),
					newParams
				);
			} );
			$btn.replaceWith( menu.$element );
		} );
	} );
}() );
