<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\Pager\InvitationsListPager;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\SpecialPage\SpecialPage;
use OOUI\MessageWidget;

class SpecialMyInvitationLists extends SpecialPage {
	public const PAGE_NAME = 'MyInvitationLists';

	private PermissionChecker $permissionChecker;
	private CampaignsCentralUserLookup $centralUserLookup;
	private CampaignsDatabaseHelper $databaseHelper;

	/**
	 * @param PermissionChecker $permissionChecker
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param CampaignsDatabaseHelper $databaseHelper
	 */
	public function __construct(
		PermissionChecker $permissionChecker,
		CampaignsCentralUserLookup $centralUserLookup,
		CampaignsDatabaseHelper $databaseHelper
	) {
		parent::__construct( self::PAGE_NAME );
		$this->permissionChecker = $permissionChecker;
		$this->centralUserLookup = $centralUserLookup;
		$this->databaseHelper = $databaseHelper;
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		$this->setHeaders();
		$this->outputHeader();
		$out = $this->getOutput();
		$out->enableOOUI();
		$out->addModuleStyles( [
			'codex-styles',
			'ext.campaignEvents.specialeventslist.styles',
			'oojs-ui.styles.icons-alerts'
		] );
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
		$centralUser = $this->centralUserLookup->newFromAuthority( $mwAuthority );
		$pager = new InvitationsListPager(
			$centralUser,
			$this->databaseHelper,
			$this->getContext(),
			$this->getLinkRenderer()
		);
		$out->addHTML(
			$pager->getBody() .
			$pager->getNavigationBar()
		);
		parent::execute( $par );
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'campaignevents';
	}
}
