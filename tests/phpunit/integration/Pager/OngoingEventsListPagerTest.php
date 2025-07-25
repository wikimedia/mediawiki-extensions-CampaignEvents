<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Pager;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Pager\OngoingEventsListPager
 */
class OngoingEventsListPagerTest extends MediaWikiIntegrationTestCase {
	use ListPagersTestHelperTrait;

	/**
	 * @dataProvider provideOngoingDateFilters
	 */
	public function testDateFilters(
		int $searchStart,
		?int $searchTo,
		bool $expectsFound
	): void {
		$pager = CampaignEventsServices::getEventsPagerFactory()->newOngoingListPager(
			new RequestContext(),
			'',
			[],
			wfTimestamp( TS_MW, $searchStart ),
			null,
			null,
			[],
			[],
			true
		);
		$this->assertSame( $expectsFound ? 1 : 0, $pager->getNumRows() );
	}

	public function testCanUseFilters() {
		$pager = CampaignEventsServices::getEventsPagerFactory()->newOngoingListPager(
			new RequestContext(),
			self::$EVENT_NAME,
			[],
			wfTimestamp( TS_MW, self::$EVENT_START + 1 ),
			EventRegistration::PARTICIPATION_OPTION_ONLINE_AND_IN_PERSON,
			null,
			[ 'any_wiki_name' ],
			[ self::$EVENT_TOPIC ],
			true
		);
		$this->assertSame( 1, $pager->getNumRows() );
	}
}
