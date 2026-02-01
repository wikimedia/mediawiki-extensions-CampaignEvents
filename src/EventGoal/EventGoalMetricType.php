<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventGoal;

/**
 * Enumeration of valid event goal metric types.
 * Use from( string ) to parse a string from JSON/API (throws ValueError on invalid).
 */
enum EventGoalMetricType: string {
	case TotalArticlesCreated = 'total_articles_created';
	case TotalArticlesEdited = 'total_articles_edited';
	case TotalEdits = 'total_edits';
	case TotalBytesAdded = 'total_bytes_added';
	case TotalBytesRemoved = 'total_bytes_removed';
	case TotalLinksAdded = 'total_links_added';
	case TotalLinksRemoved = 'total_links_removed';
}
