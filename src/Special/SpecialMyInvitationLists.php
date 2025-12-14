<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\Pager\InvitationsListPager;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\SpecialPage\SpecialPage;

class SpecialMyInvitationLists extends SpecialPage {
	use InvitationFeatureAccessTrait;

	public const PAGE_NAME = 'MyInvitationLists';

	public function __construct(
		private readonly PermissionChecker $permissionChecker,
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly CampaignsDatabaseHelper $databaseHelper,
	) {
		parent::__construct( self::PAGE_NAME );
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
		$performer = $this->getAuthority();
		$isEnabledAndPermitted = $this->checkInvitationFeatureAccess(
			$this->getOutput(),
			$performer
		);
		if ( $isEnabledAndPermitted ) {
			$centralUser = $this->centralUserLookup->newFromAuthority( $performer );
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
