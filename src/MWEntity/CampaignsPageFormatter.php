<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use TitleFormatter;
use UnexpectedValueException;

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
	public function getPrefixedText( ICampaignsPage $page ): string {
		if ( $page instanceof MWPageProxy ) {
			return $this->titleFormatter->getPrefixedText( $page->getPageIdentity() );
		}
		throw new UnexpectedValueException( 'Unknown campaigns page implementation: ' . get_class( $page ) );
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
