( function () {
	'use strict';

	var ConfirmEventDeletionDialog = require( './ConfirmEventDeletionDialog.js' );

	/**
	 * Widgets for the kebab menu used in EventsPager.
	 *
	 * @extends OO.ui.ButtonMenuSelectWidget
	 *
	 * @constructor
	 * @param {Object} [config] Configuration options
	 * @param {number} [config.eventID] ID of the event that the menu is used for.
	 * @param {string} [config.eventName] Name of the event that the menu is used for.
	 * @param {boolean} [config.isEventClosed] Whether the event is closed.
	 * @param {string} [config.eventPageURL] URL of the event page (could be on another wiki).
	 * @param {Object} [config.windowManager] WindowManager object shared by all menus.
	 */
	function EventKebabMenu( config ) {
		this.eventID = config.eventID;
		this.eventName = config.eventName;
		this.isClosed = config.isEventClosed;
		this.windowManager = config.windowManager;

		var editHref = mw.util.getUrl( 'Special:EditEventRegistration/' + this.eventID ),
			deleteHref = mw.util.getUrl( 'Special:DeleteEventRegistration/' + this.eventID );

		// Note: all options are actually <a> elements, so that it's possible to middle- or
		// right-click them and copy the link or open it in a new tab.
		var getLinkWithLeftClickDisabled = function ( href ) {
			return $( '<a>' ).attr( 'href', href ).on( 'click', function ( e ) {
				if ( e.button === 0 ) {
					return false;
				}
			} );
		};
		this.openAndCloseOptionIndex = 2;
		this.closeRegistrationOption = new OO.ui.MenuOptionWidget( {
			$element: getLinkWithLeftClickDisabled( editHref ),
			data: {
				name: 'close',
				href: editHref
			},
			label: mw.msg( 'campaignevents-eventslist-menu-close' )
		} );
		this.openRegistrationOption = new OO.ui.MenuOptionWidget( {
			$element: getLinkWithLeftClickDisabled( editHref ),
			data: {
				name: 'open',
				href: editHref
			},
			label: mw.msg( 'campaignevents-eventslist-menu-open' )
		} );

		config = $.extend(
			{
				icon: 'ellipsis',
				framed: false,
				menu: {
					horizontalPosition: 'end',
					items: [
						// Below we use <a> elements for accessibility, so it's possible to
						// right-click, middle-click, see preview etc. We still need an event
						// listener for left-clicks.
						new OO.ui.MenuOptionWidget( {
							$element: getLinkWithLeftClickDisabled( editHref ),
							data: {
								name: 'edit',
								href: editHref
							},
							label: mw.msg( 'campaignevents-eventslist-menu-edit' )
						} ),
						new OO.ui.MenuOptionWidget( {
							$element: getLinkWithLeftClickDisabled( config.eventPageURL ),
							data: {
								name: 'eventpage',
								href: config.eventPageURL
							},
							label: mw.msg( 'campaignevents-eventslist-menu-view-eventpage' )
						} ),
						this.isClosed ? this.openRegistrationOption : this.closeRegistrationOption,
						new OO.ui.MenuOptionWidget( {
							$element: getLinkWithLeftClickDisabled( deleteHref ),
							data: {
								name: 'delete',
								href: deleteHref
							},
							label: mw.msg( 'campaignevents-eventslist-menu-delete' )
						} )
					]
				}
			},
			config
		);
		EventKebabMenu.super.call( this, config );

		this.getMenu().on( 'choose', EventKebabMenu.prototype.onChooseOption.bind( this ) );
	}

	OO.inheritClass( EventKebabMenu, OO.ui.ButtonMenuSelectWidget );

	EventKebabMenu.prototype.onChooseOption = function ( item ) {
		var data = item.getData(),
			that = this;
		switch ( data.name ) {
			case 'edit':
			case 'eventpage':
				window.location.assign( data.href );
				break;
			case 'close':
				this.changeRegistrationStatus( 'closed' )
					.done( function () {
						mw.notify(
							mw.message( 'campaignevents-eventslist-menu-close-success', that.eventName ),
							{ type: 'success' }
						);
						that.getMenu()
							.removeItems( [ that.closeRegistrationOption ] )
							.addItems(
								[ that.openRegistrationOption ],
								that.openAndCloseOptionIndex
							);
					} )
					.fail( function () {
						// Fall back to the special page.
						window.location.assign( data.href );
					} );
				break;
			case 'open':
				this.changeRegistrationStatus( 'open' )
					.done( function () {
						mw.notify(
							mw.message( 'campaignevents-eventslist-menu-open-success', that.eventName ),
							{ type: 'success' }
						);
						that.getMenu()
							.removeItems( [ that.openRegistrationOption ] )
							.addItems(
								[ that.closeRegistrationOption ],
								that.openAndCloseOptionIndex
							);
					} )
					.fail( function () {
						// Fall back to the special page.
						window.location.assign( data.href );
					} );
				break;
			case 'delete':
				this.maybeDeleteRegistration()
					.done( function ( deleteData ) {
						if ( deleteData && deleteData.deleted ) {
							that.emit( 'deleted', that.eventName );
						}
					} )
					.fail( function () {
						// Fall back to the special page.
						window.location.assign( data.href );
					} );
				break;
		}
	};

	/**
	 * @param {string} status 'open' or 'closed'
	 * @return {jQuery.Promise}
	 */
	EventKebabMenu.prototype.changeRegistrationStatus = function ( status ) {
		var eventID = this.eventID;
		return new mw.Rest().get( '/campaignevents/v0/event_registration/' + eventID )
			.then(
				function ( data ) {
					var eventPageWiki = data.event_page_wiki;
					if ( eventPageWiki !== mw.config.get( 'wgWikiID' ) ) {
						// Can't edit registrations whose event page is on another wiki, see T311582
						// TODO Remove this limitation
						return $.Deferred().reject();
					}

					var trackingToolID, trackingToolEventID = null;
					if ( data.tracking_tools.length === 1 ) {
						trackingToolID = data.tracking_tools[ 0 ].tool_id;
						trackingToolEventID = data.tracking_tools[ 0 ].tool_event_id;
					} else if ( data.tracking_tools.length > 1 ) {
						throw new Error( 'Expecting at most one tracking tool' );
					}
					return new mw.Rest().put(
						'/campaignevents/v0/event_registration/' + eventID,
						{
							/* eslint-disable camelcase */
							token: mw.user.tokens.get( 'csrfToken' ),
							event_page: data.event_page,
							status: status,
							chat_url: data.chat_url,
							tracking_tool_id: trackingToolID,
							tracking_tool_event_id: trackingToolEventID,
							timezone: data.timezone,
							start_time: data.start_time,
							end_time: data.end_time,
							online_meeting: data.online_meeting,
							inperson_meeting: data.inperson_meeting,
							meeting_url: data.meeting_url,
							meeting_country: data.meeting_country,
							meeting_address: data.meeting_address
							/* eslint-enable camelcase */
						}
					)
						.fail( function ( _errCode, errData ) {
							mw.log.error( errData.xhr.responseText );
						} );
				},
				function ( _errCode, errData ) {
					mw.log.error( errData.xhr.responseText );
				}
			);
	};

	/**
	 * @return {jQuery.Promise} Resolved with { deleted: true } as value if the event was deleted.
	 */
	EventKebabMenu.prototype.maybeDeleteRegistration = function () {
		var confirmDelDialog = new ConfirmEventDeletionDialog( { eventName: this.eventName } ),
			eventID = this.eventID;

		this.windowManager.addWindows( [ confirmDelDialog ] );

		return this.windowManager.openWindow( confirmDelDialog ).closed.then( function ( data ) {
			if ( data && data.action === 'confirm' ) {
				return new mw.Rest().delete(
					'/campaignevents/v0/event_registration/' + eventID,
					{ token: mw.user.tokens.get( 'csrfToken' ) }
				)
					.then( function () {
						return $.Deferred().resolve( { deleted: true } );
					} )
					.fail( function ( _errCode, errData ) {
						mw.log.error( errData.xhr.responseText );
					} );
			}
		} );
	};

	module.exports = EventKebabMenu;
}() );
