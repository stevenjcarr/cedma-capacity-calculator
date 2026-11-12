<?php
/**
 * CEdMA Enterprise capacity calculator
 *
 * The browser sends the list of items to this file, and the calculation runs
 * on the server, in includes/calculator.php. Styles, script, fonts, and logos
 * are in assets/. The ratios are never sent to the browser; the page only
 * ever receives results.
 */
declare(strict_types=1);

require __DIR__ . '/includes/calculator.php';
require __DIR__ . '/includes/layout.php';

// -----------------------------------------------------------------------------
// Calculation endpoint: POST with a JSON body {"rows": [...]}, and
// "format": "csv" to download the list as a CSV file instead
// -----------------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	header('X-Content-Type-Options: nosniff');

	$raw = file_get_contents('php://input', false, null, 0, MAX_REQUEST_BYTES + 1);
	if ($raw === false || strlen($raw) > MAX_REQUEST_BYTES) {
		http_response_code(413);
		echo json_encode(['ok' => false, 'error' => 'Request too large']);
		exit;
	}

	$data = json_decode($raw, true);
	if (!is_array($data) || !isset($data['rows']) || !is_array($data['rows'])) {
		http_response_code(400);
		echo json_encode(['ok' => false, 'error' => 'Invalid request']);
		exit;
	}

	if (($data['format'] ?? '') === 'csv') {
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="cedma-capacity-estimate-' . gmdate('Y-m-d') . '.csv"');
		echo build_csv(array_values($data['rows']));
		exit;
	}

	echo json_encode(calculate(array_values($data['rows'])), JSON_UNESCAPED_UNICODE);
	exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
	http_response_code(405);
	header('Allow: GET, POST');
	exit;
}

header('X-Content-Type-Options: nosniff');
?>
<?php page_start('Capacity calculator', 'Estimate the development work your training plans need, as work hours or as developer headcount.'); ?>

<main>
	<div class="wrap">
		<div class="page-head">
			<p class="label">Planning tool</p>
			<h1>Capacity <em>calculator</em></h1>
			<p class="lead">Estimate how much development work your training plans need, either as work hours or as the number of developers it would take over a year.</p>
		</div>

		<noscript>
			<p class="noscript">This calculator needs JavaScript. Turn it on in your browser settings, then reload the page.</p>
		</noscript>

		<section aria-labelledby="items-heading">
			<h2 id="items-heading" class="sr-only">Items</h2>
			<div class="courses">
				<table>
					<colgroup>
						<col class="c-name"><col class="c-type"><col class="c-duration"><col class="c-effort"><col class="c-ai"><col class="c-weeks"><col class="c-remove">
					</colgroup>
					<thead>
					<tr>
						<th scope="col" class="col-name">Item name</th>
						<th scope="col" class="col-type">Content type</th>
						<th scope="col" class="col-duration">Duration</th>
						<th scope="col" class="col-effort">Level of effort</th>
						<th scope="col" class="col-ai">AI assisted</th>
						<th scope="col" class="col-weeks num" id="unit-heading">Weeks</th>
						<th scope="col" class="col-remove"><span class="sr-only">Remove</span></th>
					</tr>
					</thead>
					<tbody id="rows"></tbody>
				</table>
			</div>

			<div class="actions">
				<button type="button" class="btn btn-primary" id="add-row">Add an item</button>
				<span class="row-limit" id="row-limit" role="status"></span>
				<div class="actions-end">
					<button type="button" class="btn btn-secondary" id="download-csv">Download CSV</button>
					<button type="button" class="btn btn-secondary" id="clear-all">Clear all</button>
				</div>
			</div>
		</section>

		<section class="results" id="results" data-view="headcount" aria-labelledby="results-heading">
			<img class="emblem" src="<?= h(asset('cedma-logomark-rgb-dark.svg')) ?>" alt="">
			<div class="results-content">
				<div class="results-head">
					<h2 id="results-heading" class="label">Your estimate</h2>
					<fieldset class="view-switch">
						<legend class="switch-label">Show as</legend>
						<label><input type="radio" name="view" value="headcount" checked><span>Headcount</span></label>
						<label><input type="radio" name="view" value="hours"><span>Work hours</span></label>
					</fieldset>
				</div>
				<dl class="stats">
					<div class="stat stat-hours">
						<dt>Total hours of work</dt>
						<dd id="total-hours">0</dd>
					</div>
					<div class="stat stat-weeks">
						<dt>Total weeks of work</dt>
						<dd id="total-weeks">0</dd>
					</div>
					<div class="stat stat-headcount">
						<dt>Estimated developer headcount</dt>
						<dd id="headcount">0.0</dd>
					</div>
				</dl>
				<p class="summary" id="summary" role="status" aria-live="polite">Add an item to see an estimate.</p>
			</div>
		</section>

		<section class="method" aria-labelledby="method-heading">
			<h2 id="method-heading">How the estimate works</h2>
			<div class="method-grid">
				<div class="method-text">
					<p>The figures are a planning guide, not a quote. The development hours for each content type are for new content, and the level of effort scales them for updates.</p>
					<p>A week of work is <?= HOURS_PER_WEEK ?> hours of development, which allows for the regular meetings and admin in a normal working week. The <?= WORKING_WEEKS_PER_YEAR ?> working weeks a year allow for holidays and leave.</p>
					<p>To show a backlog, compare the work hours with the time your team actually has free for development.</p>
					<p>The AI savings are CEdMA planning estimates, not measured figures, and allow for the extra review AI-generated content needs. Where no reliable published figure exists for a content type, CEdMA has set an estimate. <a href="sources.php">See where the figures come from</a>.</p>
				</div>
				<aside class="calc-card" aria-labelledby="calc-heading">
					<h3 id="calc-heading" class="label">The calculation</h3>
					<ol class="calc-steps">
						<li>
							<span class="calc-term">Work hours</span>
							<span class="calc-formula">duration × development hours for the content type × level of effort</span>
						</li>
						<li>
							<span class="calc-term">If AI assisted</span>
							<span class="calc-formula">work hours less the AI saving for the content type</span>
						</li>
						<li>
							<span class="calc-term">Weeks of work</span>
							<span class="calc-formula">work hours ÷ <?= HOURS_PER_WEEK ?> development hours a week</span>
						</li>
						<li>
							<span class="calc-term">Headcount</span>
							<span class="calc-formula">weeks of work ÷ <?= WORKING_WEEKS_PER_YEAR ?> working weeks a year</span>
						</li>
					</ol>
				</aside>
			</div>

			<h2 id="types-heading">Content types</h2>
			<p>What each content type covers, and what the AI assisted option means for it.</p>
			<div class="types-wrap">
				<table class="types-table">
					<thead>
					<tr>
						<th scope="col">Content type</th>
						<th scope="col">Duration in</th>
						<th scope="col">What it covers</th>
						<th scope="col">With AI assisted</th>
						<th scope="col" class="num">AI saving</th>
					</tr>
					</thead>
					<tbody>
					<?php foreach (CONTENT_TYPES as $item): ?>
					<tr>
						<th scope="row" data-label="Content type"><?= h($item['label']) ?></th>
						<td data-label="Duration in"><?= h(ucfirst(UNITS[$item['unit']]['plural'])) ?></td>
						<td data-label="What it covers"><?= h($item['description']) ?></td>
						<td data-label="With AI assisted"><?= h($item['ai_note']) ?></td>
						<td data-label="AI saving" class="num<?= $item['ai_saving'] === null ? ' na' : '' ?>"><?= $item['ai_saving'] === null ? 'Does not apply' : h((string)(int)round($item['ai_saving'] * 100)) . '%' ?></td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
	</div>
