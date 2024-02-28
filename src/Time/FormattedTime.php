<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Time;

/**
 * This is a value object that represents a formatted time, with separate getters for time, date, datetime,
 * and timezone.
 */
class FormattedTime {
	private string $time;
	private string $date;
	private string $timeAndDate;

	/**
	 * @param string $time
	 * @param string $date
	 * @param string $timeAndDate
	 */
	public function __construct(
		string $time,
		string $date,
		string $timeAndDate
	) {
		$this->time = $time;
		$this->date = $date;
		$this->timeAndDate = $timeAndDate;
	}

	/**
	 * @return string
	 */
	public function getTime(): string {
		return $this->time;
	}

	/**
	 * @return string
	 */
	public function getDate(): string {
		return $this->date;
	}

	/**
	 * @return string
	 */
	public function getTimeAndDate(): string {
		return $this->timeAndDate;
	}
}
