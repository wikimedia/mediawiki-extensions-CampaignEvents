( function () {
	'use strict';

	var ManageRegistrationWidget = require( './ManageRegistrationWidget.js' );

	/**
	 * Dialog which shows additional information about an event
	 *
	 * @param {number} eventID
	 * @param {boolean} userIsParticipant
	 * @extends OO.ui.ProcessDialog
	 * @constructor
	 */
	function EventDetailsDialog( eventID, userIsParticipant ) {
		EventDetailsDialog.super.call( this, {} );
		this.eventID = eventID;
		this.userIsParticipant = userIsParticipant;
		this.$element.addClass( 'ext-campaignevents-eventpage-detailsdialog' );
	}

	OO.inheritClass( EventDetailsDialog, OO.ui.ProcessDialog );

	EventDetailsDialog.static.name = 'campaignEventsDetailsDialog';
	EventDetailsDialog.static.size = 'large';
	EventDetailsDialog.static.title = mw.msg( 'campaignevents-eventpage-dialog-title' );
	EventDetailsDialog.static.actions = [
		{
			action: 'cancel',
			label: mw.msg( 'campaignevents-eventpage-dialog-action-close' ),
			flags: [ 'safe', 'close' ]
		}
	];

	EventDetailsDialog.prototype.initialize = function () {
		EventDetailsDialog.super.prototype.initialize.apply( this, arguments );
		this.content = new OO.ui.PanelLayout( {
			padded: true,
			expanded: false
		} );

		// eslint-disable-next-line no-jquery/no-global-selector
		this.content.$element.append( $( '#ext-campaignevents-eventpage-details-dialog-content' ) );
		this.$body.append( this.content.$element );
	};

	/**
	 * Populates the dialog footer with the relevant action elements.
	 */
	EventDetailsDialog.prototype.populateFooter = function () {
		var collabButton = new OO.ui.ButtonWidget( {
			flags: [ 'quiet', 'progressive' ],
			label: mw.msg( 'campaignevents-eventpage-btn-collaboration-list' ),
			classes: [
				'ext-campaignevents-eventpage-collaboration-list-btn'
			],
			href: mw.util.getUrl( 'Special:AllEvents' )
		} );
		this.$foot.append(
			collabButton.$element
		);
		if ( this.userIsParticipant ) {
			// eslint-disable-next-line no-jquery/no-global-selector
			this.$foot.append( $( '.ext-campaignevents-eventpage-participant-notice' ).clone( true ) );
			// Use an overlay attached to the dialog, so that it can extend outside of it.
			var $menuOverlay = $( '<div>' ).appendTo( this.$element );
			var manageRegistrationMenu = new ManageRegistrationWidget(
				this.eventID,
				{
					$overlay: $menuOverlay
				}
			);
			var that = this;
			manageRegistrationMenu
				.on( 'editregistration', function () {
					that.emit( 'editregistration' );
				} )
				.on( 'cancelregistration', function () {
					that.emit( 'cancelregistration' );
				} );
			this.$foot.append( manageRegistrationMenu.$element );
		} else {
			// eslint-disable-next-line no-jquery/no-global-selector
			this.$foot.append( $( '.ext-campaignevents-eventpage-action-element' ).clone( true ) );
		}
	};

	EventDetailsDialog.prototype.getBodyHeight = function () {
		return this.content.$element.outerHeight( true );
	};

	EventDetailsDialog.prototype.getActionProcess = function ( action ) {
		return EventDetailsDialog.super.prototype.getActionProcess.call( this, action )
			.next( function () {
				this.close();
			}, this );
	};

	module.exports = EventDetailsDialog;
}() );
