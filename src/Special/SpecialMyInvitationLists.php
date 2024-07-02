<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\SpecialPage\SpecialPage;
use OOUI\ButtonWidget;
use OOUI\MessageWidget;
use OOUI\Tag;

class SpecialMyInvitationLists extends SpecialPage {
	public const PAGE_NAME = 'MyInvitationLists';

	private PermissionChecker $permissionChecker;

	/**
	 * @param PermissionChecker $permissionChecker
	 */
	public function __construct(
		PermissionChecker $permissionChecker
	 ) {
		parent::__construct( self::PAGE_NAME );
		$this->permissionChecker = $permissionChecker;
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		$this->setHeaders();
		$this->outputHeader();
		$out = $this->getOutput();
		$out->enableOOUI();
		$mwAuthority = new MWAuthorityProxy( $this->getAuthority() );
		if ( !$this->getConfig()->get( 'CampaignEventsEnableEventInvitation' ) ) {
			$messageWidget = new MessageWidget( [
				'type' => 'notice',
				'label' => $this->msg( 'campaignevents-invitation-list-disabled' )->text()
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

		$out->addHTML( $this->getPageContent() );
		parent::execute( $par );
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'campaignevents';
	}

	private function getPageContent(): Tag {
		$container = ( new Tag() );

		$text = new Tag( 'p' );
		$text->appendContent(
			$this->msg(
				'campaignevents-myinvitationslist-empty-text'
			)->text()
		);
		$button = new ButtonWidget(
			[
				'href' => SpecialPage::getTitleFor( SpecialGenerateInvitationList::PAGE_NAME )->getLocalURL(),
				'label' => $this->msg( 'campaignevents-myinvitationslist-generate-button' )->text(),
				'flags' => [ 'primary', 'progressive' ]
			]
		);
		$textContainer = ( new Tag() )->appendContent( [ $text, $button ] );
		return $container->appendContent( [ $textContainer ] );
	}
}
