( function () {
	'use strict';

	/**
	 * Dialog which shows additional information about an event
	 *
	 * @param {Object} config Configuration options
	 * @extends OO.ui.ProcessDialog
	 * @constructor
	 */
	function EventDetailsDialog( config ) {
		EventDetailsDialog.super.call( this, config );
		this.$element.addClass( 'ext-campaignevents-eventpage-detailsdialog' );
	}

	OO.inheritClass( EventDetailsDialog, OO.ui.ProcessDialog );

	EventDetailsDialog.static.name = 'campaignEventsDetailsDialog';
	EventDetailsDialog.static.size = 'large';
	EventDetailsDialog.static.title = mw.msg( 'campaignevents-eventpage-dialog-title' );
	EventDetailsDialog.static.actions = [
		{
			action: 'cancel',
			label: mw.msg( 'campaignevents-eventpage-dialog-action-close' ),
			flags: [ 'safe', 'close' ]
		}
	];

	EventDetailsDialog.prototype.initialize = function () {
		EventDetailsDialog.super.prototype.initialize.apply( this, arguments );
		this.content = new OO.ui.PanelLayout( {
			padded: true,
			expanded: false
		} );

		/* eslint-disable no-jquery/no-global-selector */
		this.content.$element.append( $( '#ext-campaignEvents-detailsDialog-content' ) );
		this.$body.append( this.content.$element );
		this.$foot.append(
			$( '.ext-campaignevents-eventpage-manage-btn,.ext-campaignevents-eventpage-register-btn,.ext-campaignevents-eventpage-unregister-layout' ).clone( true )
		);
		/* eslint-enable no-jquery/no-global-selector */
	};

	EventDetailsDialog.prototype.getBodyHeight = function () {
		return this.content.$element.outerHeight( true );
	};

	EventDetailsDialog.prototype.getActionProcess = function ( action ) {
		return EventDetailsDialog.super.prototype.getActionProcess.call( this, action )
			.next( function () {
				this.close();
			}, this );
	};

	module.exports = EventDetailsDialog;
}() );
