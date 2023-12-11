<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\Title\TitleFormatter;
use UnexpectedValueException;

/**
 * This class formats a page, providing some string representation of it.
 * @note This must work for cross-wiki pages, so getPrefixedText() cannot be here.
 */
class CampaignsPageFormatter {
	public const SERVICE_NAME = 'CampaignEventsCampaignsPageFormatter';

	/** @var TitleFormatter */
	private $titleFormatter;

	/**
	 * @param TitleFormatter $titleFormatter
	 */
	public function __construct( TitleFormatter $titleFormatter ) {
		$this->titleFormatter = $titleFormatter;
	}

	/**
	 * @param ICampaignsPage $page
	 * @return string
	 */
	public function getText( ICampaignsPage $page ): string {
		if ( $page instanceof MWPageProxy ) {
			return $this->titleFormatter->getText( $page->getPageIdentity() );
		}
		throw new UnexpectedValueException( 'Unknown campaigns page implementation: ' . get_class( $page ) );
	}
}
