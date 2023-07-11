/* eslint-disable no-jquery/no-global-selector */
// # sourceURL=index.js
( function () {
	'use strict';

	var EventDetailsDialog = require( './EventDetailsDialog.js' ),
		ConfirmUnregistrationDialog = require( './ConfirmUnregistrationDialog.js' ),
		ParticipantRegistrationDialog = require( './ParticipantRegistrationDialog.js' ),
		EnableRegistrationDialog = require( './EnableRegistrationDialog.js' ),
		ManageRegistrationWidget = require( './ManageRegistrationWidget.js' ),
		EventQuestions = require( './EventQuestions.js' ),
		confirmUnregistrationDialog,
		participantRegistrationDialog,
		eventID = mw.config.get( 'wgCampaignEventsEventID' ),
		eventQuestionsData = mw.config.get( 'wgCampaignEventsEventQuestions' ),
		configData = require( './data.json' ),
		userIsParticipant = mw.config.get( 'wgCampaignEventsParticipantIsPublic' ) !== null,
		userIsRegisteredPublicly = mw.config.get( 'wgCampaignEventsParticipantIsPublic' ),
		windowManager = new OO.ui.WindowManager(),
		detailsDialog = new EventDetailsDialog( eventID, userIsParticipant );

	windowManager.addWindows( [ detailsDialog ] );
	detailsDialog
		.on( 'editregistration', handleRegistrationOrEdit )
		.on( 'cancelregistration', handleCancelRegistration );

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
	var SUCCESS_COOKIE_NEW_REGISTRATION = 'new',
		SUCCESS_COOKIE_REGISTRATION_UPDATED = 'update';
	/**
	 * Checks whether the user just registered for this event, and thus a succes
	 * notification should be shown. The cookie has a very short expiry and is
	 * removed immediately on page refresh.
	 */
	function maybeShowRegistrationSuccessNotification() {
		var cookieVal = mw.cookie.get( SUCCESS_NOTIFICATION_COOKIE );
		if ( cookieVal ) {
			mw.cookie.set( SUCCESS_NOTIFICATION_COOKIE, 0, { expires: 1 } );
			var msg = cookieVal === SUCCESS_COOKIE_NEW_REGISTRATION ?
				mw.message( 'campaignevents-eventpage-register-notification', mw.config.get( 'wgTitle' ) ) :
				mw.message( 'campaignevents-eventpage-register-notification-edit' );

			mw.notify( msg, { type: 'success' } );
		}
	}

	/**
	 * @param {boolean} privateRegistration
	 * @param {Object} answers
	 * @return {jQuery.Promise}
	 */
	function registerUser( privateRegistration, answers ) {
		return new mw.Rest().put(
			'/campaignevents/v0/event_registration/' + eventID + '/participants/self',
			{
				token: mw.user.tokens.get( 'csrfToken' ),
				// eslint-disable-next-line camelcase
				is_private: privateRegistration,
				answers: answers
			}
		)
			.done( function () {
				var cookieVal = userIsParticipant ?
					SUCCESS_COOKIE_REGISTRATION_UPDATED :
					SUCCESS_COOKIE_NEW_REGISTRATION;
				mw.cookie.set( SUCCESS_NOTIFICATION_COOKIE, cookieVal, { expires: 30 } );
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
		return new mw.Rest().delete(
			'/campaignevents/v0/event_registration/' + eventID + '/participants/self',
			{ token: mw.user.tokens.get( 'csrfToken' ) }
		)
			.done( function () {
				window.location.reload();
			} )
			.fail( function ( _err, errData ) {
				logRequestError( errData );
			} );
	}

	function getParticipantRegistrationDialog( msg, eventQuestions ) {
		if ( !participantRegistrationDialog ) {
			var curParticipantData;
			if ( userIsParticipant ) {
				curParticipantData = {
					public: userIsRegisteredPublicly
				};
			}
			participantRegistrationDialog = new ParticipantRegistrationDialog(
				{
					policyMsg: msg,
					curParticipantData: curParticipantData,
					eventQuestions: eventQuestions
				} );
			windowManager.addWindows( [ participantRegistrationDialog ] );
		}
		return participantRegistrationDialog;
	}

	/**
	 * @return {jQuery.promise}
	 */
	function showParticipantRegistrationDialog() {
		participantRegistrationDialog = getParticipantRegistrationDialog(
			configData.policyMsg, new EventQuestions( eventQuestionsData )
		);
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

	/**
	 * Handles the user registering for this event or editing their registration.
	 */
	function handleRegistrationOrEdit() {
		if ( !mw.user.isNamed() ) {
			redirectToLogin();
			return;
		}
		showParticipantRegistrationDialog().then( function ( data ) {
			if ( data && data.action === 'confirm' ) {
				registerUser( data.isPrivate, data.answers )
					.fail( function () {
						// Fall back to the special page
						// TODO We could also show an error here once T269492 and T311423
						//  are resolved
						window.location.assign( mw.util.getUrl( 'Special:RegisterForEvent/' + eventID ) );
					} );
			}
		} );
	}

	/**
	 * Handles the user cancelling their registration for this event.
	 */
	function handleCancelRegistration() {
		var confirmDialog = getConfirmUnregistrationDialog();
		windowManager.closeWindow( windowManager.getCurrentWindow() );
		windowManager.openWindow( confirmDialog ).closed.then( function ( data ) {
			if ( data && data.action === 'confirm' ) {
				unregisterUser()
					.fail( function () {
						// Fall back to the special page
						// TODO We could also show an error here once T269492 and T311423
						//  are resolved
						window.location.assign( mw.util.getUrl( 'Special:CancelEventRegistration/' + eventID ) );
					} );
			}
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

	/**
	 * Replace the "manage registration" layout of two buttons with a single menu, if present.
	 */
	function replaceManageRegistrationLayout() {
		var $layout = $( '.ext-campaignevents-eventpage-manage-registration-layout' );
		if ( !$layout.length ) {
			return;
		}

		var menu = new ManageRegistrationWidget( eventID, {} );

		menu
			.on( 'editregistration', handleRegistrationOrEdit )
			.on( 'cancelregistration', handleCancelRegistration );

		$layout.replaceWith( menu.$element );
	}

	$( function () {
		$( document.body ).append( windowManager.$element );
		replaceManageRegistrationLayout();
		detailsDialog.populateFooter();

		maybeShowRegistrationSuccessNotification();

		$( '.ext-campaignevents-eventpage-register-btn' ).on( 'click', function ( e ) {
			e.preventDefault();
			handleRegistrationOrEdit();
		} );
		$( '.ext-campaignevents-event-details-btn' ).on( 'click', function ( e ) {
			e.preventDefault();
			windowManager.openWindow( detailsDialog );
		} );

		showEnableRegistrationDialogOnPageCreation();
	} );
}() );
