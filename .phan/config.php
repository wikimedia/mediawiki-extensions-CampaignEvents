<?php

declare( strict_types=1 );

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'../../extensions/Echo',
		'../../extensions/Translate',
		'../../extensions/WikimediaMessages',
		'../../extensions/CommunityConfiguration',
		'../../extensions/cldr',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		'../../extensions/Echo',
		'../../extensions/Translate',
		'../../extensions/WikimediaMessages',
		'../../extensions/CommunityConfiguration',
		'../../extensions/cldr',
	]
);

$cfg['file_list'][] = 'CampaignEvents.alias.php';
$cfg['file_list'][] = 'CampaignEvents.namespaces.php';

$cfg['exclude_file_list'] = array_merge(
	$cfg['exclude_file_list'],
	[
		'../../extensions/Translate/.phan/stubs/BeforeCreateEchoEventHook.php',
		'../../extensions/Translate/.phan/stubs/EchoAttributeManager.php',
		'../../extensions/Translate/.phan/stubs/EchoEventPresentationModel.php',
		'../../extensions/Translate/.phan/stubs/EchoGetBundleRulesHook.php',
		'../../extensions/Translate/.phan/stubs/Event.php',
	]
);

$cfg['plugins'] = array_merge( $cfg['plugins'], [
	'AlwaysReturnPlugin',
	'PHPDocRedundantPlugin',
	'PHPDocToRealTypesPlugin',
	'StrictComparisonPlugin',
	'StrictLiteralComparisonPlugin',
	'UnknownElementTypePlugin',
] );

return $cfg;
