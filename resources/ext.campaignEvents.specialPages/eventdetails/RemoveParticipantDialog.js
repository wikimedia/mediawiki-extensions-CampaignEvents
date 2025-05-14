( function () {
	'use strict';

	/**
	 * @class
	 * @extends OO.ui.MessageDialog
	 *
	 * @param {Object} config
	 * @constructor
	 */
	function RemoveParticipantDialog( config ) {
		RemoveParticipantDialog.super.call( this, config );
	}

	OO.inheritClass( RemoveParticipantDialog, OO.ui.MessageDialog );
	RemoveParticipantDialog.static.name = 'RemoveParticipantDialog';
	RemoveParticipantDialog.static.size = 'small';
	RemoveParticipantDialog.static.actions = [
		{
			flags: [ 'safe' ],
			label: mw.msg( 'campaignevents-event-details-remove-participant-cancel-btn' ),
			action: 'cancel'
		},
		{
			flags: [ 'primary', 'destructive' ],
			label: mw.msg( 'campaignevents-event-details-remove-participant-remove-btn' ),
			action: 'remove'
		}
	];

	RemoveParticipantDialog.prototype.getSetupProcess = function ( data ) {
		data = Object.assign(
			{
				title: mw.message(
					'campaignevents-event-details-remove-participant-confirmation-title',
					mw.language.convertNumber(
						data.selectedParticipantsAmount
					)
				).text(),
				message: mw.message(
					'campaignevents-event-details-remove-participant-confirmation-msg',
					mw.language.convertNumber(
						data.selectedParticipantsAmount
					)
				).text()
			},
			data
		);
		return RemoveParticipantDialog.super.prototype.getSetupProcess.call( this, data );
	};

	module.exports = RemoveParticipantDialog;
}() );
