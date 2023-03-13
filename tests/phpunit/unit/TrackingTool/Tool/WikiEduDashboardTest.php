<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\TrackingTool\Tool;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\TrackingTool\Tool\WikiEduDashboard;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\TrackingTool\Tool\WikiEduDashboard
 * @covers ::__construct
 */
class WikiEduDashboardTest extends MediaWikiUnitTestCase {
	private function getTool(): WikiEduDashboard {
		return new WikiEduDashboard(
			1,
			'some-url',
			[ 'secret' => '' ]
		);
	}

	/**
	 * @covers ::validateToolAddition
	 */
	public function testValidateToolAddition() {
		$actual = $this->getTool()->validateToolAddition(
			$this->createMock( EventRegistration::class ),
			[],
			'something'
		);
		$this->assertEquals( StatusValue::newGood(), $actual );
	}

	/**
	 * @covers ::validateToolRemoval
	 */
	public function testValidateToolRemoval() {
		$actual = $this->getTool()->validateToolRemoval(
			$this->createMock( ExistingEventRegistration::class ),
			'something'
		);
		$this->assertEquals( StatusValue::newGood(), $actual );
	}

	/**
	 * @covers ::validateEventDeletion
	 */
	public function testValidateEventDeletion() {
		$actual = $this->getTool()->validateEventDeletion(
			$this->createMock( ExistingEventRegistration::class ),
			'something'
		);
		$this->assertEquals( StatusValue::newGood(), $actual );
	}

	/**
	 * @covers ::validateParticipantAdded
	 */
	public function testValidateParticipantAdded() {
		$actual = $this->getTool()->validateParticipantAdded(
			$this->createMock( ExistingEventRegistration::class ),
			'something',
			$this->createMock( CentralUser::class ),
			false
		);
		$this->assertEquals( StatusValue::newGood(), $actual );
	}

	/**
	 * @covers ::validateParticipantsRemoved
	 */
	public function testValidateParticipantsRemoved() {
		$actual = $this->getTool()->validateParticipantsRemoved(
			$this->createMock( ExistingEventRegistration::class ),
			'something',
			null,
			false
		);
		$this->assertEquals( StatusValue::newGood(), $actual );
	}
}
