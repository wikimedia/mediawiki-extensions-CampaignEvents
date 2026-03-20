<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\EventGoal;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionSummary;
use MediaWiki\Extension\CampaignEvents\EventGoal\EventGoal;
use MediaWiki\Extension\CampaignEvents\EventGoal\EventGoalCompletionCalculator;
use MediaWiki\Extension\CampaignEvents\EventGoal\EventGoalMetric;
use MediaWiki\Extension\CampaignEvents\EventGoal\EventGoalMetricType;
use MediaWiki\Extension\CampaignEvents\EventGoal\GoalProgressFormatter;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Language\Language;
use MediaWiki\Permissions\Authority;
use MediaWikiUnitTestCase;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\EventGoal\GoalProgressFormatter
 */
class GoalProgressFormatterTest extends MediaWikiUnitTestCase {

	private function makeFormatter(
		float $completion = 0.5,
		?EventContributionSummary $summary = null,
		?CampaignsCentralUserLookup $centralUserLookup = null
	): GoalProgressFormatter {
		$centralUserLookup ??= $this->createMock( CampaignsCentralUserLookup::class );
		$permissionChecker = $this->createMock( PermissionChecker::class );
		$permissionChecker->method( 'userCanViewPrivateParticipants' )->willReturn( true );

		$store = $this->createMock( EventContributionStore::class );
		$store->method( 'getEventSummaryData' )
			->willReturn( $summary ?? $this->makeSummary() );

		$calculator = $this->createMock( EventGoalCompletionCalculator::class );
		$calculator->method( 'calculateCompletion' )->willReturn( $completion );

		$textFormatter = $this->createMock( ITextFormatter::class );
		$textFormatter->method( 'format' )
			->willReturnCallback( static fn ( MessageValue $mv ) => $mv->getKey() );

		$formatterFactory = $this->createMock( IMessageFormatterFactory::class );
		$formatterFactory->method( 'getTextFormatter' )->willReturn( $textFormatter );

		return new GoalProgressFormatter(
			$centralUserLookup,
			$permissionChecker,
			$store,
			$calculator,
			$formatterFactory
		);
	}

	private function makeEvent( ?EventGoal $goal = new EventGoal(
		EventGoal::OPERATOR_AND,
		[ new EventGoalMetric( EventGoalMetricType::TotalEdits, 100 ) ]
	) ): ExistingEventRegistration {
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getGoal' )->willReturn( $goal );
		$event->method( 'getID' )->willReturn( 1 );
		return $event;
	}

	private function makeSummary( array $overrides = [] ): EventContributionSummary {
		$defaults = [
			'participantsCount' => 0,
			'wikisEditedCount' => 0,
			'articlesCreatedCount' => 0,
			'articlesEditedCount' => 0,
			'bytesAdded' => 0,
			'bytesRemoved' => 0,
			'linksAdded' => 0,
			'linksRemoved' => 0,
			'editCount' => 0,
		];
		$data = array_replace( $defaults, $overrides );
		return new EventContributionSummary(
			participantsCount: $data['participantsCount'],
			wikisEditedCount: $data['wikisEditedCount'],
			articlesCreatedCount: $data['articlesCreatedCount'],
			articlesEditedCount: $data['articlesEditedCount'],
			bytesAdded: $data['bytesAdded'],
			bytesRemoved: $data['bytesRemoved'],
			linksAdded: $data['linksAdded'],
			linksRemoved: $data['linksRemoved'],
			editCount: $data['editCount']
		);
	}

	public function testGetProgressDataReturnsNullWhenNoGoal(): void {
		$formatter = $this->makeFormatter();
		$event = $this->makeEvent( null );
		$authority = $this->createMock( Authority::class );
		$language = $this->createMock( Language::class );
		$language->method( 'getCode' )->willReturn( 'en' );

		$this->assertNull( $formatter->getProgressData( $event, $authority, $language->getCode() ) );
	}

