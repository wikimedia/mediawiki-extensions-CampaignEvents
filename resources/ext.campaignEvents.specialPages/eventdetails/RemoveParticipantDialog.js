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

	RemoveParticipantDialog.prototype.getSetupProcess = function ( data ) {
		const formattedSelectedParticipantsAmount = mw.language.convertNumber(
			data.selectedParticipantsAmount
		);
		const actions = [
			{
				flags: [ 'safe' ],
				label: mw.msg( 'campaignevents-event-details-remove-participant-cancel-btn' ),
				action: 'cancel'
			},
			{
				flags: [ 'primary', 'destructive' ],
				label: mw.msg(
					'campaignevents-event-details-remove-participant-remove-btn',
					formattedSelectedParticipantsAmount
				),
				action: 'remove'
			}
		];

		data = Object.assign(
			{
				title: mw.message(
					'campaignevents-event-details-remove-participant-confirmation-title',
					formattedSelectedParticipantsAmount
				).text(),
				message: mw.message(
					'campaignevents-event-details-remove-participant-confirmation-msg',
					formattedSelectedParticipantsAmount
				).text(),
				actions: actions
			},
			data
		);

		return RemoveParticipantDialog.super.prototype.getSetupProcess.call( this, data );
	};

	module.exports = RemoveParticipantDialog;
}() );
