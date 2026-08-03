<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\EventGoal;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\EventGoal\EventGoal;
use MediaWiki\Extension\CampaignEvents\EventGoal\EventGoalCompletionCalculator;
use MediaWiki\Extension\CampaignEvents\EventGoal\EventGoalCompletionResult;
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
		?EventGoalCompletionResult $result = null,
		?CampaignsCentralUserLookup $centralUserLookup = null
	): GoalProgressFormatter {
		$centralUserLookup ??= $this->createMock( CampaignsCentralUserLookup::class );
		$permissionChecker = $this->createMock( PermissionChecker::class );
		$permissionChecker->method( 'userCanViewPrivateParticipants' )->willReturn( true );

		$result ??= new EventGoalCompletionResult( 0.5, EventGoalMetricType::TotalEdits, 0 );

		$calculator = $this->createMock( EventGoalCompletionCalculator::class );
		$calculator->method( 'calculateCompletion' )->willReturn( $result );

		$textFormatter = $this->createMock( ITextFormatter::class );
		$textFormatter->method( 'format' )
			->willReturnCallback( static fn ( MessageValue $mv ) => $mv->getKey() );

		$formatterFactory = $this->createMock( IMessageFormatterFactory::class );
		$formatterFactory->method( 'getTextFormatter' )->willReturn( $textFormatter );

		return new GoalProgressFormatter(
			$centralUserLookup,
			$permissionChecker,
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

		$result = new EventGoalCompletionResult( 0.0, EventGoalMetricType::TotalEdits, 0 );
		$formatter = $this->makeFormatter( $result, $centralUserLookup );
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
		int $primaryMetricCurrent,
		float $completion,
		int $expectedPercent
	): void {
		$result = new EventGoalCompletionResult( $completion, $metricType, $primaryMetricCurrent );
		$formatter = $this->makeFormatter( $result );
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
			EventGoalMetricType::TotalEdits, 100, 50,
			0.5, 50,
		];
		yield 'TotalArticlesCreated at 75%' => [
			EventGoalMetricType::TotalArticlesCreated, 200, 150,
			0.75, 75,
		];
		yield 'TotalArticlesEdited at 25%' => [
			EventGoalMetricType::TotalArticlesEdited, 400, 100,
			0.25, 25,
		];
		yield 'TotalBytesAdded at 100%' => [
			EventGoalMetricType::TotalBytesAdded, 1000, 1000,
			1.0, 100,
		];
		yield 'TotalBytesRemoved at 50%' => [
			EventGoalMetricType::TotalBytesRemoved, 1000, 500,
			0.5, 50,
		];
		yield 'TotalLinksAdded at 10%' => [
			EventGoalMetricType::TotalLinksAdded, 100, 10,
			0.1, 10,
		];
		yield 'TotalLinksRemoved at 33%' => [
			EventGoalMetricType::TotalLinksRemoved, 300, 99,
			0.33, 33,
		];
	}

	public function testPercentCompleteUsesFloor(): void {
		$result = new EventGoalCompletionResult( 0.999, EventGoalMetricType::TotalEdits, 0 );
		$formatter = $this->makeFormatter( $result );
		$event = $this->makeEvent();
		$authority = $this->createMock( Authority::class );
		$language = $this->createMock( Language::class );
		$language->method( 'getCode' )->willReturn( 'en' );

		$result = $formatter->getProgressData( $event, $authority, $language->getCode() );
		$this->assertSame( 99, $result['percentComplete'] );
	}

}
