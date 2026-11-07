/* CEdMA Enterprise capacity calculator. The calculation itself runs in index.php. */
(function () {
	'use strict';

	// Settings passed from index.php. Rows saved in a cookie by the previous
	// version arrive here already converted by the server.
	var config = {};
	try {
		config = JSON.parse(document.getElementById('calculator-config').textContent);
	} catch (e) { /* fall back to defaults */ }

	var STORAGE_KEY = 'cedma-capacity-calculator-v1';
	var MAX_ROWS = config.maxRows || 200;
	var FIELDS = ['name', 'type', 'duration', 'effort'];
	var LEGACY_COOKIE = config.legacyCookie || 'ctc_items_v3';
	var LEGACY_ROWS = Array.isArray(config.legacyRows) ? config.legacyRows : [];
	// Content types renamed since earlier versions, so saved lists still load
	var TYPE_ALIASES = config.typeAliases || {};

	var rowsEl = document.getElementById('rows');
	var template = document.getElementById('row-template');
	var addBtn = document.getElementById('add-row');
	var clearBtn = document.getElementById('clear-all');
	var csvBtn = document.getElementById('download-csv');
	var limitEl = document.getElementById('row-limit');
	var weeksEl = document.getElementById('total-weeks');
	var headcountEl = document.getElementById('headcount');
	var summaryEl = document.getElementById('summary');
	var hoursEl = document.getElementById('total-hours');
	var resultsEl = document.getElementById('results');
	var unitHeading = document.getElementById('unit-heading');
	var viewInputs = document.querySelectorAll('input[name="view"]');
	var VIEW_KEY = 'cedma-capacity-calculator-view';
	var view = 'headcount';
	var lastData = null;
	var endpoint = window.location.pathname + window.location.search;

	// ---------- Rows ----------

	function addRow(data) {
		if (rowsEl.rows.length >= MAX_ROWS) return null;
		var tr = template.content.firstElementChild.cloneNode(true);
		data = data || {};
		// Lists saved before video was added call the duration "hours"
		if (data.duration == null && data.hours != null) data.duration = data.hours;
		if (data.type && TYPE_ALIASES[data.type]) data.type = TYPE_ALIASES[data.type];
		FIELDS.forEach(function (f) {
			var el = tr.querySelector('[data-field="' + f + '"]');
			var value = data[f] == null ? '' : String(data[f]);
			if (el.tagName === 'SELECT' && !el.querySelector('option[value="' + CSS.escape(value) + '"]')) value = '';
			el.value = value;
		});
		tr.querySelector('[data-field="ai"]').checked = data.ai === true || data.ai === '1';
		tr.querySelector('.unit-label').textContent = view === 'hours' ? 'Hours' : 'Weeks';
		updateDurationUnit(tr);
		rowsEl.appendChild(tr);
		return tr;
	}

	// Shows hours or minutes beside the duration to match the content type, and
	// turns off the AI assisted option for types already made with AI.
	function updateDurationUnit(tr) {
		var select = tr.querySelector('[data-field="type"]');
		var option = select.options[select.selectedIndex] || select.options[0];
		tr.querySelector('.duration-unit').textContent = option.getAttribute('data-unit-short');
		tr.querySelector('.duration-unit-text').textContent = option.getAttribute('data-unit');
		var ai = tr.querySelector('[data-field="ai"]');
		var aiApplies = option.getAttribute('data-ai') !== 'no';
		ai.disabled = !aiApplies;
		if (!aiApplies) ai.checked = false;
		ai.title = aiApplies ? '' : 'Already made with AI, so this does not apply';
	}

	function renumber() {
		Array.prototype.forEach.call(rowsEl.rows, function (tr, i) {
			var n = i + 1;
			FIELDS.forEach(function (f) {
				var id = f + '-' + n;
				var input = tr.querySelector('[data-field="' + f + '"]');
				var label = tr.querySelector('label[data-for="' + f + '"]');
				var error = tr.querySelector('[data-error="' + f + '"]');
				input.id = id;
				label.htmlFor = id;
				error.id = id + '-error';
			});
			tr.querySelectorAll('.row-ref').forEach(function (s) { s.textContent = ', item ' + n; });
			tr.querySelector('[data-action="remove"]').setAttribute('aria-label', 'Remove item ' + n);
			tr.querySelector('[data-field="ai"]').id = 'ai-' + n;
		});
		var full = rowsEl.rows.length >= MAX_ROWS;
		addBtn.disabled = full;
		limitEl.textContent = full ? 'You have reached the limit of ' + MAX_ROWS + ' items.' : '';
	}

	function collectRows() {
		return Array.prototype.map.call(rowsEl.rows, function (tr) {
			var row = {};
			FIELDS.forEach(function (f) {
				row[f] = tr.querySelector('[data-field="' + f + '"]').value.trim();
			});
			row.ai = tr.querySelector('[data-field="ai"]').checked;
			return row;
		});
	}

	function isEmpty(row) {
		return FIELDS.every(function (f) { return row[f] === ''; });
	}

	// ---------- Saving in this browser ----------

	function save() {
		try {
			var rows = collectRows().filter(function (r) { return !isEmpty(r); });
			if (rows.length) {
				localStorage.setItem(STORAGE_KEY, JSON.stringify({ v: 1, rows: rows }));
			} else {
				localStorage.removeItem(STORAGE_KEY);
			}
		} catch (e) { /* storage unavailable: the page still works */ }
	}

	function loadSaved() {
		try {
			var stored = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
			if (stored && stored.v === 1 && Array.isArray(stored.rows)) return stored.rows;
		} catch (e) { /* ignore */ }
		return loadLegacyCookie();
	}

	function loadLegacyCookie() {
		if (!LEGACY_ROWS.length) return [];
		document.cookie = LEGACY_COOKIE + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; SameSite=Lax';
		return LEGACY_ROWS;
	}

	// ---------- Calculation (runs on the server) ----------

	var timer = null;
	var sequence = 0;
	var controller = null;

	function scheduleCalc() {
		save();
		updateCsvButton();
		clearTimeout(timer);
		timer = setTimeout(runCalc, 250);
	}

	function runCalc() {
		var mine = ++sequence;
		if (controller) controller.abort();
		controller = typeof AbortController === 'function' ? new AbortController() : null;

		fetch(endpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
			body: JSON.stringify({ rows: collectRows() }),
			signal: controller ? controller.signal : undefined
		})
			.then(function (res) {
				if (!res.ok) throw new Error('HTTP ' + res.status);
				return res.json();
			})
			.then(function (data) {
				if (mine !== sequence) return;
				if (!data || !data.ok) throw new Error('Bad response');
				showResults(data);
			})
			.catch(function (e) {
				if (e && e.name === 'AbortError') return;
				if (mine !== sequence) return;
				summaryEl.classList.add('has-incomplete');
				summaryEl.textContent = 'The calculator could not be reached. Check your connection and try again.';
			});
	}

	function showResults(data) {
		lastData = data;
		weeksEl.textContent = data.total_weeks;
		headcountEl.textContent = data.headcount;
		hoursEl.textContent = data.total_hours;
		summaryEl.textContent = data.summary;
		summaryEl.classList.toggle('has-incomplete', data.incomplete > 0);

		Array.prototype.forEach.call(rowsEl.rows, function (tr, i) {
			var result = data.rows[i] || { status: 'empty' };
			var invalid = result.invalid || {};
			tr.querySelector('.weeks-value').textContent = result.status === 'ok' ? (view === 'hours' ? result.hours : result.weeks) : '';
			tr.querySelector('.weeks-note').textContent = result.status === 'incomplete' ? 'Not counted' : '';
			tr.querySelector('.row-note').textContent = result.status === 'incomplete' ? (result.note || '') : '';

			FIELDS.forEach(function (f) {
				var input = tr.querySelector('[data-field="' + f + '"]');
				var error = tr.querySelector('[data-error="' + f + '"]');
				if (invalid[f]) {
					input.setAttribute('aria-invalid', 'true');
					input.setAttribute('aria-describedby', error.id);
					error.textContent = invalid[f];
				} else {
					input.removeAttribute('aria-invalid');
					input.removeAttribute('aria-describedby');
					error.textContent = '';
				}
			});
		});
	}

	// ---------- Events ----------

	rowsEl.addEventListener('input', scheduleCalc);
	rowsEl.addEventListener('change', function (e) {
		if (e.target.matches('[data-field="type"]')) updateDurationUnit(e.target.closest('tr'));
		scheduleCalc();
	});

	rowsEl.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-action="remove"]');
		if (!btn) return;
		var tr = btn.closest('tr');
		var next = tr.nextElementSibling || tr.previousElementSibling;
		if (rowsEl.rows.length > 1) {
			tr.remove();
			renumber();
			(next ? next.querySelector('[data-action="remove"]') : addBtn).focus();
		} else {
			FIELDS.forEach(function (f) { tr.querySelector('[data-field="' + f + '"]').value = ''; });
			tr.querySelector('[data-field="ai"]').checked = false;
			updateDurationUnit(tr);
			tr.querySelector('[data-field="name"]').focus();
		}
		scheduleCalc();
	});

	addBtn.addEventListener('click', function () {
		var tr = addRow();
		if (!tr) return;
		renumber();
		tr.querySelector('[data-field="name"]').focus();
		scheduleCalc();
	});

	// ---------- CSV download (built on the server) ----------

	function updateCsvButton() {
		csvBtn.disabled = !collectRows().some(function (r) { return !isEmpty(r); });
	}

	function localDate() {
		var d = new Date();
		var pad = function (n) { return (n < 10 ? '0' : '') + n; };
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
	}

	csvBtn.addEventListener('click', function () {
		csvBtn.disabled = true;
		fetch(endpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'Accept': 'text/csv' },
			body: JSON.stringify({ rows: collectRows(), format: 'csv' })
		})
			.then(function (res) {
				if (!res.ok) throw new Error('HTTP ' + res.status);
				return res.blob();
			})
			.then(function (blob) {
				var url = URL.createObjectURL(blob);
				var link = document.createElement('a');
				link.href = url;
				link.download = 'cedma-capacity-estimate-' + localDate() + '.csv';
				document.body.appendChild(link);
				link.click();
				link.remove();
				setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
			})
			.catch(function () {
				summaryEl.classList.add('has-incomplete');
				summaryEl.textContent = 'The CSV file could not be created. Check your connection and try again.';
			})
			.then(updateCsvButton);
	});

	clearBtn.addEventListener('click', function () {
		var hasData = collectRows().some(function (r) { return !isEmpty(r); });
		if (hasData && !window.confirm('Clear all items? This also removes the list saved in this browser.')) return;
		rowsEl.innerHTML = '';
		addRow();
		renumber();
		try { localStorage.removeItem(STORAGE_KEY); } catch (e) { /* ignore */ }
		updateCsvButton();
		rowsEl.querySelector('[data-field="name"]').focus();
		runCalc();
	});

	// ---------- Headcount or work hours view ----------

	function setView(next, remember) {
		view = next === 'hours' ? 'hours' : 'headcount';
		resultsEl.setAttribute('data-view', view);
		viewInputs.forEach(function (input) { input.checked = input.value === view; });
		var unit = view === 'hours' ? 'Hours' : 'Weeks';
		unitHeading.textContent = unit;
		rowsEl.querySelectorAll('.unit-label').forEach(function (el) { el.textContent = unit; });
		if (lastData) showResults(lastData);
		if (remember) {
			try { localStorage.setItem(VIEW_KEY, view); } catch (e) { /* ignore */ }
		}
	}

	viewInputs.forEach(function (input) {
		input.addEventListener('change', function () { setView(input.value, true); });
	});

	// ---------- Start ----------

	loadSaved().slice(0, MAX_ROWS).forEach(addRow);
	if (!rowsEl.rows.length) addRow();
	var savedView = null;
	try { savedView = localStorage.getItem(VIEW_KEY); } catch (e) { /* ignore */ }
	setView(savedView, false);
	renumber();
	save();
	updateCsvButton();
	runCalc();
})();
