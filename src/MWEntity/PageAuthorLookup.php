<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStoreFactory;
use UnexpectedValueException;

/**
 * This service can be used to find the global user who created a given page.
 */
class PageAuthorLookup {
	public const SERVICE_NAME = 'CampaignEventsPageAuthorLookup';

	private RevisionStoreFactory $revisionStoreFactory;
	private CampaignsCentralUserLookup $centralUserLookup;

	/**
	 * @param RevisionStoreFactory $revisionStoreFactory
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 */
	public function __construct(
		RevisionStoreFactory $revisionStoreFactory,
		CampaignsCentralUserLookup $centralUserLookup
	) {
		$this->revisionStoreFactory = $revisionStoreFactory;
		$this->centralUserLookup = $centralUserLookup;
	}

	/**
	 * @param ICampaignsPage $page
	 * @return CentralUser|null Null if the author is not available for some reason, e.g. because
	 * the account does not exist globally.
	 * @warning This method bypasses visibility checks on the author's name.
	 */
	public function getAuthor( ICampaignsPage $page ): ?CentralUser {
		if ( !$page instanceof MWPageProxy ) {
			throw new UnexpectedValueException( 'Unknown campaigns page implementation: ' . get_class( $page ) );
		}
		$revStore = $this->revisionStoreFactory->getRevisionStore( $page->getWikiId() );
		$firstRev = $revStore->getFirstRevision( $page->getPageIdentity() );
		if ( !$firstRev ) {
			return null;
		}
		$userIdentity = $firstRev->getUser( RevisionRecord::RAW );
		if ( !$userIdentity ) {
			return null;
		}
		try {
			return $this->centralUserLookup->newFromUserIdentity( $userIdentity );
		} catch ( UserNotGlobalException $_ ) {
			return null;
		}
	}
}
