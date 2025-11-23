<?php

namespace MediaWiki\Extension\Yappin\Specials;

namespace MediaWiki\Extension\Yappin\Specials;

use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\User\UserFactory;

class SpecialExportComments extends FormSpecialPage {
	private UserFactory $userFactory;

	public function __construct() {
		parent::__construct( 'ExportComments', 'comments-manage' );
		$this->userFactory = MediaWikiServices::getInstance()->getUserFactory();
	}

	protected function getFormFields(): array {
		return [
			'includeDeleted' => [
				'type' => 'check',
				'label-message' => 'yappin-export-include-deleted',
				'default' => true,
			],
		];
	}

	public function onSubmit( array $data ) {
		$this->getOutput()->disable();
		$this->doExport( $data['includeDeleted'] );
		return Status::newGood();
	}

	private function doExport( $includeDeleted ) {
		$response = $this->getRequest()->response();
		$response->header( 'Content-Type: application/json; charset=utf-8' );
		$response->header( 'Content-Disposition: attachment; filename="comments-export.json"' );

		echo '[';

		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$conditions = [];
		if ( !$includeDeleted ) {
			$conditions[] = 'c_deleted_actor IS NULL';
		}

		$res = $dbr->newSelectQueryBuilder()
				   ->select( [ 'c.*', 'page_title', 'page_namespace', 'page_id' ] )
				   ->from( 'com_comment', 'c' )
				   ->join( 'page', null, 'c_page = page_id' )
				   ->where( $conditions )
				   ->orderBy( 'c_page' )
				   ->caller( __METHOD__ )
				   ->fetchResultSet();

		$isFirstPage = true;
		$currentArrayOpen = false;
		$currentPageId = null;
		$isFirstComment = true;

		foreach ( $res as $row ) {
			if ( $row->c_page !== $currentPageId ) {
				if ( $currentArrayOpen ) {
					echo ']}'; // Close previous comments array and page object
				}

				if ( !$isFirstPage ) {
					echo ',';
				}

				$pageObj = [
					'title' => $row->page_title,
					'ns' => (int)$row->page_namespace,
					'id' => (int)$row->page_id
				];

				// Manually start the JSON object to allow streaming comments array
				echo '{"page":' . json_encode( $pageObj ) . ',"comments":[';

				$currentPageId = $row->c_page;
				$currentArrayOpen = true;
				$isFirstPage = false;
				$isFirstComment = true;
			}

			if ( !$isFirstComment ) {
				echo ',';
			}

			$actor = (int)$row->c_actor;
			if ($actor === 0 ) {
				$username = $row->c_username;
			} else {
				$username = $this->userFactory->newFromActorId( $actor )->getUser()->getName();
			}

			$commentObj = [
				'id' => (int)$row->c_id,
				'parentId' => $row->c_parent ? (int)$row->c_parent : null,
				'timestamp' => wfTimestamp( TS_MW, $row->c_timestamp ),
				'editedTimestamp' => $row->c_edited_timestamp ? wfTimestamp( TS_MW, $row->c_edited_timestamp ) : null,
				'wikitext' => $row->c_wikitext,
				'username' => $username
			];

			echo json_encode( $commentObj );
			$isFirstComment = false;
		}

		if ( $currentArrayOpen ) {
			echo ']}'; // Close the last page object
		}

		echo ']';
		exit;
	}

	protected function getDisplayFormat() {
		return 'ooui';
	}

	protected function getGroupName() {
		return 'pagetools';
	}

	protected function alterForm( HTMLForm $form ) {
		$form->setSubmitTextMsg( 'yappin-export-submit' );
	}
}
