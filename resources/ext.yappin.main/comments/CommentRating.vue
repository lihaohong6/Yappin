<template>
	<div class="comment-rating-actions">
		<button
			class="comment-rating-btn"
			:title="$i18n( 'yappin-rating-upvote' )"
			data-type="upvote"
			:value="$props.comment.userRating === 1"
			:disabled="waiting"
			@click="onButtonClick"
		>
			<cdx-icon
				:icon="cdxIconUpTriangle"
				size="small"
			></cdx-icon>
		</button>
		<button
			class="comment-rating-btn"
			:title="$i18n( 'yappin-rating-downvote' )"
			data-type="downvote"
			:value="$props.comment.userRating === -1"
			:disabled="waiting"
			@click="onButtonClick"
		>
			<cdx-icon
				:icon="cdxIconDownTriangle"
				size="small"
			></cdx-icon>
		</button>
	</div>
</template>

<script>
const Comment = require( '../comment.js' );
const { defineComponent } = require( 'vue' );
const { CdxIcon } = require( '../codex.js' );
const { cdxIconUpTriangle, cdxIconDownTriangle } = require( '../icons.json' );
const { extractApiError } = require( '../util.js' );

const api = new mw.Rest();
const actionApi = new mw.Api();

module.exports = exports = defineComponent( {
	name: 'CommentRating',
	components: {
		CdxIcon
	},
	props: {
		comment: {
			type: Comment,
			default: null,
			required: true
		}
	},
	setup() {
		return {
			cdxIconUpTriangle,
			cdxIconDownTriangle
		};
	},
	data() {
		return {
			waiting: false
		};
	},
	methods: {
		onButtonClick( e ) {
			this.$data.waiting = true;
			const type = e.currentTarget.dataset.type;
			let newValue = 0;

			if ( type === 'upvote' ) {
				newValue = this.$props.comment.userRating === 1 ? 0 : 1;
			} else if ( type === 'downvote' ) {
				newValue = this.$props.comment.userRating === -1 ? 0 : -1;
			}

			// Change the UI state before the API call happens for quick visual feedback
			const oldValue = this.$props.comment.userRating;
			this.$props.comment.userRating = newValue;

			this.changeRating( newValue, oldValue );
		},

		changeRating( newValue, oldValue, retryOnTokenMismatch = true ) {
			actionApi.getToken( 'csrf' ).then( ( token ) => {
				api.post( `/comments/v0/comment/${ this.$props.comment.id }/vote`, {
					rating: newValue,
					token: token
				} ).then( ( data ) => {
					this.$props.comment.rating = data.comment.rating;
				} ).always( () => {
					this.$data.waiting = false;
				} ).catch( ( _, result ) => {
					if ( retryOnTokenMismatch &&
						result &&
						result.xhr &&
						result.xhr.responseJSON &&
						result.xhr.responseJSON.errorKey === 'rest-badtoken'
					) {
						actionApi.badToken( 'csrf' );
						return this.changeRating( newValue, oldValue, false );
					}

					// Reset the UI state back to the previous value if the API call failed
					this.$props.comment.userRating = oldValue;

					const { text } = extractApiError( result );
					mw.notify( text || mw.message( 'unknown-error' ).text(),
						{ type: 'error', tag: 'vote-comment-error' } );
				} );
			} );
		}
	}
} );
</script>
