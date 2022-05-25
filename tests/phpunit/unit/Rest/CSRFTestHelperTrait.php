<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use MediaWiki\User\UserFactory;
use User;

trait CSRFTestHelperTrait {
	/**
	 * @param bool $tokenMatches
	 * @return UserFactory
	 */
	protected function getUserFactory( bool $tokenMatches ): UserFactory {
		$user = $this->createMock( User::class );
		$user->method( 'matchEditToken' )->willReturn( $tokenMatches );
		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromAuthority' )->willReturn( $user );
		return $userFactory;
	}
}
