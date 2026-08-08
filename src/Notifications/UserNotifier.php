<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Notifications;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Notification\NotificationService;
use MediaWiki\Notification\RecipientSet;
use MediaWiki\Notification\Types\WikiNotification;
use MediaWiki\Permissions\Authority;

class UserNotifier {
	public const SERVICE_NAME = 'CampaignEventsUserNotifier';

	public const NOTIFICATION_NAME = 'campaign-events-notification-registration-confirmation';

	public function __construct(
		private readonly NotificationService $notificationService,
	) {
	}

	public function notifyRegistration( Authority $performer, ExistingEventRegistration $event ): void {
		DeferredUpdates::addCallableUpdate( function () use ( $performer, $event ): void {
			$this->notificationService->notify(
				new WikiNotification(
					self::NOTIFICATION_NAME,
					$event->getPage()->getPageIdentity(),
					$performer->getUser(),
					[
						'event-id' => $event->getID()
					],
				),
				new RecipientSet( $performer->getUser() )
			);
		} );
	}
}
