( function () {
	'use strict';

	/**
	 * Dialog used to display a confirmation notice to the user before registering.
	 *
	 * @param {Object} config Configuration options
	 * @param {string|null} config.policyMsg Policy acknowledgement message
	 * @extends OO.ui.ProcessDialog
	 * @constructor
	 */
	function RegistrationConfirmationDialog( config ) {
		RegistrationConfirmationDialog.super.call( this, config );
		this.$element.addClass( 'ext-campaignevents-registration-confirmation-dialog' );
		this.policyMsg = config.policyMsg;
		this.publicRegistration = true;
		this.$helpText = $( '<span>' );
		this.$helpText.addClass( 'campaignevents-registration-confirmation-helptext' );
		this.icon = new OO.ui.IconWidget( {
			icon: this.publicRegistration ? 'globe' : 'lock'
		} );
		this.publicHelpText = mw.message( 'campaignevents-registration-confirmation-helptext-public' ).parseDom();
		this.privateHelpText = mw.message( 'campaignevents-registration-confirmation-helptext-private' ).parseDom();
		this.toggleSwitch = new OO.ui.ToggleSwitchWidget( {
			value: true
		} );

	}

	OO.inheritClass( RegistrationConfirmationDialog, OO.ui.ProcessDialog );

	RegistrationConfirmationDialog.static.name = 'campaignEventsRegistrationConfirmationDialog';
	RegistrationConfirmationDialog.static.title = mw.msg( 'campaignevents-eventpage-register-confirmation-title' );
	RegistrationConfirmationDialog.static.actions = [
		{
			flags: [ 'safe', 'close' ],
			action: 'cancel'
		},
		{
			flags: [ 'primary', 'progressive' ],
			label: mw.msg( 'campaignevents-eventpage-register-confirmation-confirm' ),
			action: 'confirm'
		}
	];

	RegistrationConfirmationDialog.prototype.initialize = function () {
		RegistrationConfirmationDialog.super.prototype.initialize.apply( this );

		var self = this;
		self.toggleSwitch.on( 'change',
			function ( value ) {
				self.publicRegistration = value;
				self.$helpText.empty()
					.append( self.getHelpText( self.publicRegistration ) );
				self.icon.setIcon( self.publicRegistration ? 'globe' : 'lock' );
				self.updateSize();
			}
		);
		self.$helpText.append( self.getHelpText( self.publicRegistration ) );
		self.$body.append( self.getDialogContent( self.publicRegistration ) );
	};

	RegistrationConfirmationDialog.prototype.getActionProcess = function ( action ) {
		if ( action === 'confirm' ) {
			return new OO.ui.Process( function () {
				this.close( {
					action: action,
					isPrivate: !this.publicRegistration
				} );
			}, this );
		}
		return RegistrationConfirmationDialog.super.prototype.getActionProcess.call( this, action )
			.next( function () {
				this.close();
			}, this );
	};

	RegistrationConfirmationDialog.prototype.getHelpText = function () {
		return this.publicRegistration ?
			this.publicHelpText :
			this.privateHelpText;
	};

	RegistrationConfirmationDialog.prototype.getDialogContent = function () {
		var self = this,
			icon = this.icon,
			fieldLayout = new OO.ui.FieldLayout( this.toggleSwitch,
				{
					classes: [ 'campaignevents-registration-confirmation-toggle-fieldLayout' ],
					label: mw.msg( 'campaignevents-registration-confirmation-toggle-public' )
				} ),
			regTypePanel = new OO.ui.PanelLayout(
				{
					content: [ icon, self.$helpText ],
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

	module.exports = RegistrationConfirmationDialog;
}() );
