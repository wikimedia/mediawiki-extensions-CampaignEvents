<?php

declare( strict_types=1 );
namespace MediaWiki\Extension\CampaignEvents\Formatters;

use MediaWiki\Extension\CampaignEvents\Address\Address;
use MediaWiki\Extension\CampaignEvents\Address\CountryProvider;
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
 */
class EventFormatter {
	public const SERVICE_NAME = 'CampaignEventsEventFormatter';

	public const DISPLAYED_WIKI_COUNT = 3;

	private CountryProvider $countryProvider;

	public function __construct(
		CountryProvider $countryProvider
	) {
		$this->countryProvider = $countryProvider;
	}

	public function formatWikis(
		EventRegistration $event,
		ITextFormatter $messageFormatter,
		WikiLookup $wikiLookup,
		Language $language,
		LinkRenderer $linkRenderer,
		string $allWikisMessage,
		string $moreWikisMessage
	): HtmlSnippet|string {
		$wikis = $event->getWikis();
		if ( $wikis === EventRegistration::ALL_WIKIS ) {
			return $messageFormatter->format( MessageValue::new( $allWikisMessage ) );
		}
		$currentWikiId = WikiMap::getCurrentWikiId();
		$curWikiKey = array_search( $currentWikiId, $wikis, true );
		if ( $curWikiKey !== false ) {
			unset( $wikis[$curWikiKey] );
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

	public function formatAddress(
		Address $address,
		string $languageCode,
		?string $noAddressMsg = null
	): string {
		$countryCode = $address->getCountryCode();
		if ( $countryCode ) {
			$countryString = $this->countryProvider->getCountryName( $countryCode, $languageCode );
		} else {
			$countryString = '';
		}
		$addressWithoutCountry = $address->getAddressWithoutCountry();
		if ( $addressWithoutCountry === null || $addressWithoutCountry === '' ) {
			$output = $countryString;
			if ( $noAddressMsg ) {
				$output .= "\n" . $noAddressMsg;
			}
		} else {
			// This is quite ugly, but we can't do much better without geocoding and letting the user enter
			// the full address (T309325).
			return $address->getAddressWithoutCountry() . "\n" . $countryString;
		}
		return $output;
	}
}
