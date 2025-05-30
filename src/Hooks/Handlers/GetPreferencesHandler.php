<?php
declare( strict_types=1 );
namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\User\User;

class GetPreferencesHandler implements GetPreferencesHook {
	public const ALLOW_INVITATIONS_PREFERENCE = 'campaignevents-allow-invitations';

	/**
	 * @param User $user
	 * @param array<string,array<string,mixed>> &$preferences
	 */
	public function onGetPreferences( $user, &$preferences ): void {
		$preferences[self::ALLOW_INVITATIONS_PREFERENCE] = [
			'type' => 'toggle',
			'label-message' => 'campaignevents-invitationlist-preference',
			// Message: prefs-campaignevents-invitations
			'section' => 'personal/campaignevents-invitations'
		];
	}
}
