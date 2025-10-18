<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Address;

use Wikimedia\JsonCodec\JsonCodecable;
use Wikimedia\JsonCodec\JsonCodecableTrait;

/**
 * Value object that represents an address.
 */
class Address implements JsonCodecable {
	use JsonCodecableTrait;

	public function __construct(
		private readonly ?string $addressWithoutCountry,
		private readonly string $countryCode,
	) {
	}

	public function getAddressWithoutCountry(): ?string {
		return $this->addressWithoutCountry;
	}

	public function getCountryCode(): string {
		return $this->countryCode;
	}

	/**
	 * @inheritDoc
	 * @return array<string,mixed>
	 */
	public function toJsonArray(): array {
		return [
			'addressWithoutCountry' => $this->addressWithoutCountry,
			'countryCode' => $this->countryCode,
		];
	}

	/**
	 * @inheritDoc
	 * @param array<string,mixed> $json
	 */
	public static function newFromJsonArray( array $json ): self {
		return new self(
			$json['addressWithoutCountry'],
			$json['countryCode'],
		);
	}
}
