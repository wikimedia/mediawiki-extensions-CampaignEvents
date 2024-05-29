( function () {
	'use strict';

	/**
	 * Dialog used to display a form letting users register for an event.
	 *
	 * @class
	 * @extends OO.ui.ProcessDialog
	 *
	 * @constructor
	 * @param {Object} config Configuration options
	 * @param {string|null} [config.policyMsg] Policy acknowledgement message
	 * @param {Object|undefined} [config.curParticipantData] Current registration data for
	 *   this user, if available. Undefined otherwise. Must have the following keys:
	 *    - public (boolean): Whether the user is registered publicly
	 *    - aggregationTimestamp (string|null): Planned timestamp of when the user's answers
	 *       will be aggregated.
	 * @param {boolean} [config.answersAggregated] Whether the user's answers have already
	 *   been aggregated.
	 * @param {Object} [config.eventQuestions] EventQuestions object
	 */
	function ParticipantRegistrationDialog( config ) {
		ParticipantRegistrationDialog.super.call( this, config );
		this.$element.addClass( 'ext-campaignevents-registration-dialog' );
		this.policyMsg = config.policyMsg;
		if ( typeof config.curParticipantData !== 'undefined' ) {
			this.publicRegistration = config.curParticipantData.public;
			this.aggregationTimestamp = config.curParticipantData.aggregationTimestamp;
			this.isEdit = true;
		} else {
			this.publicRegistration = true;
			this.aggregationTimestamp = null;
			this.isEdit = false;
		}
		this.answersAggregated = config.answersAggregated;
		this.eventQuestions = config.eventQuestions;
	}

	OO.inheritClass( ParticipantRegistrationDialog, OO.ui.ProcessDialog );

	ParticipantRegistrationDialog.static.name = 'campaignEventsParticipantRegistrationDialog';

	ParticipantRegistrationDialog.prototype.getSetupProcess = function ( data ) {
		var title, submitMsg;
		if ( this.isEdit ) {
			title = mw.msg( 'campaignevents-eventpage-register-dialog-title-edit' );
			submitMsg = mw.msg( 'campaignevents-eventpage-register-dialog-save' );
		} else {
			title = mw.msg( 'campaignevents-eventpage-register-dialog-title' );
			submitMsg = mw.msg( 'campaignevents-eventpage-register-dialog-register' );
		}

		var actions = [
			{
				flags: [ 'safe', 'close' ],
				action: 'cancel'
			},
			{
				flags: [ 'primary', 'progressive' ],
				label: submitMsg,
				action: 'confirm'
			}
		];
		if ( !this.answersAggregated && this.eventQuestions.hasQuestions() ) {
			actions.push( {
				label: mw.msg( 'campaignevents-eventpage-register-dialog-clear' ),
				action: 'clear'
			} );
		}
		data = $.extend(
			{
				title: title,
				actions: actions
			},
			data
		);
		return ParticipantRegistrationDialog.super.prototype.getSetupProcess.call( this, data );
	};

	ParticipantRegistrationDialog.prototype.initialize = function () {
		ParticipantRegistrationDialog.super.prototype.initialize.apply( this );

		var visibilityFields = this.getVisibilityFields();
		var visibilityFieldset = new OO.ui.FieldsetLayout( {
			items: visibilityFields,
			label: mw.msg( 'campaignevents-eventpage-register-dialog-visibility-title' )
		} );

		var fieldsets = [ visibilityFieldset ];

		var questionFields;
		if ( this.answersAggregated ) {
			questionFields = [
				new OO.ui.FieldLayout(
					new OO.ui.MessageWidget( {
						type: 'notice',
						inline: true,
						label: mw.msg( 'campaignevents-eventpage-register-dialog-answers-aggregated' )
					} )
				)
			];
		} else {
			questionFields = this.eventQuestions.getQuestionFields();
		}
		if ( questionFields.length > 0 ) {
			var questionsFieldset = new OO.ui.FieldsetLayout( {
				items: questionFields,
				label: mw.msg( 'campaignevents-eventpage-register-dialog-questions-title' ),
				help: mw.msg( 'campaignevents-eventpage-register-dialog-questions-subtitle' ),
				helpInline: true
			} );
			fieldsets.push( questionsFieldset );

			fieldsets.push( this.getDataRetentionFieldset() );
		}

		var formPanel = new OO.ui.PanelLayout( {
			content: fieldsets,
			padded: true,
			scrollable: false,
			expanded: false
		} );
		this.$body.append( formPanel.$element );

		if ( this.policyMsg !== null ) {
			var policyPanel = new OO.ui.PanelLayout( {
				$content: this.policyMsg,
				padded: true,
				scrollable: false,
				expanded: false,
				classes: [ 'ext-campaignevents-policy-message-panel' ]
			} );
			this.$body.append( policyPanel.$element );
		}
	};

	/**
	 * Returns an array of fields for the registration visibility input.
	 *
	 * @return {OO.ui.FieldLayout[]}
	 */
	ParticipantRegistrationDialog.prototype.getVisibilityFields = function () {
		var publicIcon = new OO.ui.IconWidget( { icon: 'globe' } ),
			privateIcon = new OO.ui.IconWidget( { icon: 'lock' } );
		var $publicHelpText = mw.message( 'campaignevents-registration-confirmation-helptext-public' ).parseDom(),
			$privateHelpText = mw.message( 'campaignevents-registration-confirmation-helptext-private' ).parseDom();
		var $publicLabel = $( '<div>' )
			.addClass( 'ext-campaignevents-registration-visibility-label' )
			.append( publicIcon.$element, $( '<span>' ).append( $publicHelpText ) );
		var $privateLabel = $( '<div>' )
			.addClass( 'ext-campaignevents-registration-visibility-label' )
			.append( privateIcon.$element, $( '<span>' ).append( $privateHelpText ) );

		var visibilityHelpLabel = new OO.ui.LabelWidget( {
				label: this.publicRegistration ? $publicLabel : $privateLabel
			} ),
			visibilityHelpField = new OO.ui.FieldLayout( visibilityHelpLabel );

		var visibilityToggle = new OO.ui.ToggleSwitchWidget( {
			value: this.publicRegistration
		} );
		var self = this;
		visibilityToggle.on( 'change', function ( value ) {
			self.publicRegistration = value;
			visibilityHelpLabel.setLabel( self.publicRegistration ? $publicLabel : $privateLabel );
			self.updateSize();
		} );

		var visibilityField = new OO.ui.FieldLayout(
			visibilityToggle,
			{
				classes: [ 'ext-campaignevents-registration-visibility-toggle-field' ],
				label: mw.msg( 'campaignevents-registration-confirmation-toggle-public' )
			}
		);

		return [ visibilityField, visibilityHelpField ];
	};

	/**
	 * Returns a fieldset with information about the data retention policy.
	 *
	 * @return {OO.ui.FieldsetLayout}
	 */
	ParticipantRegistrationDialog.prototype.getDataRetentionFieldset = function () {
		var retentionMsg = mw.message( 'campaignevents-eventpage-register-dialog-retention-base' ).escaped();
		if ( this.aggregationTimestamp !== null ) {
			var additionalRetentionMsg,
				curTimestamp = Math.floor( Date.now() / 1000 ),
				timeRemaining = parseInt( this.aggregationTimestamp ) - curTimestamp;
			if ( timeRemaining < 60 * 60 * 24 ) {
				additionalRetentionMsg = mw.message( 'campaignevents-eventpage-register-dialog-retention-hours' ).parse();
			} else {
				var remainingDays = Math.round( timeRemaining / ( 60 * 60 * 24 ) );
				additionalRetentionMsg = mw.message( 'campaignevents-eventpage-register-dialog-retention-days' )
					.params( [ mw.language.convertNumber( remainingDays ) ] )
					.parse();
			}
			retentionMsg += mw.message( 'word-separator' ).escaped() + additionalRetentionMsg;
		}
		var retentionInfoField = new OO.ui.FieldLayout(
			new OO.ui.LabelWidget( {
				label: new OO.ui.HtmlSnippet( retentionMsg ),
				classes: [ 'ext-campaignevents-registration-retention-label' ]
			} )
		);

		return new OO.ui.FieldsetLayout( {
			items: [ retentionInfoField ],
			label: mw.msg( 'campaignevents-eventpage-register-dialog-retention-title' )
		} );
	};

	ParticipantRegistrationDialog.prototype.getActionProcess = function ( action ) {
		if ( action === 'clear' ) {
			return new OO.ui.Process( this.eventQuestions.resetToDefault, this.eventQuestions );
		}
		if ( action === 'confirm' ) {
			return new OO.ui.Process( function () {
				this.close( {
					action: action,
					isPrivate: !this.publicRegistration,
					answers: this.eventQuestions.getParticipantAnswers()
				} );
			}, this );
		}
		return ParticipantRegistrationDialog.super.prototype.getActionProcess.call( this, action )
			.next( function () {
				this.close();
			}, this );
	};

	module.exports = ParticipantRegistrationDialog;
}() );
