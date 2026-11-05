(function () {
	'use strict';

	if (window.wpDruidPulse && typeof window.wpDruidPulse.send === 'function') {
		return;
	}
	const settings = window.wpDruidPulseSettings || {};
	let nonce = settings.nonce;
	let paused = false;
	let endpoint;
	try {
		endpoint = typeof settings.endpoint === 'string' && settings.endpoint ? new URL(settings.endpoint, window.location.href) : null;
	} catch (_) {
		endpoint = null;
	}

	async function send(event) {
		if (paused) {
			return { status: 'rejected', code: 'session_unavailable' };
		}
		if (!endpoint || endpoint.origin !== window.location.origin || typeof nonce !== 'string' || !nonce
			|| !Number.isInteger(settings.maxBytes) || settings.maxBytes < 1) {
			return { status: 'rejected', code: 'invalid_settings' };
		}
		if (typeof window.fetch !== 'function' || typeof TextEncoder !== 'function') {
			return { status: 'rejected', code: 'unsupported_browser' };
		}
		let body;
		try {
			body = JSON.stringify(event);
		} catch (_) {
			return { status: 'rejected', code: 'invalid_event' };
		}
		if (!body) {
			return { status: 'rejected', code: 'invalid_event' };
		}
		if (new TextEncoder().encode(body).length > settings.maxBytes) {
			return { status: 'rejected', code: 'request_size' };
		}
		try {
			const response = await window.fetch(endpoint.href, {
				method: 'POST',
				mode: 'same-origin',
				credentials: 'same-origin',
				redirect: 'error',
				keepalive: true,
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body: body
			});
			if (response.status === 401) {
				paused = true;
			}
			const data = await response.json();
			if (data && ['rest_cookie_invalid_nonce', 'wp_druid_pulse_session_required', 'wp_druid_pulse_session_invalid'].includes(data.code)) {
				paused = true;
			}
			if (!response.ok && data && typeof data.code === 'string') {
				return { status: 'rejected', code: data.code, httpStatus: response.status };
			}
			if (response.status !== 202 || !data || data.accepted !== true || typeof data.event_id !== 'string'
				|| !/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(data.event_id)) {
				return { status: 'unknown', code: 'unexpected_response', httpStatus: response.status };
			}
			const refreshed = response.headers.get('X-WP-Nonce');
			if (!paused && refreshed) {
				nonce = refreshed;
			}
			return { status: 'accepted', eventId: data.event_id };
		} catch (_) {
			return { status: 'unknown', code: 'network_or_response_error' };
		}
	}

	window.wpDruidPulse = { send: send };
}());
