'use strict';

/**
 * Jest stand-in for the ResourceLoader module
 * ext.campaignEvents.worklistEventDiscoveryTracking.
 */
module.exports = {
	appendOriginToUrl: jest.fn( ( url ) => url + '?ce_from=61ac4b96c6aed176' ),
	recordPromotionModalInteraction: jest.fn(),
	recordEventPageRegisterInteraction: jest.fn(),
	hasPromotionOriginParam: jest.fn( () => false ),
	isWorklistArticlePromotionTrackingEnabled: jest.fn( () => true )
};
