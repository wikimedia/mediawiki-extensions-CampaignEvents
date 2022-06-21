( function () {
	'use strict';

	/**
	 * Dialog used to display a legal notice to the user before registering.
	 *
	 * @param {Object} config Configuration options
	 * @param {string} config.policyMsg Policy acknowledgement message
	 * @extends OO.ui.MessageDialog
	 * @constructor
	 */
	function PolicyAcknowledgementDialog( config ) {
		PolicyAcknowledgementDialog.super.call( this, config );
		this.policyMsg = config.policyMsg;
	}
	OO.inheritClass( PolicyAcknowledgementDialog, OO.ui.MessageDialog );

	PolicyAcknowledgementDialog.static.name = 'campaignEventsPolicyAcknowledgementDialog';
	PolicyAcknowledgementDialog.static.size = 'small';
	PolicyAcknowledgementDialog.static.actions = [
		{
			flags: 'safe',
			label: mw.msg( 'campaignevents-eventpage-register-confirmation-cancel' ),
			action: 'cancel'
		},
		{
			flags: [ 'primary', 'progressive' ],
			label: mw.msg( 'campaignevents-eventpage-register-confirmation-confirm' ),
			action: 'confirm'
		}
	];

	PolicyAcknowledgementDialog.prototype.getSetupProcess = function ( data ) {
		data = $.extend(
			{ message: $( '<span>' ).html( this.policyMsg ) },
			data
		);
		return PolicyAcknowledgementDialog.super.prototype.getSetupProcess.call( this, data );
	};

	module.exports = PolicyAcknowledgementDialog;
}() );
