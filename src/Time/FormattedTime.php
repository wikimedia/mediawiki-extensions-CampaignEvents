<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Time;

/**
 * This is a value object that represents a formatted time, with separate getters for time, date, datetime,
 * and timezone.
 */
class FormattedTime {
	public function __construct(
		private readonly string $time,
		private readonly string $date,
		private readonly string $timeAndDate
	) {
	}

	public function getTime(): string {
		return $this->time;
	}

	public function getDate(): string {
		return $this->date;
	}

	public function getTimeAndDate(): string {
		return $this->timeAndDate;
	}
}
