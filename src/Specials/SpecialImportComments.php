<?php

namespace MediaWiki\Extension\Yappin\Specials;

use Exception;
use MediaWiki\Exception\PermissionsError;
use MediaWiki\Extension\Yappin\CommentFactory;
use MediaWiki\Extension\Yappin\Models\Comment;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Json\FormatJson;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\Parsoid\ParsoidParser;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentityLookup;
use Wikimedia\Rdbms\DBConnRef;

class SpecialImportComments extends FormSpecialPage {
	private CommentFactory $commentFactory;
	private DBConnRef $dbr;
	private ParsoidParser $parser;
	private UserIdentityLookup $userLookup;

	public function __construct() {
		parent::__construct( 'ImportComments', 'comments-import' );
		$services = MediaWikiServices::getInstance();
		$this->commentFactory = $services->getService( 'Yappin.CommentFactory' );
		$this->dbr = $services->getDBLoadBalancer()->getMaintenanceConnectionRef( DB_REPLICA );
		$this->parser = MediaWikiServices::getInstance()->getParsoidParserFactory()->create();
		$this->userLookup = MediaWikiServices::getInstance()->getUserIdentityLookup();
	}

	public function execute( $par ) {
		$user = $this->getUser();
		if ( !$this->userCanExecute( $user ) ) {
			throw new PermissionsError( 'comments-manage' );
		}
		parent::execute( $par );
	}

	public function onSubmit( array $data ) {
		// Get uploaded file
		$upload = $_FILES['wpjsonfile'] ?? null;

		// Check to make sure there is a file uploaded
		if ( $upload === null || !$upload['name'] ) {
			return Status::newFatal( 'yappin-import-no-file' );
		}

		// Check for upload errors
		if ( !empty( $upload['error'] ) ) {
			return match ( $upload['error'] ) {
				UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => Status::newFatal( 'yappin-import-file-too-large' ),
				UPLOAD_ERR_PARTIAL => Status::newFatal( 'yappin-import-partial-upload' ),
				UPLOAD_ERR_NO_TMP_DIR => Status::newFatal( 'yappin-import-no-temp-dir' ),
				default => Status::newFatal( 'yappin-import-upload-error' ),
			};
		}

		// Read file
		$fname = $upload['tmp_name'];
		if ( !is_uploaded_file( $fname ) ) {
			return Status::newFatal( 'yappin-import-no-file' );
		}

		$jsonContent = file_get_contents( $fname );
		$parseResult = FormatJson::parse( $jsonContent, FormatJson::FORCE_ASSOC );

		// If there is an error during JSON parsing, abort
		if ( !$parseResult->isOK() ) {
			return Status::newFatal( 'yappin-import-invalid-json' );
		}

		$skipExisting = $data['skipexisting'] ?? false;
		$attachUsers = $data['attachusers'] ?? false;

		try {
			$this->doImport( $parseResult->getValue(), $skipExisting, $attachUsers );
		} catch ( Exception $e ) {
			return Status::newFatal( 'yappin-import-failed', $e->getMessage() );
		}

		return Status::newGood();
	}

	private function doImport( array $json, bool $skipExisting, bool $attachUsers ): void {
		$output = $this->getOutput();
		$services = MediaWikiServices::getInstance();
		$dbw = $services->getDBLoadBalancer()->getMaintenanceConnectionRef( DB_PRIMARY );

		$totalImported = 0;
		$totalSkipped = 0;
		$totalFailed = 0;

		foreach ( $json as $pageData ) {
			if ( !isset( $pageData['page'] ) || !isset( $pageData['comments'] ) ) {
				$totalFailed++;
				continue;
			}

			$pageInfo = $pageData['page'];
			$pageTitle = $pageInfo['title'] ?? null;

			if ( !$pageTitle ) {
				$output->addWikiMsg( 'yappin-import-no-page-title' );
				$totalFailed++;
				continue;
			}

			$title = Title::newFromText( $pageTitle );

			$comments = $pageData['comments'];
			// Skip if page doesn't exist
			if ( !$title || !$title->exists() ) {
				$output->addWikiMsg( 'yappin-import-page-not-found', $pageTitle );
				$totalFailed += count( $comments );
				continue;
			}

			[
				$pageImported,
				$pageSkipped,
				$pageFailed
			] = $this->importPageComments(
				$title,
				$pageData['comments'],
				$dbw,
				$output,
				$skipExisting,
				$attachUsers
			);

			$totalImported += $pageImported;
			$totalSkipped += $pageSkipped;
			$totalFailed += $pageFailed;

			if ( $pageImported > 0 ) {
				// Log the import
				$logEntry = new ManualLogEntry( 'comments', 'import' );
				$logEntry->setPerformer( $this->getUser() );
				$logEntry->setTarget( $title );
				$logEntry->setParameters( [
					'4::count' => $pageImported,
				] );
				$logId = $logEntry->insert( $dbw );
				$logEntry->publish( $logId );

				$output->addWikiMsg( 'yappin-import-success', $title->getPrefixedText(), $pageImported );
			}

			if ( $pageSkipped > 0 ) {
				$output->addWikiMsg( 'yappin-import-skipped', $title->getPrefixedText(), $pageSkipped );
			}

			if ( $pageFailed > 0 ) {
				$output->addWikiMsg( 'yappin-import-failed-count', $title->getPrefixedText(), $pageFailed );
			}
		}

		// Summary message
		$output->addHTML( '<hr>' );
		$output->addWikiMsg( 'yappin-import-summary', $totalImported, $totalSkipped, $totalFailed );
	}

