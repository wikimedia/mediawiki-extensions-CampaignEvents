<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Message\Message;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\Authority;
use OOUI\MessageWidget;

// https://github.com/phan/phan/issues/4927 plus phan refusing to suppress unused suppressions...
// @phan-file-suppress UnusedPluginSuppression,UnusedSuppression,UnusedPluginFileSuppression

/**
 * @property PermissionChecker $permissionChecker
 * @method Config getConfig()
 * @phan-suppress-next-line PhanPluginUnknownArrayMethodParamType
 * @method Message msg(mixed $key, mixed ...$params)
 * @method void requireNamedUser(string $reasonMsg = '', string $titleMsg = '', bool $alwaysRedirectToLoginPage = true)
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
