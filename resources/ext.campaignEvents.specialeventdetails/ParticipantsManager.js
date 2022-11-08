( function () {
	'use strict';

	var RemoveParticipantDialog = require( './RemoveParticipantDialog.js' ),
		ScrollDownObserver = require( './ScrollDownObserver.js' );
	function ParticipantsManager() {
		this.registrationID = mw.config.get( 'wgCampaignEventsEventID' );
		this.showParticipantCheckboxes = mw.config.get( 'wgCampaignEventsShowParticipantCheckboxes' );
		this.showPrivateParticipants = mw.config.get( 'wgCampaignEventsShowPrivateParticipants' );
		this.lastParticipantID = mw.config.get( 'wgCampaignEventsLastParticipantID' );
		this.curUserCentralID = mw.config.get( 'wgCampaignEventsCurUserCentralID' );
		/* eslint-disable no-jquery/no-global-selector */
		var $selectAllParticipantsField = $(
			'.ext-campaignevents-event-details-select-all-participant-checkbox-field'
		);
		if ( $selectAllParticipantsField.length ) {
			this.selectAllParticipantsField = OO.ui.FieldLayout.static.infuse(
				$selectAllParticipantsField
			);
			this.selectAllParticipantsCheckbox = this.selectAllParticipantsField.getField();
		}

		this.$participantsTitle = $( '.ext-campaignevents-details-participants-header' );

		this.participantCheckboxes = [];
		this.selectedParticipantIDs = [];
		this.participantsTotal = mw.config.get( 'wgCampaignEventsEventDetailsParticipantsTotal' );

		this.$noParticipantsStateElement = $( '.ext-campaignevents-details-no-participants-state' );
		this.$userActionsContainer = $( '.ext-campaignevents-details-user-actions-container' );
		this.$userRowsContainer = $( '.ext-campaignevents-details-users-rows-container' );
		this.$removeParticipantsButton = $( '#ext-campaignevents-event-details-remove-participant-button' );
		this.removeParticipantDialog = new RemoveParticipantDialog( {
			classes: [ 'ext-campaignevents-details-remove-participant-dialog' ]
		} );
		this.windowManager = new OO.ui.WindowManager();
		this.$usersContainer = $( '.ext-campaignevents-details-users-container' );
		this.$searchParticipantsContainer = $( '.ext-campaignevents-details-participants-search-container' );
		this.$searchParticipantsElement = $( '.ext-campaignevents-details-participants-search' );
		this.usernameFilter = null;

		this.installEventListeners();
		/* eslint-enable no-jquery/no-global-selector */
	}

	ParticipantsManager.prototype.installEventListeners = function () {
		var thisClass = this;
		if ( this.selectAllParticipantsCheckbox ) {
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
			this.$usersContainer[ 0 ],
			function () {
				if (
					thisClass.participantsTotal > thisClass.$userRowsContainer.children().length
				) {
					thisClass.loadMoreParticipants();
				}
			}
		);

		if ( this.$searchParticipantsElement.length ) {
			var searchParticipantsWidget = OO.ui.SearchInputWidget.static.infuse(
				this.$searchParticipantsElement
			);
			searchParticipantsWidget.on(
				'change',
				mw.util.debounce( function () {
					thisClass.deleteParticipantsList();

					if ( thisClass.selectAllParticipantsCheckbox ) {
						thisClass.selectAllParticipantsCheckbox.setSelected( false, true );
						thisClass.onDeselectAll();
					}
					var inputVal = searchParticipantsWidget.getValue();
					thisClass.usernameFilter = inputVal === '' ? null : inputVal;
					// Reset last participant so we can list them from the start if the
					// filter changes
					thisClass.lastParticipantID = null;
					thisClass.loadMoreParticipants();
				}, 500 )
			);
		}
	};

	ParticipantsManager.prototype.onSelectAll = function () {
		this.selectedParticipantIDs = this.participantCheckboxes.map( function ( el ) {
			return el.getValue();
		} );
		this.selectAllParticipantsField.setLabel(
			mw.message( 'campaignevents-event-details-all-selected' ).text()
		);
		if ( this.removeParticipantsButton ) {
			this.removeParticipantsButton.$element.show();
		}
	};

	ParticipantsManager.prototype.onDeselectAll = function () {
		this.selectedParticipantIDs = [];
		this.selectAllParticipantsField.setLabel(
			mw.message( 'campaignevents-event-details-select-all' ).text()
		);
		if ( this.removeParticipantsButton ) {
			this.removeParticipantsButton.$element.hide();
		}
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
		this.selectAllParticipantsField.setLabel(
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
			this.selectAllParticipantsField.setLabel(
				mw.message( 'campaignevents-event-details-select-all' ).text()
			);
		} else {
			this.selectAllParticipantsField.setLabel(
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
			numSelected = removeAll ? this.participantsTotal :
				thisClass.selectedParticipantIDs.length;

		new mw.Rest().delete(
			'/campaignevents/v0/event_registration/' + this.registrationID + '/participants',
			{
				token: mw.user.tokens.get( 'csrfToken' ),
				// eslint-disable-next-line camelcase
				user_ids: removeAll ? null : this.selectedParticipantIDs
			}
		)
			.done( function () {
				thisClass.participantCheckboxes =
					thisClass.participantCheckboxes.filter( function ( el ) {
						if ( el.isSelected() ) {
							el.$element
								.closest( '.ext-campaignevents-details-user-row' )
								.remove();
							return false;
						} else {
							return el;
						}
					} );

				thisClass.selectAllParticipantsCheckbox.setSelected( false, true );
				thisClass.onDeselectAll();
				var successMsg;
				if ( removeAll ) {
					thisClass.participantsTotal = 0;
					successMsg = mw.message(
						'campaignevents-event-details-remove-all-participant-notification'
					).text();
				} else {
					thisClass.participantsTotal -= numSelected;
					successMsg = mw.message(
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
					thisClass.$noParticipantsStateElement.removeClass( 'ext-campaignevents-details-hide-element' );
					thisClass.$searchParticipantsContainer.hide();
					thisClass.$userActionsContainer.hide();
					thisClass.$usersContainer.hide();
				}
				thisClass.scrollDownObserver.reset();
				thisClass.selectedParticipantIDs = [];
				thisClass.showNotification( 'success', successMsg );
			} )
			.fail( function ( _err, errData ) {
				var errorMsg;

				if ( errData.xhr.responseJSON.messageTranslations ) {
					errorMsg = errData.xhr.responseJSON.messageTranslations.en;
				} else {
					errorMsg = mw.message(
						'campaignevents-event-details-remove-participant-notification-error',
						mw.language.convertNumber( numSelected )
					).text();
				}
				thisClass.showNotification( 'error', errorMsg );
			} );
	};

	ParticipantsManager.prototype.deleteParticipantsList = function () {
		// eslint-disable-next-line no-jquery/no-global-selector
		$( '.ext-campaignevents-details-user-row' ).remove();
		this.participantCheckboxes = [];
		this.scrollDownObserver.reset();
	};

	ParticipantsManager.prototype.loadMoreParticipants = function () {
		var thisClass = this;

		/* eslint-disable camelcase */
		var params = {
			include_private: this.showPrivateParticipants
		};
		if ( thisClass.curUserCentralID !== null ) {
			params.exclude_user = thisClass.curUserCentralID;
		}
		if ( thisClass.lastParticipantID !== null ) {
			params.last_participant_id = thisClass.lastParticipantID;
		}
		if ( thisClass.usernameFilter !== null ) {
			params.username_filter = thisClass.usernameFilter;
		}
		/* eslint-enable camelcase */

		new mw.Rest().get(
			'/campaignevents/v0/event_registration/' + thisClass.registrationID + '/participants',
			params
		)
			.done( function ( data ) {
				if ( data.length ) {
					thisClass.addParticipantsToList( data );
				}
			} )
			.fail( function ( _err, errData ) {
				mw.log.error( errData.xhr.responseText || 'Unknown error' );
			} );
	};

	/**
	 * Adds the participants in the given response to the list of participants.
	 *
	 * @param {Object} apiResponse Response of the "list participants" API endpoint
	 */
	ParticipantsManager.prototype.addParticipantsToList = function ( apiResponse ) {
		var thisClass = this;

		var allSelected = this.selectAllParticipantsCheckbox ?
			this.selectAllParticipantsCheckbox.isSelected() :
			false;
		this.lastParticipantID = apiResponse[ apiResponse.length - 1 ].participant_id;
		for ( var i = 0; i < apiResponse.length; i++ ) {
			var curParticipantData = apiResponse[ i ];
			var items = [];
			if ( this.showParticipantCheckboxes ) {
				var newParticipantCheckbox =
					new OO.ui.CheckboxInputWidget( {
						selected: allSelected,
						name: 'event-details-participants-checkboxes',
						value: curParticipantData.user_id,
						classes: [
							'ext-campaignevents-event-details-participants-checkboxes'
						]
					} );

				newParticipantCheckbox.on( 'change', function ( selected ) {
					thisClass.onParticipantCheckboxChange( selected, this );
				}, [], newParticipantCheckbox );

				this.participantCheckboxes.push( newParticipantCheckbox );
				if ( allSelected ) {
					this.selectedParticipantIDs.push( String( curParticipantData.user_id ) );
				}
				items.push( newParticipantCheckbox );
			}

			var $usernameElement;
			if ( curParticipantData.user_name ) {
				$usernameElement = thisClass.makeUserLink(
					curParticipantData.user_name,
					curParticipantData.user_page
				);
			} else {
				$usernameElement = thisClass.getDeletedOrNotFoundParticipantElement(
					curParticipantData
				);
			}
			items.push(
				new OO.ui.Element( {
					$element: $( '<span>' ),
					$content: $usernameElement,
					classes: [ 'ext-campaignevents-details-participant-username' ]
				} )
			);

			if ( curParticipantData.private ) {
				items.push(
					new OO.ui.IconWidget( {
						icon: 'lock',
						classes: [ 'ext-campaignevents-event-details-participants-private-icon' ]
					} )
				);
			}

			items.push(
				new OO.ui.Element( {
					$element: $( '<span>' ),
					text: curParticipantData.user_registered_at_formatted,
					classes: [ 'ext-campaignevents-details-participant-registered-at' ]
				} )
			);

			var layout = new OO.ui.Element( {
				classes: [ 'ext-campaignevents-details-user-row' ],
				content: items
			} );

			this.$userRowsContainer.append( layout.$element );
		}

		this.scrollDownObserver.reset();
	};

	/**
	 * Builds a link to a user page.
	 * FIXME: This functionality should really be in MW core.
	 *
	 * @param {string} userName
	 * @param {Object} userLinkData As returned by the "list participants" endpoint
	 * @return {jQuery}
	 */
	ParticipantsManager.prototype.makeUserLink = function ( userName, userLinkData ) {
		return $( '<a>' )
			.attr( 'href', userLinkData.path )
			.attr( 'title', userLinkData.title )
			// The following classes are used here:
			// * mw-userLink
			// * new
			.attr( 'class', userLinkData.classes )
			.append( $( '<bdi>' ).text( userName ) );
	};

	/**
	 * Returns a placeholder to be used in place of the username for who were not found,
	 * or whose account was suppressed.
	 * FIXME: Keep in sync with UserLinker::generateUserLinkWithFallback
	 *
	 * @param {Object} userData Data about this participant as returned by the
	 *   "list participants" endpoint
	 * @return {jQuery}
	 */
	ParticipantsManager.prototype.getDeletedOrNotFoundParticipantElement = function ( userData ) {
		var $el = $( '<span>' );
		if ( userData.hidden ) {
			$el.addClass( 'ext-campaignevents-userlink-hidden' )
				.text( mw.msg( 'campaignevents-userlink-suppressed-user' ) );
		} else if ( userData.not_found ) {
			$el.addClass( 'ext-campaignevents-userlink-deleted' )
				.text( mw.msg( 'campaignevents-userlink-deleted-user' ) );
		} else {
			throw new Error( 'No username but user is not hidden and they were found?!' );
		}
		return $el;
	};

	module.exports = new ParticipantsManager();
}() );
