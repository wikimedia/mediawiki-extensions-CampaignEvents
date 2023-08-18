<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Questions;

/**
 * Value object representing the aggregated data of the event.
 */
class EventAggregatedAnswers {
	/** @var array */
	private array $data = [];

	/**
	 * @param int $questionID
	 * @param int $optionID
	 * @param int $amount
	 */
	public function addEntry( int $questionID, int $optionID, int $amount ) {
		if ( !isset( $this->data[ $questionID ] ) ) {
			$this->data[ $questionID ] = [];
		}

		$this->data[ $questionID ][ $optionID ] = $amount;
	}

	/**
	 * @return array
	 */
	public function getData() {
		return $this->data;
	}
}
