<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Invitation\InvitationListGenerator;
use MediaWiki\Extension\CampaignEvents\Invitation\InvitationListStore;
use MediaWiki\Extension\CampaignEvents\Invitation\WorklistParser;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Special\SpecialGenerateInvitationList;
use MediaWiki\Extension\CampaignEvents\Special\SpecialInvitationList;
use MediaWiki\Extension\CampaignEvents\Special\SpecialMyInvitationLists;
use MediaWiki\SpecialPage\Hook\SpecialPage_initListHook;

/**
 * Hook handler for the SpecialPage_initList hook
 */
class SpecialPageInitListHandler implements SpecialPage_initListHook {
	private Config $config;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/** @inheritDoc */
	public function onSpecialPage_initList( &$list ) {
		if ( $this->config->get( 'CampaignEventsShowEventInvitationSpecialPages' ) ) {
			$list[ 'GenerateInvitationList' ] = [
				'class' => SpecialGenerateInvitationList::class,
				'services' => [
					PermissionChecker::SERVICE_NAME,
					InvitationListGenerator::SERVICE_NAME,
					WorklistParser::SERVICE_NAME,
				],
			];
			$list[ 'MyInvitationLists' ] = [
				'class' => SpecialMyInvitationLists::class,
				'services' => [
					PermissionChecker::SERVICE_NAME,
					CampaignsCentralUserLookup::SERVICE_NAME,
					CampaignsDatabaseHelper::SERVICE_NAME,
				],
			];
			$list[ 'InvitationList' ] = [
				'class' => SpecialInvitationList::class,
				'services' => [
					PermissionChecker::SERVICE_NAME,
					InvitationListStore::SERVICE_NAME,
					CampaignsCentralUserLookup::SERVICE_NAME,
					UserLinker::SERVICE_NAME,
				],
			];
		}
		return true;
	}
}
