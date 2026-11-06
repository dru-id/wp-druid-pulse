(function () {
	'use strict';

	const integration = window.wpDruidPulse;
	const configuration = window.wpDruidPulseTracking || { view: window.wpDruidPulseView, clicks: false };
	const context = configuration.view;
	if (!integration || (!context && !configuration.clicks) || integration.tracker) {
		return;
	}
	function report(type, result) {
		window.dispatchEvent(new CustomEvent('wp-druid-pulse:tracking-result', { detail: { type: type, result: result } }));
	}
	function getContext(information) {
		if (information.type === 'page_view') {
			return context;
		}
		const element = information.element;
		const label = (element.getAttribute('aria-label') || element.textContent || element.getAttribute('title') || '').replace(/\s+/g, ' ').trim();
		const result = { page: { ...configuration.page }, link: { title: label } };
		if (element.matches('a[href]')) {
			try {
				const destination = new URL(element.getAttribute('href'), document.baseURI);
				if (['http:', 'https:', 'tel:', 'mailto:'].includes(destination.protocol) && !destination.username && !destination.password) {
					result.target = { objectType: ['http:', 'https:'].includes(destination.protocol) ? 'page' : 'link', url: destination.href };
				}
			} catch (_) {
				// A link without a resolvable destination still represents an activation.
			}
		}
		return result;
	}
	try {
		integration.tracker = window.DruidTracker.create({
			id: 'wp-druid-pulse',
			pages: Boolean(context),
			clicks: configuration.clicks,
			getContext: getContext,
			emit: async function (event) {
				const result = await integration.send(event);
				report(event.type, result);
				return result;
			},
			onError: function (error, information) {
				report(information.type, { status: error.code === 'consumer_failed' ? 'unknown' : 'rejected', code: 'sdk_' + error.code });
			}
		});
		integration.tracker.start();
	} catch (_) {
		report(context ? 'page_view' : 'click', { status: 'rejected', code: 'tracker_initialization_failed' });
	}
}());
