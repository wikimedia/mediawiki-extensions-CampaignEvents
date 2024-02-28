<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\Permissions\Authority;

class MWAuthorityProxy implements ICampaignsAuthority {
	private Authority $authority;

	/**
	 * @param Authority $authority
	 */
	public function __construct( Authority $authority ) {
		$this->authority = $authority;
	}

	/**
	 * @inheritDoc
	 */
	public function hasRight( string $right ): bool {
		return $this->authority->isAllowed( $right );
	}

	/**
	 * @inheritDoc
	 */
	public function isSitewideBlocked(): bool {
		$block = $this->authority->getBlock();
		return $block && $block->isSitewide();
	}

	/**
	 * @inheritDoc
	 */
	public function isNamed(): bool {
		return $this->authority->isNamed();
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->authority->getUser()->getName();
	}

	/**
	 * @return int
	 */
	public function getLocalUserID(): int {
		return $this->authority->getUser()->getId();
	}
}
