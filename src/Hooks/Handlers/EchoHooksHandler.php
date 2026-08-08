<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\Notifications\RegistrationNotificationPresentationModel;
use MediaWiki\Extension\CampaignEvents\Notifications\UserNotifier;
use MediaWiki\Extension\Notifications\Hooks\BeforeCreateEchoEventHook;

class EchoHooksHandler implements BeforeCreateEchoEventHook {

	private const REGISTRATION_NOTIFICATION_CATEGORY = 'campaign-events-notification-registration';

	/**
	 * @param array<string,array<string,mixed>> &$notifications
	 * @param array<string,array<string,mixed>> &$notificationCategories
	 * @param array<string,array<string,mixed>> &$notificationIcons
	 */
	public function onBeforeCreateEchoEvent(
		array &$notifications,
		array &$notificationCategories,
		array &$notificationIcons
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

		$notifications[ UserNotifier::NOTIFICATION_NAME ] = [
			'category' => self::REGISTRATION_NOTIFICATION_CATEGORY,
			'group' => 'positive',
			'section' => 'message',
			'presentation-model' => RegistrationNotificationPresentationModel::class,
			'canNotifyAgent' => true,
		];

		$notificationIcons[RegistrationNotificationPresentationModel::ICON_NAME]['path'] =
			'CampaignEvents/resources/icons/calendar.svg';
	}
}
