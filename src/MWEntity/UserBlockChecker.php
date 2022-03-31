<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\User\UserFactory;
use UnexpectedValueException;

class UserBlockChecker {
	public const SERVICE_NAME = 'CampaignEventsUserBlockChecker';

	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param UserFactory $userFactory
	 */
	public function __construct( UserFactory $userFactory ) {
		$this->userFactory = $userFactory;
	}

	/**
	 * @param ICampaignsUser $user
	 * @return bool
	 */
	public function isSitewideBlocked( ICampaignsUser $user ): bool {
		if ( $user instanceof MWUserProxy ) {
			$block = $this->userFactory->newFromId( $user->getLocalID() )->getBlock();
			return $block && $block->isSitewide();
		}
		throw new UnexpectedValueException( 'Unknown user implementation: ' . get_class( $user ) );
	}
}
