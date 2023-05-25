<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Messaging;

use Generator;
use JobQueueGroup;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\CampaignEvents\Messaging\CampaignsUserMailer;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserOptionsLookup;
use MediaWikiUnitTestCase;
use User;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Messaging\CampaignsUserMailer
 */
class CampaignsUserMailerTest extends MediaWikiUnitTestCase {

	/**
	 * @param UserFactory|null $userFactory
	 * @param JobQueueGroup|null $jobQueueGroup
	 * @param ServiceOptions|null $serviceOptions
	 * @param CampaignsCentralUserLookup|null $centralUserLookup
	 * @param UserOptionsLookup|null $userOptionsLookup
	 * @return CampaignsUserMailer
	 */
	private function getCampaignsUserMailer(
		UserFactory $userFactory = null,
		JobQueueGroup $jobQueueGroup = null,
		ServiceOptions $serviceOptions = null,
		CampaignsCentralUserLookup $centralUserLookup = null,
		UserOptionsLookup $userOptionsLookup = null

	): CampaignsUserMailer {
		return new CampaignsUserMailer(
			$userFactory ?? $this->createMock( UserFactory::class ),
			$jobQueueGroup ?? $this->createMock( JobQueueGroup::class ),
			$serviceOptions ?? $this->createMock( ServiceOptions::class ),
			$centralUserLookup ?? $this->createMock( CampaignsCentralUserLookup::class ),
			$userOptionsLookup ?? $this->createMock( UserOptionsLookup::class )
		);
	}

	/**
	 * @param User $target
	 * @param string|null $expected
	 * @covers ::validateTarget
	 * @dataProvider provideValidateTarget
	 */
	public function testValidateTarget(
		User $target,
		string $expected
	) {
		$performer = $this->createMock( User::class );
		$this->assertSame(
			$expected,
			$this->getCampaignsUserMailer()->validateTarget( $target, $performer )
		);
	}

	public function provideValidateTarget(): Generator {
		$target1 = $this->createMock( User::class );
		$target2 = $this->createMock( User::class );
		$target3 = $this->createMock( User::class );

		yield 'no id' => [
			$target1,
			"notarget"
		];
		$target2->method( "getId" )->willReturn( 1 );
		yield 'email not confirmed' => [
			$target2,
			"noemail"
		];
		$target3->method( "getId" )->willReturn( 1 );
		$target3->method( "isEmailConfirmed" )->willReturn( true );
		yield 'no email' => [
			$target3,
			"nowikiemail"
		];
	}
}
