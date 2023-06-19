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
		this.$visibilityHelpText = $( '<span>' );
		this.$visibilityHelpText.addClass( 'ext-campaignevents-registration-visibility-helptext' );
		this.icon = new OO.ui.IconWidget( {
			icon: this.publicRegistration ? 'globe' : 'lock'
		} );
		this.publicHelpText = mw.message( 'campaignevents-registration-confirmation-helptext-public' ).parseDom();
		this.privateHelpText = mw.message( 'campaignevents-registration-confirmation-helptext-private' ).parseDom();
		this.toggleSwitch = new OO.ui.ToggleSwitchWidget( {
			value: this.publicRegistration
		} );

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
					}
				]
			},
			data
		);
		return ParticipantRegistrationDialog.super.prototype.getSetupProcess.call( this, data );
	};

	ParticipantRegistrationDialog.prototype.initialize = function () {
		ParticipantRegistrationDialog.super.prototype.initialize.apply( this );

		var self = this;
		self.toggleSwitch.on( 'change',
			function ( value ) {
				self.publicRegistration = value;
				self.$visibilityHelpText.empty()
					.append( self.getHelpText( self.publicRegistration ) );
				self.icon.setIcon( self.publicRegistration ? 'globe' : 'lock' );
				self.updateSize();
			}
		);
		self.$visibilityHelpText.append( self.getHelpText( self.publicRegistration ) );
		self.$body.append( self.getDialogContent( self.publicRegistration ) );
	};

	ParticipantRegistrationDialog.prototype.getActionProcess = function ( action ) {
		if ( action === 'confirm' ) {
			return new OO.ui.Process( function () {
				this.close( {
					action: action,
					isPrivate: !this.publicRegistration
				} );
			}, this );
		}
		return ParticipantRegistrationDialog.super.prototype.getActionProcess.call( this, action )
			.next( function () {
				this.close();
			}, this );
	};

	ParticipantRegistrationDialog.prototype.getHelpText = function () {
		return this.publicRegistration ?
			this.publicHelpText :
			this.privateHelpText;
	};

	ParticipantRegistrationDialog.prototype.getDialogContent = function () {
		var self = this,
			icon = this.icon,
			fieldLayout = new OO.ui.FieldLayout( this.toggleSwitch,
				{
					classes: [ 'ext-campaignevents-registration-visibility-toggle-field' ],
					label: mw.msg( 'campaignevents-registration-confirmation-toggle-public' )
				} ),
			regTypePanel = new OO.ui.PanelLayout(
				{
					content: [ icon, self.$visibilityHelpText ],
					padded: false,
					scrollable: false,
					expanded: false,
					classes: [ 'ext-campaignevents-registration-type-panel' ]
				} ),
			fieldset = new OO.ui.FieldsetLayout( {
				items: [ fieldLayout ],
				classes: [ 'ext-campaignevents-registration-ack-fieldset' ]
			} );
		var fieldsPanel = new OO.ui.PanelLayout( {
			content: [ fieldset, regTypePanel ],
			padded: true,
			scrollable: false,
			expanded: false
		} );
		var $elements = $();
		$elements = $elements.add( fieldsPanel.$element );
		if ( this.policyMsg !== null ) {
			var policyPanel = new OO.ui.PanelLayout( {
				$content: this.policyMsg,
				padded: true,
				scrollable: false,
				expanded: false,
				classes: [ 'ext-campaignevents-policy-message-panel' ]
			} );
			$elements = $elements.add( policyPanel.$element );
		}
		return $elements;
	};

	module.exports = ParticipantRegistrationDialog;
}() );
