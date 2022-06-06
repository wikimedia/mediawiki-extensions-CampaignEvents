( function () {
	'use strict';

	var RemoveParticipantDialog = require( './RemoveParticipantDialog.js' );
	function ParticipantsManager() {
		/* eslint-disable no-jquery/no-global-selector */
		this.$selectAllParticipantsLabel = $(
			'.ext-campaignevents-details-select-all-users-div label.oo-ui-labelElement-label'
		);
		this.$selectAllParticipantsCheckbox = $(
			'#event-details-select-all-participant-checkbox'
		);

		this.$participantCheckboxes = $( '.ext-campaignevents-event-details-participants-checkboxes' );
		this.participantCheckboxes = [];
		this.selectedParticipantsAmount = 0;

		this.$removeParticipantsButton = $( '#ext-campaignevents-event-details-remove-participant-button' );
		this.removeParticipantDialog = new RemoveParticipantDialog( {
			classes: [ 'ext-campaignevents-details-remove-participant-dialog' ]
		} );
		this.windowManager = new OO.ui.WindowManager();
		this.installEventListeners();
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

		if ( this.$participantCheckboxes.length ) {
			this.$participantCheckboxes.each( function () {
				thisClass.participantCheckboxes.push(
					OO.ui.CheckboxInputWidget.static.infuse(
						$( this )
					).on( 'change', function ( selected ) {
						if ( selected ) {
							thisClass.onSelectParticipant();
							return;
						}
						thisClass.onDeselectParticipant();
					} )
				);
			} );
		}

		if ( this.$removeParticipantsButton.length ) {
			this.removeParticipantsButton = OO.ui.ButtonWidget.static.infuse(
				this.$removeParticipantsButton
			);
			this.removeParticipantsButton.on( 'click', function () {
				thisClass.windowManager.openWindow(
					thisClass.removeParticipantDialog,
					{ selectedParticipantsAmount: thisClass.selectedParticipantsAmount }
				);
			} );
			$( document.body ).append( this.windowManager.$element );
			this.windowManager.addWindows( [ this.removeParticipantDialog ] );
		}
	};

	ParticipantsManager.prototype.onSelectAll = function () {
		this.removeParticipantsButton.$element.show();
		this.selectedParticipantsAmount = this.participantCheckboxes.length;
		this.$selectAllParticipantsLabel.text(
			mw.message( 'campaignevents-event-details-all-selected' ).text()
		);
	};

	ParticipantsManager.prototype.onDeselectAll = function () {
		this.selectedParticipantsAmount = 0;
		this.$selectAllParticipantsLabel.text(
			mw.message( 'campaignevents-event-details-select-all' ).text()
		);
		this.removeParticipantsButton.$element.hide();
	};

	ParticipantsManager.prototype.onSelectParticipant = function () {
		this.selectedParticipantsAmount += 1;
		this.$selectAllParticipantsLabel.text(
			mw.message(
				'campaignevents-event-details-participants-checkboxes-selected',
				mw.language.convertNumber( this.selectedParticipantsAmount )
			).text()
		);
		this.removeParticipantsButton.$element.show();
	};

	ParticipantsManager.prototype.onDeselectParticipant = function () {
		this.selectAllParticipantsCheckbox.setSelected( false, true );
		this.selectedParticipantsAmount -= 1;
		if ( this.selectedParticipantsAmount === 0 ) {
			this.removeParticipantsButton.$element.hide();
			this.$selectAllParticipantsLabel.text(
				mw.message( 'campaignevents-event-details-select-all' ).text()
			);
			return;
		}

		this.$selectAllParticipantsLabel.text(
			mw.message(
				'campaignevents-event-details-participants-checkboxes-selected',
				mw.language.convertNumber( this.selectedParticipantsAmount )
			).text()
		);
	};

	module.exports = new ParticipantsManager();
}() );
