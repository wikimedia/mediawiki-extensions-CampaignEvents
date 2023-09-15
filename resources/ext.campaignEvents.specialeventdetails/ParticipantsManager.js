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
		this.viewerHasEmail = mw.config.get( 'wgCampaignEventsViewerHasEmail' );
		/* eslint-disable no-jquery/no-global-selector */
		var $selectAllParticipantsField = $(
			'.ext-campaignevents-event-details-select-all-participant-checkbox-field'
		);
		this.$participantCountLabel = $( '.ext-campaignevents-details-participants-count-button' );
		if ( $selectAllParticipantsField.length ) {
			this.selectAllParticipantsField = OO.ui.FieldLayout.static.infuse(
				$selectAllParticipantsField
			);
			this.selectAllParticipantsCheckbox = this.selectAllParticipantsField.getField();
		}

		this.$participantsTitle = $( '.ext-campaignevents-details-participants-header-text' );
		this.participantCheckboxes = [];
		this.curParticipantCheckbox = null;
		this.isSelectionInverted = false;
		// This can be an array of IDs or null. Null means all participants are selected. If array,
		// then the listed user are respectively selected or excluded from selection, depending
		// on the value of isSelectionInverted.
		this.selectedParticipantIDs = [];
		this.participantsTotal = mw.config.get( 'wgCampaignEventsEventDetailsParticipantsTotal' );
		this.usernameFilter = null;

		this.$noParticipantsStateElement = $( '.ext-campaignevents-details-no-participants-state' );
		this.$curUserRow = $( '.ext-campaignevents-details-current-user-row' );
		// Note: this can be null if the user is not logged in
		this.curUserName = mw.user.getName();
		this.$removeParticipantsButton = $( '#ext-campaignevents-event-details-remove-participant-button' );
		this.removeParticipantDialog = new RemoveParticipantDialog( {
			classes: [ 'ext-campaignevents-details-remove-participant-dialog' ]
		} );
		this.$messageParticipantsButton = $( '.ext-campaignevents-event-details-message-all-participants-button' );
		this.canEmailParticipants = this.$messageParticipantsButton.length !== 0;
		this.windowManager = new OO.ui.WindowManager();
		this.$participantsContainer = $( '.ext-campaignevents-details-participants-container' );
		this.$participantsTable = $( '.ext-campaignevents-details-participants-table' );
		this.$searchParticipantsElement = $( '.ext-campaignevents-details-participants-search' );
		this.selectedParticipantsAmount = 0;
		this.$tabPanel = $( '#ext-campaignevents-eventdetails-tabs' );
		/* eslint-enable no-jquery/no-global-selector */

		this.installEventListeners();
		OO.EventEmitter.call( this );
	}

	OO.mixinClass( ParticipantsManager, OO.EventEmitter );

	ParticipantsManager.prototype.toggleSelectAll = function ( selected ) {
		for ( var i = 0; i < this.participantCheckboxes.length; i++ ) {
			this.participantCheckboxes[ i ].setSelected( selected, true );
		}
		this.selectAllParticipantsCheckbox.setIndeterminate( false, true );
		this.selectAllParticipantsCheckbox.setSelected( selected, true );
		if ( selected ) {
			this.onSelectAll();
		} else {
			this.onDeselectAll();
		}
	};

	ParticipantsManager.prototype.installEventListeners = function () {
		var thisClass = this;
		if ( this.$participantCountLabel.length ) {
			this.participantCountLabel = OO.ui.ButtonWidget.static.infuse(
				this.$participantCountLabel
			);
			this.participantCountLabel.on( 'click', function () {
				thisClass.toggleSelectAll( false );
				thisClass.updateSelectedLabel();
			} );
		}
		if ( this.$tabPanel.length ) {
			this.tabPanel = OO.ui.IndexLayout.static.infuse(
				thisClass.$tabPanel
			);
		}
		if ( thisClass.selectAllParticipantsCheckbox ) {
			thisClass.selectAllParticipantsCheckbox.on( 'change', function ( selected ) {
				thisClass.toggleSelectAll( selected );
				thisClass.updateSelectedLabel();
			} );
		}

		if ( this.$messageParticipantsButton.length ) {
			if ( this.viewerHasEmail ) {
				this.messageParticipantsButton = OO.ui.ButtonWidget.static.infuse(
					this.$messageParticipantsButton
				);
				this.messageParticipantsButton.on( 'click', function () {
					if ( thisClass.selectedParticipantsAmount === 0 ) {
						thisClass.selectAllParticipantsCheckbox.setSelected( true );
					}
					thisClass.tabPanel.setTabPanel( 'EmailPanel' );
				} );
			} else {
				var popup = {
					$content: $( '<p>' ).append(
						mw.message( 'campaignevents-event-details-no-organizer-email' ).parse() ),
					padded: true,
					classes: [ 'ext-campaignevents-event-details-message-all-participants-button-popup' ],
					align: 'forwards'
				};
				this.messageParticipantsButton = new OO.ui.PopupButtonWidget( {
					label: mw.message( 'campaignevents-event-details-message-participants' ).text(),
					classes: [ 'ext-campaignevents-event-details-message-all-participants-button' ],
					popup: popup
				} );
				this.$messageParticipantsButton.replaceWith(
					this.messageParticipantsButton.$element
				);
				this.messageParticipantsButton.getPopup().toggle( true );
			}
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
				if ( infusedCheckbox.getValue() === String( thisClass.curUserCentralID ) ) {
					thisClass.curParticipantCheckbox = infusedCheckbox;
				}
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
			this.$participantsContainer[ 0 ],
			function () {
				// eslint-disable-next-line no-jquery/no-global-selector
				if ( thisClass.participantsTotal > $( '.ext-campaignevents-details-user-row' ).length ) {
					thisClass.loadMoreParticipants();
				}
			}
		);

		if ( this.$searchParticipantsElement.length ) {
			thisClass.searchParticipantsWidget = OO.ui.SearchInputWidget.static.infuse(
				this.$searchParticipantsElement
			);
			thisClass.searchParticipantsWidget.on(
				'change',
				mw.util.debounce( function ( inputVal ) {
					thisClass.usernameFilter = inputVal === '' ? null : inputVal;
					thisClass.rebuildList();
				}, 500 )
			);
		}
	};

	ParticipantsManager.prototype.onSelectAll = function () {
		this.selectedParticipantIDs = null;
		this.isSelectionInverted = false;
		this.selectedParticipantsAmount = this.participantsTotal;
		if ( this.removeParticipantsButton ) {
			this.removeParticipantsButton.$element.show();
		}
		this.emit( 'change' );
	};

	ParticipantsManager.prototype.onDeselectAll = function () {
		this.selectedParticipantIDs = [];
		this.isSelectionInverted = false;
		this.selectedParticipantsAmount = 0;
		if ( this.removeParticipantsButton ) {
			this.removeParticipantsButton.$element.hide();
		}
		this.emit( 'change' );
	};

	ParticipantsManager.prototype.updateSelectedLabel = function () {
		this.participantCountLabel.$element.hide();
		if ( this.selectedParticipantsAmount > 0 ) {
			this.participantCountLabel.setLabel(
				mw.message( 'campaignevents-event-details-participants-checkboxes-selected',
					mw.language.convertNumber( this.selectedParticipantsAmount ),
					mw.language.convertNumber( this.participantsTotal )
				).text()
			);
			this.participantCountLabel.$element.show();
			if ( this.$messageParticipantsButton.length ) {
				this.messageParticipantsButton.setLabel(
					mw.message( 'campaignevents-event-details-message-participants' ).text()
				);
			}
			return;
		}
		if ( this.$messageParticipantsButton.length ) {
			this.messageParticipantsButton.setLabel(
				mw.message( 'campaignevents-event-details-message-all' ).text()
			);
		}
	};

	ParticipantsManager.prototype.onParticipantCheckboxChange = function ( selected, el ) {
		if ( selected ) {
			this.onSelectParticipant( el );
		} else {
			this.onDeselectParticipant( el );
		}
		this.updateSelectedLabel();
		this.emit( 'change' );
	};

	ParticipantsManager.prototype.onSelectParticipant = function ( checkbox ) {
		this.selectedParticipantsAmount++;
		if ( this.selectedParticipantsAmount === this.participantsTotal ) {
			this.selectAllParticipantsCheckbox.setSelected( true, true );
			this.selectAllParticipantsCheckbox.setIndeterminate( false, true );
			this.isSelectionInverted = false;
			this.selectedParticipantIDs = null;
		} else if ( this.isSelectionInverted ) {
			this.selectedParticipantIDs.splice(
				this.selectedParticipantIDs.indexOf( checkbox.getValue() ), 1
			);
		} else {
			this.selectedParticipantIDs.push( checkbox.getValue() );
		}
		if ( this.removeParticipantsButton ) {
			this.removeParticipantsButton.$element.show();
		}
	};

	ParticipantsManager.prototype.onDeselectParticipant = function ( checkbox ) {
		this.selectedParticipantsAmount--;
		if ( this.selectedParticipantsAmount === 0 ) {
			this.selectAllParticipantsCheckbox.setSelected( false, true );
			this.selectAllParticipantsCheckbox.setIndeterminate( false, true );
			this.selectedParticipantIDs = [];
			this.removeParticipantsButton.$element.hide();
			this.isSelectionInverted = false;
			return;
		}

		if ( this.selectAllParticipantsCheckbox.isSelected() ) {
			this.isSelectionInverted = true;
			this.selectAllParticipantsCheckbox.setIndeterminate( true, true );
		}

		this.selectedParticipantIDs = this.selectedParticipantIDs || [];
		if ( this.isSelectionInverted ) {
			this.selectedParticipantIDs.push( checkbox.getValue() );
		} else {
			this.selectedParticipantIDs.splice(
				this.selectedParticipantIDs.indexOf( checkbox.getValue() ), 1
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
		var thisClass = this;
		new mw.Rest().delete(
			'/campaignevents/v0/event_registration/' + this.registrationID + '/participants',
			{
				token: mw.user.tokens.get( 'csrfToken' ),
				// eslint-disable-next-line camelcase
				user_ids: this.selectedParticipantIDs,
				// eslint-disable-next-line camelcase
				invert_users: this.isSelectionInverted
			}
		)
			.done( function ( response ) {
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
				thisClass.selectAllParticipantsCheckbox.setIndeterminate( false, true );
				thisClass.onDeselectAll();
				thisClass.updateSelectedLabel();
				var successMsg;
				thisClass.participantsTotal = thisClass.participantsTotal - response.modified;
				if ( thisClass.participantsTotal === 0 ) {
					successMsg = mw.message(
						'campaignevents-event-details-remove-all-participant-notification'
					).text();
					thisClass.$noParticipantsStateElement.removeClass( 'ext-campaignevents-details-hide-element' );
					thisClass.searchParticipantsWidget.$element.hide();
					thisClass.messageParticipantsButton.$element.hide();
					thisClass.$participantsContainer.hide();
				} else {
					successMsg = mw.message(
						'campaignevents-event-details-remove-participant-notification',
						mw.language.convertNumber( response.modified )
					).text();
					thisClass.loadMoreParticipants();
				}

				thisClass.$participantsTitle.text(
					mw.message(
						'campaignevents-event-details-header-participants',
						mw.language.convertNumber( thisClass.participantsTotal )
					)
				);
				thisClass.scrollDownObserver.reset();
				thisClass.showNotification( 'success', successMsg );
			} )
			.fail( function ( _err, errData ) {
				var errorMsg;

				if ( errData.xhr.responseJSON.messageTranslations ) {
					errorMsg = errData.xhr.responseJSON.messageTranslations.en;
				} else {
					errorMsg = mw.message(
						'campaignevents-event-details-remove-participant-notification-error',
						mw.language.convertNumber( thisClass.selectedParticipantsAmount )
					).text();
				}
				thisClass.showNotification( 'error', errorMsg );
			} );
	};

	/**
	 * Rebuilds the list of participant. For instance, this is used when the
	 * username filter changes.
	 */
	ParticipantsManager.prototype.rebuildList = function () {
		// Pause the scrolldown observer while we rebuild the list (T340897)
		this.scrollDownObserver.pause();
		// eslint-disable-next-line no-jquery/no-global-selector
		$( '.ext-campaignevents-details-user-row' )
			.not( this.$curUserRow )
			.remove();
		this.$curUserRow.hide();
		// The checkbox for the current user can be left here so that we don't have to check
		// whether it should be selected every time we toggle it.
		this.participantCheckboxes = this.curParticipantCheckbox ?
			[ this.curParticipantCheckbox ] :
			[];
		this.scrollDownObserver.reset();
		// Reset last participant so we can list them from the start if the
		// filter changes
		this.lastParticipantID = null;
		// Note that the selected participants should persist and are not reset here.
		this.loadMoreParticipants();
		var that = this;
		// Unpause the scrolldown observer once everything's settled.
		setTimeout( function () {
			that.scrollDownObserver.unpause();
		}, 0 );
	};

	ParticipantsManager.prototype.loadMoreParticipants = function () {
		var thisClass = this;

		/* eslint-disable camelcase */
		var params = {
			include_private: this.showPrivateParticipants
		};
		if ( thisClass.curUserCentralID !== null ) {
			params.exclude_users = [ thisClass.curUserCentralID ];
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
				thisClass.toggleCurUserRow();
				if ( data.length ) {
					thisClass.addParticipantsToList( data );
				}
			} )
			.fail( function ( _err, errData ) {
				mw.log.error( errData.xhr.responseText || 'Unknown error' );
			} );
	};

	/**
	 * Toggles the current user's row, depending on the username filter. This needs
	 * to be special-cased, like in PHP, and the code should be kept in sync with
	 * its PHP counterpart.
	 * TODO This is subpar.
	 */
	ParticipantsManager.prototype.toggleCurUserRow = function () {
		if ( this.curUserName === null ) {
			return;
		}

		if (
			this.usernameFilter === null ||
			this.curUserName.toLowerCase().indexOf( this.usernameFilter.toLowerCase() ) > -1
		) {
			this.$curUserRow.show();
		} else {
			this.$curUserRow.hide();
		}
	};

	ParticipantsManager.prototype.getValidRecipientLabel = function ( isValidRecipient ) {
		if ( isValidRecipient ) {
			return mw.message( 'campaignevents-email-participants-yes' ).text();
		} else {
			return mw.message( 'campaignevents-email-participants-no' ).text();
		}
	};
	/**
	 * Adds the participants in the given response to the list of participants.
	 *
	 * @param {Object} apiResponse Response of the "list participants" API endpoint
	 */
	ParticipantsManager.prototype.addParticipantsToList = function ( apiResponse ) {
		var thisClass = this;
		this.lastParticipantID = apiResponse[ apiResponse.length - 1 ].participant_id;

		for ( var i = 0; i < apiResponse.length; i++ ) {
			var curParticipantData = apiResponse[ i ],
				items = [];

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

			if ( this.showParticipantCheckboxes ) {
				// eslint-disable-next-line camelcase
				curParticipantData.user_id = String( curParticipantData.user_id );
				var loadAsSelected = this.loadParticipantAsSelected( curParticipantData.user_id ),
					canReceiveEmail = curParticipantData.user_is_valid_recipient || false,
					newParticipantCheckbox =
						new OO.ui.CheckboxInputWidget( {
							selected: loadAsSelected,
							name: 'event-details-participants-checkboxes',
							value: curParticipantData.user_id,
							classes: [
								'ext-campaignevents-event-details-participants-checkboxes'
							],
							data: {
								canReceiveEmail: canReceiveEmail,
								username: curParticipantData.user_name,
								userId: curParticipantData.user_id,
								userPageLink: curParticipantData.user_page
							}
						} ),
					checkboxField = new OO.ui.FieldLayout(
						newParticipantCheckbox,
						{
							label: $usernameElement.text(),
							invisibleLabel: true
						}
					),
					checkboxCell = new OO.ui.Element( {
						$element: $( '<td>' ),
						content: [ checkboxField ],
						classes: [ 'ext-campaignevents-details-user-row-checkbox' ]
					} );
				newParticipantCheckbox.on( 'change', function ( selected ) {
					thisClass.onParticipantCheckboxChange( selected, this );
				}, [], newParticipantCheckbox );

				this.participantCheckboxes.push( newParticipantCheckbox );
				items.push( checkboxCell );
			}

			var usernameCell = new OO.ui.Element( {
				$element: $( '<td>' ),
				$content: $usernameElement
			} );

			if ( curParticipantData.private ) {
				// TODO: Implement gender correctly
				var privateLabel = mw.message(
					'campaignevents-event-details-private-participant-label',
					'unknown'
				).text();
				usernameCell.$element.append(
					new OO.ui.IconWidget( {
						icon: 'lock',
						label: privateLabel,
						title: privateLabel,
						classes: [ 'ext-campaignevents-event-details-participants-private-icon' ]
					} ).$element
				);
			}

			items.push( usernameCell );

			items.push(
				new OO.ui.Element( {
					$element: $( '<td>' ),
					text: curParticipantData.user_registered_at_formatted
				} )
			);

			if ( this.canEmailParticipants ) {
				items.push(
					new OO.ui.Element( {
						$element: $( '<td>' ),
						text: thisClass.getValidRecipientLabel(
							curParticipantData.user_is_valid_recipient
						)
					} )
				);
			}

			var row = new OO.ui.Element( {
				$element: $( '<tr>' ),
				classes: [ 'ext-campaignevents-details-user-row' ],
				content: items
			} );

			this.$participantsTable.append( row.$element );
		}

		this.scrollDownObserver.reset();
	};

	/**
	 *
	 * @param {string} userID
	 * @return {boolean}
	 */
	ParticipantsManager.prototype.loadParticipantAsSelected = function ( userID ) {
		if ( this.selectedParticipantIDs === null &&
			this.selectAllParticipantsCheckbox.isSelected() ) {
			return true;
		}

		return this.isSelectionInverted ?
			this.selectedParticipantIDs.indexOf( userID ) === -1 :
			this.selectedParticipantIDs.indexOf( userID ) > -1;
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
