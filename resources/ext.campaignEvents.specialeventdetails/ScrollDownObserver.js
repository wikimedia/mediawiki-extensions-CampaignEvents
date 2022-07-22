( function () {
	'use strict';

	/**
	 *
	 * @param {Element} observedElement a plain DOM node that should be observed when scrolling on it
	 * @constructor
	 */
	function ScrollDownObserver( observedElement ) {
		this.observedElement = observedElement;
		this.preloadFromBottom = 50;
		this.lastTop = 0;
		this.scrollingEnd = false;
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

	module.exports = ScrollDownObserver;
}() );
