<?php
/**
 * Upgrade manifest — required files for health checks and legacy paths to remove after update.
 * Updated each release; loaded by PDX_Recovery and PDX_Updater.
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
		'includes/class-pdx-phishing-heuristics.php',
		'includes/class-pdx-scan-orchestrator.php',
		'includes/class-pdx-threat-feeds.php',
		'includes/class-pdx-ai-service.php',
		'includes/class-pdx-conversation.php',
		'includes/class-pdx-flow-store.php',
		'includes/class-pdx-workflow-engine.php',
		'includes/class-pdx-browser-automation.php',
		'includes/class-pdx-auth.php',
		'includes/class-pdx-account.php',
	],
	'legacy_remove'    => [],
];
