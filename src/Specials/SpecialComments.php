<?php

namespace MediaWiki\Extension\Yappin\Specials;

use MediaWiki\Config\Config;
use MediaWiki\Extension\Yappin\Utils;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * Implement Special:Comments. It is essentially a dummy special page that simply loads our module, which then fetches
 * all of the comments on the wiki. Users without JS enabled will see a message telling them to enable it.
 */
class SpecialComments extends SpecialPage {
	private Config $config;

	public function __construct( Config $config ) {
		parent::__construct( 'Comments' );
		$this->config = $config;
	}

	/**
	 * @param string $subPage
	 * @return void
	 */
	public function execute( $subPage ) {
		if ( $subPage ) {
			$this->getOutput()->redirect(
				$this->getPageTitle()->createFragmentTarget( 'user=' . $subPage )->getFullURL()
			);
		}

		$out = $this->getOutput();
		$this->setHeaders();

		Utils::loadCommentsModule( $out, $this->config );
		$out->addHTML(
			'<noscript>' . $out->msg( 'yappin-no-script' )->text() . '</noscript>'
		);
	}

	/**
	 * @return string
	 */
	public function getGroupName() {
		return 'pages';
	}
}
