( function () {
	'use strict';

	const ConfirmEventDeletionDialog = require( './ConfirmEventDeletionDialog.js' );

	/**
	 * Widgets for the kebab menu used in EventsTablePager.
	 *
	 * @extends OO.ui.ButtonMenuSelectWidget
	 *
	 * @constructor
	 * @param {Object} [config] Configuration options
	 * @param {number} [config.eventID] ID of the event that the menu is used for.
	 * @param {string} [config.eventName] Name of the event that the menu is used for.
	 * @param {string} [config.eventPageURL] URL of the event page (could be on another wiki).
	 * @param {Object} [config.windowManager] WindowManager object shared by all menus.
	 */
	function EventKebabMenu( config ) {
		this.eventID = config.eventID;
		this.eventName = config.eventName;
		this.windowManager = config.windowManager;
		this.isLocalWiki = config.isLocalWiki;

		const editHref = mw.util.getUrl( 'Special:EditEventRegistration/' + this.eventID ),
			deleteHref = mw.util.getUrl( 'Special:DeleteEventRegistration/' + this.eventID );

		// Note: all options are actually <a> elements, so that it's possible to middle- or
		// right-click them and copy the link or open it in a new tab.
		const getLinkWithLeftClickDisabled = function ( href ) {
			return $( '<a>' ).attr( 'href', href ).on( 'click', ( e ) => {
				if ( e.button === 0 ) {
					return false;
				}
			} );
		};

		config = Object.assign(
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
		const data = item.getData(),
			that = this;
		switch ( data.name ) {
			case 'edit':
			case 'eventpage':
				window.location.assign( data.href );
				break;
			case 'delete':
				if ( !this.isLocalWiki ) {
					window.location.assign( data.href );
					break;
				}
				this.maybeDeleteRegistration()
					.then(
						( deleteData ) => {
							if ( deleteData && deleteData.deleted ) {
								that.emit( 'deleted', that.eventName );
							}
						},
						() => {
							// Fall back to the special page.
							window.location.assign( data.href );
						}
					);
				break;
		}
	};

	/**
	 * @return {jQuery.Promise} Resolved with { deleted: true } as value if the event was deleted.
	 */
	EventKebabMenu.prototype.maybeDeleteRegistration = function () {
		const confirmDelDialog = new ConfirmEventDeletionDialog( { eventName: this.eventName } ),
			eventID = this.eventID;

		this.windowManager.addWindows( [ confirmDelDialog ] );
		return this.windowManager.openWindow( confirmDelDialog ).closed.then( ( data ) => {
			if ( data && data.action === 'confirm' ) {
				return new mw.Rest().delete(
					'/campaignevents/v0/event_registration/' + eventID,
					{ token: mw.user.tokens.get( 'csrfToken' ) }
				)
					.then(
						() => $.Deferred().resolve( { deleted: true } ),
						( errCode, errData ) => {
							mw.log.error( errData.xhr.responseText );
							throw errCode;
						}
					);
			}
		} );
	};

	module.exports = EventKebabMenu;
}() );
