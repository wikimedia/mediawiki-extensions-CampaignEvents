<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\SpecialPage\SpecialPage;
use OOUI\HtmlSnippet;
use OOUI\MessageWidget;

class SpecialInvitationList extends SpecialPage {

	public const PAGE_NAME = 'InvitationList';

	private PermissionChecker $permissionChecker;

	/**
	 * @param PermissionChecker $permissionChecker
	 */
	public function __construct( PermissionChecker $permissionChecker ) {
		parent::__construct( self::PAGE_NAME );
		$this->permissionChecker = $permissionChecker;
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		$this->setHeaders();
		$this->outputHeader();
		$mwAuthority = new MWAuthorityProxy( $this->getAuthority() );
		$out = $this->getOutput();
		$out->enableOOUI();
		if ( !$this->getConfig()->get( 'CampaignEventsEnableEventInvitation' ) ) {
			$messageWidget = new MessageWidget( [
				'type' => 'notice',
				'label' => new HtmlSnippet( $this->msg( 'campaignevents-invitation-list-processing' )->parse() )
			] );
			$out->addHTML( $messageWidget );
			return;
		}

		$this->requireNamedUser();
		if ( !$this->permissionChecker->userCanUseInvitationLists( $mwAuthority ) ) {
			$messageWidget = new MessageWidget( [
				'type' => 'error',
				'label' => $this->msg( 'campaignevents-invitation-list-not-allowed' )->text()
			] );
			$out->addHTML( $messageWidget );
		} else {
			// TDB set real page title will be done later after the DB layer T366633
			$out->setPageTitle( "My Invitation List" );
			$messageWidget = new MessageWidget( [
				'type' => 'notice',
				'label' => new HtmlSnippet( $this->msg( 'campaignevents-invitation-list-processing' )->parse() )
			] );
			$out->addHTML( $messageWidget );
		}
	}
}
