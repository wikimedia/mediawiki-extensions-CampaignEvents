<?php

declare( strict_types=1 );

/**
 * Namespace name definitions
 *
 * @file
 * @ingroup Extensions
 */

$namespaceNames = [];

// For wikis where the extension is not installed
if ( !defined( 'NS_EVENT' ) ) {
	define( 'NS_EVENT', 1728 );
	define( 'NS_EVENT_TALK', 1729 );
}

$namespaceNames['en'] = [
	NS_EVENT => 'Event',
	NS_EVENT_TALK => 'Event_talk',
];

$namespaceNames['he'] = [
	NS_EVENT => 'אירוע',
	NS_EVENT_TALK => 'שיחת_אירוע',
];
