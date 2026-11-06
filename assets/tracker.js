(function (root) {
	'use strict';

	const version = '0.2.0';
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

	function paths(value) {
		const valid = Array.isArray(value) && Array.from(value).every(path => typeof path === 'string' && path.startsWith('/') && !/[?#\s\x00-\x1f\x7f]/.test(path));
		if (!valid) {
			throw failure('invalid_configuration', 'Page paths must be an array of absolute pathnames without query strings or fragments.');
		}
		return new Set(value);
	}

	function pageFilter(value) {
		if (value === undefined || typeof value === 'boolean') {
			return function () { return value !== false; };
		}
		if (Object.prototype.toString.call(value) !== '[object Object]' || Object.keys(value).some(key => !['include', 'exclude'].includes(key))) {
			throw failure('invalid_configuration', 'Pages must be a boolean or an object with include and exclude paths.');
		}
		const include = value.include === undefined ? null : paths(value.include);
		const exclude = value.exclude === undefined ? new Set() : paths(value.exclude);
		return function () {
			const path = root.location.pathname;
			return (include === null || include.has(path)) && !exclude.has(path);
		};
	}

	function clickFilter(value) {
		if (value === undefined || value === false) {
			return null;
		}
		if (Object.prototype.toString.call(value) !== '[object Object]' || Object.keys(value).some(key => !['include', 'exclude'].includes(key))) {
			throw failure('invalid_configuration', 'Clicks must contain include and exclude rules.');
		}
		function rules(list = []) {
			if (!Array.isArray(list)) {
				throw failure('invalid_configuration', 'Click rules must be an array.');
			}
			return Array.from(list, rule => {
				if (Object.prototype.toString.call(rule) !== '[object Object]' || Object.keys(rule).some(key => !['pages', 'selector', 'classPrefix'].includes(key))
					|| typeof rule.selector !== 'string' || !rule.selector.trim()
					|| (rule.classPrefix !== undefined && (typeof rule.classPrefix !== 'string' || !rule.classPrefix || /\s/.test(rule.classPrefix)))) {
					throw failure('invalid_configuration', 'Each click rule needs a CSS selector and an optional class prefix and pages.');
				}
				const selector = rule.selector;
				try {
					root.document.createElement('a').matches(selector);
				} catch (_) {
					throw failure('invalid_configuration', 'A click selector is invalid.');
				}
				const pagePaths = rule.pages === undefined ? null : paths(rule.pages);
				const prefix = rule.classPrefix;
				return element => (!pagePaths || pagePaths.has(root.location.pathname)) && element.matches(selector)
					&& (prefix === undefined || Array.from(element.classList).some(name => name.startsWith(prefix)));
			});
		}
		const include = rules(value.include);
		const exclude = rules(value.exclude);
		return include.length ? element => include.some(matches => matches(element)) && !exclude.some(matches => matches(element)) : null;
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
		const acceptsPage = pageFilter(options.pages);
		const acceptsClick = clickFilter(options.clicks);
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

		function report(error, type) {
			const failedHandler = function () {
				root.setTimeout(function () { throw failure('error_handler_failed', 'The tracker error handler failed.'); }, 0);
			};
			try {
				Promise.resolve(onError(error, { type: type })).catch(failedHandler);
			} catch (_) {
				failedHandler();
			}
		}

		function capture(type, element) {
			let context;
			try {
				context = getContext({ type: type, element: element });
			} catch (_) {
				report(failure('context_failed', 'The event context provider failed.'), type);
				return;
			}
			if (context !== null) {
				track(type, context).catch(error => report(error, type));
			}
		}

		function capturePage() {
			if (!running || viewed || away || root.document.readyState === 'loading'
				|| root.document.visibilityState !== 'visible' || root.document.prerendering === true) {
				return;
			}
			viewed = true;
			if (!acceptsPage()) {
				return;
			}
			capture('page_view', null);
		}

		function captureClick(event) {
			if (!running || away || !event.isTrusted || (event.type === 'click' ? event.button !== 0 : event.button !== 1)) {
				return;
			}
			const target = event.target;
			const element = target && typeof target.closest === 'function' ? target.closest('a[href], button') : null;
			if (!element || element.disabled || (event.type === 'auxclick' && !element.matches('a[href]')) || !acceptsClick(element)) {
				return;
			}
			capture('click', element);
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
		if (acceptsClick) {
			listeners.push([root.document, 'click', captureClick, true], [root.document, 'auxclick', captureClick, true]);
		}
		const tracker = Object.freeze({
			start: function () {
				if (!running) {
					running = true;
					away = false;
					listeners.forEach(([target, type, listener, capture]) => target.addEventListener(type, listener, capture));
					capturePage();
				}
				return tracker;
			},
			stop: function () {
				if (running) {
					running = false;
					listeners.forEach(([target, type, listener, capture]) => target.removeEventListener(type, listener, capture));
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
