(() => {
	'use strict';
	const form = document.getElementById('wp-druid-pulse-selection');
	if (!form) return;

	const checkboxes = Array.from(form.querySelectorAll('input[type="checkbox"]'));
	let submitting = false;

	form.addEventListener('submit', () => { submitting = true; });
	window.addEventListener('pageshow', () => { submitting = false; });
	window.addEventListener('beforeunload', (event) => {
		if (!submitting && checkboxes.some((input) => input.checked !== input.defaultChecked)) {
			event.preventDefault();
			event.returnValue = '';
		}
	});
})();
