(function () {
	'use strict';
	const form = document.getElementById('wp-druid-pulse-click-rules');
	if (!form) {
		return;
	}
	const body = form.querySelector('tbody');
	function refresh() {
		Array.from(body.rows).forEach((row, index) => {
			row.querySelectorAll('[data-field]').forEach(input => { input.name = 'rules[' + index + '][' + input.dataset.field + ']'; });
			const pages = row.querySelector('[data-field="pages"]');
			pages.hidden = row.querySelector('[data-field="scope"]').value === 'all';
			pages.required = !pages.hidden;
		});
	}
	form.querySelector('[data-add]').addEventListener('click', function () {
		body.appendChild(form.querySelector('template').content.cloneNode(true));
		refresh();
		body.lastElementChild.querySelector('[data-field="selector"]').focus();
	});
	form.addEventListener('click', function (event) {
		if (event.target.matches('[data-remove]')) {
			event.target.closest('tr').remove();
			refresh();
		}
	});
	form.addEventListener('change', refresh);
	form.addEventListener('input', function (event) { event.target.setCustomValidity(''); });
	form.addEventListener('submit', function (event) {
		for (const input of body.querySelectorAll('[data-field="selector"]')) {
			try {
				document.createElement('a').matches(input.value);
			} catch (_) {
				input.setCustomValidity(form.dataset.invalidSelector);
				input.reportValidity();
				event.preventDefault();
				return;
			}
		}
	});
	refresh();
	form.elements.complete.value = '1';
}());
