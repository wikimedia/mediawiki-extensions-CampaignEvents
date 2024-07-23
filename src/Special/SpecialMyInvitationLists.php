<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\Pager\InvitationsListPager;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\SpecialPage\SpecialPage;

class SpecialMyInvitationLists extends SpecialPage {
	use InvitationFeatureAccessTrait;

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
			'ext.campaignEvents.specialPages.styles',
			'oojs-ui.styles.icons-alerts'
		] );
		$mwAuthority = new MWAuthorityProxy( $this->getAuthority() );
		$isEnabledAndPermitted = $this->checkInvitationFeatureAccess(
			$this->getOutput(),
			$mwAuthority
		);
		if ( $isEnabledAndPermitted ) {
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
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'campaignevents';
	}
}
