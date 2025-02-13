/* eslint-disable no-jquery/no-global-selector */
( function () {
	'use strict';
	var FilterEventsWidget = require( './FilterEventsWidget.js' ),
		EventKebabMenu = require( './EventKebabMenu.js' ),
		DateTimeWidgetsEnhancer = require( './DateTimeWidgetsEnhancer.js' ),
		EventAccordionWatcher = require( './EventAccordionWatcher.js' ),
		deletedEventParam = 'deletedEvent';
	mw.loader.using( [ 'mediawiki.widgets.datetime' ], function () {
		mw.hook( 'htmlform.enhance' ).add( function ( $root ) {
			if ( $root ) {
				DateTimeWidgetsEnhancer.init( $root );
			}
		} );
	} );
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
					label: $btn.data( 'mw-label' ),
					invisibleLabel: true,
					eventID: $btn.data( 'mw-event-id' ),
					eventName: $btn.data( 'mw-event-name' ),
					isEventClosed: $btn.data( 'mw-is-closed' ),
					eventPageURL: $btn.data( 'mw-event-page-url' ),
					isLocalWiki: $btn.data( 'mw-is-local-wiki' ),
					windowManager: windowManager
				} );
			menu.on( 'deleted', function ( eventName ) {
				var newParams = new URL( window.location.href ).searchParams;
				newParams.set( deletedEventParam, eventName );
				newParams.delete( 'title' );

				var newQuery = {};
				newParams.forEach( function ( value, key ) {
					newQuery[ key ] = value;
				} );
				window.location.href = mw.util.getUrl(
					mw.config.get( 'wgPageName' ),
					newQuery
				);
			} );
			$btn.replaceWith( menu.$element );
		} );

		EventAccordionWatcher.setup();
	} );
}() );
