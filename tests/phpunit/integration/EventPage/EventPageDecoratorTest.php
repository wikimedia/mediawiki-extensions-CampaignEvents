<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\EventPage;

use Generator;
use LogicException;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\CampaignEvents\Address\CountryProvider;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Extension\CampaignEvents\EventGoal\GoalProgressFormatter;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageCacheUpdater;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageDecorator;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter;
use MediaWiki\Language\Language;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\GroupPermissionsLookup;
use MediaWiki\Request\WebRequest;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWikiIntegrationTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\ITextFormatter;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\EventPage\EventPageDecorator
 * @covers ::__construct
 * XXX: make this a pure unit test once we can use NS_EVENT (T310375)
 */
class EventPageDecoratorTest extends MediaWikiIntegrationTestCase {
	use MockAuthorityTrait;

	private function getDecorator(
		PageEventLookup $pageEventLookup,
		array $allowedNamespaces,
		bool $canEnableRegistration,
		Authority $performer,
		OutputPage $out,
		?GoalProgressFormatter $goalProgressFormatter = null
	): EventPageDecorator {
		// Explicit mocking to make sure that information such as the page namespace is preserved.
		$campaignsPageFactory = $this->createMock( CampaignsPageFactory::class );
		$campaignsPageFactory->method( 'newFromLocalMediaWikiPage' )
			->willReturnCallback( static function ( $page ) {
				if ( !$page instanceof ProperPageIdentity ) {
					throw new LogicException( 'Not supported in this test.' );
				}
				return new MWPageProxy( $page, 'Some prefixed text!' );
			} );

		$permissionChecker = $this->createMock( PermissionChecker::class );
		$permissionChecker->method( 'userCanEnableRegistration' )->willReturn( $canEnableRegistration );

		$textFormatter = $this->createMock( ITextFormatter::class );
		$textFormatter->method( 'format' )->willReturn( '' );
		$msgFormatterFactory = $this->createMock( IMessageFormatterFactory::class );
		$msgFormatterFactory->method( 'getTextFormatter' )->willReturn( $textFormatter );

		if ( $goalProgressFormatter === null ) {
			$goalProgressFormatter = $this->createMock( GoalProgressFormatter::class );
		}

		return new EventPageDecorator(
			$pageEventLookup,
			$this->createMock( ParticipantsStore::class ),
			$this->createMock( OrganizersStore::class ),
			$permissionChecker,
			$msgFormatterFactory,
			$campaignsPageFactory,
			$this->createMock( CampaignsCentralUserLookup::class ),
			$this->createMock( EventTimeFormatter::class ),
			$this->createMock( EventPageCacheUpdater::class ),
			$this->createMock( EventQuestionsRegistry::class ),
			$this->createMock( GroupPermissionsLookup::class ),
			new HashConfig( [
				'CampaignEventsEventNamespaces' => $allowedNamespaces
			] ),
			$this->createMock( CountryProvider::class ),
			$goalProgressFormatter,
			$this->createMock( Language::class ),
			$performer,
			$out
		);
	}

	/** @return OutputPage&MockObject */
	private function getMockOutputPage(): OutputPage {
		$out = $this->createMock( OutputPage::class );
		$out->method( 'enableOOUI' )->willReturnCallback( static function () {
			// Make sure the call sets the OOUI theme singleton, to avoid uncaught exceptions.
			OutputPage::setupOOUI();
		} );
		// Needed becase these methods do not have return type declarations.
		$out->method( 'msg' )->willReturn( $this->getMockMessage() );
		$out->method( 'getRequest' )->willReturn( $this->createMock( WebRequest::class ) );

		return $out;
	}

