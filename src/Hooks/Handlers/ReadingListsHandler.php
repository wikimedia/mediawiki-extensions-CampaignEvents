<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\MWEntity\GlobalEditCountLookup;
use MediaWiki\SpecialPage\Hook\SpecialPageBeforeExecuteHook;

/**
 * Adds the CampaignEvents features to Special:ReadingLists.
 *
 * The hook is in core, so the ReadingLists extension has no code for these features. When
 * ReadingLists is not installed, the special page does not exist, and this handler does
 * nothing.
 *
 * This is a temporary solution for an experimental feature. It adds a module to the special page
 * of another extension. If the feature is kept, replace it with an extension point in
 * ReadingLists, for example T442596.
 *
 * The module is added only for users who can see the editing signals. The checks go from the
 * cheapest to the most expensive:
 * - The flag CampaignEventsEnableReadingListsCardElements is on. When it is off, the endpoint
 *   that gives the signals returns 404.
 * - The user is named. Anonymous users and temporary users do not get the signals.
 * - The user has at least MIN_GLOBAL_EDITS edits on all the wikis.
 */
class ReadingListsHandler implements SpecialPageBeforeExecuteHook {
	/** The number of global edits that a user must have to see the editing signals. */
	public const MIN_GLOBAL_EDITS = 3;

	public function __construct(
		private readonly Config $config,
		private readonly GlobalEditCountLookup $globalEditCountLookup,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onSpecialPageBeforeExecute( $special, $subPage ) {
		if ( $special->getName() !== 'ReadingLists' ) {
			return;
		}
		if ( !$this->config->get( 'CampaignEventsEnableReadingListsCardElements' ) ) {
			return;
		}
		$user = $special->getUser();
		// Use isNamed(), not isRegistered(): temporary users are registered, but not named.
		if ( !$user->isNamed() ) {
			return;
		}
		if ( $this->globalEditCountLookup->getGlobalEditCount( $user ) < self::MIN_GLOBAL_EDITS ) {
			return;
		}
		$special->getOutput()->addModules( 'ext.campaignEvents.readingLists' );
	}
}
