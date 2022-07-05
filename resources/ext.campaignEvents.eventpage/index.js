/* eslint-disable no-jquery/no-global-selector */
( function () {
	'use strict';

	var EventDetailsDialog = require( './EventDetailsDialog.js' ),
		ConfirmUnregistrationDialog = require( './ConfirmUnregistrationDialog.js' ),
		PolicyAcknowledgementDialog = require( './PolicyAcknowledgementDialog.js' ),
		EnableRegistrationDialog = require( './EnableRegistrationDialog.js' ),
		configData = require( './data.json' ),
		windowManager = new OO.ui.WindowManager(),
		detailsDialog = new EventDetailsDialog( {} ),
		policyAcknowledgementDialog,
		confirmUnregistrationDialog;

	windowManager.addWindows( [ detailsDialog ] );

	function redirectToLogin() {
		var currentUri = new mw.Uri();
		// Prevent duplicate "title" param
		delete currentUri.query.title;
		// TODO Should we also add a parameter to show a modal right after the user comes back?

		window.location.href = mw.util.getUrl(
			'Special:UserLogin',
			{
				returnto: mw.config.get( 'wgPageName' ),
				returntoquery: currentUri.getQueryString()
			}
		);
	}

	function logRequestError( errData ) {
		var errorText;
		if ( errData.xhr ) {
			errorText = errData.xhr.responseText || 'Unknown error';
		} else {
			errorText = 'Unknown error';
		}
		mw.log.error( errorText );
	}

	/**
	 * @return {jQuery.Promise}
	 */
	function registerUser() {
		var registrationID = mw.config.get( 'wgCampaignEventsEventID' );
		return new mw.Rest().put(
			'/campaignevents/v0/event_registration/' + registrationID + '/participants/self',
			{ token: mw.user.tokens.get( 'csrfToken' ) }
		)
			.done( function () {
				mw.notify(
					mw.message( 'campaignevents-eventpage-register-notification', mw.config.get( 'wgTitle' ) ),
					{ type: 'success' }
				);
				$( '.ext-campaignevents-eventpage-register-btn' ).addClass( 'ext-campaignevents-eventpage-hidden-action' );
				$( '.ext-campaignevents-eventpage-unregister-layout' ).removeClass( 'ext-campaignevents-eventpage-hidden-action' );
			} )
			.fail( function ( _err, errData ) {
				logRequestError( errData );
			} );
	}

	/**
	 * @return {jQuery.Promise}
	 */
	function unregisterUser() {
		var registrationID = mw.config.get( 'wgCampaignEventsEventID' );
		return new mw.Rest().delete(
			'/campaignevents/v0/event_registration/' + registrationID + '/participants/self',
			{ token: mw.user.tokens.get( 'csrfToken' ) }
		)
			.done( function () {
				$( '.ext-campaignevents-eventpage-unregister-layout' ).addClass( 'ext-campaignevents-eventpage-hidden-action' );
				$( '.ext-campaignevents-eventpage-register-btn' ).removeClass( 'ext-campaignevents-eventpage-hidden-action' );
			} )
			.fail( function ( _err, errData ) {
				logRequestError( errData );
			} );
	}

	function getPolicyAcknowledgementDialog( msg ) {
		if ( !policyAcknowledgementDialog ) {
			policyAcknowledgementDialog = new PolicyAcknowledgementDialog( { policyMsg: msg } );
			windowManager.addWindows( [ policyAcknowledgementDialog ] );
		}
		return policyAcknowledgementDialog;
	}

	/**
	 * @return {jQuery.promise}
	 */
	function maybeRequirePolicyAcknowledgement() {
		if ( !configData.policyMsg ) {
			return $.Deferred().resolve( { action: 'confirm' } );
		}
		var policyDialog = getPolicyAcknowledgementDialog( configData.policyMsg );
		windowManager.closeWindow( windowManager.getCurrentWindow() );
		return windowManager.openWindow( policyDialog ).closed;
	}

	function getConfirmUnregistrationDialog() {
		if ( !confirmUnregistrationDialog ) {
			confirmUnregistrationDialog = new ConfirmUnregistrationDialog( {} );
			windowManager.addWindows( [ confirmUnregistrationDialog ] );
		}
		return confirmUnregistrationDialog;
	}

	function installRegisterAndUnregisterHandlers() {
		$( '.ext-campaignevents-eventpage-register-btn' ).on( 'click', function ( e ) {
			e.preventDefault();
			var $btn = $( this );
			if ( mw.user.isAnon() ) {
				redirectToLogin();
			} else {
				maybeRequirePolicyAcknowledgement().then( function ( data ) {
					if ( data && data.action === 'confirm' ) {
						registerUser()
							.fail( function () {
								// Fall back to the special page
								// TODO MVP Some errors (at least) could be surfaced here,
								// e.g., if the event is over.
								$btn.off( 'click' ).find( 'a' )[ 0 ].click();
							} );
					}
				} );
			}
		} );

		$( '.ext-campaignevents-event-unregister-btn' ).on( 'click', function ( e ) {
			e.preventDefault();
			var $btn = $( this );
			var confirmDialog = getConfirmUnregistrationDialog();
			windowManager.closeWindow( windowManager.getCurrentWindow() );
			windowManager.openWindow( confirmDialog ).closed.then( function ( data ) {
				if ( data && data.action === 'confirm' ) {
					unregisterUser()
						.fail( function () {
							// Fall back to the special page
							// TODO MVP Some errors (at least) could be surfaced here,
							//  e.g., if the event is over.
							$btn.off( 'click' ).find( 'a' )[ 0 ].click();
						} );
				}
			} );
		} );
	}

	function showEnableRegistrationDialogOnPageCreation() {
		var enableRegistrationURL = mw.config.get( 'wgCampaignEventsEnableRegistrationURL' );
		if ( !enableRegistrationURL ) {
			return;
		}
		mw.hook( 'postEdit' ).add( function () {
			var action = mw.config.get( 'wgPostEdit' );
			if ( action === 'created' ) {
				var enableRegistrationDialog = new EnableRegistrationDialog( {} );
				windowManager.addWindows( [ enableRegistrationDialog ] );
				windowManager.openWindow( enableRegistrationDialog ).closed.then(
					function ( data ) {
						if ( data && data.action === 'confirm' ) {
							window.location.assign( enableRegistrationURL );
						}
					}
				);
			}
		} );
	}

	$( function () {
		$( document.body ).append( windowManager.$element );

		installRegisterAndUnregisterHandlers();

		$( '.ext-campaignevents-event-details-btn' ).on( 'click', function ( e ) {
			e.preventDefault();
			windowManager.openWindow( detailsDialog );
		} );

		showEnableRegistrationDialogOnPageCreation();
	} );
}() );