	/**
	 * @covers ::decoratePage
	 * @covers ::maybeAddEnableRegistrationHeader
	 * @dataProvider provideAddsHeader
	 */
	public function testAddsHeader(
		array $allowedNamespaces,
		ProperPageIdentity $page,
		bool $hasRegistration,
		bool $registrationIsDeleted,
		bool $canEnableRegistration,
		bool $expectsRegistrationHeader,
		bool $expectsCTAHeader
	): void {
		if ( $hasRegistration ) {
			$registration = $this->createMock( ExistingEventRegistration::class );
			$deletionTimestamp = $registrationIsDeleted ? '1600000000' : null;
			$registration->method( 'getDeletionTimestamp' )->willReturn( $deletionTimestamp );
			$registration->method( 'getParticipationOptions' )
				->willReturn( EventRegistration::PARTICIPATION_OPTION_ONLINE );
		} else {
			$registration = null;
		}

		$pageEventLookup = $this->createMock( PageEventLookup::class );
		$pageEventLookup->expects( $this->once() )
			->method( 'getRegistrationForLocalPage' )
			->willReturn( $registration );

		$addedRegistrationHeader = $addedCTAHeader = false;
		if ( $expectsRegistrationHeader || $expectsCTAHeader ) {
			$out = $this->getMockOutputPage();
			$out->expects( $this->atLeastOnce() )
				->method( 'addHTML' )
				->willReturnCallback( static function ( $html ) use ( &$addedRegistrationHeader, &$addedCTAHeader ) {
					// Check the container element class to determine which header is being shown.
					if ( str_contains( $html, 'ext-campaignevents-eventpage-header' ) ) {
						$addedRegistrationHeader = true;
					}
					if ( str_contains( $html, 'ext-campaignevents-eventpage-enableheader' ) ) {
						$addedCTAHeader = true;
					}
				} );
		} else {
			$out = $this->createNoOpMock( OutputPage::class );
		}

		$performer = $canEnableRegistration
			? $this->mockRegisteredUltimateAuthority()
			: $this->mockAnonNullAuthority();
		$decorator = $this->getDecorator(
			$pageEventLookup,
			$allowedNamespaces,
			$canEnableRegistration,
			$performer,
			$out
		);
		$decorator->decoratePage( $page );

		$this->assertSame( $expectsRegistrationHeader, $addedRegistrationHeader, 'Registration header' );
		$this->assertSame( $expectsCTAHeader, $addedCTAHeader, 'CTA header' );
	}

