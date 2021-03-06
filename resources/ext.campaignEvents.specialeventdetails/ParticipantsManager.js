( function () {
	'use strict';

	var RemoveParticipantDialog = require( './RemoveParticipantDialog.js' ),
		ScrollDownObserver = require( './ScrollDownObserver.js' );
	function ParticipantsManager() {
		this.registrationID = mw.config.get( 'wgCampaignEventsEventID' );
		this.showParticipantCheckboxes = mw.config.get( 'wgCampaignEventsShowParticipantCheckboxes' );
		this.lastParticipantID = mw.config.get( 'wgCampaignEventsLastParticipantID' );
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
		this.$userRowsContainer = $( '.ext-campaignevents-details-users-rows-container' );
		this.$removeParticipantsButton = $( '#ext-campaignevents-event-details-remove-participant-button' );
		this.removeParticipantDialog = new RemoveParticipantDialog( {
			classes: [ 'ext-campaignevents-details-remove-participant-dialog' ]
		} );
		this.windowManager = new OO.ui.WindowManager();
		this.$usersContainer = $( '.ext-campaignevents-details-users-container' );

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
					thisClass.onParticipantCheckboxChange( selected, this );
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

		this.scrollDownObserver = new ScrollDownObserver(
			this.$usersContainer[ 0 ]
		);
		this.$usersContainer.on( 'scroll', function () {
			if ( thisClass.scrollDownObserver.scrolledToBottom() ) {
				thisClass.loadMoreParticipants();
			}
		} );
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

	ParticipantsManager.prototype.onParticipantCheckboxChange = function ( selected, el ) {
		if ( selected ) {
			this.onSelectParticipant( el );
			return;
		}
		this.onDeselectParticipant( el );
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
					thisClass.loadMoreParticipants();
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
				thisClass.scrollDownObserver.reset();
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

	ParticipantsManager.prototype.loadMoreParticipants = function () {
		var thisClass = this;

		new mw.Rest().get(
			'/campaignevents/v0/event_registration/' + thisClass.registrationID + '/participants',
			{
				// eslint-disable-next-line camelcase
				last_participant_id: thisClass.lastParticipantID
			}
		)
			.done( function ( data ) {
				if ( !data.length ) {
					return;
				}
				thisClass.lastParticipantID = data[ data.length - 1 ].participant_id;
				var allSelected = thisClass.selectAllParticipantsCheckbox ?
					thisClass.selectAllParticipantsCheckbox.isSelected() :
					false;
				for ( var i = 0; i < data.length; i++ ) {
					var items = [];
					if ( thisClass.showParticipantCheckboxes ) {
						var newParticipantCheckbox =
							new OO.ui.CheckboxInputWidget( {
								selected: allSelected,
								name: 'event-details-participants-checkboxes',
								value: data[ i ].user_id,
								classes: [
									'ext-campaignevents-event-details-participants-checkboxes'
								]
							} );

						newParticipantCheckbox.on( 'change', function ( selected ) {
							thisClass.onParticipantCheckboxChange( selected, this );
						}, [], newParticipantCheckbox );

						thisClass.participantCheckboxes.push( newParticipantCheckbox );
						if ( allSelected ) {
							thisClass.selectedParticipantIDs.push( String( data[ i ].user_id ) );
						}
						items.push( newParticipantCheckbox );
					}

					items.push(
						new OO.ui.Element( {
							$element: $( '<span>' ),
							text: data[ i ].user_name,
							classes: [ 'ext-campaignevents-details-participant-username' ]
						} )
					);
					items.push(
						// TO DO T312910
						new OO.ui.Element( {
							$element: $( '<span>' ),
							text: data[ i ].user_registered_at,
							classes: [ 'ext-campaignevents-details-participant-registered-at' ]
						} )
					);

					var layout = new OO.ui.Element( {
						classes: [ 'ext-campaignevents-details-user-div' ],
						content: items
					} );

					thisClass.$userRowsContainer.append( layout.$element );
				}

				thisClass.scrollDownObserver.reset();
			} )
			.fail( function ( _err, errData ) {
				mw.log.error( errData.xhr.responseText || 'Unknown error' );
			} );
	};

	module.exports = new ParticipantsManager();
}() );
