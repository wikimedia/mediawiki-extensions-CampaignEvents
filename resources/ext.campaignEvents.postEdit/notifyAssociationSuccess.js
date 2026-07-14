'use strict';

/**
 * Show the lightweight confirmation shown after an edit is associated with an event, whether the
 * association happened through the dialog or automatically (single-worklist auto-association).
 *
 * @param {number} eventID
 * @param {string} eventName
 */
module.exports = function notifyAssociationSuccess( eventID, eventName ) {
	mw.notify(
		mw.message(
			'campaignevents-postedit-success-text',
			eventName,
			mw.util.getUrl( `Special:EventDetails/${ eventID }`, { tab: 'ContributionsPanel' } )
		),
		{
			type: 'success',
			autoHideSeconds: 'long'
		}
	);
};