	/**
	 * @param Title $title
	 * @param array $commentsList
	 * @param DBConnRef $dbw
	 * @param OutputPage $output
	 * @param bool $skipExisting
	 *
	 * @return int[]
	 */
	public function importPageComments(
		Title $title,
		array $commentsList,
		DBConnRef $dbw,
		OutputPage $output,
		bool $skipExisting,
		bool $attachUsers
	): array {
		$pageId = $title->getArticleID();
		$importedCounter = 0;
		$skippedCounter = 0;
		$failedCounter = 0;

		// Comment deduplication is done through timestamps
		$existingTimestamps = [];
		if ( $skipExisting ) {
			$cond = [ "c_page" => $pageId ];
			$rows = $this->dbr->newSelectQueryBuilder()->select( [ "c_timestamp" ] )->from( "com_comment" )->where(
				$cond
			)->fetchResultSet();
			foreach ( $rows as $row ) {
				$existingTimestamps[$row->c_timestamp] = true;
			}
		}

		// Build a map of old IDs to new IDs for parent relationships
		$idMap = [];

		foreach ( $commentsList as $commentData ) {
			$oldId = $commentData['id'] ?? null;
			if ( !$oldId ) {
				$failedCounter++;
				continue;
			}
			if ( $skipExisting && isset( $existingTimestamps[$commentData['timestamp']] ) ) {
				$skippedCounter++;
				continue;
			}

			try {
				// Handle parent ID mapping
				$parentId = null;
				if ( isset( $commentData['parentId'] ) ) {
					$parentId = $idMap[$commentData['parentId']] ?? null;
				}
				$wikitext = $commentData['wikitext'] ?? '';
				$parserOpts = ParserOptions::newFromAnon();
				$parserOpts->setSuppressSectionEditLinks();
				$parserOutput = $this->parser->parse( $wikitext, $title, $parserOpts );
				$parserOutput->clearWrapperDivClass();
				$html = $parserOutput->runOutputPipeline( $parserOpts )->getRawText();

				$actorId = 0;
				$username = $commentData['username'] ?? null;
				if ( $attachUsers && $username !== null ) {
					$userIdentity = $this->userLookup->getUserIdentityByName( $username );
					if ( $userIdentity !== null ) {
						$actorId = $userIdentity->getId();
						$username = null;
					}
				}

				// Note that we should never use the old comment id.
				$row = [
					'c_page' => $pageId,
					'c_actor' => $actorId,
					'c_username' => $username,
					'c_parent' => $parentId,
					'c_timestamp' => $dbw->timestamp( $commentData['timestamp'] ),
					'c_edited_timestamp' => isset( $commentData['editedTimestamp'] ) ? $dbw->timestamp(
						$commentData['editedTimestamp']
					) : null,
					'c_deleted_actor' => null,
					'c_rating' => 0,
					'c_wikitext' => $wikitext,
					'c_html' => $html,
				];

				// Insert the comment
				$dbw->newInsertQueryBuilder()
					->insertInto( Comment::TABLE_NAME )
					->row( $row )
					->caller( __METHOD__ )
					->execute();

				$newId = $dbw->insertId();
				$idMap[$oldId] = $newId;
				$importedCounter++;
			} catch ( Exception $e ) {
				$output->addWikiMsg( 'yappin-import-comment-failed', $oldId, $e->getMessage() );
				$failedCounter++;
			}
		}

		return [
			$importedCounter,
			$skippedCounter,
			$failedCounter
		];
	}

	protected function alterForm( HTMLForm $form ) {
		$form->setSubmitTextMsg( 'yappin-import-submit' );
	}

	protected function getFormFields() {
		return [
			'jsonfile' => [
				'type' => 'file',
				'label-message' => 'yappin-import-file-label',
				'accept' => [ 'application/json' ],
			],
			'skipexisting' => [
				'type' => 'check',
				'label-message' => 'yappin-import-skip-existing',
				'default' => true,
				'help-message' => 'yappin-import-skip-existing-help',
			],
			'attachusers' => [
				'type' => 'check',
				'label-message' => 'yappin-import-attach-users',
				'default' => false,
				'help-message' => 'yappin-import-attach-users-help',
			],
		];
	}

	protected function getDisplayFormat() {
		return 'ooui';
	}

	protected function getGroupName() {
		return 'pagetools';
	}
}
