( function () {
	'use strict';

	/**
	 * Widget that implements the "manage registration" menu button on event pages.
	 *
	 * @extends OO.ui.ButtonMenuSelectWidget
	 *
	 * @param {number} eventID
	 * @param {Object} [config] Configuration options
	 * @constructor
	 */
	function ManageRegistrationWidget( eventID, config ) {
		const getLinkWithLeftClickDisabled = function ( href ) {
			return $( '<a>' ).attr( 'href', href ).on( 'click', ( e ) => {
				if ( e.button === 0 ) {
					return false;
				}
			} );
		};

		config = Object.assign(
			{
				label: mw.msg( 'campaignevents-eventpage-btn-manage-registration' ),
				indicator: 'down',
				flags: [ 'progressive' ],
				menu: {
					horizontalPosition: 'start',
					items: [
						// Below we use <a> elements for accessibility, so it's possible to
						// right-click, middle-click, see preview etc. We still need an event
						// listener for left-clicks.
						new OO.ui.MenuOptionWidget( {
							$element: getLinkWithLeftClickDisabled( mw.util.getUrl( 'Special:RegisterForEvent/' + eventID ) ),
							data: 'edit',
							label: mw.msg( 'campaignevents-eventpage-btn-edit' )
						} ),
						new OO.ui.MenuOptionWidget( {
							$element: getLinkWithLeftClickDisabled( mw.util.getUrl( 'Special:CancelEventRegistration/' + eventID ) ),
							data: 'cancel',
							label: mw.msg( 'campaignevents-eventpage-btn-cancel' )
						} )
					]
				}
			},
			config
		);

		ManageRegistrationWidget.super.call( this, config );
		this.getMenu().on( 'choose', ManageRegistrationWidget.prototype.onChooseOption.bind( this ) );
		this.$element.addClass( 'ext-campaignevents-eventpage-manage-registration-menu' );
	}

	OO.inheritClass( ManageRegistrationWidget, OO.ui.ButtonMenuSelectWidget );

	ManageRegistrationWidget.prototype.onChooseOption = function ( option ) {
		const data = option.getData();

		if ( data === 'edit' ) {
			this.emit( 'editregistration' );
		} else if ( data === 'cancel' ) {
			this.emit( 'cancelregistration' );
		}
	};

	module.exports = ManageRegistrationWidget;
}() );
