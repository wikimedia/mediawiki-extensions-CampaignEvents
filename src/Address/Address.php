<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Address;

use InvalidArgumentException;

/**
 * Value object that represents an address.
 */
class Address {
	private ?string $addressWithoutCountry;
	private ?string $country;
	private ?string $countryCode;

	public function __construct(
		?string $addressWithoutCountry,
		?string $country,
		?string $countryCode,
	) {
		if ( $addressWithoutCountry === null && $country === null && $countryCode === null ) {
			throw new InvalidArgumentException( 'Need at least one of address and country' );
		}

		$this->addressWithoutCountry = $addressWithoutCountry;
		$this->country = $country;
		$this->countryCode = $countryCode;
	}

	public function getAddressWithoutCountry(): ?string {
		return $this->addressWithoutCountry;
	}

	public function getCountry(): ?string {
		return $this->country;
	}

	public function getCountryCode(): ?string {
		return $this->countryCode;
	}
}
