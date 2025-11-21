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
	 *    - showContributionPrompt (boolean): Whether the user chose to see the contribution prompt
	 * @param {boolean} [config.answersAggregated] Whether the user's answers have already
	 *   been aggregated.
	 * @param {Object} [config.eventQuestions] EventQuestions object
	 * @param {Object} [config.groupsCanViewPrivateMessage] pre-parsed helptext for private
	 * registration
	 * @param {boolean} [config.showContributionStatsSection] Whether to show
	 *  contribution statistics opt-out controls
	 * @param {string} [config.contributionsTabURL] URL to the Contributions tab on
	 *  Special:EventDetails
	 */
	function ParticipantRegistrationDialog( config ) {
		ParticipantRegistrationDialog.super.call( this, config );
		this.$element.addClass( 'ext-campaignevents-registration-dialog' );
		this.policyMsg = config.policyMsg;
		if ( typeof config.curParticipantData !== 'undefined' ) {
			this.publicRegistration = config.curParticipantData.public;
			this.aggregationTimestamp = config.curParticipantData.aggregationTimestamp;
			this.showContributionPrompt = config.curParticipantData.showContributionPrompt;
			this.isEdit = true;
		} else {
			this.publicRegistration = true;
			this.aggregationTimestamp = null;
			this.showContributionPrompt = true;
			this.isEdit = false;
		}
		this.answersAggregated = config.answersAggregated;
		this.eventQuestions = config.eventQuestions;
		this.groupsCanViewPrivateMessage = config.groupsCanViewPrivateMessage;
		this.showContributionStatsSection = config.showContributionStatsSection;
		this.contributionsTabURL = config.contributionsTabURL;
	}

	OO.inheritClass( ParticipantRegistrationDialog, OO.ui.ProcessDialog );

	ParticipantRegistrationDialog.static.name = 'campaignEventsParticipantRegistrationDialog';

	ParticipantRegistrationDialog.prototype.getSetupProcess = function ( data ) {
		let title, submitMsg;
		if ( this.isEdit ) {
			title = mw.msg( 'campaignevents-eventpage-register-dialog-title-edit' );
			submitMsg = mw.msg( 'campaignevents-eventpage-register-dialog-save' );
		} else {
			title = mw.msg( 'campaignevents-eventpage-register-dialog-title' );
			submitMsg = mw.msg( 'campaignevents-eventpage-register-dialog-register' );
		}

		const actions = [
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
		data = Object.assign(
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

		const visibilityFields = this.getVisibilityFields();
		const visibilityFieldset = new OO.ui.FieldsetLayout( {
			items: visibilityFields,
			label: mw.msg( 'campaignevents-eventpage-register-dialog-visibility-title' )
		} );

		const fieldsets = [ visibilityFieldset ];
		if ( this.showContributionStatsSection ) {
			fieldsets.push( this.getContributionStatsFieldset() );
		}

		let questionFields;
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
			const questionsFieldset = new OO.ui.FieldsetLayout( {
				items: questionFields,
				label: mw.msg( 'campaignevents-eventpage-register-dialog-questions-title' ),
				help: mw.msg( 'campaignevents-eventpage-register-dialog-questions-subtitle' ),
				helpInline: true
			} );
			fieldsets.push( questionsFieldset );

			fieldsets.push( this.getDataRetentionFieldset() );
		}

		const formPanel = new OO.ui.PanelLayout( {
			content: fieldsets,
			padded: true,
			scrollable: false,
			expanded: false
		} );
		this.$body.append( formPanel.$element );

		if ( this.policyMsg !== null ) {
			const policyPanel = new OO.ui.PanelLayout( {
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
		const publicIcon = new OO.ui.IconWidget( { icon: 'globe' } ),
			privateIcon = new OO.ui.IconWidget( { icon: 'lock' } );
		const $publicHelpText = mw.message( 'campaignevents-registration-confirmation-helptext-public' ).parseDom(),
			$privateHelpText = this.groupsCanViewPrivateMessage;
		const $publicLabel = $( '<div>' )
			.addClass( 'ext-campaignevents-registration-visibility-label' )
			.append( publicIcon.$element, $( '<span>' ).append( $publicHelpText ) );
		const $privateLabel = $( '<div>' )
			.addClass( 'ext-campaignevents-registration-visibility-label' )
			.append( privateIcon.$element, $( '<span>' ).append( $privateHelpText ) );

		const visibilityHelpLabel = new OO.ui.LabelWidget( {
				label: this.publicRegistration ? $publicLabel : $privateLabel
			} ),
			visibilityHelpField = new OO.ui.FieldLayout( visibilityHelpLabel );

		const visibilityToggle = new OO.ui.ToggleSwitchWidget( {
			value: this.publicRegistration
		} );
		const self = this;
		visibilityToggle.on( 'change', ( value ) => {
			self.publicRegistration = value;
			visibilityHelpLabel.setLabel( self.publicRegistration ? $publicLabel : $privateLabel );
			self.updateSize();
		} );

		const visibilityField = new OO.ui.FieldLayout(
			visibilityToggle,
			{
				classes: [ 'ext-campaignevents-registration-visibility-toggle-field' ],
				label: mw.msg( 'campaignevents-registration-confirmation-toggle-public' )
			}
		);

		return [ visibilityField, visibilityHelpField ];
	};

	/**
	 * Returns a fieldset with controls for contribution statistics preferences.
	 *
	 * @return {OO.ui.FieldsetLayout}
	 */
	ParticipantRegistrationDialog.prototype.getContributionStatsFieldset = function () {
		const checkbox = new OO.ui.CheckboxInputWidget( {
			selected: this.showContributionPrompt
		} );
		checkbox.on( 'change', ( selected ) => {
			this.showContributionPrompt = selected;
		} );

		const helpMsg = mw.message( 'campaignevents-eventpage-register-dialog-contribstats-help' )
			.params( [ this.contributionsTabURL ] )
			.parse();

		const field = new OO.ui.FieldLayout( checkbox, {
			label: mw.msg( 'campaignevents-eventpage-register-dialog-contribstats-label' ),
			align: 'inline',
			help: new OO.ui.HtmlSnippet( helpMsg ),
			helpInline: true
		} );

		return new OO.ui.FieldsetLayout( {
			items: [ field ],
			label: mw.msg( 'campaignevents-eventpage-register-dialog-contribstats-title' )
		} );
	};

	/**
	 * Returns a fieldset with information about the data retention policy.
	 *
	 * @return {OO.ui.FieldsetLayout}
	 */
	ParticipantRegistrationDialog.prototype.getDataRetentionFieldset = function () {
		let retentionMsg = mw.message( 'campaignevents-eventpage-register-dialog-retention-base' ).escaped();
		if ( this.aggregationTimestamp !== null ) {
			const curTimestamp = Math.floor( Date.now() / 1000 ),
				timeRemaining = parseInt( this.aggregationTimestamp ) - curTimestamp;

			let additionalRetentionMsg;
			if ( timeRemaining < 60 * 60 * 24 ) {
				additionalRetentionMsg = mw.message( 'campaignevents-eventpage-register-dialog-retention-hours' ).parse();
			} else {
				const remainingDays = Math.floor( timeRemaining / ( 60 * 60 * 24 ) );
				additionalRetentionMsg = mw.message( 'campaignevents-eventpage-register-dialog-retention-days' )
					.params( [ mw.language.convertNumber( remainingDays ) ] )
					.parse();
			}
			retentionMsg += mw.message( 'word-separator' ).escaped() + additionalRetentionMsg;
		}
		const retentionInfoField = new OO.ui.FieldLayout(
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
					answers: this.eventQuestions.getParticipantAnswers(),
					showContributionPrompt: this.showContributionPrompt
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
