(function () {
	'use strict';

	const integration = window.wpDruidPulse;
	const context = window.wpDruidPulseView;
	if (!integration || !context || integration.tracker) {
		return;
	}
	function report(type, result) {
		window.dispatchEvent(new CustomEvent('wp-druid-pulse:tracking-result', { detail: { type: type, result: result } }));
	}
	try {
		integration.tracker = window.DruidTracker.create({
			id: 'wp-druid-pulse',
			getContext: function () { return context; },
			emit: async function (event) {
				const result = await integration.send(event);
				report(event.type, result);
				return result;
			},
			onError: function (error) {
				report('page_view', { status: error.code === 'consumer_failed' ? 'unknown' : 'rejected', code: 'sdk_' + error.code });
			}
		});
		integration.tracker.start();
	} catch (_) {
		report('page_view', { status: 'rejected', code: 'tracker_initialization_failed' });
	}
}());
