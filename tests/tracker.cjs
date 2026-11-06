const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(`${__dirname}/../assets/tracker.js`, 'utf8');
const transport = fs.readFileSync(`${__dirname}/../assets/frontend-transport.js`, 'utf8');
const adapter = fs.readFileSync(`${__dirname}/../assets/frontend-views.js`, 'utf8');
let checks = 0;
function check(condition, message) { assert.ok(condition, message); checks++; }
class Surface extends EventTarget {
	constructor() { super(); this.listeners = new Map(); }
	addEventListener(type, listener) { if (!this.listeners.has(type)) this.listeners.set(type, new Set()); this.listeners.get(type).add(listener); super.addEventListener(type, listener); }
	removeEventListener(type, listener) { this.listeners.get(type)?.delete(listener); super.removeEventListener(type, listener); }
	count() { return [...this.listeners.values()].reduce((sum, listeners) => sum + listeners.size, 0); }
	fire(type, properties = {}) { this.dispatchEvent(Object.assign(new Event(type), properties)); }
}
function browser(properties = {}) {
	const window = new Surface();
	window.document = Object.assign(new Surface(), { readyState: 'complete', visibilityState: 'visible', prerendering: false }, properties);
	window.setTimeout = setTimeout;
	window.location = new URL('https://consumer.example/page');
	const scope = vm.createContext({ window, URL, TextEncoder, CustomEvent });
	const run = code => vm.runInContext(code, scope);
	run(source);
	return { window, document: window.document, run, create: window.DruidTracker.create };
}
const settled = () => new Promise(resolve => setImmediate(resolve));
function consumer(b, overrides = {}) {
	const events = [], errors = [];
	const tracker = b.create({ id: 'test', getContext: () => ({ arbitrary: 'consumer context' }), emit: event => { events.push(event); return 'consumer-result'; }, onError: error => errors.push(error.code), ...overrides });
	return { tracker, events, errors };
}

