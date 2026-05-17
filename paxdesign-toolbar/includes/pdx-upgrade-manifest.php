<?php
/**
 * Upgrade manifest for 8.0.x — required files and legacy cleanup paths.
 *
 * @return array{required_files:list<string>,legacy_remove:list<string>}
 */
return [
	'required_files' => [
		'includes/class-pdx-loader.php',
		'includes/class-pdx-settings.php',
		'includes/class-pdx-target.php',
		'includes/class-pdx-http.php',
		'includes/class-pdx-intelligence.php',
		'includes/class-pdx-url-analyzer.php',
		'includes/class-pdx-scan-orchestrator.php',
		'includes/class-pdx-threat-feeds.php',
	],
	'legacy_remove'    => [],
];
