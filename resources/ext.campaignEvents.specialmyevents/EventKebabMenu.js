( function () {
	'use strict';

	/**
	 * Widgets for the kebab menu used in EventsPager.
	 *
	 * @extends OO.ui.ButtonMenuSelectWidget
	 *
	 * @constructor
	 * @param {Object} [config] Configuration options
	 * @param {number} [config.eventID] ID of the event that the menu is used for.
	 */
	function EventKebabMenu( config ) {
		this.eventID = config.eventID;

		var editHref = mw.util.getUrl( 'Special:EditEventRegistration/' + this.eventID );

		config = $.extend(
			{
				icon: 'ellipsis',
				framed: false,
				menu: {
					items: [
						// Below we use <a> elements for accessibility, so it's possible to
						// right-click, middle-click, see preview etc. We still need an event
						// listener for left-clicks.
						new OO.ui.MenuOptionWidget( {
							$element: $( '<a>' ).attr( 'href', editHref ),
							data: {
								name: 'edit',
								href: editHref
							},
							label: mw.msg( 'campaignevents-eventslist-menu-edit' )
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
		var data = item.getData();
		switch ( data.name ) {
			case 'edit':
				window.location.assign( data.href );
				break;
		}
	};

	module.exports = EventKebabMenu;
}() );
