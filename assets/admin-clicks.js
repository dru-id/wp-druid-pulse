(function () {
	'use strict';
	const form = document.getElementById('wp-druid-pulse-click-rules');
	if (!form) return;
	const body = form.querySelector('tbody');
	const dialog = document.getElementById('pulse-page-picker');
	const labels = JSON.parse(document.getElementById('pulse-click-page-labels').textContent);
	const pageSize = 20;
	let page = 1, submitting = false, pickerRow = null, selected = new Set(), searchPage = 1, searchTerm = '', searchRequest = 0, controller;
	const rows = () => Array.from(body.rows);
	const field = (row, key) => row.querySelector('[data-field="' + key + '"]');
	const keys = row => field(row, 'pages').value.split(',').map(key => key.trim()).filter(Boolean);
	const format = (message, current, pages, total) => message.replace('%1$s', current).replace('%2$s', pages).replace('%3$s', total);
	const serialize = () => rows().map(row => Object.fromEntries(Array.from(row.querySelectorAll('[data-field]'), input => [input.dataset.field, input.value])));
	function renderLabels(list, values, removable) {
		list.replaceChildren();
		values.forEach(key => {
			const item = document.createElement('li');
			const label = labels[key] || key;
			item.textContent = label;
			if (removable) {
				const remove = document.createElement('button');
				remove.type = 'button';
				remove.className = 'button-link-delete';
				remove.textContent = '×';
				remove.setAttribute('aria-label', form.dataset.removeLabel.replace('%s', label));
				remove.addEventListener('click', () => {
					selected.delete(key);
					renderChosen();
					dialog.querySelectorAll('[data-page-results] input').forEach(input => { input.checked = selected.has(input.value); });
				});
				item.append(remove);
			}
			list.append(item);
		});
	}
	function renderChosen() {
		renderLabels(dialog.querySelector('[data-chosen-pages]'), Array.from(selected), true);
	}
	function refreshRow(row) {
		row.querySelector('[data-page-selection]').hidden = field(row, 'scope').value === 'all';
		row.querySelector('[data-custom-selector]').hidden = row.querySelector('[data-preset]').value !== 'custom';
		renderLabels(row.querySelector('[data-selected-pages]'), keys(row), false);
	}
	function paginate() {
		const all = rows();
		const pages = Math.max(1, Math.ceil(all.length / pageSize));
		page = Math.max(1, Math.min(pages, page));
		all.forEach((row, index) => { row.hidden = index < (page - 1) * pageSize || index >= page * pageSize; });
		form.querySelector('[data-empty]').hidden = all.length !== 0;
		form.querySelector('[data-previous]').disabled = page === 1;
		form.querySelector('[data-next]').disabled = page === pages;
		form.querySelector('[data-rule-page]').textContent = format(form.dataset.pageLabel, page, pages, all.length);
	}
	function invalid(row, input, message) {
		page = Math.floor(rows().indexOf(row) / pageSize) + 1;
		paginate();
		const notice = form.querySelector('[data-editor-error]');
		notice.querySelector('p').textContent = message;
		notice.hidden = false;
		if (input.tagName === 'INPUT') {
			input.closest('details')?.setAttribute('open', '');
			input.setCustomValidity(message);
			input.reportValidity();
		}
		input.focus();
	}
	async function search(targetPage) {
		controller?.abort();
		controller = new AbortController();
		const request = ++searchRequest;
		const status = dialog.querySelector('[data-search-status]');
		const results = dialog.querySelector('[data-page-results]');
		results.replaceChildren();
		status.textContent = dialog.dataset.loading;
		dialog.querySelector('[data-search-previous]').disabled = true;
		dialog.querySelector('[data-search-next]').disabled = true;
		const url = new URL(dialog.dataset.endpoint);
		Object.entries({ action: dialog.dataset.action, nonce: dialog.dataset.nonce, search: searchTerm, paged: targetPage }).forEach(([key, value]) => url.searchParams.set(key, value));
		try {
			const response = await fetch(url, { credentials: 'same-origin', signal: controller.signal });
			const result = await response.json();
			if (!response.ok || !result.success || !Array.isArray(result.data.rows)) throw new Error('search_failed');
			if (request !== searchRequest || !dialog.open) return;
			searchPage = result.data.page;
			status.textContent = result.data.total ? format(dialog.dataset.status, searchPage, result.data.pages, result.data.total) : dialog.dataset.empty;
			result.data.rows.forEach(item => {
				labels[item.key] = item.title;
				const li = document.createElement('li');
				const label = document.createElement('label');
				const checkbox = document.createElement('input');
				checkbox.type = 'checkbox'; checkbox.value = item.key; checkbox.checked = selected.has(item.key);
				checkbox.addEventListener('change', () => {
					if (checkbox.checked) selected.add(item.key); else selected.delete(item.key);
					renderChosen();
				});
				const name = document.createElement('span'); name.textContent = item.title;
				const description = document.createElement('small'); description.textContent = item.type + ' · ' + item.url;
				name.append(description); label.append(checkbox, name); li.append(label); results.append(li);
			});
			renderChosen();
			dialog.querySelector('[data-search-previous]').disabled = searchPage <= 1;
			dialog.querySelector('[data-search-next]').disabled = searchPage >= result.data.pages;
		} catch (error) {
			if (request === searchRequest && error.name !== 'AbortError') status.textContent = dialog.dataset.failed;
		}
	}
	form.querySelector('[data-add]').addEventListener('click', () => {
		body.appendChild(form.querySelector('template').content.cloneNode(true));
		refreshRow(body.lastElementChild);
		page = Math.ceil(rows().length / pageSize); paginate();
		body.lastElementChild.querySelector('[data-choose-pages]').focus();
	});
	form.querySelector('[data-previous]').addEventListener('click', () => { page--; paginate(); });
	form.querySelector('[data-next]').addEventListener('click', () => { page++; paginate(); });
	form.addEventListener('click', event => {
		if (event.target.matches('[data-remove]')) { event.target.closest('tr').remove(); paginate(); }
		if (event.target.matches('[data-choose-pages]')) {
			pickerRow = event.target.closest('tr');
			selected = new Set(keys(pickerRow));
			searchTerm = ''; dialog.querySelector('input[type="search"]').value = '';
			renderChosen(); dialog.showModal(); search(1);
			dialog.querySelector('input[type="search"]').focus();
		}
	});
	form.addEventListener('change', event => {
		const row = event.target.closest('tr');
		if (!row) return;
		if (event.target.matches('[data-preset]') && event.target.value !== 'custom') {
			field(row, 'selector').value = event.target.value;
			field(row, 'selector').setCustomValidity('');
		}
		refreshRow(row);
		form.querySelector('[data-editor-error]').hidden = true;
	});
	form.addEventListener('input', event => {
		if (typeof event.target.setCustomValidity === 'function') event.target.setCustomValidity('');
		form.querySelector('[data-editor-error]').hidden = true;
	});
	dialog.querySelector('[data-search]').addEventListener('click', () => { searchTerm = dialog.querySelector('input[type="search"]').value; search(1); });
	dialog.querySelector('input[type="search"]').addEventListener('keydown', event => {
		if (event.key === 'Enter') { event.preventDefault(); dialog.querySelector('[data-search]').click(); }
	});
	dialog.querySelector('[data-search-previous]').addEventListener('click', () => search(searchPage - 1));
	dialog.querySelector('[data-search-next]').addEventListener('click', () => search(searchPage + 1));
	dialog.querySelector('[data-apply-pages]').addEventListener('click', () => {
		field(pickerRow, 'pages').value = Array.from(selected).join(',');
		refreshRow(pickerRow);
		form.querySelector('[data-editor-error]').hidden = true;
		dialog.close();
	});
	dialog.querySelector('[data-cancel-pages]').addEventListener('click', () => dialog.close());
	dialog.addEventListener('close', () => { ++searchRequest; controller?.abort(); pickerRow?.querySelector('[data-choose-pages]').focus(); });
	form.addEventListener('submit', event => {
		for (const row of rows()) {
			const selector = field(row, 'selector');
			try {
				if (!selector.value.trim()) throw new Error('empty_selector');
				document.createElement('a').matches(selector.value);
			} catch (_) {
				event.preventDefault(); invalid(row, selector, form.dataset.invalidSelector); return;
			}
			const prefix = field(row, 'prefix');
			if (prefix.value.trim() && /[\s\x00-\x1f\x7f]/.test(prefix.value.trim())) {
				event.preventDefault(); invalid(row, prefix, form.dataset.invalidPrefix); return;
			}
			if (field(row, 'scope').value === 'pages' && !keys(row).length) {
				event.preventDefault(); invalid(row, row.querySelector('[data-choose-pages]'), form.dataset.missingPages); return;
			}
		}
		// A single field avoids PHP's max_input_vars truncating rules on other editor pages.
		form.elements.rules_json.value = JSON.stringify(serialize());
		form.elements.complete.value = '1';
		submitting = true;
	});
	rows().forEach(refreshRow); paginate();
	const initial = JSON.stringify(serialize());
	window.addEventListener('pageshow', () => { submitting = false; });
	window.addEventListener('beforeunload', event => {
		if (!submitting && initial !== JSON.stringify(serialize())) { event.preventDefault(); event.returnValue = ''; }
	});
}());
