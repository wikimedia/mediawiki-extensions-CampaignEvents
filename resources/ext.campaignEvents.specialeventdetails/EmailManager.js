( function () {
	'use strict';
	var participantsManager = require( './ParticipantsManager.js' );

	function EmailManager() {
		this.registrationID = mw.config.get( 'wgCampaignEventsEventID' );
		/* eslint-disable no-jquery/no-global-selector */
		this.message = OO.ui.infuse( $( '.ext-campaignevents-details-email-message' ) );
		this.subject = OO.ui.infuse( $( '.ext-campaignevents-details-email-subject' ) );
		this.button = OO.ui.infuse( $( '.ext-campaignevents-details-email-button' ) );
		this.recipientsLink = OO.ui.infuse( $( '.ext-campaignevents-details-email-recipients-link' ) );
		this.resultMessageField = OO.ui.infuse( $( '.ext-campaignevents-details-email-notification' ) );
		this.resultMessageField.toggle( false );
		this.resultMessage = this.resultMessageField.getField();
		this.tabLayout = OO.ui.infuse( $( '#ext-campaignevents-eventdetails-tabs' ) );
		this.recipientsList = [];
		this.$recipientsListElement = $( '.ext-campaignevents-details-email-recipient-list' );
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
		this.message.setValue();
		this.subject.setValue();
		this.button.setDisabled( true );
	};

	EmailManager.prototype.installEventListeners = function () {
		var self = this;

		participantsManager.on( 'change', this.onRecipientsUpdate.bind( this ) );

		this.button.on( 'click', this.emailUsers.bind( this ) );

		this.recipientsLink.on( 'click', function () {
			self.tabLayout.setTabPanel( 'ParticipantsPanel' );
		} );

		this.message.on( 'change', function ( value ) {
			if ( self.message.validate( value ) ) {
				self.button.setDisabled( false );
			}
		} );
	};

	EmailManager.prototype.onRecipientsUpdate = function () {
		var getRecipientsListCheckboxes = function () {
			return participantsManager.participantCheckboxes.filter( function ( item ) {
				return participantsManager.isSelectionInverted ?
					!item.isSelected() :
					item.isSelected();
			} );
		};

		var hasNoEmail = function ( data ) {
			return participantsManager.isSelectionInverted ? data.hasEmail : !data.hasEmail;
		};

		var recipientsListCheckboxes = getRecipientsListCheckboxes();
		this.$recipientsListElement.empty();
		this.recipientsList = recipientsListCheckboxes.map( function ( recipientCheckbox ) {
			return recipientCheckbox.getData();
		} ).filter(
			function ( recipient ) {
				return recipient.username !== undefined;
			} );
		var allSelected =
				participantsManager.selectedParticipantsAmount ===
				participantsManager.participantsTotal;

		if ( this.recipientsList.some( hasNoEmail ) ) {
			this.setWarning( mw.message( 'campaignevents-email-participants-missing-address' )
				.text() );
		} else {
			this.resultMessageField.toggle( false );
		}

		if ( allSelected ) {
			this.$recipientsListElement.append(
				mw.message( 'campaignevents-email-participants-all' ).text() );
			return;
		}

		if ( this.recipientsList.length > 1 ) {
			if ( participantsManager.selectAllParticipantsCheckbox.selected ) {
				this.$recipientsListElement.append(
					mw.message(
						'campaignevents-email-participants-except-count',
						mw.language.convertNumber(
							this.recipientsList.length
						) ).text() );
				return;
			}
			this.$recipientsListElement.append(
				mw.message(
					'campaignevents-email-participants-count',
					mw.language.convertNumber(
						this.recipientsList.length
					) ).text() );
			return;
		}

		var recipientsListItems = this.recipientsList.map( function ( recipient ) {
			return new OO.ui.Element( {
				$element: $( '<li>' ),
				$content: participantsManager.makeUserLink(
					recipient.username,
					recipient.userPageLink ),
				classes: [ 'ext-campaignevents-email-participant-username' ]
			} ).$element;
		} );
		var $recipientsList = $( '<ul>' ).append( recipientsListItems );
		if ( participantsManager.selectAllParticipantsCheckbox.selected ) {
			this.$recipientsListElement.append(
				mw.message( 'campaignevents-email-participants-except', $recipientsList )
					.parseDom()
			);
			return;
		}
		this.$recipientsListElement.append( $recipientsList );

	};

	EmailManager.prototype.addValidation = function () {
		this.button.setDisabled( true );
		this.message.setValidation( function ( value ) {
			return value.length > 10;
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
				subject: this.subject.getValue()
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
