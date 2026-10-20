<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use ContentTranslation\ContentTranslationAfterPublishHook;
use ContentTranslation\SiteMapper;
use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionValidator;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistEventsStore;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;

/**
 * Auto-associates translations published with ContentTranslation with an event, when the source article is in the
 * worklist of exactly one event that the translator can associate edits with (T435388). This mirrors the
 * auto-association in PostEditHandler, except that the worklist is matched against the source page rather than
 * the edited page, since the source is what organizers add to the worklist.
 */
class ContentTranslationPublishHandler implements ContentTranslationAfterPublishHook {
	private const MAX_EVENTS = 50;

	public function __construct(
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly IEventLookup $eventLookup,
		private readonly WorklistEventsStore $worklistEventsStore,
		private readonly EventContributionValidator $eventContributionValidator,
		private readonly NamespaceInfo $namespaceInfo,
		private readonly WikiLookup $wikiLookup,
		private readonly Config $mainConfig,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onContentTranslationAfterPublish(
		UserIdentity $user,
		PageIdentity $targetPage,
		int $targetRevisionId,
		string $sourceLanguage,
		string $sourceTitle,
		string $targetLanguage,
		bool $isSectionTranslation
	): void {
		if ( !$this->namespaceInfo->isContent( $targetPage->getNamespace() ) ) {
			// Translations published to a non-content namespace (e.g., a sandbox in the user namespace) are not
			// event participation, same as in PostEditHandler.
			return;
		}

		try {
			$centralUser = $this->centralUserLookup->newFromUserIdentity( $user );
		} catch ( UserNotGlobalException ) {
			return;
		}

		$sourceWikiID = $this->getSourceWikiID( $sourceLanguage );
		if ( $sourceWikiID === null ) {
			return;
		}

		// Note, this only returns events where the current (target) wiki is a target wiki.
		$events = $this->eventLookup->getEventsForContributionAssociationByParticipant(
			$centralUser,
			self::MAX_EVENTS
		);
		if ( !$events ) {
			return;
		}

		$eventIDs = array_map( static fn ( ExistingEventRegistration $e ): int => $e->getID(), $events );
		$matchingEventIDs = $this->worklistEventsStore->filterEventsByPageInWorklist(
			$eventIDs,
			$sourceWikiID,
			// Worklist pages are stored as prefixed text. We can't parse titles from another wiki, but at least
			// handle titles in DB key form.
			strtr( $sourceTitle, '_', ' ' )
		);

		// Like PostEditHandler, only auto-associate when there's a single candidate event, since an edit can only
		// be associated with one event. In any other case, the edit can still be associated manually.
		if ( count( $matchingEventIDs ) === 1 ) {
			// Validation is skipped for the same reasons as in PostEditHandler.
			$this->eventContributionValidator->scheduleAssociationJob(
				$targetRevisionId,
				WikiMap::getCurrentWikiId(),
				$matchingEventIDs[0],
				$centralUser->getCentralID()
			);
		}
	}

	/**
	 * Returns the ID of the wiki that ContentTranslation uses as a source for the given language, or null if it
	 * cannot be determined. This finds the wiki whose API URL matches the one from ContentTranslation's site mapping,
	 * rather than guessing the wiki ID from the language code, which does not work in general (e.g., "nb" is
	 * "nowiki"). The API URL is used because ContentTranslation fetches the source page from it, so it must be
	 * correct. Full URLs are compared because WikiMap::getWikiFromUrl() only compares hosts, so it can't tell apart
	 * wikis that share a host and only differ by path, as is common in local environments (T441941).
	 */
	private function getSourceWikiID( string $sourceLanguage ): ?string {
		$apiUrls = $this->wikiLookup->getAllApiUrls( $this->mainConfig->get( MainConfigNames::ScriptPath ) );
		$wikiID = array_search( SiteMapper::getApiURL( $sourceLanguage ), $apiUrls, true );
		return $wikiID !== false ? (string)$wikiID : null;
	}
}
