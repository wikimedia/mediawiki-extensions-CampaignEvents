<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\EventPage;

use Generator;
use LogicException;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageCacheUpdater;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageDecorator;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter;
use MediaWiki\Extension\CampaignEvents\Topics\ITopicRegistry;
use MediaWiki\Language\Language;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\GroupPermissionsLookup;
use MediaWiki\Request\WebRequest;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWikiIntegrationTestCase;
use OOUI\PanelLayout;
use PHPUnit\Framework\MockObject\MockObject;
use Wikimedia\Message\IMessageFormatterFactory;

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
		OutputPage $out
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
		if ( $canEnableRegistration ) {
			$performer = $this->mockRegisteredUltimateAuthority();
		} else {
			$performer = $this->mockAnonNullAuthority();
		}

		return new EventPageDecorator(
			$pageEventLookup,
			$this->createMock( ParticipantsStore::class ),
			$this->createMock( OrganizersStore::class ),
			$permissionChecker,
			$this->createMock( IMessageFormatterFactory::class ),
			$this->createMock( LinkRenderer::class ),
			$campaignsPageFactory,
			$this->createMock( CampaignsCentralUserLookup::class ),
			$this->createMock( UserLinker::class ),
			$this->createMock( EventTimeFormatter::class ),
			$this->createMock( EventPageCacheUpdater::class ),
			$this->createMock( EventQuestionsRegistry::class ),
			$this->createMock( WikiLookup::class ),
			$this->createMock( ITopicRegistry::class ),
			$this->createMock( EventTypesRegistry::class ),
			$this->createMock( GroupPermissionsLookup::class ),
			new HashConfig( [
				'CampaignEventsEventNamespaces' => $allowedNamespaces
			] ),
			$this->createMock( Language::class ),
			$performer,
			$out
		);
	}

	/** @return OutputPage&MockObject */
	private function getMockOutputPage(): OutputPage {
		$out = $this->createMock( OutputPage::class );
		$out->method( 'getConfig' )->willReturn( new HashConfig( [ 'CampaignEventsEnableEventTypes' => true ] ) );
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
					if ( !$html instanceof PanelLayout ) {
						return;
					}
					// Check the panel class to determine which header is being shown.
					if ( $html->hasClass( 'ext-campaignevents-eventpage-header' ) ) {
						$addedRegistrationHeader = true;
					}
					if ( $html->hasClass( 'ext-campaignevents-eventpage-enableheader' ) ) {
						$addedCTAHeader = true;
					}
				} );
		} else {
			$out = $this->createNoOpMock( OutputPage::class );
		}

		$decorator = $this->getDecorator( $pageEventLookup, $allowedNamespaces, $canEnableRegistration, $out );
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
}
