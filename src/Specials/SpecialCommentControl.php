<?php

namespace MediaWiki\Extension\Yappin\Specials;

use MediaWiki\Extension\Yappin\Models\CommentControlStatus;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use function MediaWiki\Extension\Yappin\Models\commentControlStatusToKey;

class SpecialCommentControl extends SpecialPage {
	public function __construct() {
		parent::__construct( 'CommentControl', 'comments-manage' );
	}

	public function execute( $subPage ) {
		$this->setHeaders();
		$this->checkPermissions();

		$out = $this->getOutput();

		if ( $subPage ) {
			// User accessed Special:CommentControl/PageName
			$title = Title::newFromText( $subPage );

			if ( !$title || !$title->exists() ) {
				$out->addWikiMsg( 'yappin-commentcontrol-error-invalid-page', $subPage );
				$this->showPageSelector();

				return;
			}

			$this->showPageControlForm( $title );
		} else {
			$this->showPageSelector();
			$this->showCurrentRestrictions();
		}
	}

	private function showPageSelector() {
		$formDescriptor = [
			'page' => [
				'type' => 'title',
				'label-message' => 'specialprotectpage-page',
				'required' => true,
				'exists' => true,
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitTextMsg( 'specialprotectpage-submit' )->setSubmitCallback(
			function ( array $data ) {
				$title = Title::newFromText( $data['page'] );
				if ( $title && $title->exists() ) {
					$target = $this->getPageTitle( $title->getPrefixedText() )->getFullURL();
					$this->getOutput()->redirect( $target );

					return true;
				}

				return [
					$data['page']
				];
			}
		)->show();
	}

	private function showPageControlForm( Title $title ) {
		$out = $this->getOutput();
		$out->setPageTitleMsg( wfMessage('yappin-commentcontrol-special-title') );
		$out->addBacklinkSubtitle( $title );

		$currentStatus = $this->getControlStatus( $title );

		$formDescriptor = [
			'info' => [
				'type' => 'info',
				'label-message' => 'yappin-commentcontrol-current-status',
				'default' => $this->msg(
					'yappin-commentcontrol-status-' . commentControlStatusToKey( $currentStatus )
				)->text(),
			],
			'status' => [
				'type' => 'radio',
				'label-message' => 'yappin-commentcontrol-new-status-label',
				'options-messages' => [
					'yappin-commentcontrol-status-enabled' => CommentControlStatus::ENABLED->value,
					'yappin-commentcontrol-status-read-only' => CommentControlStatus::READ_ONLY->value,
					'yappin-commentcontrol-status-disabled' => CommentControlStatus::DISABLED->value,
				],
				'default' => $currentStatus->value,
				'required' => true,
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitTextMsg( 'yappin-commentcontrol-submit' )->setSubmitCallback(
			function ( array $data ) use ( $title ) {
				$status = CommentControlStatus::from( (int)$data['status'] );
				$this->setControlStatus( $title, $status );
				$this->getOutput()->addWikiMsg(
					'yappin-commentcontrol-success',
					"{$title->getPrefixedText()}",
					$this->msg( 'yappin-commentcontrol-status-' . commentControlStatusToKey( $status ) )->text()
				);

				return true;
			}
		)->show();
	}

	private function showCurrentRestrictions() {
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getMaintenanceConnectionRef( DB_REPLICA );

		$res = $dbr->newSelectQueryBuilder()->select(
			[
				'cc_page',
				'cc_restriction'
			]
		)->from( 'com_control' )->caller( __METHOD__ )->fetchResultSet();

		if ( $res->numRows() === 0 ) {
			return;
		}

		$out = $this->getOutput();
		$out->addHTML( '<h2>' . $this->msg( 'yappin-commentcontrol-current-restrictions' )->escaped() . '</h2>' );
		$out->addHTML( '<ul>' );

		foreach ( $res as $row ) {
			$title = Title::newFromID( $row->cc_page );
			if ( $title ) {
				$status = CommentControlStatus::from( (int)$row->cc_restriction );
				$statusMsg = $this->msg( 'yappin-commentcontrol-status-' . commentControlStatusToKey( $status ) )
								  ->escaped();

				$out->addHTML(
					'<li>' . $this->getLinkRenderer()->makeLink(
						$this->getPageTitle( $title->getPrefixedText() ),
						$title->getPrefixedText()
					) . ' - ' . $statusMsg . '</li>'
				);
			}
		}

		$out->addHTML( '</ul>' );
	}

	public static function getControlStatus( Title $title ): CommentControlStatus {
		$id = $title->getArticleID();

		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getMaintenanceConnectionRef( DB_REPLICA );
		$cond = [ 'cc_page' => $id ];
		$res = $dbr->newSelectQueryBuilder()
				   ->select( [ "cc_restriction" ] )
				   ->from( 'com_control' )
				   ->where( $cond )
				   ->caller( __METHOD__ )
				   ->fetchResultSet()
				   ->fetchRow();

		if ( !$res ) {
			return CommentControlStatus::ENABLED;
		}

		return CommentControlStatus::from( $res['cc_restriction'] );
	}

	private static function setControlStatus( Title $title, CommentControlStatus $status ): void {
		$id = $title->getArticleID();
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getMaintenanceConnectionRef( DB_PRIMARY );
		if ( $status === CommentControlStatus::ENABLED ) {
			$dbw->newDeleteQueryBuilder()->deleteFrom( 'com_control' )->where( [
				'cc_page' => $id,
			] )->caller( __METHOD__ )->execute();
		} else {
			$values = [
				'cc_page' => $id,
				'cc_restriction' => $status->value,
			];
			$dbw->newInsertQueryBuilder()
				->insertInto( 'com_control' )
				->set( $values )
				->row( $values )
				->onDuplicateKeyUpdate()
				->uniqueIndexFields( [ 'cc_page' ] )
				->caller( __METHOD__ )
				->execute();
		}
	}

	protected function getGroupName(): string {
		return 'pagetools';
	}
}
