( function () {
	'use strict';

	/**
	 * Dialog used to let the user confirm that they want to delete a registration.
	 *
	 * @param {Object} config Configuration options
	 * @param {string} config.eventName Name of the event being deleted
	 * @extends OO.ui.MessageDialog
	 * @constructor
	 */
	function ConfirmEventDeletionDialog( config ) {
		this.eventName = config.eventName;
		ConfirmEventDeletionDialog.super.call( this, config );
		this.$element.addClass( 'ext-campaignevents-myevents-delete-confirm-dialog' );
	}
	OO.inheritClass( ConfirmEventDeletionDialog, OO.ui.MessageDialog );

	ConfirmEventDeletionDialog.static.name = 'campaignEventsConfirmEventDeletionDialog';
	ConfirmEventDeletionDialog.static.size = 'small';
	ConfirmEventDeletionDialog.static.message = mw.msg( 'campaignevents-eventslist-delete-dialog-body' );
	ConfirmEventDeletionDialog.static.actions = [
		{
			flags: 'safe',
			label: mw.msg( 'campaignevents-eventslist-delete-dialog-cancel' ),
			action: 'cancel'
		},
		{
			flags: [ 'primary', 'destructive' ],
			label: mw.msg( 'campaignevents-eventslist-delete-dialog-delete' ),
			action: 'confirm'
		}
	];

	ConfirmEventDeletionDialog.prototype.getSetupProcess = function ( data ) {
		data = $.extend(
			{ title: mw.message( 'campaignevents-eventslist-delete-dialog-title', this.eventName ).parseDom() },
			data
		);
		return ConfirmEventDeletionDialog.super.prototype.getSetupProcess.call( this, data );
	};

	module.exports = ConfirmEventDeletionDialog;
}() );
