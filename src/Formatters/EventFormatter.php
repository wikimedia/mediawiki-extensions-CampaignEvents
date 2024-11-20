<?php

declare( strict_types=1 );
namespace MediaWiki\Extension\CampaignEvents\Formatters;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Special\SpecialEventDetails;
use MediaWiki\Language\Language;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\WikiMap\WikiMap;
use OOUI\HtmlSnippet;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;

/**
 * To make UI code more DRY, all display formatting logic will be moved to this class.
 * For this patch, only wiki display code has been implemented here, but this will change in future patches.
 * TODO: Add formatting logic for Organizers, Timezones, Participants etc
 * TODO: Make this a service
 */
class EventFormatter {
	public const DISPLAYED_WIKI_COUNT = 3;

	/**
	 * @param EventRegistration $event
	 * @param ITextFormatter $messageFormatter
	 * @param WikiLookup $wikiLookup
	 * @param Language $language
	 * @param LinkRenderer $linkRenderer
	 * @param string $allWikisMessage
	 * @param string $moreWikisMessage
	 * @return HtmlSnippet|string
	 */
	public static function formatWikis(
		EventRegistration $event,
		ITextFormatter $messageFormatter,
		WikiLookup $wikiLookup,
		Language $language,
		LinkRenderer $linkRenderer,
		string $allWikisMessage,
		string $moreWikisMessage
	) {
		$wikis = $event->getWikis();
		if ( $wikis === EventRegistration::ALL_WIKIS ) {
			return $messageFormatter->format( MessageValue::new( $allWikisMessage ) );
		}
		$currentWikiId = WikiMap::getCurrentWikiId();
		if ( array_key_exists( $currentWikiId, $wikis ) ) {
			unset( $wikis[$currentWikiId] );
			array_unshift( $wikis, $currentWikiId );
		}
		$displayedWikiNames = $wikiLookup->getLocalizedNames(
			array_slice( $wikis, 0, self::DISPLAYED_WIKI_COUNT )
		);
		$wikiCount = count( $wikis );
		$escapedWikiNames = [];
		foreach ( $displayedWikiNames as $name ) {
			$escapedWikiNames[] = htmlspecialchars( $name );
		}
		if ( $wikiCount > self::DISPLAYED_WIKI_COUNT ) {
			$escapedWikiNames[] = $linkRenderer->makeKnownLink(
				SpecialPage::getTitleFor( SpecialEventDetails::PAGE_NAME, (string)$event->getID() ),
				$messageFormatter->format( MessageValue::new( $moreWikisMessage )
					->numParams( $wikiCount - self::DISPLAYED_WIKI_COUNT )
				)
			);
		}
		return new HtmlSnippet( $language->listToText( $escapedWikiNames ) );
	}
}
