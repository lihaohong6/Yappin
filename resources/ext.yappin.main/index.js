/**
 * Main module for the Yappin extension.
 *
 * This module creates a new Vue app, which handles displaying comments and allowing users
 * to post new comments. It is used on all wiki pages where comments should be displayed.
 *
 * @author Jayden Bailey <jayden@weirdgloop.org>
 */

const
	Vue = require( 'vue' ),
	App = require( './App.vue' );

/**
 * @return {void}
 */
function initApp() {
	const bodyContent = document.getElementById( 'bodyContent' );
	if ( !bodyContent ) {
		return;
	}

	const container = document.createElement( 'div' );
	container.id = 'ext-comments-container';
	bodyContent.appendChild( container );

	Vue.createMwApp( App )
		.mount( container );
}

mw.commentsExt = mw.commentsExt || {};

$( () => {
	// Create the Vue app
	initApp();
} );
