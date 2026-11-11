/**
 * Card elements: small UI components that a card shows for one page, and the loaders of their
 * data.
 *
 * The elements do not fetch data. The card gets the data with the composables useQuality()
 * and usePageData(), and gives it to the elements as props. The loaders combine the calls of
 * one tick into batches, and share the requests that are in progress. Thus many callers on
 * one screen cause one request for each batch. The quality loader gets the endpoint path from
 * its caller, so the event worklist and other lists can use it.
 */
const qualityLoader = require( './qualityLoader.js' );
const pageDataLoader = require( './pageDataLoader.js' );

module.exports = {
	ImpactChip: require( './ImpactChip.vue' ),
	ViewCount: require( './ViewCount.vue' ),
	useQuality: qualityLoader.useQuality,
	usePageData: pageDataLoader.usePageData
};
