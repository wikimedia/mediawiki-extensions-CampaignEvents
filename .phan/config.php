<?php

declare( strict_types=1 );

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['file_list'][] = 'CampaignEvents.alias.php';
$cfg['file_list'][] = 'CampaignEvents.namespaces.php';

$cfg['plugins'] = array_merge( $cfg['plugins'], [
	'StrictComparisonPlugin',
	'StrictLiteralComparisonPlugin',
] );

return $cfg;
