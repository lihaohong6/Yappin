const SORT_OPTIONS = [
	{ label: mw.message( 'yappin-sort-highest-rated' ).text(), value: 'sort_rating_desc' },
	{ label: mw.message( 'yappin-sort-newest' ).text(), value: 'sort_date_desc' },
	{ label: mw.message( 'yappin-sort-oldest' ).text(), value: 'sort_date_asc' },
];

/**
 * @param {HTMLElement|jQuery} el
 * @returns {boolean}
 */
const isElementInView = ( el ) => {
	if ( el instanceof jQuery ) {
		el = el[ 0 ];
	}

	const rect = el.getBoundingClientRect();

	return (
		rect.top >= 0 &&
		rect.left >= 0 &&
		rect.bottom <= ( window.innerHeight || document.documentElement.clientHeight ) &&
		rect.right <= ( window.innerWidth || document.documentElement.clientWidth )
	);
};

/**
 * Extract the localized error message from a failed mw.Rest request.
 *
 * Errors thrown as a LocalizedHttpException carry an `errorKey` and a set of
 * `messageTranslations`. Anything else — network failures, errors produced by a proxy
 * rather than MediaWiki — has neither, in which case both returned properties are null
 * and the caller should fall back to a message of its own.
 *
 * @param {Object} result the second argument jQuery passes to a fail() handler
 * @return {{key: ?string, text: ?string}}
 */
const extractApiError = ( result ) => {
	const json = result && result.xhr && result.xhr.responseJSON;

	if ( !json || !json.messageTranslations ) {
		return { key: null, text: null };
	}

	const lang = mw.config.get( 'wgUserLanguage' );

	return {
		key: json.errorKey || null,
		text: json.messageTranslations[ lang ] || json.messageTranslations.en || null
	};
};

module.exports = {
	SORT_OPTIONS,
	isElementInView,
	extractApiError
};