	public function testGetProgressDataWhenUserIsNotGlobal(): void {
		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->method( 'newFromAuthority' )
			->willThrowException( new UserNotGlobalException( 0 ) );

		$formatter = $this->makeFormatter( 0.0, null, $centralUserLookup );
		$event = $this->makeEvent();
		$authority = $this->createMock( Authority::class );
		$language = $this->createMock( Language::class );
		$language->method( 'getCode' )->willReturn( 'en' );

		// UserNotGlobalException causes centralUser=null but getProgressData should still return data
		$result = $formatter->getProgressData( $event, $authority, $language->getCode() );
		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['percentComplete'] );
	}

	/**
	 * @dataProvider provideGetProgressData
	 */
	public function testGetProgressDataStructure(
		EventGoalMetricType $metricType,
		int $target,
		array $summaryOverrides,
		float $completion,
		int $expectedPercent
	): void {
		$formatter = $this->makeFormatter(
			$completion,
			$this->makeSummary( $summaryOverrides )
		);
		$goal = new EventGoal(
			EventGoal::OPERATOR_AND,
			[ new EventGoalMetric( $metricType, $target ) ]
		);
		$event = $this->makeEvent( $goal );
		$authority = $this->createMock( Authority::class );
		$language = $this->createMock( Language::class );
		$language->method( 'getCode' )->willReturn( 'en' );

		$result = $formatter->getProgressData( $event, $authority, $language->getCode() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'heading', $result );
		$this->assertArrayHasKey( 'description', $result );
		$this->assertArrayHasKey( 'percentComplete', $result );
		$this->assertArrayHasKey( 'numericText', $result );
		$this->assertSame( 'campaignevents-goal-progress-heading', $result['heading'] );
		$this->assertSame( 'campaignevents-goal-progress-description', $result['description'] );
		$this->assertSame( 'campaignevents-goal-progress-numeric', $result['numericText'] );
		$this->assertSame( $expectedPercent, $result['percentComplete'] );
	}

	public static function provideGetProgressData(): iterable {
		yield 'TotalEdits at 50%' => [
			EventGoalMetricType::TotalEdits, 100,
			[ 'editCount' => 50 ],
			0.5, 50,
		];
		yield 'TotalArticlesCreated at 75%' => [
			EventGoalMetricType::TotalArticlesCreated, 200,
			[ 'articlesCreatedCount' => 150 ],
			0.75, 75,
		];
		yield 'TotalArticlesEdited at 25%' => [
			EventGoalMetricType::TotalArticlesEdited, 400,
			[ 'articlesEditedCount' => 100 ],
			0.25, 25,
		];
		yield 'TotalBytesAdded at 100%' => [
			EventGoalMetricType::TotalBytesAdded, 1000,
			[ 'bytesAdded' => 1000 ],
			1.0, 100,
		];
		yield 'TotalBytesRemoved at 50%' => [
			EventGoalMetricType::TotalBytesRemoved, 1000,
			[ 'bytesRemoved' => -500 ],
			0.5, 50,
		];
		yield 'TotalLinksAdded at 10%' => [
			EventGoalMetricType::TotalLinksAdded, 100,
			[ 'linksAdded' => 10 ],
			0.1, 10,
		];
		yield 'TotalLinksRemoved at 33%' => [
			EventGoalMetricType::TotalLinksRemoved, 300,
			[ 'linksRemoved' => -99 ],
			0.33, 33,
		];
	}

	public function testPercentCompleteUsesFloor(): void {
		// 0.999 completion should give 99, not 100
		$formatter = $this->makeFormatter( 0.999 );
		$event = $this->makeEvent();
		$authority = $this->createMock( Authority::class );
		$language = $this->createMock( Language::class );
		$language->method( 'getCode' )->willReturn( 'en' );

		$result = $formatter->getProgressData( $event, $authority, $language->getCode() );
		$this->assertSame( 99, $result['percentComplete'] );
	}

}
