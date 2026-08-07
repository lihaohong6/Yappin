<?php

namespace MediaWiki\Extension\Yappin;

use MediaWiki\MediaWikiServices;

/**
 * Yappin wiring for MediaWiki services.
 */
return [
	'Yappin.CommentFactory' => static function ( MediaWikiServices $services ): CommentFactory {
		return new CommentFactory(
			$services->getDBLoadBalancerFactory(),
			$services->getActorStore(),
			$services->getTitleFactory(),
			$services->getUserFactory(),
			$services->getParsoidParserFactory(),
			$services->getHtmlTransformFactory(),
			$services->getMainConfig(),
			$services->getUserIdentityUtils()
		);
	}
];
