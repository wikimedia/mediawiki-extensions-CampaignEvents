<?php

declare( strict_types=1 );

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'../../extensions/Echo',
		'../../extensions/Translate',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		'../../extensions/Echo',
		'../../extensions/Translate',
	]
);

$cfg['file_list'][] = 'CampaignEvents.alias.php';
$cfg['file_list'][] = 'CampaignEvents.namespaces.php';

$cfg['plugins'] = array_merge( $cfg['plugins'], [
	'StrictComparisonPlugin',
	'StrictLiteralComparisonPlugin',
] );

return $cfg;
