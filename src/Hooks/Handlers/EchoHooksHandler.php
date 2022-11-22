<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use EchoAttributeManager;
use EchoUserLocator;
use MediaWiki\Extension\CampaignEvents\Notifications\RegistrationNotificationPresentationModel;

class EchoHooksHandler {

	private const REGISTRATION_NOTIFICATION_CATEGORY = 'campaign-events-notification-registration';

	/**
	 * @param array &$notifications
	 * @param array &$notificationCategories
	 * @param array &$icons
	 */
	public static function onBeforeCreateEchoEvent(
		array &$notifications,
		array &$notificationCategories,
		array &$icons
	): void {
		global $wgNotifyTypeAvailabilityByCategory;
		$wgNotifyTypeAvailabilityByCategory[ self::REGISTRATION_NOTIFICATION_CATEGORY ] = [
			'web' => false,
			'email' => true,
			'push' => false,
		];

		$notificationCategories[ self::REGISTRATION_NOTIFICATION_CATEGORY ] = [
			'tooltip' => 'echo-pref-tooltip-' . self::REGISTRATION_NOTIFICATION_CATEGORY
		];

		$notifications[ RegistrationNotificationPresentationModel::NOTIFICATION_NAME ] = [
			'category' => self::REGISTRATION_NOTIFICATION_CATEGORY,
			'group' => 'positive',
			'section' => 'message',
			'presentation-model' => RegistrationNotificationPresentationModel::class,
			EchoAttributeManager::ATTR_LOCATORS => [
				[
					[ EchoUserLocator::class, 'locateFromEventExtra' ],
					[ 'user' ]
				]
			],
		];
	}
}
