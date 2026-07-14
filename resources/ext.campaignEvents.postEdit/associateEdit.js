'use strict';

/**
 * Associate the given revision on the current wiki with an event.
 *
 * @param {number} eventID
 * @param {number} revisionID
 * @return {jQuery.Promise}
 */
module.exports = function associateEdit( eventID, revisionID ) {
	const wikiID = mw.config.get( 'wgDBname' );
	return new mw.Rest().put(
		`/campaignevents/v0/event_registration/${ eventID }/edits/${ wikiID }/${ revisionID }`,
		{ token: mw.user.tokens.get( 'csrfToken' ) }
	);
};