</main>

<?php page_footer(); ?>

<template id="row-template">
	<tr>
		<td class="col-name">
			<label class="cell-label" data-for="name">Item name<span class="sr-only row-ref"></span></label>
			<input type="text" data-field="name" maxlength="<?= MAX_NAME_LENGTH ?>" autocomplete="off" spellcheck="true">
			<p class="field-error" data-error="name"></p>
			<p class="row-note"></p>
		</td>
		<td class="col-type">
			<label class="cell-label" data-for="type">Content type<span class="sr-only row-ref"></span></label>
			<select data-field="type">
				<option value="" data-unit="<?= h(UNITS['hours']['plural']) ?>" data-unit-short="<?= h(UNITS['hours']['short']) ?>">Choose</option>
				<?php foreach (CONTENT_TYPES as $key => $item): ?>
				<option value="<?= h($key) ?>" data-ai="<?= $item['ai_saving'] === null ? 'no' : 'yes' ?>" data-unit="<?= h(UNITS[$item['unit']]['plural']) ?>" data-unit-short="<?= h(UNITS[$item['unit']]['short']) ?>"><?= h($item['label']) ?></option>
				<?php endforeach; ?>
			</select>
			<p class="field-error" data-error="type"></p>
		</td>
		<td class="col-duration">
			<label class="cell-label" data-for="duration">Duration<span class="sr-only"> in <span class="duration-unit-text">hours</span></span><span class="sr-only row-ref"></span></label>
			<div class="duration">
				<input type="text" data-field="duration" inputmode="decimal" autocomplete="off">
				<span class="duration-unit" aria-hidden="true">hrs</span>
			</div>
			<p class="field-error" data-error="duration"></p>
		</td>
		<td class="col-effort">
			<label class="cell-label" data-for="effort">Level of effort<span class="sr-only row-ref"></span></label>
			<select data-field="effort">
				<option value="">Choose</option>
				<?php foreach (EFFORT_LEVELS as $key => $item): ?>
				<option value="<?= h($key) ?>"><?= h($item['label']) ?></option>
				<?php endforeach; ?>
			</select>
			<p class="field-error" data-error="effort"></p>
		</td>
		<td class="col-ai">
			<label class="ai-toggle" data-for="ai">
				<input type="checkbox" data-field="ai">
				<span class="ai-text">AI assisted<span class="sr-only row-ref"></span></span>
			</label>
		</td>
		<td class="col-weeks">
			<span class="cell-label unit-label" aria-hidden="true">Weeks</span>
			<div class="weeks" aria-live="off">
				<span class="weeks-value"></span>
				<span class="weeks-note"></span>
			</div>
		</td>
		<td class="col-remove">
			<button type="button" class="btn-remove" data-action="remove">Remove</button>
		</td>
	</tr>
</template>

<script type="application/json" id="calculator-config"><?= json_encode([
	'maxRows'      => MAX_ROWS,
	'legacyCookie' => LEGACY_COOKIE,
	'legacyRows'   => legacy_rows(),
	'typeAliases'  => TYPE_ALIASES,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= h(asset('calculator.js')) ?>" defer></script>
</body>
</html>