	/** @noinspection PhpConditionAlreadyCheckedInspection */
	public static function provideAddsHeader(): Generator {
		$allowsProjectNamespaceOnly = [ NS_PROJECT ];
		$allowsEventNamespaceOnly = [ NS_EVENT ];
		$projectPage = new PageIdentityValue( 42, NS_PROJECT, 'Project:Allowed', PageIdentity::LOCAL );
		$eventPage = new PageIdentityValue( 42, NS_EVENT, 'Event:TestDecorator', PageIdentity::LOCAL );
		$hasRegistration = true;
		$doesNotHaveRegistration = false;
		$registrationIsDeleted = true;
		$registrationIsNotDeleted = false;
		$canEnableRegistration = true;
		$cannotEnableRegistration = false;
		$expectsRegistrationHeader = true;
		$doesNotExpectRegistrationHeader = false;
		$expectsCTAHeader = true;
		$doesNotExpectCTAHeader = false;
		// Random value for no-ops, to be used when the parameter is not relevant for the test case.
		$_ = true;

		yield 'Project page, allowed namespace, has registration, not deleted, expects registration header' => [
			$allowsProjectNamespaceOnly,
			$projectPage,
			$hasRegistration,
			$registrationIsNotDeleted,
			$_,
			$expectsRegistrationHeader,
			$doesNotExpectCTAHeader
		];
		yield 'Project page, allowed namespace, has registration, deleted, expects no header' => [
			$allowsProjectNamespaceOnly,
			$projectPage,
			$hasRegistration,
			$registrationIsDeleted,
			$_,
			$doesNotExpectRegistrationHeader,
			$doesNotExpectCTAHeader
		];
		yield 'Project page, allowed namespace, no registration, can enable registration, expects no header' => [
			$allowsProjectNamespaceOnly,
			$projectPage,
			$doesNotHaveRegistration,
			$_,
			$canEnableRegistration,
			$doesNotExpectRegistrationHeader,
			$doesNotExpectCTAHeader
		];
		yield 'Project page, allowed namespace, no registration, cannot enable registration, expects no header' => [
			$allowsProjectNamespaceOnly,
			$projectPage,
			$doesNotHaveRegistration,
			$_,
			$cannotEnableRegistration,
			$doesNotExpectRegistrationHeader,
			$doesNotExpectCTAHeader
		];

		yield 'Project page, namespace not allowed, has registration, not deleted, expects registration header' => [
			$allowsEventNamespaceOnly,
			$projectPage,
			$hasRegistration,
			$registrationIsNotDeleted,
			$_,
			$expectsRegistrationHeader,
			$doesNotExpectCTAHeader
		];
		yield 'Project page, namespace not allowed, has registration, deleted, expects no header' => [
			$allowsEventNamespaceOnly,
			$projectPage,
			$hasRegistration,
			$registrationIsDeleted,
			$_,
			$doesNotExpectRegistrationHeader,
			$doesNotExpectCTAHeader
		];
		yield 'Project page, namespace not allowed, no registration, can enable registration, expects no header' => [
			$allowsEventNamespaceOnly,
			$projectPage,
			$doesNotHaveRegistration,
			$_,
			$canEnableRegistration,
			$doesNotExpectRegistrationHeader,
			$doesNotExpectCTAHeader
		];
		yield 'Project page, namespace not allowed, no registration, cannot enable registration, expects no header' => [
			$allowsEventNamespaceOnly,
			$projectPage,
			$doesNotHaveRegistration,
			$_,
			$cannotEnableRegistration,
			$doesNotExpectRegistrationHeader,
			$doesNotExpectCTAHeader
		];

		yield 'Event page, allowed namespace, has registration, not deleted, expects registration header' => [
			$allowsEventNamespaceOnly,
			$eventPage,
			$hasRegistration,
			$registrationIsNotDeleted,
			$_,
			$expectsRegistrationHeader,
			$doesNotExpectCTAHeader
		];
		yield 'Event page, allowed namespace, has registration, deleted, expects no header' => [
			$allowsEventNamespaceOnly,
			$eventPage,
			$hasRegistration,
			$registrationIsDeleted,
			$_,
			$doesNotExpectRegistrationHeader,
			$doesNotExpectCTAHeader
		];
		yield 'Event page, allowed namespace, no registration, can enable registration, expects CTA header' => [
			$allowsEventNamespaceOnly,
			$eventPage,
			$doesNotHaveRegistration,
			$_,
			$canEnableRegistration,
			$doesNotExpectRegistrationHeader,
			$expectsCTAHeader
		];
		yield 'Event page, allowed namespace, no registration, cannot enable registration, expects no header' => [
			$allowsEventNamespaceOnly,
			$eventPage,
			$doesNotHaveRegistration,
			$_,
			$cannotEnableRegistration,
			$doesNotExpectRegistrationHeader,
			$doesNotExpectCTAHeader
		];

		yield 'Event page, namespace not allowed, has registration, not deleted, expects registration header' => [
			$allowsProjectNamespaceOnly,
			$eventPage,
			$hasRegistration,
			$registrationIsNotDeleted,
			$_,
			$expectsRegistrationHeader,
			$doesNotExpectRegistrationHeader
		];
		yield 'Event page, namespace not allowed, has registration, deleted, expects no header' => [
			$allowsProjectNamespaceOnly,
			$eventPage,
			$hasRegistration,
			$registrationIsDeleted,
			$_,
			$doesNotExpectRegistrationHeader,
			$doesNotExpectCTAHeader
		];
		yield 'Event page, namespace not allowed, no registration, can enable registration, expects no header' => [
			$allowsProjectNamespaceOnly,
			$eventPage,
			$doesNotHaveRegistration,
			$_,
			$canEnableRegistration,
			$doesNotExpectRegistrationHeader,
			$doesNotExpectCTAHeader
		];
		yield 'Event page, namespace not allowed, no registration, cannot enable registration, expects no header' => [
			$allowsProjectNamespaceOnly,
			$eventPage,
			$doesNotHaveRegistration,
			$_,
			$cannotEnableRegistration,
			$doesNotExpectRegistrationHeader,
			$doesNotExpectCTAHeader
		];
	}

