( function () {
	'use strict';

	/**
	 * Dialog shown after an event page is created that prompts the organizer to enable registration.
	 *
	 * @param {Object} config Configuration options
	 * @extends OO.ui.MessageDialog
	 * @constructor
	 */
	function EnableRegistrationDialog( config ) {
		EnableRegistrationDialog.super.call( this, config );
	}
	OO.inheritClass( EnableRegistrationDialog, OO.ui.MessageDialog );

	EnableRegistrationDialog.static.name = 'campaignEventsEnableRegistrationDialog';
	EnableRegistrationDialog.static.size = 'small';
	EnableRegistrationDialog.static.title = mw.msg( 'campaignevents-eventpage-enable-registration-dialog-title' );
	EnableRegistrationDialog.static.message = mw.msg( 'campaignevents-eventpage-enable-registration-dialog-body' );
	EnableRegistrationDialog.static.actions = [
		{
			flags: 'safe',
			label: mw.msg( 'campaignevents-eventpage-enable-registration-dialog-dismiss' ),
			action: 'cancel'
		},
		{
			flags: [ 'primary', 'progressive' ],
			label: mw.msg( 'campaignevents-eventpage-enable-registration-dialog-confirm' ),
			action: 'confirm'
		}
	];

	module.exports = EnableRegistrationDialog;
}() );
