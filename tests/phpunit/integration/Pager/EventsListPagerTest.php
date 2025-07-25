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
		$this->overrideConfigValue( 'CampaignEventsCountrySchemaMigrationStage', MIGRATION_NEW );
		$searchStartStr = $searchStart !== null ? wfTimestamp( TS_MW, $searchStart ) : null;
		$searchToStr = $searchTo !== null ? wfTimestamp( TS_MW, $searchTo ) : null;
		$pager = CampaignEventsServices::getEventsPagerFactory()->newListPager(
			new RequestContext(),
			'',
			[],
			$searchStartStr,
			$searchToStr,
			null,
			null,
			[],
			[],
			true
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
			[],
			$timestamp,
			null,
			null,
			null,
			[],
			[],
			true
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
			[],
			null,
			$timestamp,
			null,
			null,
			[],
			[],
			true
		);
	}

	public static function provideInvalidTimestamps(): array {
		return [
			'Random string' => [ 'not a valid timestamp 123456' ],
			'Empty string' => [ '' ],
		];
	}

	public function testCanUseFilters() {
		$this->overrideConfigValue( 'CampaignEventsCountrySchemaMigrationStage', MIGRATION_NEW );
		$pager = CampaignEventsServices::getEventsPagerFactory()->newListPager(
			new RequestContext(),
			self::$EVENT_NAME,
			[],
			'19120623000000',
			'29120623000000',
			EventRegistration::PARTICIPATION_OPTION_ONLINE_AND_IN_PERSON,
			'HT',
			[ 'any_wiki_name' ],
			[ self::$EVENT_TOPIC ],
			true
		);
		$this->assertSame( 1, $pager->getNumRows() );
	}
}
