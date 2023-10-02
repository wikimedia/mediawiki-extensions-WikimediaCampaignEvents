<?php

declare( strict_types=1 );

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg[ 'directory_list' ] = array_merge(
	$cfg[ 'directory_list' ],
	[
	  '../../extensions/CampaignEvents',
	]
);
$cfg[ 'exclude_analysis_directory_list' ] = array_merge(
	$cfg[ 'exclude_analysis_directory_list' ],
	[
	  '../../extensions/CampaignEvents',
	]
);

$cfg['plugins'] = array_merge( $cfg['plugins'], [
	'StrictComparisonPlugin',
	'StrictLiteralComparisonPlugin',
] );

return $cfg;
