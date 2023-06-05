/* eslint-disable no-jquery/no-global-selector */
// # sourceURL=index.js
( function () {
	'use strict';

	var EventDetailsDialog = require( './EventDetailsDialog.js' ),
		ConfirmUnregistrationDialog = require( './ConfirmUnregistrationDialog.js' ),
		ParticipantRegistrationDialog = require( './ParticipantRegistrationDialog.js' ),
		EnableRegistrationDialog = require( './EnableRegistrationDialog.js' ),
		confirmUnregistrationDialog,
		participantRegistrationDialog,
		configData = require( './data.json' ),
		windowManager = new OO.ui.WindowManager(),
		detailsDialog = new EventDetailsDialog( {} );

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

	var SUCCESS_NOTIFICATION_COOKIE = 'showsuccessnotif';
	/**
	 * Checks whether the user just registered for this event, and thus a succes
	 * notification should be shown. The cookie has a very short expiry and is
	 * removed immediately on page refresh.
	 */
	function maybeShowRegistrationSuccessNotification() {
		if ( mw.cookie.get( SUCCESS_NOTIFICATION_COOKIE ) ) {
			mw.cookie.set( SUCCESS_NOTIFICATION_COOKIE, 1, { expires: 1 } );
			mw.notify(
				mw.message( 'campaignevents-eventpage-register-notification', mw.config.get( 'wgTitle' ) ),
				{ type: 'success' }
			);
		}
	}

	/**
	 * @param {boolean} privateRegistration
	 * @return {jQuery.Promise}
	 */
	function registerUser( privateRegistration ) {
		var registrationID = mw.config.get( 'wgCampaignEventsEventID' );

		return new mw.Rest().put(
			'/campaignevents/v0/event_registration/' + registrationID + '/participants/self',
			{
				token: mw.user.tokens.get( 'csrfToken' ),
				// eslint-disable-next-line camelcase
				is_private: privateRegistration
			}
		)
			.done( function () {
				mw.cookie.set( SUCCESS_NOTIFICATION_COOKIE, 1, { expires: 30 } );
				// Reload the page so that the number and list of participants are updated.
				// TODO This should be improved at some point, see T312646#8105313
				window.location.reload();
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
				window.location.reload();
			} )
			.fail( function ( _err, errData ) {
				logRequestError( errData );
			} );
	}

	function getParticipantRegistrationDialog( msg ) {
		if ( !participantRegistrationDialog ) {
			participantRegistrationDialog = new ParticipantRegistrationDialog(
				{
					policyMsg: msg
				} );
			windowManager.addWindows( [ participantRegistrationDialog ] );
		}
		return participantRegistrationDialog;
	}

	/**
	 * @return {jQuery.promise}
	 */
	function showParticipantRegistrationDialog() {
		participantRegistrationDialog = getParticipantRegistrationDialog( configData.policyMsg );
		windowManager.closeWindow( windowManager.getCurrentWindow() );
		return windowManager.openWindow( participantRegistrationDialog ).closed;
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
				return;
			}
			showParticipantRegistrationDialog().then( function ( data ) {
				if ( data && data.action === 'confirm' ) {
					registerUser( data.isPrivate )
						.fail( function () {
							// Fall back to the special page
							// TODO We could also show an error here once T269492 and T311423
							//  are resolved
							$btn.off( 'click' ).find( 'a' )[ 0 ].click();
						} );
				}
			} );
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
							// TODO We could also show an error here once T269492 and T311423
							//  are resolved
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

		maybeShowRegistrationSuccessNotification();
		installRegisterAndUnregisterHandlers();

		$( '.ext-campaignevents-event-details-btn' ).on( 'click', function ( e ) {
			e.preventDefault();
			windowManager.openWindow( detailsDialog );
		} );

		showEnableRegistrationDialogOnPageCreation();
	} );
}() );
