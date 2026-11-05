/**
 * Card elements: small UI components that a card shows for one page.
 *
 * The elements do not fetch data. The card gets the data from its own source and gives it to
 * them as props. Thus the same element works with the event worklist endpoint now, and with
 * other endpoints later.
 */
module.exports = {
	ImpactChip: require( './ImpactChip.vue' ),
	ViewCount: require( './ViewCount.vue' )
};
