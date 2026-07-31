<?php

namespace MediaWiki\Extension\Yappin\Hooks;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaHookHandlers implements
	LoadExtensionSchemaUpdatesHook
{
	/**
	 * @param DatabaseUpdater $updater
	 * @return void
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = dirname( __DIR__, 2 ) . '/sql';
		$maintenanceDb = $updater->getDB();
		$dbType = $maintenanceDb->getType();

		// In case of migrating from upstream's version of Yappin.
		if ( $updater->tableExists( 'com_comment' ) && !$updater->tableExists( 'yappin_comment' ) ) {
			$updater->addExtensionUpdate( [
				'applyPatch',
				"$dir/$dbType/patch-rename-comments-to-yappin.sql",
				true,
				'Renaming com_comment/com_rating/com_control tables and columns to yappin_*',
			] );
		}

		$updater->addExtensionTable( 'yappin_comment', "$dir/$dbType/tables-generated.sql" );
	}
}
