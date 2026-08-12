'use strict';

const config = require( './config.json' );

const CE_FROM_PARAM = 'ce_from';
// Opaque value for traffic from the worklist article promotion flow.
// Hash-like on purpose, to discourage casual tampering. Kept private;
// callers use appendOriginToUrl() / hasPromotionOriginParam().
const CE_FROM_WORKLIST_ARTICLE_PROMOTION = '61ac4b96c6aed176';

const INSTRUMENT = 'campaignevents-worklist-article-promotion-funnel';

const ACTION_SOURCE = {
	PROMOTION_MODAL: 'promotion_modal',
	EVENT_PAGE_FROM_PROMOTION: 'event_page_from_promotion',
	EVENT_PAGE_DIRECT: 'event_page_direct',
	REGISTER_MODAL_FROM_PROMOTION: 'register_modal_from_promotion',
	REGISTER_MODAL_DIRECT: 'register_modal_direct'
};

/**
 * @return {boolean}
 */
function isWorklistArticlePromotionTrackingEnabled() {
	return !!config.CampaignEventsEnableWorklistEventDiscoveryTracking;
}

/**
 * @param {string} action e.g. 'impression' | 'click'
 * @param {string} actionSource A constant in ACTION_SOURCE
 * @param {Object} params
 */
function submitInstrumentAction( action, actionSource, params ) {
	if ( !isWorklistArticlePromotionTrackingEnabled() ) {
		return;
	}

	mw.loader.using( 'ext.testKitchen' ).then( async () => {
		const interactionData = {
			/* eslint-disable camelcase */
			action_source: actionSource,
			action_context: await buildActionContext( params )
			/* eslint-enable camelcase */
		};

		mw.testKitchen
			.getInstrument( INSTRUMENT )
			.send( action, interactionData );
	} ).catch( ( error ) => {
		mw.log( 'Error loading ext.testKitchen module:', error );
	} );
}

/**
 * Whether the current URL has the worklist article promotion origin param.
 *
 * @return {boolean}
 */
function hasPromotionOriginParam() {
	return new URLSearchParams( window.location.search )
		.get( CE_FROM_PARAM ) === CE_FROM_WORKLIST_ARTICLE_PROMOTION;
}

/**
 * @param {string} url
 * @return {string}
 */
function appendOriginToUrl( url ) {
	const u = new URL( url, window.location.origin );
	u.searchParams.set( CE_FROM_PARAM, CE_FROM_WORKLIST_ARTICLE_PROMOTION );
	return u.toString();
}

/**
 * Polyfill for the upcoming `performer.name_hash` and `performer.session_id_hash` contextual
 * attributes. To drop in favour of native attributes once T436524 is resolved.
 *
 * @return {Object}
 */
async function generatePerformerIdentifier() {
	const username = mw.user.getName();
	let identifier, attributeName;
	if ( username !== null ) {
		identifier = username;
		attributeName = 'performer.name_hash';
	} else {
		identifier = mw.user.sessionId();
		attributeName = 'performer.session_id_hash';
	}

	const raw = INSTRUMENT + identifier;
	const encoder = new TextEncoder();
	const hashBuffer = await crypto.subtle.digest( 'SHA-256', encoder.encode( raw ) ),
		hashArray = Array.from( new Uint8Array( hashBuffer ) ),
		hashHex = hashArray.map( ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' );

	return {
		[ attributeName ]: hashHex
	};
}

/**
 * Wiki and user type come from instrument contextual attributes:
 * - mediawiki_database → mediawiki.database
 * - performer_is_logged_in / performer_is_temp → named | temp | anonymous
 *
 * @param {Object} params
 * @param {number} params.eventId
 * @param {number[]} params.eventIds
 * @return {Promise<string>}
 */
async function buildActionContext( params ) {
	const eventParams = {};
	if ( params.eventId ) {
		// eslint-disable-next-line camelcase
		eventParams.event_id = params.eventId;
	}
	if ( params.eventIds ) {
		// eslint-disable-next-line camelcase
		eventParams.event_ids = params.eventIds;
	}
	const context = Object.assign(
		eventParams,
		await generatePerformerIdentifier()
	);

	return JSON.stringify( context );
}

/**
 * @param {string} action
 * @param {Object} params
 * @param {number[]} params.eventIds For action 'impression'
 * @param {number} params.eventId For action 'click'
 */
async function recordPromotionModalInteraction( action, params ) {
	submitInstrumentAction(
		action,
		ACTION_SOURCE.PROMOTION_MODAL,
		params
	);
}

/**
 * Tracks Register on the event page.
 *
 * @param {string} action
 * @param {Object} params
 * @param {number} params.eventId
 */
async function recordEventPageRegisterInteraction( action, params ) {
	const actionSource = hasPromotionOriginParam() ?
		ACTION_SOURCE.EVENT_PAGE_FROM_PROMOTION :
		ACTION_SOURCE.EVENT_PAGE_DIRECT;
	submitInstrumentAction(
		action,
		actionSource,
		params
	);
}

/**
 * Tracks the registration modal shown after clicking Register on the event page,
 * and the user clicking the final Register button inside the modal.
 *
 * @param {string} action e.g. 'impression' | 'click'
 * @param {Object} params
 * @param {number} params.eventId
 */
async function recordRegisterModalInteraction( action, params ) {
	const actionSource = hasPromotionOriginParam() ?
		ACTION_SOURCE.REGISTER_MODAL_FROM_PROMOTION :
		ACTION_SOURCE.REGISTER_MODAL_DIRECT;
	submitInstrumentAction(
		action,
		actionSource,
		params
	);
}

module.exports = {
	appendOriginToUrl,
	recordPromotionModalInteraction,
	recordEventPageRegisterInteraction,
	recordRegisterModalInteraction
};
