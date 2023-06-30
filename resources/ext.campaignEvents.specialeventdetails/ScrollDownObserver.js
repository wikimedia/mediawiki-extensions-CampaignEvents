( function () {
	'use strict';

	/**
	 *
	 * @param {Element} observedElement A plain DOM node that should be observed when
	 *   scrolling on it.
	 * @param {Function} onScrolledToBottom Callback to execute when the bottom
	 *   of the observed element is reached.
	 * @constructor
	 */
	function ScrollDownObserver( observedElement, onScrolledToBottom ) {
		this.observedElement = observedElement;
		this.preloadFromBottom = 50;
		this.lastTop = 0;
		this.scrollingEnd = false;
		var that = this;
		$( observedElement ).on( 'scroll', function () {
			if ( !that.paused && that.scrolledToBottom() ) {
				onScrolledToBottom();
			}
		} );
	}

	ScrollDownObserver.prototype.scrolledToBottom = function () {
		if ( this.observedElement.scrollTop < this.lastTop ) {
			return false;
		}
		this.lastTop = this.observedElement.scrollTop;
		if (
			!this.scrollingEnd &&
			this.observedElement.scrollTop + this.observedElement.clientHeight >=
			this.observedElement.scrollHeight - this.preloadFromBottom
		) {
			this.scrollingEnd = true;
			this.lastTop = 0;
			return true;
		}
	};

	ScrollDownObserver.prototype.reset = function () {
		this.lastTop = 0;
		this.scrollingEnd = false;
	};

	ScrollDownObserver.prototype.pause = function () {
		this.paused = true;
	};

	ScrollDownObserver.prototype.unpause = function () {
		this.paused = false;
	};

	module.exports = ScrollDownObserver;
}() );
