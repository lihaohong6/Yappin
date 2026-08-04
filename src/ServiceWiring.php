<?php

namespace MediaWiki\Extension\Yappin;

use MediaWiki\MediaWikiServices;

/**
 * Comments wiring for MediaWiki services.
 */
return [
	'Yappin.CommentFactory' => static function ( MediaWikiServices $services ): CommentFactory {
		return new CommentFactory(
			$services->getDBLoadBalancerFactory(),
			$services->getActorStoreFactory(),
			$services->getTitleFactory(),
			$services->getUserIdentityUtils(),
			$services->getService( 'Yappin.CommentHelperService' )
		);
	},
	'Yappin.CommentHelperService' => static function ( MediaWikiServices $services ): CommentHelperService {
		return new CommentHelperService(
			$services->getParsoidParserFactory(),
			$services->getMainWANObjectCache(),
			$services->getUserFactory(),
			$services->getMainConfig()
		);
	}
];
