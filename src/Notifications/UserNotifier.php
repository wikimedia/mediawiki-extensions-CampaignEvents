<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Notifications;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Title\Title;

class UserNotifier {
	public const SERVICE_NAME = 'CampaignEventsUserNotifier';

	private bool $isEchoLoaded;

	/**
	 * @param bool $isEchoLoaded
	 */
	public function __construct( bool $isEchoLoaded ) {
		$this->isEchoLoaded = $isEchoLoaded;
	}

	/**
	 * @param MWAuthorityProxy $performer
	 * @param ExistingEventRegistration $event
	 */
	public function notifyRegistration( MWAuthorityProxy $performer, ExistingEventRegistration $event ): void {
		if ( $this->isEchoLoaded ) {
			DeferredUpdates::addCallableUpdate( static function () use ( $performer, $event ) {
				Event::create( [
					'type' => RegistrationNotificationPresentationModel::NOTIFICATION_NAME,
					'title' => Title::castFromPageIdentity( $event->getPage()->getPageIdentity() ),
					'extra' => [
						'user' => $performer->getLocalUserID(),
						'event-id' => $event->getID()
					]
				] );
			} );
		}
	}
}
