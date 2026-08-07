<?php

namespace MediaWiki\Extension\Yappin;

use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\Authority;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Request\WebRequest;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Status\StatusFormatter;
use MediaWiki\Title\Title;
use MediaWiki\User\TempUser\TempUserCreator;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use Wikimedia\Message\MessageValue;

class Utils {
	/**
	 * If the user cannot comment, this method returns a MessageValue object indicating why.
	 * @param User|Authority $userOrAuthority
	 * @return MessageValue|true
	 */
	public static function canUserComment( $userOrAuthority ) {
		if ( !$userOrAuthority->isAllowed( 'yappin-comment' ) ) {
			return new MessageValue( 'yappin-submit-error-noperm' );
		}

		$block = $userOrAuthority->getBlock();
		if ( $block && ( $block->isSitewide() || $block->appliesToRight( 'yappin-comment' ) ) ) {
			return new MessageValue( 'yappin-submit-error-blocked' );
		}

		return true;
	}

	/**
	 * Returns the user that an action should be attributed to, creating a temporary account first
	 * if the wiki is configured to auto-create one for the given authority.
	 *
	 * If no temporary account is needed, the user behind the authority is returned unchanged.
	 *
	 * @param Authority $authority
	 * @param WebRequest $request the request to throttle temporary account creation against
	 * @param TempUserCreator $tempUserCreator
	 * @param StatusFormatter $statusFormatter used to turn a failed creation into an error message
	 * @return UserIdentity
	 * @throws LocalizedHttpException if a temporary account was needed but could not be created
	 */
	public static function acquireActingUser(
		Authority $authority,
		WebRequest $request,
		TempUserCreator $tempUserCreator,
		StatusFormatter $statusFormatter
	): UserIdentity {
		// Use the "edit" action, as it's the only one supported right now.
		if ( !$tempUserCreator->shouldAutoCreate( $authority, 'edit' ) ) {
			// An anon can fail shouldAutoCreate() while temporary accounts are enabled, e.g. because
			// anons do not hold the 'createaccount' right on this wiki. (Blocks are not a reason:
			// they are not applied to the right, only to the eventual creation attempt below.)
			// Returning the IP user here would make ActorStore::acquireActorId() throw, so refuse.
			if ( $tempUserCreator->isEnabled() && !$authority->isRegistered() ) {
				throw new LocalizedHttpException(
					new MessageValue( 'yappin-submit-error-noperm' ), 403
				);
			}

			return $authority->getUser();
		}

		$status = $tempUserCreator->create( null, $request );
		if ( $status->isOK() ) {
			return $status->getUser();
		}

		$msg = $statusFormatter->getMessage( $status );
		// Core's throttle message talks about account creation, which makes no sense to someone who
		// was only trying to comment, so reword it. Its parameters ($1 accounts created, $2 duration
		// of the throttle window) carry over. MW < 1.46 used the un-suffixed key.
		if ( in_array(
			$msg->getKey(), [ 'acct_creation_throttle_hit-temp', 'acct_creation_throttle_hit' ], true
		) ) {
			$msg = new Message( 'yappin-generic-error-tempuser-throttle', $msg->getParams() );
		}

		throw new LocalizedHttpException( MessageValue::newFromSpecifier( $msg ), 400 );
	}

	/**
	 * Returns whether the given user can moderate comments or not.
	 * @param User|Authority $userOrAuthority
	 * @return bool
	 */
	public static function canUserModerate( $userOrAuthority ) {
		return $userOrAuthority->isAllowed( 'yappin-manage' );
	}

	/**
	 * @param OutputPage $out
	 * @param Config $config
	 * @return void
	 */
	public static function loadCommentsModule( OutputPage $out, Config $config ) {
		$useVE = $config->get( 'YappinUseVisualEditor' ) &&
			ExtensionRegistry::getInstance()->isLoaded( 'VisualEditor' );

		if ( $useVE ) {
			$services = MediaWikiServices::getInstance();
			$isMobile = ExtensionRegistry::getInstance()->isLoaded( 'MobileFrontend' ) &&
				$services->getService( 'MobileFrontend.Context' )->shouldDisplayMobileView();
			$out->addModules( [ $isMobile ? 'ext.yappin.ve.mobile' : 'ext.yappin.ve.desktop' ] );
			$out->addModules( [ 'ext.yappin.ve' ] );
		}

		$out->addModules( [ 'ext.yappin.main' ] );
	}

	/**
	 * Given a Title object, should comments be enabled for it?
	 * @param Config $config
	 * @param Title $title
	 * @return bool
	 */
	public static function isCommentsEnabled( Config $config, Title $title ) {
		$enabledNs = $config->get( 'YappinEnabledNamespaces' );

		if ( empty( $enabledNs[ $title->getNamespace() ] ) ) {
			return false;
		}
		if ( $title->isTalkPage() ) {
			return false;
		}
		if ( $title->isSpecialPage() ) {
			return false;
		}
		if ( !$title->exists() ) {
			return false;
		}

		return true;
	}
}
