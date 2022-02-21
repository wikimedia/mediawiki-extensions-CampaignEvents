<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

interface ICampaignsUser {
	/**
	 * @return int
	 */
	public function getId(): int;
}
