<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Pager;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWikiIntegrationTestCase;
use Wikimedia\Assert\ParameterAssertionException;

/**
 * @group Test
 * @group Database
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Pager\EventsListPager
 * @covers ::__construct()
 */
class EventsListPagerTest extends MediaWikiIntegrationTestCase {
	use ListPagersTestHelperTrait;

	/**
	 * @dataProvider provideUpcomingDateFilters
	 */
	public function testDateFilters(
		?int $searchStart,
		?int $searchTo,
		bool $expectsFound
	): void {
		$searchStartStr = $searchStart !== null ? wfTimestamp( TS_MW, $searchStart ) : null;
		$searchToStr = $searchTo !== null ? wfTimestamp( TS_MW, $searchTo ) : null;
		$pager = CampaignEventsServices::getEventsPagerFactory()->newListPager(
			new RequestContext(),
			'',
			null,
			$searchStartStr,
			$searchToStr,
			[],
			true,
			[]
		);
		$this->assertSame( $expectsFound ? 1 : 0, $pager->getNumRows() );
	}

	/**
	 * @dataProvider provideInvalidTimestamps
	 */
	public function testConstruct__invalidStartDate( string $timestamp ) {
		$this->expectException( ParameterAssertionException::class );
		$this->expectExceptionMessage( '$startDate' );
		CampaignEventsServices::getEventsPagerFactory()->newListPager(
			new RequestContext(),
			'',
			null,
			$timestamp,
			null,
			[],
			true,
			[]
		);
	}

	/**
	 * @dataProvider provideInvalidTimestamps
	 */
	public function testConstruct__invalidEndDate( string $timestamp ) {
		$this->expectException( ParameterAssertionException::class );
		$this->expectExceptionMessage( '$endDate' );
		CampaignEventsServices::getEventsPagerFactory()->newListPager(
			new RequestContext(),
			'',
			null,
			null,
			$timestamp,
			[],
			true,
			[]
		);
	}

	public static function provideInvalidTimestamps(): array {
		return [
			'Random string' => [ 'not a valid timestamp 123456' ],
			'Empty string' => [ '' ],
		];
	}

	public function testCanUseFilters() {
		$pager = CampaignEventsServices::getEventsPagerFactory()->newListPager(
			new RequestContext(),
			self::$EVENT_NAME,
			EventRegistration::PARTICIPATION_OPTION_ONLINE_AND_IN_PERSON,
			'19120623000000',
			'29120623000000',
			[ 'any_wiki_name' ],
			true,
			[ self::$EVENT_TOPIC ]
		);
		$this->assertSame( 1, $pager->getNumRows() );
	}
}
