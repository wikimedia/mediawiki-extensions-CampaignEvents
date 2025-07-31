<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Notifications;

use DateTimeZone;
use LogicException;
use MediaWiki\Extension\CampaignEvents\Address\Address;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Extension\CampaignEvents\Notifications\RegistrationNotificationPresentationModel;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation;
use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Notifications\RegistrationNotificationPresentationModel
 */
class RegistrationNotificationPresentationModelTest extends MediaWikiIntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'Echo' );
		$this->overrideConfigValue( 'CampaignEventsCountrySchemaMigrationStage', MIGRATION_WRITE_NEW );
	}

	private function makeNotificationModel( int $eventID ): EchoEventPresentationModel {
		$echoEvent = Event::create( [
			'type' => RegistrationNotificationPresentationModel::NOTIFICATION_NAME,
			'extra' => [
				'event-id' => $eventID
			]
		] );
		$model = EchoEventPresentationModel::factory(
			$echoEvent,
			$this->getServiceContainer()->getLanguageFactory()->getLanguage( 'qqx' ),
			$this->createMock( User::class )
		);
		if ( !$model instanceof RegistrationNotificationPresentationModel ) {
			throw new LogicException( 'Wrong model class: ' . $model::class );
		}
		return $model;
	}

	private function saveEventWithData( array $overrides ): int {
		$defaultArguments = [
			'id' => null,
			'name' => 'RegistrationNotificationPresentationModelTest',
			'page' => new MWPageProxy(
				new PageIdentityValue(
					37,
					NS_PROJECT,
					'RegistrationNotificationPresentationModelTest',
					PageIdentityValue::LOCAL
				),
				'Project:RegistrationNotificationPresentationModelTest'
			),
			'status' => EventRegistration::STATUS_OPEN,
			'timezone' => new DateTimeZone( 'UTC' ),
			'start' => '20250815120000',
			'end' => '20250815120001',
			'types' => [ EventTypesRegistry::EVENT_TYPE_OTHER ],
			'wikis' => [ 'awiki', 'bwiki' ],
			'topics' => [ 'atopic', 'btopic' ],
			'tracking_tools' => [
				new TrackingToolAssociation(
					1,
					'some-event-identifier',
					TrackingToolAssociation::SYNC_STATUS_UNKNOWN,
					null
				)
			],
			'participation_options' => EventRegistration::PARTICIPATION_OPTION_ONLINE_AND_IN_PERSON,
			'meeting_url' => 'https://meet.example.org',
			'address' => new Address( 'Some address', 'France', null ),
			'chat' => 'https://chat.example.org',
			'is_test_event' => false,
			'questions' => [],
			'creation' => '1650000000',
			'last_edit' => '1651000000',
			'deletion' => null,
		];
		$ctrArgs = array_values( array_replace( $defaultArguments, $overrides ) );
		$event = new EventRegistration( ...$ctrArgs );

		return CampaignEventsServices::getEventStore()->saveRegistration( $event );
	}

	public function testNotificationContainsAddress() {
		$address = 'Some place somewhere 123';
		$eventID = $this->saveEventWithData( [ 'address' => new Address( $address, 'Australia', 'AU' ) ] );
		$model = $this->makeNotificationModel( $eventID );

		$this->assertStringContainsString( $address, $model->getBodyMessage()->text() );
	}

	public function testNotificationContainsAddressNotAvailableMessage() {
		$eventID = $this->saveEventWithData( [ 'address' => new Address( '', 'Australia', 'AU' ) ] );
		$model = $this->makeNotificationModel( $eventID );

		$this->assertStringContainsString(
			'campaignevents-notification-registration-details-venue-not-available',
			$model->getBodyMessage()->text()
		);
	}
}
