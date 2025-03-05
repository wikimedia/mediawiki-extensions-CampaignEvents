( function () {
	'use strict';
	var participantsManager = require( './ParticipantsManager.js' );

	function EmailManager() {
		this.registrationID = mw.config.get( 'wgCampaignEventsEventID' );
		/* eslint-disable no-jquery/no-global-selector */
		this.message = OO.ui.infuse( $( '.ext-campaignevents-details-email-message' ) );
		this.subject = OO.ui.infuse( $( '.ext-campaignevents-details-email-subject' ) );
		this.CCMe = OO.ui.infuse( $( '.ext-campaignevents-details-email-ccme' ) );
		this.button = OO.ui.infuse( $( '.ext-campaignevents-details-email-button' ) );
		this.recipientsLink = OO.ui.infuse( $( '.ext-campaignevents-details-email-recipients-link' ) );
		this.resultMessageField = OO.ui.infuse( $( '.ext-campaignevents-details-email-notification' ) );
		this.resultMessageField.toggle( false );
		this.resultMessage = this.resultMessageField.getField();
		this.tabLayout = OO.ui.infuse( $( '#ext-campaignevents-eventdetails-tabs' ) );
		this.$recipientsListElement = $( '.ext-campaignevents-eventdetails-email-recipient-list' );
		/* eslint-enable no-jquery/no-global-selector */
		this.addValidation();
		this.installEventListeners();
	}

	EmailManager.prototype.setError = function ( error ) {
		this.resultMessage.setType( 'error' );
		this.resultMessage.setInline( true );
		this.resultMessage.setLabel( error );
		this.resultMessageField.toggle( true );
	};

	EmailManager.prototype.setSuccess = function ( message ) {
		this.showNotification( 'success', message );
	};

	EmailManager.prototype.setWarning = function ( message ) {
		this.resultMessage.setType( 'warning' );
		this.resultMessage.setInline( true );
		this.resultMessage.setLabel( message );
		this.resultMessageField.toggle( true );
	};

	EmailManager.prototype.clearMessage = function () {
		this.resultMessage.setLabel( null );
		this.resultMessageField.toggle( false );
	};

	/**
	 * @param {string} type
	 * @param {string} message
	 */
	EmailManager.prototype.showNotification = function ( type, message ) {
		mw.notify(
			new OO.ui.MessageWidget( {
				type: type,
				inline: true,
				label: message
			} ).$element[ 0 ],
			{ type: type }
		);
	};

	EmailManager.prototype.resetFields = function () {
		// FIXME HACK: set the value property first so that the 'change' listener isn't fired
		// and the field remains valid. See T346838, T347748.
		this.message.value = '';
		this.message.setValue( '' );
		this.subject.setValue( '' );
		this.CCMe.setSelected( false );
		this.button.setDisabled( true );
	};

	EmailManager.prototype.installEventListeners = function () {
		var self = this;
		if ( !participantsManager.viewerHasEmail ) {
			this.setWarning(
				new OO.ui.HtmlSnippet(
					mw.message( 'campaignevents-event-details-no-organizer-email' ).parse()
				)
			);
			this.subject.setDisabled( true );
			this.message.setDisabled( true );
			this.CCMe.setDisabled( true );
		}
		participantsManager.on( 'change', this.onRecipientsUpdate.bind( this ) );

		this.button.on( 'click', this.emailUsers.bind( this ) );

		this.recipientsLink.on( 'click', function () {
			self.tabLayout.setTabPanel( 'ParticipantsPanel' );
		} );

		this.message.on( 'change', function ( value ) {
			var toggleButton = function ( enabled ) {
				return function () {
					self.button.setDisabled( !enabled );
				};
			};
			self.message.getValidity( value )
				.done( toggleButton( true ) )
				.fail( toggleButton( false ) );
		} );
	};

	EmailManager.prototype.onRecipientsUpdate = function () {
		var userData = participantsManager.participantCheckboxes
			.map( function ( participantCheckbox ) {
				var data = participantCheckbox.getData();
				data.userID = parseInt( participantCheckbox.getValue() );
				return data;
			} )
			.filter( function ( participantData ) {
				return participantData.username !== undefined;
			} );
		var validRecipients = userData
			.filter( function ( participantData ) {
				return participantData.canReceiveEmail;
			} )
			.map( function ( participantData ) {
				return participantData.userID;
			} );

		this.$recipientsListElement.empty();
		this.clearMessage();

		if ( participantsManager.selectedParticipantIDs === null ) {
			this.$recipientsListElement.text( mw.message( 'campaignevents-email-participants-all' ).text() );
			this.setWarning( mw.message( 'campaignevents-email-participants-missing-address' ).text() );
			return;
		}

		var hasInvalidRecipients;
		if ( participantsManager.isSelectionInverted ) {
			hasInvalidRecipients = true;
		} else {
			var invalidRecipients = participantsManager.selectedParticipantIDs
				.filter( function ( id ) {
					return validRecipients.indexOf( id ) === -1;
				} );
			hasInvalidRecipients = invalidRecipients.length > 0;
		}
		if ( hasInvalidRecipients ) {
			this.setWarning( mw.message( 'campaignevents-email-participants-missing-address' ).text() );
		}

		var selectionSize = participantsManager.selectedParticipantIDs.length;
		if ( selectionSize > 1 ) {
			var msg = participantsManager.isSelectionInverted ?
				'campaignevents-email-participants-except-count' :
				'campaignevents-email-participants-count';

			this.$recipientsListElement.text(
				// eslint-disable-next-line mediawiki/msg-doc
				mw.message( msg, mw.language.convertNumber( selectionSize ) ).text()
			);
			return;
		}

		var selectionUserInfo = userData.filter( function ( data ) {
			return participantsManager.selectedParticipantIDs.indexOf( data.userID ) > -1;
		} );
		var recipientsListItems = selectionUserInfo.map( function ( selectedUserData ) {
			return new OO.ui.Element( {
				$element: $( '<li>' ),
				$content: participantsManager.makeUserLink(
					selectedUserData.username,
					selectedUserData.userPageLink ),
				classes: [ 'ext-campaignevents-email-participant-username' ]
			} ).$element;
		} );
		var $recipientsList = $( '<ul>' ).append( recipientsListItems );
		if ( participantsManager.isSelectionInverted ) {
			$recipientsList = mw.message( 'campaignevents-email-participants-except', $recipientsList )
				.parseDom();
		}
		this.$recipientsListElement.append( $recipientsList );
	};

	EmailManager.prototype.addValidation = function () {
		this.message.setValidation( function ( value ) {
			return value.length >= 10;
		} );
	};

	EmailManager.prototype.showNoParticipantsError = function () {
		var self = this;
		self.setError(
			mw.message(
				'campaignevents-email-select-participant-notification'
			).text()
		);
	};

	/**
	 * @return {jQuery.Promise|null}
	 */
	EmailManager.prototype.emailUsers = function () {
		var self = this;
		if ( participantsManager.selectedParticipantsAmount === 0 ) {
			self.showNoParticipantsError();
			return null;
		}
		return new mw.Rest().post(
			'/campaignevents/v0/event_registration/' + this.registrationID + '/email',
			{
				token: mw.user.tokens.get( 'csrfToken' ),
				// eslint-disable-next-line camelcase
				user_ids: participantsManager.selectedParticipantIDs,
				// eslint-disable-next-line camelcase
				invert_users: participantsManager.isSelectionInverted,
				message: this.message.getValue(),
				subject: this.subject.getValue(),
				ccme: this.CCMe.isSelected()
			}
		)
			.done( function ( result ) {
				if ( result.sent === 0 ) {
					self.showNoParticipantsError();
				} else {
					self.resetFields();
					self.setSuccess(
						mw.message(
							'campaignevents-email-success-notification'
						).text()
					);
				}
			} )
			.fail( function ( _err, errData ) {
				self.setError(
					mw.message(
						'campaignevents-email-error-notification'
					).text()
				);
				mw.log.error( errData.xhr.responseText || 'Unknown error' );
			} );
	};

	module.exports = new EmailManager();

}()
);