(async () => {
	const b = browser({ readyState: 'loading', visibilityState: 'hidden' });
	const c = consumer(b);
	check(c.events.length === 0 && b.window.count() + b.document.count() === 0, 'Loading and creating a tracker must not activate it.');
	c.tracker.start().start();
	check(b.window.count() + b.document.count() === 5 && c.events.length === 0, 'Repeated start must not duplicate listeners or capture an unready/hidden page.');
	b.document.readyState = 'interactive'; b.document.fire('DOMContentLoaded');
	check(c.events.length === 0, 'A prepared background page is not yet a view.');
	b.document.visibilityState = 'visible'; b.document.fire('visibilitychange');
	b.window.fire('pageshow', { persisted: false }); b.document.fire('DOMContentLoaded');
	check(c.events.length === 1 && c.events[0].type === 'page_view' && c.events[0].context.arbitrary === 'consumer context'
		&& /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(c.events[0].published), 'Ready/visible/pageshow must produce one context-configurable event.');
	b.document.visibilityState = 'hidden'; b.document.fire('visibilitychange');
	b.document.visibilityState = 'visible'; b.document.fire('visibilitychange');
	check(c.events.length === 1, 'Returning to an already viewed tab must not count another view.');
	b.window.fire('pagehide', { persisted: true });
	b.document.fire('visibilitychange');
	check(c.events.length === 1, 'Visibility must not race ahead of history restoration.');
	b.window.fire('pageshow', { persisted: true }); b.window.fire('pageshow', { persisted: true });
	check(c.events.length === 2, 'A history return counts once, even with a repeated pageshow notification.');
	b.window.fire('pagehide', { persisted: true }); b.document.visibilityState = 'hidden';
	b.window.fire('pageshow', { persisted: true });
	check(c.events.length === 2, 'A restored background page must wait until visible.');
	b.document.visibilityState = 'visible'; b.document.fire('visibilitychange');
	check(c.events.length === 3, 'Restored hidden view must count on first visibility.');
	c.tracker.stop().stop();
	check(b.window.count() + b.document.count() === 0 && await c.tracker.track('extra', {}) === null, 'Stopped trackers must remove listeners and stop manual emission.');
	c.tracker.start();
	check(c.events.length === 3 && b.window.count() + b.document.count() === 5, 'Restart must preserve the current view guard.');
	const result = await c.tracker.track('custom_event', { source: 'independent integration' });
	check(result === 'consumer-result' && c.events.at(-1).type === 'custom_event', 'Additional types must use the same consumer and preserve its result.');
	await c.tracker.track('custom_event', { source: 'independent integration' });
	check(c.events.length === 5, 'Distinct explicit events must not be deduplicated by type, URL or time.');
	check(b.create({ id: 'test' }) === c.tracker, 'Creating the same named tracker must reuse its configuration and state.');
	b.run(source);
	check(b.window.DruidTracker.create({ id: 'test' }) === c.tracker, 'Loading the SDK twice must preserve the registry.');
	const another = consumer(b, { id: 'second-consumer' }); another.tracker.start();
	check(another.events.length === 1 && c.events.length === 5, 'Independent consumers must have independent state.');
	const reload = consumer(browser()); reload.tracker.start();
	check(reload.events.length === 1, 'A new document counts a legitimate new view.');
	let capture;
	const informed = consumer(browser(), { getContext: information => { capture = information; return { provided: true }; } }); informed.tracker.start();
	check(capture.type === 'page_view' && capture.element === null && informed.events[0].context.provided === true,
		'The context provider must receive capture information without changing the emitted context.');
	for (const [pages, expected] of [[true, 1], [false, 0], [{}, 1], [{ include: ['/page'] }, 1],
		[{ include: ['/other'] }, 0], [{ include: [] }, 0], [{ exclude: ['/other'] }, 1], [{ exclude: ['/page'] }, 0],
		[{ include: ['/page'], exclude: ['/page'] }, 0], [{ include: ['/page', '/page'], exclude: [] }, 1]]) {
		const filtered = consumer(browser(), { pages }); filtered.tracker.start();
		check(filtered.events.length === expected, 'Page inclusion/exclusion must respect explicit empty lists and exclusion priority.');
	}
	const query = browser(); query.window.location = new URL('https://consumer.example/page?campaign=x#section');
	const queryView = consumer(query, { pages: { include: ['/page'] } }); queryView.tracker.start();
	check(queryView.events.length === 1 && queryView.events[0].context.arbitrary === 'consumer context', 'Page selection must ignore query/fragment without changing consumer context.');
	for (const path of ['/Page', '/page/', '/page/child']) {
		const exact = browser(); exact.window.location = new URL('https://consumer.example' + path);
		const excluded = consumer(exact, { pages: { include: ['/page'] } }); excluded.tracker.start();
		check(excluded.events.length === 0, 'Page paths must not acquire case folding, slash normalization or prefix semantics.');
	}
	const noUrl = browser(); Object.defineProperty(noUrl.window, 'location', { get() { throw new Error('Implicit URL access'); } });
	const defaultView = consumer(noUrl); defaultView.tracker.start();
	check(defaultView.events.length === 1, 'Existing integrations without page rules must not require URL access.');
	let contextCalls = 0;
	const manualOnly = consumer(browser(), { pages: false, getContext: () => { contextCalls++; return {}; } }); manualOnly.tracker.start();
	await manualOnly.tracker.track('custom_event', { source: 'manual' });
	check(contextCalls === 0 && manualOnly.events.length === 1 && manualOnly.events[0].type === 'custom_event', 'Disabling views must not call their context provider or suppress explicit events.');
	const options = { include: ['/page'] }; const configured = browser(); const snapshot = consumer(configured, { pages: options });
	options.include.length = 0; snapshot.tracker.start();
	check(snapshot.events.length === 1, 'Changing the original configuration array must not mutate an existing tracker.');
	configured.window.fire('pagehide', { persisted: true }); configured.window.fire('pageshow', { persisted: true });
	check(snapshot.events.length === 2, 'Page rules must preserve legitimate history views.');
	for (const pages of [null, [], 'all', { includes: ['/page'] }, { include: '/page' }, { exclude: null },
		{ include: [42] }, { include: Array(1) }, { include: ['/page\0'] }, { include: ['page'] }, { include: ['/page?query=x'] }, { include: ['/page#part'] }, { include: ['/some path'] }]) {
		const invalid = browser();
		assert.throws(() => consumer(invalid, { pages }), error => error.code === 'invalid_configuration'); checks++;
		check(invalid.window.count() + invalid.document.count() === 0, 'Invalid page rules must fail before activating listeners.');
	}

	const prerender = browser({ prerendering: true }); const p = consumer(prerender); p.tracker.start();
	check(p.events.length === 0, 'Prerendering must not count a view.');
	prerender.document.prerendering = false; prerender.document.fire('prerenderingchange');
	check(p.events.length === 1, 'Activation of a prepared visible document must count once.');
	const skipped = consumer(browser(), { getContext: () => null }); skipped.tracker.start();
	check(skipped.events.length === 0, 'A null context must skip automatic tracking.');
	const beforeReady = browser({ readyState: 'loading' }); const stopped = consumer(beforeReady); stopped.tracker.start().stop();
	beforeReady.document.readyState = 'complete'; beforeReady.document.fire('DOMContentLoaded');
	check(stopped.events.length === 0, 'A stopped tracker must ignore late document events.');
	stopped.tracker.start();
	check(stopped.events.length === 1, 'Starting after preparation must capture without relying on a missed DOMContentLoaded event.');
	const missed = browser({ visibilityState: 'hidden' }); const neverViewed = consumer(missed); neverViewed.tracker.start();
	missed.window.fire('pagehide', { persisted: true }); neverViewed.tracker.stop();
	missed.document.visibilityState = 'visible'; missed.window.fire('pageshow', { persisted: true }); neverViewed.tracker.start();
	check(neverViewed.events.length === 1, 'Restarting on a visible document must not remain stuck after a missed pageshow.');
	const hiddenRestart = browser({ visibilityState: 'hidden' }); const resumed = consumer(hiddenRestart); resumed.tracker.start();
	hiddenRestart.window.fire('pagehide', { persisted: true }); resumed.tracker.stop();
	hiddenRestart.window.fire('pageshow', { persisted: true }); resumed.tracker.start();
	hiddenRestart.document.visibilityState = 'visible'; hiddenRestart.document.fire('visibilitychange');
	check(resumed.events.length === 1, 'Restarting in the background must not retain an obsolete pagehide state.');

	for (const options of [{}, { id: '' }, { id: 'missing-callbacks' }, { id: 'missing-errors', emit() {}, getContext() {} }]) {
		assert.throws(() => browser().create(options), error => error.code === 'invalid_configuration'); checks++;
	}
	for (const context of [undefined, [], 'text', Promise.resolve({})]) {
		await assert.rejects(c.tracker.track('custom_event', context), error => error.code === 'invalid_event'); checks++;
	}
	const brokenContext = consumer(browser(), { getContext: () => { throw new Error('private context'); } }); brokenContext.tracker.start();
	check(brokenContext.errors[0] === 'context_failed' && brokenContext.events.length === 0, 'Context failures must be observable without emitting an event.');
	for (const emit of [() => { throw new Error('private consumer'); }, () => Promise.reject(new Error('private consumer'))]) {
		const broken = consumer(browser(), { emit }); broken.tracker.start(); await settled();
		check(broken.errors[0] === 'consumer_failed', 'Synchronous and asynchronous consumer failures must be reported without retry.');
		await assert.rejects(broken.tracker.track('custom', {}), error => error.code === 'consumer_failed' && !error.message.includes('private')); checks++;
	}
	const invalidContext = consumer(browser(), { getContext: () => [] }); invalidContext.tracker.start(); await settled();
	check(invalidContext.errors[0] === 'invalid_event', 'Invalid automatic context must be reported.');
	for (const onError of [() => { throw new Error('private handler'); }, () => Promise.reject(new Error('private handler'))]) {
		const broken = browser(); const callbacks = []; broken.window.setTimeout = fn => callbacks.push(fn);
		consumer(broken, { getContext: () => undefined, onError }).tracker.start(); await settled();
		check(callbacks.length === 1, 'A failing error handler must be observable.');
		assert.throws(callbacks[0], error => error.code === 'error_handler_failed' && !error.message.includes('private')); checks++;
	}
	let finish;
	const pending = consumer(browser(), { emit: () => new Promise(resolve => { finish = resolve; }) });
	pending.tracker.start();
	const inFlight = pending.tracker.track('pending', {}); pending.tracker.stop(); finish('completed');
	check(await inFlight === 'completed', 'Stopping must not cancel a consumer already invoked.');

	const wp = browser(); const requests = [], outcomes = [];
	wp.window.wpDruidPulseSettings = { endpoint: '/events', nonce: 'synthetic-nonce', maxBytes: 16384 };
	wp.window.wpDruidPulseView = { page: { key: 'post:123' } };
	wp.window.fetch = async (...args) => { requests.push(args); return { ok: true, status: 202, json: async () => ({ accepted: true, event_id: '8c774d71-188b-4f31-8b1e-bf59237e20d8' }), headers: { get: () => null } }; };
	wp.window.addEventListener('wp-druid-pulse:tracking-result', event => outcomes.push(event.detail));
	wp.run(transport); wp.run(adapter); await settled();
	check(requests.length === 1 && JSON.parse(requests[0][1].body).context.page.key === 'post:123'
		&& outcomes[0].result.status === 'accepted', 'The WordPress adapter must connect generic capture to the existing authenticated transport.');
	const original = wp.window.wpDruidPulse.tracker;
	wp.run(source); wp.run(transport); wp.run(adapter); await settled();
	check(requests.length === 1 && original === wp.window.wpDruidPulse.tracker, 'Loading the entire integration twice must not duplicate capture or replace state.');
	await original.track('custom_type', { custom: 'value' });
	check(outcomes.at(-1).type === 'custom_type' && outcomes.at(-1).result.status === 'accepted', 'The adapter must preserve additional event types in its result notifications.');
	wp.window.fetch = async () => ({ ok: false, status: 403, json: async () => ({ code: 'rest_cookie_invalid_nonce' }) });
	wp.window.fire('pagehide', { persisted: true }); wp.window.fire('pageshow', { persisted: true }); await settled();
	wp.run(transport);
	check((await wp.window.wpDruidPulse.send({})).code === 'session_unavailable' && outcomes.at(-1).result.code === 'rest_cookie_invalid_nonce', 'Script reload must not bypass session pause; capture results remain observable.');
	const absent = browser(); absent.run(transport); absent.run(adapter);
	check(!absent.window.wpDruidPulse.tracker, 'Without a selected page context the adapter must not activate capture.');
	console.log(`PASS: ${checks} SDK and adapter assertions.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
