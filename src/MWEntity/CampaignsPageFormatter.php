<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\Title\TitleFormatter;

/**
 * This class formats a page, providing some string representation of it.
 * @note This must work for cross-wiki pages, so getPrefixedText() cannot be here.
 */
class CampaignsPageFormatter {
	public const SERVICE_NAME = 'CampaignEventsCampaignsPageFormatter';

	private TitleFormatter $titleFormatter;

	public function __construct( TitleFormatter $titleFormatter ) {
		$this->titleFormatter = $titleFormatter;
	}

	public function getText( MWPageProxy $page ): string {
		return $this->titleFormatter->getText( $page->getPageIdentity() );
	}
}
