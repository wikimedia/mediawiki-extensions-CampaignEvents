<?php

declare( strict_types=1 );
namespace MediaWiki\Extension\CampaignEvents\Config\CommunityConfiguration\Schemas;

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase

use MediaWiki\Extension\CommunityConfiguration\Controls\NamespacesControl;
use MediaWiki\Extension\CommunityConfiguration\Schema\JsonSchema;

class CampaignEventsSchema extends JsonSchema {
	public const CampaignEventsEventNamespaces = [
		self::TYPE => self::TYPE_ARRAY,
		self::MIN_ITEMS => 1,
		self::ITEMS => [
			self::TYPE => self::TYPE_INTEGER,
		],
		self::DEFAULT => [ NS_EVENT, NS_PROJECT ],
		self::CONTROL => NamespacesControl::class
	];
}
