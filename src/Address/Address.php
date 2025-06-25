<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Address;

/**
 * Value object that represents an address.
 */
class Address {
	private ?string $addressWithoutCountry;
	private ?string $country;

	public function __construct(
		?string $addressWithoutCountry,
		?string $country
	) {
		$this->addressWithoutCountry = $addressWithoutCountry;
		$this->country = $country;
	}

	public function getAddressWithoutCountry(): ?string {
		return $this->addressWithoutCountry;
	}

	public function getCountry(): ?string {
		return $this->country;
	}
}
