<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStoreFactory;
use MediaWiki\User\UserFactory;
use UnexpectedValueException;

/**
 * This service can be used to find who created a given page.
 */
class PageAuthorLookup {
	public const SERVICE_NAME = 'CampaignEventsPageAuthorLookup';

	/** @var RevisionStoreFactory */
	private $revisionStoreFactory;
	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param RevisionStoreFactory $revisionStoreFactory
	 * @param UserFactory $userFactory
	 */
	public function __construct( RevisionStoreFactory $revisionStoreFactory, UserFactory $userFactory ) {
		$this->revisionStoreFactory = $revisionStoreFactory;
		$this->userFactory = $userFactory;
	}

	/**
	 * @param ICampaignsPage $page
	 * @return ICampaignsUser|null Null if the author is not available for some reason.
	 * @warning This method bypasses visibility checks on the author's name.
	 */
	public function getAuthor( ICampaignsPage $page ): ?ICampaignsUser {
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
		return new MWUserProxy( $userIdentity, $this->userFactory->newFromUserIdentity( $userIdentity ) );
	}
}
