<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Message\Message;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\Authority;
use OOUI\MessageWidget;

/**
 * @property PermissionChecker $permissionChecker
 * @method Config getConfig()
 * @method Message msg($key, ...$params)
 * @method void requireNamedUser($reasonMsg = '', $titleMsg = '', $alwaysRedirectToLoginPage = true)
 */
trait InvitationFeatureAccessTrait {
	public function checkInvitationFeatureAccess( OutputPage $out, Authority $performer ): bool {
		if ( !$this->getConfig()->get( 'CampaignEventsEnableEventInvitation' ) ) {
			$out->enableOOUI();
			$messageWidget = new MessageWidget( [
				'type' => 'notice',
				'label' => $this->msg( 'campaignevents-invitation-list-disabled' )->text()
			] );
			$out->addHTML( $messageWidget );
			return false;
		}
		$this->requireNamedUser();
		if ( !$this->permissionChecker->userCanUseInvitationLists( $performer ) ) {
			$out->enableOOUI();
			$messageWidget = new MessageWidget( [
				'type' => 'error',
				'label' => $this->msg( 'campaignevents-invitation-list-not-allowed' )->text()
			] );
			$out->addHTML( $messageWidget );
			return false;
		}
		return true;
	}
}
