<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Rest\GetFormattedTimeHandler;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\User\Options\StaticUserOptionsLookup;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\GetFormattedTimeHandler
 */
class GetFormattedTimeHandlerTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;

	private const USER_PREFERENCES = [
		'Kurt' => [ 'date' => 'mdy' ],
		'Évariste' => [ 'date' => 'dmy' ],
	];
	private const DEFAULT_PREFERENCES = [ 'date' => 'ymd' ];

	protected function setUp(): void {
		parent::setUp();
		$userOptionsLookup = new StaticUserOptionsLookup(
			self::USER_PREFERENCES,
			self::DEFAULT_PREFERENCES
		);
		$this->setService( 'UserOptionsLookup', $userOptionsLookup );
	}

	private function newHandler(): GetFormattedTimeHandler {
		return new GetFormattedTimeHandler(
			$this->getServiceContainer()->getLanguageFactory(),
			$this->getServiceContainer()->getLanguageNameUtils()
		);
	}

	/**
	 * @dataProvider provideRun
	 */
	public function testRun(
		string $language,
		string $username,
		string $start,
		string $end,
		array $expectedResponse
	): void {
		$handler = $this->newHandler();
		$performer = $this->mockAuthority( new UserIdentityValue( 42, $username ), static fn () => true );
		$respData = $this->executeHandlerAndGetBodyData(
			$handler,
			new RequestData( [ 'pathParams' => [ 'languageCode' => $language, 'start' => $start, 'end' => $end ] ] ),
			[],
			[],
			[],
			[],
			$performer
		);
		$this->assertSame( $expectedResponse, $respData );
	}

	public static function provideRun(): Generator {
		$startTS = '20231106123042';
		$endTS = '20231231235959';

		yield 'English mdy' => [
			'en',
			'Kurt',
			$startTS,
			$endTS,
			[
				'startTime' => '12:30',
				'startDate' => 'November 6, 2023',
				'startDateTime' => '12:30, November 6, 2023',
				'endTime' => '23:59',
				'endDate' => 'December 31, 2023',
				'endDateTime' => '23:59, December 31, 2023',
			]
		];
		yield 'English dmy' => [
			'en',
			'Évariste',
			$startTS,
			$endTS,
			[
				'startTime' => '12:30',
				'startDate' => '6 November 2023',
				'startDateTime' => '12:30, 6 November 2023',
				'endTime' => '23:59',
				'endDate' => '31 December 2023',
				'endDateTime' => '23:59, 31 December 2023',
			]
		];
		yield 'French dmy' => [
			'fr',
			'Évariste',
			$startTS,
			$endTS,
			[
				'startTime' => '12:30',
				'startDate' => '6 novembre 2023',
				'startDateTime' => '6 novembre 2023 à 12:30',
				'endTime' => '23:59',
				'endDate' => '31 décembre 2023',
				'endDateTime' => '31 décembre 2023 à 23:59',
			]
		];
		yield 'French mdy' => [
			'fr',
			'Kurt',
			$startTS,
			$endTS,
			[
				'startTime' => '12:30',
				'startDate' => 'novembre 6, 2023',
				'startDateTime' => 'novembre 6, 2023 à 12:30',
				'endTime' => '23:59',
				'endDate' => 'décembre 31, 2023',
				'endDateTime' => 'décembre 31, 2023 à 23:59',
			]
		];
		yield 'German default (ymd)' => [
			'de',
			'Carl Friedrich',
			$startTS,
			$endTS,
			[
				'startTime' => '12:30',
				'startDate' => '2023 Nov. 6',
				'startDateTime' => '12:30, 2023 Nov. 6',
				'endTime' => '23:59',
				'endDate' => '2023 Dez. 31',
				'endDateTime' => '23:59, 2023 Dez. 31',
			]
		];
	}

	public function testRun__invalidTimestamp(): void {
		$handler = $this->newHandler();
		$this->expectException( HttpException::class );
		$this->expectExceptionMessage( 'Invalid input timestamp' );
		$this->executeHandler(
			$handler,
			new RequestData( [ 'pathParams' => [ 'languageCode' => 'en', 'start' => 'hot', 'end' => 'garbage' ] ] )
		);
	}
}
