(function (root) {
	'use strict';

	const version = '0.1.0';
	if (root.DruidTracker) {
		if (root.DruidTracker.version === version) {
			return;
		}
		throw new Error('DruidTracker is already defined by another version.');
	}
	const instances = new Map();

	function failure(code, message) {
		return Object.assign(new Error(message), { code: code });
	}

	function create(options) {
		if (!options || typeof options.id !== 'string' || !options.id.trim()) {
			throw failure('invalid_configuration', 'A nonempty tracker ID is required.');
		}
		if (instances.has(options.id)) {
			return instances.get(options.id);
		}
		const { getContext, emit, onError } = options;
		if (typeof getContext !== 'function' || typeof emit !== 'function' || typeof onError !== 'function') {
			throw failure('invalid_configuration', 'Provide getContext, emit and onError functions.');
		}
		let running = false;
		let viewed = false;
		let away = false;

		async function track(type, context) {
			if (!running) {
				return null;
			}
			if (typeof type !== 'string' || !type.trim() || Object.prototype.toString.call(context) !== '[object Object]') {
				throw failure('invalid_event', 'Provide a nonempty event type and an object context.');
			}
			const event = { type: type, published: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'), context: context };
			try {
				return await emit(event);
			} catch (_) {
				throw failure('consumer_failed', 'The event consumer failed.');
			}
		}

		function report(error) {
			const failedHandler = function () {
				root.setTimeout(function () { throw failure('error_handler_failed', 'The tracker error handler failed.'); }, 0);
			};
			try {
				Promise.resolve(onError(error)).catch(failedHandler);
			} catch (_) {
				failedHandler();
			}
		}

		function capturePage() {
			if (!running || viewed || away || root.document.readyState === 'loading'
				|| root.document.visibilityState !== 'visible' || root.document.prerendering === true) {
				return;
			}
			viewed = true;
			let context;
			try {
				context = getContext();
			} catch (_) {
				report(failure('context_failed', 'The page context provider failed.'));
				return;
			}
			if (context !== null) {
				track('page_view', context).catch(report);
			}
		}

		function hide() {
			away = true;
		}

		function show(event) {
			if (away && event.persisted) {
				viewed = false;
			}
			away = false;
			capturePage();
		}

		const listeners = [
			[root.document, 'DOMContentLoaded', capturePage],
			[root.document, 'visibilitychange', capturePage],
			[root.document, 'prerenderingchange', capturePage],
			[root, 'pagehide', hide],
			[root, 'pageshow', show]
		];
		const tracker = Object.freeze({
			start: function () {
				if (!running) {
					running = true;
					away = false;
					listeners.forEach(([target, type, listener]) => target.addEventListener(type, listener));
					capturePage();
				}
				return tracker;
			},
			stop: function () {
				if (running) {
					running = false;
					listeners.forEach(([target, type, listener]) => target.removeEventListener(type, listener));
				}
				return tracker;
			},
			track: track
		});
		instances.set(options.id, tracker);
		return tracker;
	}

	root.DruidTracker = Object.freeze({ version: version, create: create });
}(window));
