<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Hook\TitleMoveHook;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

/**
 * This handler is used before moving an article (title).
 */
class TitleMoveHandler implements TitleMoveHook {
	public function __construct(
		private readonly PageEventLookup $pageEventLookup,
		private readonly Config $config,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onTitleMove( Title $old, Title $nt, User $user, $reason, Status &$status ) {
		$registration = $this->pageEventLookup->getRegistrationForLocalPage( $old, PageEventLookup::GET_DIRECT );
		// Disallow moving event pages with registration enabled outside of allowed namespaces. Otherwise, the namespace
		// restriction could easily be circumvented by creating a page in a permitted namespace and then moving it.
		if (
			$registration &&
			!in_array( $nt->getNamespace(), $this->config->get( 'CampaignEventsEventNamespaces' ), true )
		) {
			$status->fatal( 'campaignevents-error-move-eventpage-namespace-disallowed' );
			return false;
		}
		return true;
	}
}