	/**
	 * Builds a decorator suited for goal-progress tests.
	 */
	private function getDecoratorForGoalProgressTest(
		Authority $authority,
		GoalProgressFormatter $goalProgressFormatter,
		PageEventLookup $pageEventLookup,
		OutputPage $out
	): EventPageDecorator {
		return $this->getDecorator(
			$pageEventLookup,
			[ NS_EVENT ],
			false,
			$authority,
			$out,
			$goalProgressFormatter
		);
	}

	/** @return ExistingEventRegistration&MockObject */
	private function makeActiveRegistration(): ExistingEventRegistration {
		$registration = $this->createMock( ExistingEventRegistration::class );
		$registration->method( 'getDeletionTimestamp' )->willReturn( null );
		$registration->method( 'getParticipationOptions' )
			->willReturn( EventRegistration::PARTICIPATION_OPTION_ONLINE );
		return $registration;
	}

	/**
	 * @covers ::getGoalProgressSection
	 */
	public function testGoalProgressSectionNotShownForAnonymousUser(): void {
		$registration = $this->makeActiveRegistration();
		$pageEventLookup = $this->createMock( PageEventLookup::class );
		$pageEventLookup->method( 'getRegistrationForLocalPage' )->willReturn( $registration );

		$goalProgressFormatter = $this->createMock( GoalProgressFormatter::class );
		$goalProgressFormatter->expects( $this->never() )->method( 'getProgressData' );

		$out = $this->getMockOutputPage();
		$addedGoalProgress = false;
		$out->method( 'addHTML' )
			->willReturnCallback( static function ( $html ) use ( &$addedGoalProgress ) {
				if ( str_contains( $html, 'ext-campaignevents-goal-progress-card' ) ) {
					$addedGoalProgress = true;
				}
			} );

		$decorator = $this->getDecoratorForGoalProgressTest(
			$this->mockAnonNullAuthority(),
			$goalProgressFormatter,
			$pageEventLookup,
			$out
		);
		$decorator->decoratePage(
			new PageIdentityValue( 42, NS_EVENT, 'Event:GoalProgressTest', PageIdentity::LOCAL )
		);

		$this->assertFalse( $addedGoalProgress, 'Goal progress section must not be shown for anonymous users' );
	}

	/**
	 * @covers ::getGoalProgressSection
	 * @dataProvider provideAddsGoalProgressSection
	 */
	public function testAddsGoalProgressSection(
		?array $progressData,
		bool $expectsGoalProgress
	): void {
		$registration = $this->makeActiveRegistration();
		$pageEventLookup = $this->createMock( PageEventLookup::class );
		$pageEventLookup->method( 'getRegistrationForLocalPage' )->willReturn( $registration );

		$goalProgressFormatter = $this->createMock( GoalProgressFormatter::class );
		$goalProgressFormatter->method( 'getProgressData' )->willReturn( $progressData );

		$out = $this->getMockOutputPage();
		$addedGoalProgress = false;
		$out->method( 'addHTML' )
			->willReturnCallback( static function ( $html ) use ( &$addedGoalProgress ) {
				if ( str_contains( $html, 'ext-campaignevents-goal-progress-card' ) ) {
					$addedGoalProgress = true;
				}
			} );

		$decorator = $this->getDecoratorForGoalProgressTest(
			$this->mockRegisteredUltimateAuthority(),
			$goalProgressFormatter,
			$pageEventLookup,
			$out
		);
		$decorator->decoratePage(
			new PageIdentityValue( 42, NS_EVENT, 'Event:GoalProgressTest', PageIdentity::LOCAL )
		);

		$this->assertSame( $expectsGoalProgress, $addedGoalProgress );
	}

	public static function provideAddsGoalProgressSection(): Generator {
		yield 'getProgressData returns null — section not shown' => [
			null,
			false,
		];
		yield 'getProgressData returns valid data — section shown' => [
			[
				'heading' => 'Goal progress',
				'description' => 'Target: 100 edits',
				'percentComplete' => 42,
				'numericText' => '42 / 100',
			],
			true,
		];
	}
}
