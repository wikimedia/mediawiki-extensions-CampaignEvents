( function () {
	'use strict';

	/**
	 * Dialog used to display a form letting users register for an event.
	 *
	 * @param {Object} config Configuration options
	 * @cfg {string|null} [policyMsg] Policy acknowledgement message
	 * @cfg {Object|undefined} [curParticipantData=true] Current registration data for this user, if
	 *   available. Undefined otherwise. Must have the following keys:
	 *    - public (boolean): Whether the user is registered publicly
	 * @cfg {Object} [eventQuestions] EventQuestions object
	 * @extends OO.ui.ProcessDialog
	 * @constructor
	 */
	function ParticipantRegistrationDialog( config ) {
		ParticipantRegistrationDialog.super.call( this, config );
		this.$element.addClass( 'ext-campaignevents-registration-dialog' );
		this.policyMsg = config.policyMsg;
		if ( typeof config.curParticipantData !== 'undefined' ) {
			this.publicRegistration = config.curParticipantData.public;
			this.isEdit = true;
		} else {
			this.publicRegistration = true;
			this.isEdit = false;
		}
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

		data = $.extend(
			{
				title: title,
				actions: [
					{
						flags: [ 'safe', 'close' ],
						action: 'cancel'
					},
					{
						flags: [ 'primary', 'progressive' ],
						label: submitMsg,
						action: 'confirm'
					},
					{
						label: mw.msg( 'campaignevents-eventpage-register-dialog-clear' ),
						action: 'clear'
					}
				]
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

		var questionFields = this.eventQuestions.getQuestionFields();
		if ( questionFields.length > 0 ) {
			var questionsFieldset = new OO.ui.FieldsetLayout( {
				items: questionFields,
				label: mw.msg( 'campaignevents-eventpage-register-dialog-questions-title' ),
				help: mw.msg( 'campaignevents-eventpage-register-dialog-questions-subtitle' ),
				helpInline: true
			} );
			fieldsets.push( questionsFieldset );
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
