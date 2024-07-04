<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Extension\CampaignEvents\Invitation\InvitationList;
use MediaWiki\Extension\CampaignEvents\Invitation\InvitationListNotFoundException;
use MediaWiki\Extension\CampaignEvents\Invitation\InvitationListStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use OOUI\HtmlSnippet;
use OOUI\MessageWidget;

class SpecialInvitationList extends SpecialPage {

	public const PAGE_NAME = 'InvitationList';

	private PermissionChecker $permissionChecker;
	private InvitationListStore $invitationListStore;
	private CampaignsCentralUserLookup $centralUserLookup;

	public function __construct(
		PermissionChecker $permissionChecker,
		InvitationListStore $invitationListStore,
		CampaignsCentralUserLookup $centralUserLookup
	) {
		parent::__construct( self::PAGE_NAME );
		$this->permissionChecker = $permissionChecker;
		$this->invitationListStore = $invitationListStore;
		$this->centralUserLookup = $centralUserLookup;
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
			return;
		}

		$this->maybeDisplayList( $par );
	}

	private function maybeDisplayList( ?string $par ): void {
		if ( $par === null ) {
			$this->getOutput()->redirect(
				SpecialPage::getTitleFor( SpecialMyInvitationLists::PAGE_NAME )->getLocalURL()
			);
			return;
		}

		$listID = (int)$par;
		if ( (string)$listID !== $par ) {
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'campaignevents-invitation-list-invalid-id' )->parseAsBlock()
			) );
			return;
		}
		try {
			$invitationList = $this->invitationListStore->getInvitationList( $listID );
		} catch ( InvitationListNotFoundException $_ ) {
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'campaignevents-invitation-list-does-not-exist' )->parseAsBlock()
			) );
			return;
		}

		$user = $this->centralUserLookup->newFromAuthority( new MWAuthorityProxy( $this->getAuthority() ) );
		if ( !$invitationList->getCreator()->equals( $user ) ) {
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'campaignevents-invitation-list-not-creator' )->parseAsBlock()
			) );
			return;
		}

		$this->displayList( $invitationList );
	}

	private function displayList( InvitationList $list ): void {
		$out = $this->getOutput();
		// TDB set real page title will be done later after the DB layer T366633
		$out->setPageTitle( "My Invitation List" );
		$messageWidget = new MessageWidget( [
			'type' => 'notice',
			'label' => new HtmlSnippet( $this->msg( 'campaignevents-invitation-list-processing' )->parse() )
		] );
		$out->addHTML( $messageWidget );
	}
}
