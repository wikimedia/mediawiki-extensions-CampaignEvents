<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\Notifications\RegistrationNotificationPresentationModel;
use MediaWiki\Extension\Notifications\AttributeManager;
use MediaWiki\Extension\Notifications\Hooks\BeforeCreateEchoEventHook;
use MediaWiki\Extension\Notifications\UserLocator;

class EchoHooksHandler implements BeforeCreateEchoEventHook {

	private const REGISTRATION_NOTIFICATION_CATEGORY = 'campaign-events-notification-registration';

	/**
	 * @param array &$notifications
	 * @param array &$notificationCategories
	 * @param array &$icons
	 */
	public function onBeforeCreateEchoEvent(
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
			AttributeManager::ATTR_LOCATORS => [
				[
					[ UserLocator::class, 'locateFromEventExtra' ],
					[ 'user' ]
				]
			],
		];

		$icons[RegistrationNotificationPresentationModel::ICON_NAME]['path'] =
			'CampaignEvents/resources/icons/calendar.svg';
	}
}
