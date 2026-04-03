<?php

namespace MediaWiki\Extension\Yappin\Maintenance;

use MediaWiki\Maintenance\Maintenance;

class RemoveAllComments extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Remove all comments from Yappin. Mostly for dealing with botched imports.' );
		$this->requireExtension( 'Yappin' );
	}

	public function execute(): void {
		$dbw = $this->getPrimaryDB();

		foreach ( [ 'com_rating', 'com_comment', 'com_control' ] as $table ) {
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( $table )
				->where( '1=1' )
				->caller( __METHOD__ )
				->execute();
			$count = $dbw->affectedRows();
			$this->output( "Deleted $count row(s) from $table.\n" );
		}

		$this->output( "Done.\n" );
	}
}

$maintClass = RemoveAllComments::class;
