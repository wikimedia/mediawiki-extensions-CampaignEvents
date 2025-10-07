<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\EventContribution;

use Generator;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContribution;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\EventContribution\EventContribution
 */
class EventContributionTest extends MediaWikiUnitTestCase {

	/**
	 * Tests EventContribution isPageCreation method.
	 * @covers \MediaWiki\Extension\CampaignEvents\EventContribution\EventContribution::isPageCreation
	 * @dataProvider provideIsPageCreation
	 */
	public function testIsPageCreation( int $editFlags, bool $expected ): void {
		$contribution = new EventContribution(
			1,
			101,
			'User 101',
			'enwiki',
			'Test_Page',
			1,
			123,
			$editFlags,
			100,
			5,
			'20240101000000'
		);

		$this->assertSame( $expected, $contribution->isPageCreation() );
	}

	public static function provideIsPageCreation(): Generator {
		yield 'Page creation flag set' => [ EventContribution::EDIT_FLAG_PAGE_CREATION, true ];
		yield 'Page creation flag not set' => [ 0, false ];
		yield 'Other flags set' => [ 2, false ];
		yield 'Multiple flags set' => [ EventContribution::EDIT_FLAG_PAGE_CREATION | 2, true ];
	}
}
