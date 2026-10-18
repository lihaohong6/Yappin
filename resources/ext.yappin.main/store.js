const { reactive } = require( 'vue' );
const { SORT_OPTIONS } = require( './util.js' );

const isSpecialComments = !!document.querySelector( 'body.mw-special-Comments' );

const SORT_METHOD_STORAGE_KEY = 'ext-comments-comments-sort';
let initialSortMethod = 'sort_rating_desc';
const savedSortMethod = mw.storage.get( SORT_METHOD_STORAGE_KEY );
if ( savedSortMethod &&
	Object.values( SORT_OPTIONS ).map( ( v ) => v.value ).includes( savedSortMethod ) ) {
	initialSortMethod = savedSortMethod;
} else {
	// Invalid sort method saved, remove it from the browser storage
	mw.storage.remove( SORT_METHOD_STORAGE_KEY );
}

const useVisualEditor = mw.config.get( 'wgYappin' ).useVisualEditor === true;
let veLoad = null;

const store = reactive( {
	// Whether we're on Special:Comments instead of a normal wiki page
	isSpecialComments,
	ready: false,
	comments: [],
	sortMethod: isSpecialComments ? 'sort_date_desc' : initialSortMethod,
	isReadOnly: false,
	globalCooldown: 0,
	// The ID of the comment that we're currently editing, or null if we aren't editing any
	isEditing: null,
	// Whether the current user can moderate other people's comments. This variable is set
	// when a new list of comments is retrieved from the database via the API call.
	isMod: false,
	// If this is set to a comment ID, then the UI will render this comment and its children only,
	// rather than the entire comment tree.
	singleComment: null,
	// Whether to filter the comments displayed by a specific user
	filterByUser: null,
	// One of 'idle', 'loading', 'ready' or 'failed'. Wikitext mode is used when 'failed'.
	veState: useVisualEditor ? 'idle' : 'failed',
	setSortMethod( method ) {
		store.sortMethod = method;
		mw.storage.set( SORT_METHOD_STORAGE_KEY, store.sortMethod );
	},
	loadVE() {
		if ( veLoad ) {
			return veLoad;
		}
		if ( !useVisualEditor ) {
			veLoad = Promise.resolve();
			return veLoad;
		}
		store.veState = 'loading';
		veLoad = Promise.resolve( mw.loader.using( [
			OO.ui.isMobile() ? 'ext.yappin.ve.mobile' : 'ext.yappin.ve.desktop',
			'ext.yappin.ve'
		] ) ).then( () => {
			store.veState = mw.commentsExt.ve.Editor.static.isSupported() ? 'ready' : 'failed';
		}, () => {
			store.veState = 'failed';
		} );
		return veLoad;
	}
} );

module.exports = store;
