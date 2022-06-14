( function () {
	'use strict';

	/**
	 * Dialog used to let the user confirm that they want to unregister
	 *
	 * @param {Object} config Configuration options
	 * @extends OO.ui.MessageDialog
	 * @constructor
	 */
	function ConfirmUnregistrationDialog( config ) {
		ConfirmUnregistrationDialog.super.call( this, config );
	}
	OO.inheritClass( ConfirmUnregistrationDialog, OO.ui.MessageDialog );

	ConfirmUnregistrationDialog.static.name = 'campaignEventsConfirmUnregistrationDialog';
	ConfirmUnregistrationDialog.static.size = 'small';
	ConfirmUnregistrationDialog.static.title = mw.msg( 'campaignevents-eventpage-unregister-confirmation-title' );
	ConfirmUnregistrationDialog.static.message = mw.msg( 'campaignevents-eventpage-unregister-confirmation-body' );
	ConfirmUnregistrationDialog.static.actions = [
		{
			flags: 'safe',
			label: mw.msg( 'campaignevents-eventpage-unregister-confirmation-dismiss' ),
			action: 'cancel'
		},
		{
			flags: [ 'primary', 'destructive' ],
			label: mw.msg( 'campaignevents-eventpage-unregister-confirmation-confirm' ),
			action: 'confirm'
		}
	];

	module.exports = ConfirmUnregistrationDialog;
}() );
