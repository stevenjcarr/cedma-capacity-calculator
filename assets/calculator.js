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
	var FIELDS = ['name', 'hours', 'type', 'effort'];
	var LEGACY_COOKIE = config.legacyCookie || 'ctc_items_v3';
	var LEGACY_ROWS = Array.isArray(config.legacyRows) ? config.legacyRows : [];

	var rowsEl = document.getElementById('rows');
	var template = document.getElementById('row-template');
	var addBtn = document.getElementById('add-row');
	var clearBtn = document.getElementById('clear-all');
	var limitEl = document.getElementById('row-limit');
	var weeksEl = document.getElementById('total-weeks');
	var headcountEl = document.getElementById('headcount');
	var summaryEl = document.getElementById('summary');
	var endpoint = window.location.pathname + window.location.search;

	// ---------- Rows ----------

	function addRow(data) {
		if (rowsEl.rows.length >= MAX_ROWS) return null;
		var tr = template.content.firstElementChild.cloneNode(true);
		data = data || {};
		FIELDS.forEach(function (f) {
			var el = tr.querySelector('[data-field="' + f + '"]');
			var value = data[f] == null ? '' : String(data[f]);
			if (el.tagName === 'SELECT' && !el.querySelector('option[value="' + CSS.escape(value) + '"]')) value = '';
			el.value = value;
		});
		rowsEl.appendChild(tr);
		return tr;
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
			tr.querySelectorAll('.row-ref').forEach(function (s) { s.textContent = ', course ' + n; });
			tr.querySelector('[data-action="remove"]').setAttribute('aria-label', 'Remove course ' + n);
		});
		var full = rowsEl.rows.length >= MAX_ROWS;
		addBtn.disabled = full;
		limitEl.textContent = full ? 'You have reached the limit of ' + MAX_ROWS + ' courses.' : '';
	}

	function collectRows() {
		return Array.prototype.map.call(rowsEl.rows, function (tr) {
			var row = {};
			FIELDS.forEach(function (f) {
				row[f] = tr.querySelector('[data-field="' + f + '"]').value.trim();
			});
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
		weeksEl.textContent = data.total_weeks;
		headcountEl.textContent = data.headcount;
		summaryEl.textContent = data.summary;
		summaryEl.classList.toggle('has-incomplete', data.incomplete > 0);

		Array.prototype.forEach.call(rowsEl.rows, function (tr, i) {
			var result = data.rows[i] || { status: 'empty' };
			var invalid = result.invalid || {};
			tr.querySelector('.weeks-value').textContent = result.status === 'ok' ? result.weeks : '';
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
	rowsEl.addEventListener('change', scheduleCalc);

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

	clearBtn.addEventListener('click', function () {
		var hasData = collectRows().some(function (r) { return !isEmpty(r); });
		if (hasData && !window.confirm('Clear all courses? This also removes the list saved in this browser.')) return;
		rowsEl.innerHTML = '';
		addRow();
		renumber();
		try { localStorage.removeItem(STORAGE_KEY); } catch (e) { /* ignore */ }
		rowsEl.querySelector('[data-field="name"]').focus();
		runCalc();
	});

	// ---------- Start ----------

	loadSaved().slice(0, MAX_ROWS).forEach(addRow);
	if (!rowsEl.rows.length) addRow();
	renumber();
	save();
	runCalc();
})();
