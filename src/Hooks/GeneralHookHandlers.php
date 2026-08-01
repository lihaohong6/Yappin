<?php

namespace MediaWiki\Extension\Yappin\Hooks;

use MediaWiki\Block\Hook\GetAllBlockActionsHook;
use MediaWiki\Config\Config;
use MediaWiki\Extension\Yappin\Models\CommentControlStatus;
use MediaWiki\Extension\Yappin\Specials\SpecialCommentControl;
use MediaWiki\Extension\Yappin\Utils;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\ContributionsToolLinksHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\ResourceLoader\Hook\ResourceLoaderGetConfigVarsHook;
use MediaWiki\Skin\Skin;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

class GeneralHookHandlers implements
	GetAllBlockActionsHook,
	BeforePageDisplayHook,
	ResourceLoaderGetConfigVarsHook,
	ContributionsToolLinksHook
{
	private Config $config;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * @param array &$actions
	 * @return void
	 */
	public function onGetAllBlockActions( &$actions ) {
		$actions[ 'yappin-comment' ] = 300;
	}

	/**
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();

		if ( !Utils::isCommentsEnabled( $this->config, $title ) || $out->getActionName() !== 'view' ) {
			return;
		}

		if ( SpecialCommentControl::getControlStatus( $title ) === CommentControlStatus::DISABLED ) {
			return;
		}

		Utils::loadCommentsModule( $out, $this->config );
	}

	/**
	 * @param array &$vars
	 * @param string $skin
	 * @param Config $config
	 * @return void
	 */
	public function onResourceLoaderGetConfigVars( array &$vars, $skin, Config $config ): void {
		$vars['wgYappin'] = [
			'resultsPerPage' => $config->get( 'YappinResultsPerPage' ),
			'readOnly' => $config->get( 'YappinReadOnly' ),
			'useVisualEditor' => $config->get( 'YappinUseVisualEditor' ),
		];
	}

	public function onSidebarBeforeOutput( Skin $skin, &$sidebar ) {
		$user = $skin->getUser();
		if ( !$user ) {
			return;
		}
		$userHasRight = MediaWikiServices::getInstance()->getPermissionManager()->userHasRight(
			$user,
			'yappin-manage'
		);
		if ( !$userHasRight ) {
			return;
		}
		$title = $skin->getTitle();
		if ( !$title ) {
			return;
		}
		if ( !Utils::isCommentsEnabled( $this->config, $title ) ) {
			return;
		}

		$sidebar['TOOLBOX'][] = [
			'text' => wfMessage( 'sidebar-yappin-commentcontrol' )->text(),
			'href' => SpecialPage::getTitleFor( 'CommentControl', $title->getPrefixedText() )->getFullURL(),
		];
	}

	/**
	 * @param int $id
	 * @param Title $title
	 * @param array &$tools
	 * @param SpecialPage $specialPage
	 * @return void
	 */
	public function onContributionsToolLinks( $id, Title $title, array &$tools, SpecialPage $specialPage ) {
		$username = $title->getText();

		$tools['commentcontribs'] = MediaWikiServices::getInstance()->getLinkRenderer()->makeKnownLink(
			SpecialPage::getTitleFor( 'Comments' ),
			$specialPage->msg( 'yappin-contributions', $username ),
			[],
			[ 'user' => $username ]
		);
	}

	public static function onRegistration() {
		global $wgYappinEnabledNamespaces, $wgContentNamespaces;

		foreach ( $wgContentNamespaces as $contentNamespace ) {
			if ( !isset( $wgYappinEnabledNamespaces[$contentNamespace] ) ) {
				$wgYappinEnabledNamespaces[$contentNamespace] = true;
			}
		}
	}
}
