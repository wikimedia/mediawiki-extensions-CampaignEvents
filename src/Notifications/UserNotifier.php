<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Notifications;

use EchoEvent;
use InvalidArgumentException;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
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
	 * @param ICampaignsAuthority $performer
	 * @param ExistingEventRegistration $event
	 */
	public function notifyRegistration( ICampaignsAuthority $performer, ExistingEventRegistration $event ): void {
		if ( $this->isEchoLoaded ) {
			$eventPage = $event->getPage();
			if ( !$eventPage instanceof MWPageProxy ) {
				throw new InvalidArgumentException( "Unexpected Page implementation" );
			}
			DeferredUpdates::addCallableUpdate( static function () use ( $performer, $event, $eventPage ) {
				EchoEvent::create( [
					'type' => RegistrationNotificationPresentationModel::NOTIFICATION_NAME,
					'title' => Title::castFromPageIdentity( $eventPage->getPageIdentity() ),
					'extra' => [
						'user' => $performer->getLocalUserID(),
						'event-id' => $event->getID()
					]
				] );
			} );
		}
	}
}
