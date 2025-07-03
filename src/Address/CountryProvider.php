<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Address;

use MediaWiki\Extension\CLDR\CountryNames;

class CountryProvider {
	public const SERVICE_NAME = 'CampaignEventsCountryProvider';

	/**
	 * List of country codes that should be excluded from the CLDR result.
	 * These codes are not valid for our use case.
	 */
	public const EXCLUDED_COUNTRY_CODES = [
		'CP',
		'CQ',
		'DG',
		'EA',
		'EU',
		'EZ',
		'IC',
		'QO',
		'TA',
		'UN',
		'XA',
		'XB',
	];

	/**
	 * Returns the list of country names for a given language code.
	 *
	 * @param string $languageCode
	 * @return array<string, string> ISO country code => localized name
	 */
	public function getAvailableCountries( string $languageCode ): array {
		$all = CountryNames::getNames( $languageCode );

		// Filter out excluded codes
		return array_diff_key( $all, array_flip( self::EXCLUDED_COUNTRY_CODES ) );
	}

	/**
	 * Validates whether the provided country code is available for selection.
	 *
	 * @param string|null $code ISO 3166 country code
	 * @return bool
	 */
	public function isValidCountryCode( ?string $code ): bool {
		if ( !$code ) {
			return false;
		}
		// We use 'en' here to ensure consistent country codes regardless of user language.
		// Although loading localization data can be costly, this is safe:
		// MediaWiki loads all fallback languages when CLDR country data is accessed,
		// and 'en' is always part of the fallback chain, so this does not cause extra I/O.
		return array_key_exists( $code, $this->getAvailableCountries( 'en' ) );
	}
}
