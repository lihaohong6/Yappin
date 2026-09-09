<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['minimum_target_php_version'] = '8.1';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'], [
		'../../extensions/AbuseFilter',
		'../../extensions/Echo',
		'../../extensions/UserProfileV2',
		'../../tests',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'], [
		'../../extensions/AbuseFilter',
		'../../extensions/Echo',
		'../../extensions/UserProfileV2',
		'../../tests',
	]
);

// Keep the suppressions mediawiki-phan-config sets up (unused hook handler parameters,
// among others) rather than replacing them wholesale.
$cfg['suppress_issue_types'] = array_merge(
	$cfg['suppress_issue_types'], [
		'PhanAccessMethodInternal',
		'PhanPluginMixedKeyNoKey',
		'SecurityCheck-LikelyFalsePositive',
	]
);

$cfg['plugins'] = array_merge( $cfg['plugins'], [
	'AddNeverReturnTypePlugin',
	'AlwaysReturnPlugin',
	'DeprecateAliasPlugin',
	'DollarDollarPlugin',
	'DuplicateConstantPlugin',
	'EmptyMethodAndFunctionPlugin',
	'EmptyStatementListPlugin',
	'FFIAnalysisPlugin',
	'InlineHTMLPlugin',
	'InvalidVariableIssetPlugin',
	'InvokePHPNativeSyntaxCheckPlugin',
	'LoopVariableReusePlugin',
	// 'MoreSpecificElementTypePlugin',
	// 'NotFullyQualifiedUsagePlugin',
	// 'PHPDocRedundantPlugin',
	'PHPUnitAssertionPlugin',
	'PHPUnitNotDeadCodePlugin',
	'PreferNamespaceUsePlugin',
	'PrintfCheckerPlugin',
	'RedundantAssignmentPlugin',
	'SimplifyExpressionPlugin',
	'SleepCheckerPlugin',
	'StrictComparisonPlugin',
	'StrictLiteralComparisonPlugin',
	'SuspiciousParamOrderPlugin',
	// 'UnknownElementTypePlugin',
	'UnreachableCodePlugin',
	'UnsafeCodePlugin',
	'UseReturnValuePlugin',
] );

$cfg['analyze_signature_compatibility'] = true;
// Yappin supports MediaWiki 1.45 through master, and some hook interfaces were moved to a
// new namespace in 1.46 with a class_alias left behind under the old name. Resolve those
// aliases so the same code analyses cleanly against every supported release.
$cfg['enable_class_alias_support'] = true;
$cfg['enable_extended_internal_return_type_plugins'] = true;
$cfg['redundant_condition_detection'] = true;
$cfg['unused_variable_detection'] = true;
$cfg['warn_about_relative_include_statement'] = true;

// The strict_*_checking family (method, object, param, property, return) makes phan warn when
// *any* type in a union is unsuitable, rather than when none of them are. On this codebase that
// is dominated by database rows, which Rdbms types as stdClass|array|false, and by services,
// which MediaWikiServices returns as mixed; the result is a large number of annotations that
// document phan's limits rather than the code. Leave the family off until the findings it
// produces are worth acting on.

return $cfg;
