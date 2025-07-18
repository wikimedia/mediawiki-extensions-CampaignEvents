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

$namespaceNames['ar'] = [
	NS_EVENT => 'فعالية',
	NS_EVENT_TALK => 'نقاش_فعالية',
];

$namespaceNames['az'] = [
	NS_EVENT => 'Tədbir',
	NS_EVENT_TALK => 'Tədbir_müzakirəsi'
];

$namespaceNames['bew'] = [
	NS_EVENT => 'Pegelaran',
	NS_EVENT_TALK => 'Kongko_Pegelaran'
];

$namespaceNames['es'] = [
	NS_EVENT => 'Evento',
	NS_EVENT_TALK => 'Evento_discusión',
];

$namespaceNames['he'] = [
	NS_EVENT => 'אירוע',
	NS_EVENT_TALK => 'שיחת_אירוע',
];

$namespaceNames['id'] = [
	NS_EVENT => 'Acara',
	NS_EVENT_TALK => 'Pembicaraan_Acara',
];

$namespaceNames['it'] = [
	NS_EVENT => 'Evento',
	NS_EVENT_TALK => 'Discussioni_evento',
];

$namespaceNames['ko'] = [
	NS_EVENT => '행사',
	NS_EVENT_TALK => '행사토론',
];

$namespaceNames['ms'] = [
	NS_EVENT => 'Acara',
	NS_EVENT_TALK => 'Perbincangan_acara',
];

$namespaceNames['nb'] = [
	NS_EVENT => 'Arrangement',
	NS_EVENT_TALK => 'Arrangementsdiskusjon',
];

$namespaceNames['nia'] = [
	NS_EVENT => 'Acara',
	NS_EVENT_TALK => 'Huhuo_Acara',
];

$namespaceNames['nn'] = [
	NS_EVENT => 'Arrangement',
	NS_EVENT_TALK => 'Arrangementsdiskusjon',
];

$namespaceNames['pl'] = [
	NS_EVENT => 'Wydarzenie',
	NS_EVENT_TALK => 'Dyskusja_wydarzenia',
];

$namespaceNames['pt'] = [
	NS_EVENT => 'Evento',
	NS_EVENT_TALK => 'Evento_Discussão',
];
