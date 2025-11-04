<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Address;

/**
 * Value object that represents an address.
 */
class Address {
	private ?string $addressWithoutCountry;
	private string $countryCode;

	public function __construct(
		?string $addressWithoutCountry,
		string $countryCode,
	) {
		$this->addressWithoutCountry = $addressWithoutCountry;
		$this->countryCode = $countryCode;
	}

	public function getAddressWithoutCountry(): ?string {
		return $this->addressWithoutCountry;
	}

	public function getCountryCode(): string {
		return $this->countryCode;
	}
}
