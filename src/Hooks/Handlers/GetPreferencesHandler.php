<?php
declare( strict_types=1 );
namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\User\User;

class GetPreferencesHandler implements GetPreferencesHook {
	public const ALLOW_INVITATIONS_PREFERENCE = 'campaignevents-allow-invitations';
	public const OPT_OUT_EVENT_DISCOVERY_PREFERENCE = 'campaignevents-opt-out-event-discovery';

	public function __construct(
		private readonly Config $config,
	) {
	}

	/**
	 * @param User $user
	 * @param array<string,array<string,mixed>> &$preferences
	 */
	public function onGetPreferences( $user, &$preferences ): void {
		$preferences[self::ALLOW_INVITATIONS_PREFERENCE] = [
			'type' => 'toggle',
			'label-message' => 'campaignevents-invitationlist-preference',
			// Message: prefs-campaignevents-event-discovery
			'section' => 'personal/campaignevents-event-discovery'
		];
		if ( $this->config->get( 'CampaignEventsEnableWorklists' ) ) {
			$preferences[self::OPT_OUT_EVENT_DISCOVERY_PREFERENCE] = [
				'type' => 'toggle',
				'label-message' => 'campaignevents-eventdiscovery-preference-label',
				// Message: prefs-campaignevents-event-discovery
				'section' => 'personal/campaignevents-event-discovery'
			];
		}
	}
}
