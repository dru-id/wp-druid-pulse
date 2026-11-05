const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(`${__dirname}/../assets/frontend-transport.js`, 'utf8');
const id = '8c774d71-188b-4f31-8b1e-bf59237e20d8';
const event = { type: 'fixture_activity', published: '2026-11-05T12:30:00Z', context: { label: 'Synthetic' } };
let checks = 0;
function check(condition, message) {
	assert.ok(condition, message);
	checks++;
}
function response(status = 202, data = { accepted: true, event_id: id }, refreshed = null) {
	return { status, ok: status >= 200 && status < 300, json: async () => data, headers: { get: () => refreshed } };
}
function browser(overrides = {}, fetch = async () => response()) {
	const requests = [];
	const window = {
		location: new URL('https://wordpress.example/page'),
		wpDruidPulseSettings: { endpoint: 'https://wordpress.example/wp-json/wp-druid-pulse/v1/events', nonce: 'session-nonce', maxBytes: 16384, ...overrides },
		fetch: async (...args) => { requests.push(args); return fetch(...args); }
	};
	vm.runInNewContext(source, { window, URL, TextEncoder });
	return { window, send: window.wpDruidPulse.send, requests };
}

(async () => {
	const normal = browser({}, async () => response(202, undefined, 'refreshed-nonce'));
	check(normal.requests.length === 0, 'Loading the transport must not capture an event.');
	const accepted = await normal.send(event);
	check(accepted.status === 'accepted' && accepted.eventId === id, 'Persisted acknowledgement must be accepted.');
	const [url, options] = normal.requests[0];
	check(url === normal.window.wpDruidPulseSettings.endpoint && options.method === 'POST' && options.mode === 'same-origin'
		&& options.credentials === 'same-origin' && options.redirect === 'error' && options.keepalive === true, 'Use a same-origin request that can survive navigation.');
	check(options.body === JSON.stringify(event) && options.headers['X-WP-Nonce'] === 'session-nonce'
		&& options.headers['Content-Type'] === 'application/json', 'Preserve the event and supply the session nonce.');
	await normal.send(event);
	check(normal.requests[1][1].headers['X-WP-Nonce'] === 'refreshed-nonce', 'Reuse the valid response nonce.');

	for (const invalidResponse of [response(200), response(202, { accepted: true }), response(202, { accepted: true, event_id: 'invalid' }), response(202, null), response(500, {})]) {
		const instance = browser({}, async () => invalidResponse);
		check((await instance.send(event)).status === 'unknown' && instance.requests.length === 1, 'Ambiguous acknowledgement must not retry or claim acceptance.');
	}
	for (const fail of [async () => { throw new Error('offline'); }, async () => ({ ...response(), json: async () => { throw new SyntaxError('truncated'); } })]) {
		const instance = browser({}, fail);
		check((await instance.send(event)).code === 'network_or_response_error' && instance.requests.length === 1, 'Transport failure must be unknown, with no retry.');
	}
	const invalid = browser({}, async () => response(400, { code: 'fixture_context_invalid', message: 'private detail' }, 'untrusted-nonce'));
	const rejected = await invalid.send(event);
	await invalid.send(event);
	check(rejected.status === 'rejected' && rejected.httpStatus === 400 && rejected.code === 'fixture_context_invalid'
		&& !('message' in rejected) && invalid.requests.length === 2 && invalid.requests[1][1].headers['X-WP-Nonce'] === 'session-nonce', 'Invalid context must not pause the session or leak messages.');

	for (const [status, code] of [[401, 'wp_druid_pulse_session_required'], [403, 'rest_cookie_invalid_nonce'], [403, 'wp_druid_pulse_session_invalid']]) {
		const instance = browser({}, async () => response(status, { code }));
		check((await instance.send(event)).status === 'rejected' && (await instance.send(event)).code === 'session_unavailable'
			&& instance.requests.length === 1, 'Expired or changed sessions must stop sending until reload.');
	}
	const unreadableAuth = browser({}, async () => ({ ...response(401), json: async () => { throw new Error(); } }));
	await unreadableAuth.send(event);
	check((await unreadableAuth.send(event)).code === 'session_unavailable' && unreadableAuth.requests.length === 1, 'Unreadable 401 must still stop transport.');
	let resolvePending;
	const concurrent = browser({}, async () => concurrent.requests.length === 1
		? new Promise(resolve => { resolvePending = resolve; }) : response(401, { code: 'wp_druid_pulse_session_required' }));
	const pending = concurrent.send(event);
	await concurrent.send(event);
	resolvePending(response(202, undefined, 'late-nonce'));
	await pending;
	check((await concurrent.send(event)).code === 'session_unavailable' && concurrent.requests.length === 2, 'A late response must not resume a rejected session.');
	check((await browser().send(event)).status === 'accepted', 'A fresh page starts with its own session.');

	const bytes = new TextEncoder().encode(JSON.stringify(event)).length;
	check((await browser({ maxBytes: bytes }).send(event)).status === 'accepted', 'The exact byte limit is accepted.');
	const multibyte = { ...event, context: { label: 'á'.repeat(40) } };
	const limited = browser({ maxBytes: JSON.stringify(multibyte).length });
	check((await limited.send(multibyte)).code === 'request_size' && limited.requests.length === 0, 'Count UTF-8 bytes, not UTF-16 characters.');
	const circular = {}; circular.self = circular;
	for (const input of [undefined, circular, { value: 1n }]) {
		const instance = browser();
		check((await instance.send(input)).code === 'invalid_event' && instance.requests.length === 0, 'Unserializable input must not be sent.');
	}
	for (const settings of [{ endpoint: undefined }, { endpoint: '' }, { endpoint: 'https://other.example/events' }, { nonce: '' }, { maxBytes: 0 }]) {
		const instance = browser(settings);
		check((await instance.send(event)).code === 'invalid_settings' && instance.requests.length === 0, 'Missing or cross-origin settings must not send requests.');
	}
	const unsupported = browser(); delete unsupported.window.fetch;
	check((await unsupported.send(event)).code === 'unsupported_browser', 'Unsupported browsers must fail explicitly without fallback.');
	console.log(`PASS: ${checks} transport assertions.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
