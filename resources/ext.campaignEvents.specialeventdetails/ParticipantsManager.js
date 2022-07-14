( function () {
	'use strict';

	var RemoveParticipantDialog = require( './RemoveParticipantDialog.js' );
	function ParticipantsManager() {
		this.registrationID = mw.config.get( 'wgCampaignEventsEventID' );
		/* eslint-disable no-jquery/no-global-selector */
		this.$selectAllParticipantsLabel = $(
			'.ext-campaignevents-details-select-all-users-div label.oo-ui-labelElement-label'
		);
		this.$selectAllParticipantsCheckbox = $(
			'#event-details-select-all-participant-checkbox'
		);
		this.$participantsTitle = $( '.ext-campaignevents-details-participants-header' );

		this.participantCheckboxes = [];
		this.selectedParticipantIDs = [];
		this.participantsTotal = mw.config.get( 'wgCampaignEventsEventDetailsParticipantsTotal' );

		this.$noParticipantsStateElement = $( '.ext-campaignevents-details-no-participants-state' );
		this.$searchParticipantElement = $( '.ext-campaignevents-details-participants-search-div' );
		this.$selectAllParticipantElement = $( '.ext-campaignevents-details-select-all-users-div' );

		this.$removeParticipantsButton = $( '#ext-campaignevents-event-details-remove-participant-button' );
		this.removeParticipantDialog = new RemoveParticipantDialog( {
			classes: [ 'ext-campaignevents-details-remove-participant-dialog' ]
		} );
		this.windowManager = new OO.ui.WindowManager();
		this.installEventListeners();
		/* eslint-enable no-jquery/no-global-selector */
	}

	ParticipantsManager.prototype.installEventListeners = function () {
		var thisClass = this;
		if ( this.$selectAllParticipantsCheckbox.length ) {
			this.selectAllParticipantsCheckbox = OO.ui.CheckboxInputWidget.static.infuse(
				this.$selectAllParticipantsCheckbox
			);
			this.selectAllParticipantsCheckbox.on( 'change', function ( selected ) {
				for ( var i = 0; i < thisClass.participantCheckboxes.length; i++ ) {
					thisClass.participantCheckboxes[ i ].setSelected( selected, true );
				}
				if ( selected ) {
					thisClass.onSelectAll();
					return;
				}
				thisClass.onDeselectAll();
			} );
		}

		// eslint-disable-next-line no-jquery/no-global-selector
		var $participantCheckboxes = $( '.ext-campaignevents-event-details-participants-checkboxes' );
		if ( $participantCheckboxes.length ) {
			$participantCheckboxes.each( function () {
				var infusedCheckbox = OO.ui.CheckboxInputWidget.static.infuse( $( this ) );
				infusedCheckbox.on( 'change', function ( selected ) {
					if ( selected ) {
						thisClass.onSelectParticipant( this );
						return;
					}
					thisClass.onDeselectParticipant( this );
				}, [], infusedCheckbox );
				thisClass.participantCheckboxes.push( infusedCheckbox );
			} );
		}

		if ( this.$removeParticipantsButton.length ) {
			this.removeParticipantsButton = OO.ui.ButtonWidget.static.infuse(
				this.$removeParticipantsButton
			);
			this.removeParticipantsButton.on( 'click', function () {
				thisClass.windowManager.openWindow(
					thisClass.removeParticipantDialog,
					{ selectedParticipantsAmount: thisClass.selectedParticipantIDs.length }
				).closed.then( function ( data ) {
					if ( data && data.action === 'remove' ) {
						thisClass.onConfirmRemoval();
					}
				} );
			} );
			$( document.body ).append( this.windowManager.$element );
			this.windowManager.addWindows( [ this.removeParticipantDialog ] );
		}
	};

	ParticipantsManager.prototype.onSelectAll = function () {
		this.selectedParticipantIDs = this.participantCheckboxes.map( function ( el ) {
			return el.getValue();
		} );
		this.$selectAllParticipantsLabel.text(
			mw.message( 'campaignevents-event-details-all-selected' ).text()
		);
		this.removeParticipantsButton.$element.show();
	};

	ParticipantsManager.prototype.onDeselectAll = function () {
		this.selectedParticipantIDs = [];
		this.$selectAllParticipantsLabel.text(
			mw.message( 'campaignevents-event-details-select-all' ).text()
		);
		this.removeParticipantsButton.$element.hide();
	};

	ParticipantsManager.prototype.onSelectParticipant = function ( checkbox ) {
		this.selectedParticipantIDs.push( checkbox.getValue() );
		this.$selectAllParticipantsLabel.text(
			mw.message(
				'campaignevents-event-details-participants-checkboxes-selected',
				mw.language.convertNumber( this.selectedParticipantIDs.length )
			).text()
		);
		this.removeParticipantsButton.$element.show();
	};

	ParticipantsManager.prototype.onDeselectParticipant = function ( checkbox ) {
		this.selectedParticipantIDs.splice(
			this.selectedParticipantIDs.indexOf( checkbox.getValue() ), 1
		);
		this.selectAllParticipantsCheckbox.setSelected( false, true );

		if ( this.selectedParticipantIDs.length === 0 ) {
			this.removeParticipantsButton.$element.hide();
			this.$selectAllParticipantsLabel.text(
				mw.message( 'campaignevents-event-details-select-all' ).text()
			);
		} else {
			this.$selectAllParticipantsLabel.text(
				mw.message(
					'campaignevents-event-details-participants-checkboxes-selected',
					mw.language.convertNumber( this.selectedParticipantIDs.length )
				).text()
			);
		}
	};

	/**
	 * @param {string} type
	 * @param {string} message
	 */
	ParticipantsManager.prototype.showNotification = function ( type, message ) {
		mw.notify(
			new OO.ui.MessageWidget( {
				type: type,
				inline: true,
				label: message
			} ).$element[ 0 ],
			{ type: type }
		);
	};

	ParticipantsManager.prototype.onConfirmRemoval = function () {
		// TODO: This is bugged: if you have 30 participants, we only show 20; if the user select the 20 one by one
		// and remove them, the other 10 will not be loaded.
		var thisClass = this,
			removeAll = this.selectAllParticipantsCheckbox.isSelected(),
			numSelected = removeAll ? this.participantsTotal : thisClass.selectedParticipantIDs.length;

		new mw.Rest().delete(
			'/campaignevents/v0/event_registration/' + this.registrationID + '/participants',
			{
				token: mw.user.tokens.get( 'csrfToken' ),
				// eslint-disable-next-line camelcase
				user_ids: removeAll ? null : this.selectedParticipantIDs
			}
		)
			.done( function () {
				thisClass.participantCheckboxes = thisClass.participantCheckboxes.filter( function ( el ) {
					if ( el.isSelected() ) {
						$( el.$element ).closest( '.ext-campaignevents-details-user-div' ).remove();
						return false;
					} else {
						return el;
					}
				} );

				thisClass.selectAllParticipantsCheckbox.setSelected( false, true );
				thisClass.onDeselectAll();
				var succesMsg;
				if ( removeAll ) {
					thisClass.participantsTotal = 0;
					succesMsg = mw.message(
						'campaignevents-event-details-remove-all-participant-notification'
					).text();
				} else {
					thisClass.participantsTotal -= numSelected;
					succesMsg = mw.message(
						'campaignevents-event-details-remove-participant-notification',
						mw.language.convertNumber( numSelected )
					).text();
				}

				thisClass.$participantsTitle.text(
					mw.message(
						'campaignevents-event-details-header-participants',
						mw.language.convertNumber( thisClass.participantsTotal )
					)
				);
				if ( thisClass.participantsTotal === 0 ) {
					thisClass.$noParticipantsStateElement.show();
					thisClass.$searchParticipantElement.hide();
					thisClass.$selectAllParticipantElement.hide();
				}
				thisClass.selectedParticipantIDs = [];
				thisClass.showNotification( 'success', succesMsg );
			} )
			.fail( function ( _err, errData ) {
				var errorMsg = mw.message(
					'campaignevents-event-details-remove-participant-notification-error',
					mw.language.convertNumber( numSelected )
				).text();

				if (
					errData.xhr &&
					errData.xhr.responseJSON.messageTranslations
				) {
					errorMsg = errData.xhr.responseJSON.messageTranslations.en;
				}
				thisClass.showNotification( 'error', errorMsg );
			} );
	};

	module.exports = new ParticipantsManager();
}() );
