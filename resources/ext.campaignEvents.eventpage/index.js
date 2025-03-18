/* eslint-disable no-jquery/no-global-selector */
// # sourceURL=index.js

( function () {
	'use strict';

	const EventDetailsDialog = require( './EventDetailsDialog.js' ),
		ConfirmUnregistrationDialog = require( './ConfirmUnregistrationDialog.js' ),
		ParticipantRegistrationDialog = require( './ParticipantRegistrationDialog.js' ),
		EnableRegistrationDialog = require( './EnableRegistrationDialog.js' ),
		ManageRegistrationWidget = require( './ManageRegistrationWidget.js' ),
		EventQuestions = require( './EventQuestions.js' ),
		timeZoneConverter = require( '../TimeZoneConverter.js' ),
		eventID = mw.config.get( 'wgCampaignEventsEventID' ),
		eventQuestionsData = mw.config.get( 'wgCampaignEventsEventQuestions' ),
		configData = require( './data.json' ),
		userIsParticipant = mw.config.get( 'wgCampaignEventsParticipantIsPublic' ) !== null,
		userIsRegisteredPublicly = mw.config.get( 'wgCampaignEventsParticipantIsPublic' ),
		aggregationTimestamp = mw.config.get( 'wgCampaignEventsAggregationTimestamp' ),
		answersAlreadyAggregated = mw.config.get( 'wgCampaignEventsAnswersAlreadyAggregated' ),
		hasUpdatedRegistration = mw.config.get( 'wgCampaignEventsRegistrationUpdated' ),
		isNewRegistration = mw.config.get( 'wgCampaignEventsIsNewRegistration' ),
		isTestRegistration = mw.config.get( 'wgCampaignEventsIsTestRegistration' ),
		registrationUpdatedWarnings = mw.config.get( 'wgCampaignEventsRegistrationUpdatedWarnings' ),
		windowManager = new OO.ui.WindowManager(),
		detailsDialog = new EventDetailsDialog( eventID, userIsParticipant );
	let confirmUnregistrationDialog,
		participantRegistrationDialog;

	windowManager.addWindows( [ detailsDialog ] );
	detailsDialog
		.on( 'editregistration', handleRegistrationOrEdit )
		.on( 'cancelregistration', handleCancelRegistration );

	function redirectToLogin() {
		const currentQuery = new URL( window.location.href ).searchParams;
		// Prevent duplicate "title" param
		currentQuery.delete( 'title' );
		// TODO Should we also add a parameter to show a modal right after the user comes back?

		window.location.href = mw.util.getUrl(
			mw.user.isTemp() ? 'Special:CreateAccount' : 'Special:UserLogin',
			{
				returnto: mw.config.get( 'wgPageName' ),
				returntoquery: currentQuery.toString()
			}
		);
	}

	function logRequestError( errData ) {
		let errorText;
		if ( errData.xhr ) {
			errorText = errData.xhr.responseText || 'Unknown error';
		} else {
			errorText = 'Unknown error';
		}
		mw.log.error( errorText );
	}

	const SUCCESS_NOTIFICATION_COOKIE = 'showsuccessnotif';
	const SUCCESS_COOKIE_NEW_REGISTRATION = 'new',
		SUCCESS_COOKIE_REGISTRATION_UPDATED = 'update';
	/**
	 * Checks whether the user just registered for this event, and thus a succes
	 * notification should be shown. The cookie has a very short expiry and is
	 * removed immediately on page refresh.
	 */
	function maybeShowRegistrationSuccessNotification() {
		const cookieVal = mw.cookie.get( SUCCESS_NOTIFICATION_COOKIE );
		if ( cookieVal ) {
			mw.cookie.set( SUCCESS_NOTIFICATION_COOKIE, 0, { expires: 1 } );
			let $msg;
			if ( cookieVal === SUCCESS_COOKIE_NEW_REGISTRATION ) {
				$msg = $( '<p>' ).append(
					mw.message(
						'campaignevents-eventpage-register-notification',
						mw.config.get( 'wgTitle' )
					).parseDom()
				).add(
					$( '<p>' )
						.append( mw.message( 'campaignevents-eventpage-register-notification-more' ).parseDom() )
				);
			} else {
				$msg = mw.message( 'campaignevents-eventpage-register-notification-edit' ).parseDom();
			}
			mw.notify(
				$msg,
				{ type: 'success', classes: [ 'ext-campaignevents-eventpage-registered-notif' ] }
			);
		}
	}

	/**
	 * @param {boolean} privateRegistration
	 * @param {Object} answers
	 * @return {jQuery.Promise}
	 */
	function registerUser( privateRegistration, answers ) {
		const reqParams = {
			token: mw.user.tokens.get( 'csrfToken' ),
			// eslint-disable-next-line camelcase
			is_private: privateRegistration,
			answers: answers
		};
		return new mw.Rest().put(
			'/campaignevents/v0/event_registration/' + eventID + '/participants/self',
			reqParams
		)
			.done( () => {
				const cookieVal = userIsParticipant ?
					SUCCESS_COOKIE_REGISTRATION_UPDATED :
					SUCCESS_COOKIE_NEW_REGISTRATION;
				mw.cookie.set( SUCCESS_NOTIFICATION_COOKIE, cookieVal, { expires: 30 } );
				// Reload the page so that the number and list of participants are updated.
				// TODO This should be improved at some point, see T312646#8105313
				window.location.reload();
			} )
			.fail( ( _err, errData ) => {
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
			.done( () => {
				window.location.reload();
			} )
			.fail( ( _err, errData ) => {
				logRequestError( errData );
			} );
	}

	function getParticipantRegistrationDialog( msg, eventQuestions ) {
		if ( !participantRegistrationDialog ) {
			let curParticipantData;
			if ( userIsParticipant ) {
				curParticipantData = {
					public: userIsRegisteredPublicly,
					aggregationTimestamp: aggregationTimestamp
				};
			}
			participantRegistrationDialog = new ParticipantRegistrationDialog(
				{
					policyMsg: msg,
					curParticipantData: curParticipantData,
					answersAggregated: answersAlreadyAggregated,
					eventQuestions: eventQuestions,
					groupsCanViewPrivateMessage: mw.config.get( 'wgCampaignEventsPrivateAccessMessage' )
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
		showParticipantRegistrationDialog().then( ( data ) => {
			if ( data && data.action === 'confirm' ) {
				registerUser( data.isPrivate, data.answers )
					.fail( () => {
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
		const confirmDialog = getConfirmUnregistrationDialog();
		windowManager.closeWindow( windowManager.getCurrentWindow() );
		windowManager.openWindow( confirmDialog ).closed.then( ( data ) => {
			if ( data && data.action === 'confirm' ) {
				unregisterUser()
					.fail( () => {
						// Fall back to the special page
						// TODO We could also show an error here once T269492 and T311423
						//  are resolved
						window.location.assign( mw.util.getUrl( 'Special:CancelEventRegistration/' + eventID ) );
					} );
			}
		} );
	}

	function showEnableRegistrationDialogOnPageCreation() {
		const enableRegistrationURL = mw.config.get( 'wgCampaignEventsEnableRegistrationURL' );
		if ( !enableRegistrationURL ) {
			return;
		}
		mw.hook( 'postEdit' ).add( () => {
			const action = mw.config.get( 'wgPostEdit' );
			if ( action === 'created' ) {
				const enableRegistrationDialog = new EnableRegistrationDialog( {} );
				windowManager.addWindows( [ enableRegistrationDialog ] );
				windowManager.openWindow( enableRegistrationDialog ).closed.then(
					( data ) => {
						if ( data && data.action === 'confirm' ) {
							window.location.assign( enableRegistrationURL );
						}
					}
				);
			}
		} );
	}

	/**
	 * Convert the displayed time and timezone according to the user's browser preferences,
	 * if the wiki timezone was used.
	 */
	function setupTimeConversion() {
		const $headerTime = $( '.ext-campaignevents-eventpage-header-time' ),
			$dialogTime = $( '.ext-campaignevents-eventpage-detailsdialog-time' );
		if ( $headerTime.length ) {
			timeZoneConverter.convert(
				$headerTime,
				'campaignevents-eventpage-header-dates'
			);
		}
		if ( $dialogTime.length ) {
			timeZoneConverter.convert(
				$dialogTime,
				'campaignevents-eventpage-dialog-dates'
			);
		}
	}

	/**
	 * Replace the "manage registration" layout of two buttons with a single menu, if present.
	 */
	function replaceManageRegistrationLayout() {
		const $layout = $( '.ext-campaignevents-eventpage-manage-registration-layout' );
		if ( !$layout.length ) {
			return;
		}

		const menu = new ManageRegistrationWidget( eventID, {} );

		menu
			.on( 'editregistration', handleRegistrationOrEdit )
			.on( 'cancelregistration', handleCancelRegistration );

		$layout.replaceWith( menu.$element );
	}

	/**
	 * If the user has just updated the registration, show a success notifications, and warnings
	 * if there are any.
	 */
	function maybeShowRegistrationUpdatedNotification() {
		if ( !hasUpdatedRegistration ) {
			return;
		}

		const baseMsg = isNewRegistration ?
			mw.message( 'campaignevents-eventpage-registration-enabled-notification' ) :
			mw.message( 'campaignevents-eventpage-registration-edit-notification' );
		let $msg = baseMsg;
		if ( !isTestRegistration ) {
			$msg = $( '<p>' ).append( baseMsg.parseDom() ).add(
				$( '<p>' ).append(
					mw.message( 'campaignevents-eventpage-registration-updated-notification-list' ).parseDom()
				)
			);
		}

		mw.notify(
			$msg,
			{ type: 'success', classes: [ 'ext-campaignevents-eventpage-registration-success-notif' ] }
		);
		registrationUpdatedWarnings.forEach( ( warning ) => {
			mw.notify( warning, { type: 'warn' } );
		} );
	}

	$( () => {
		$( document.body ).append( windowManager.$element );
		replaceManageRegistrationLayout();
		detailsDialog.populateFooter();

		maybeShowRegistrationSuccessNotification();
		setupTimeConversion();

		$( '.ext-campaignevents-eventpage-register-btn' ).on( 'click', ( e ) => {
			e.preventDefault();
			handleRegistrationOrEdit();
		} );
		$( '.ext-campaignevents-eventpage-details-btn' ).on( 'click', ( e ) => {
			e.preventDefault();
			windowManager.openWindow( detailsDialog );
		} );

		showEnableRegistrationDialogOnPageCreation();
		maybeShowRegistrationUpdatedNotification();
	} );
}() );
