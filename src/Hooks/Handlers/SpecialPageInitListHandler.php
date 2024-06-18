<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\Special\SpecialGenerateInvitationList;
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
					'CampaignEventsPermissionChecker'
				],
			];
		}
		return true;
	}
}
