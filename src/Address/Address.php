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

	public function __construct(
		?string $addressWithoutCountry,
		?string $country
	) {
		if ( $addressWithoutCountry === null && $country === null ) {
			throw new InvalidArgumentException( '$addressWithoutCountry and $country cannot be both null' );
		}
		$this->addressWithoutCountry = $addressWithoutCountry;
		$this->country = $country;
	}

	public function getAddressWithoutCountry(): ?string {
		return $this->addressWithoutCountry;
	}

	public function getCountry(): ?string {
		return $this->country;
	}

	public function toString(): string {
		// This is quite ugly, but we can't do much better without geocoding and letting the user enter
		// the full address (T309325).
		return $this->addressWithoutCountry . "\n" . $this->country;
	}
}
