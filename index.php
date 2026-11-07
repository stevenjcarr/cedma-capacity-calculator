<?php
/**
 * CEdMA Enterprise capacity calculator
 *
 * The browser sends the course list to this file, and the calculation runs
 * here on the server. Styles, script, and logos are in assets/. The multipliers below are never
 * sent to the browser; the page only ever receives the results.
 */
declare(strict_types=1);

// -----------------------------------------------------------------------------
// Calculation settings (server-side only)
// -----------------------------------------------------------------------------

// Weeks of development per hour of finished content.
const CONTENT_TYPES = [
	'ilt'  => ['label' => 'Instructor-led', 'weeks_per_hour' => 1.0],
	'self' => ['label' => 'Self-paced',     'weeks_per_hour' => 2.0],
];

// Share of the content that is being created or reworked.
const EFFORT_LEVELS = [
	'minor' => ['label' => 'Minor updates (25%)',                  'factor' => 0.25],
	'half'  => ['label' => 'Updates to half of the content (50%)', 'factor' => 0.5],
	'major' => ['label' => 'Major updates to content (75%)',       'factor' => 0.75],
	'new'   => ['label' => 'New course from scratch (100%)',       'factor' => 1.0],
];

const WORKING_WEEKS_PER_YEAR = 45;

// Limits
const MAX_ROWS = 200;
const MAX_HOURS = 1000;
const MAX_NAME_LENGTH = 200;
const MAX_REQUEST_BYTES = 262144; // 256KB

// Cookie used by the previous version, migrated on first visit
const LEGACY_COOKIE = 'ctc_items_v3';

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function h(string $s): string
{
	return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function as_text(mixed $value): string
{
	return is_scalar($value) ? trim((string)$value) : '';
}

/**
 * Character length and truncation that work whether or not the server has the
 * mbstring extension installed.
 */
function text_length(string $s): int
{
	if (function_exists('mb_strlen')) {
		return mb_strlen($s, 'UTF-8');
	}
	return (int)preg_match_all('/./us', $s);
}

function text_cut(string $s, int $max): string
{
	if (function_exists('mb_substr')) {
		return mb_substr($s, 0, $max, 'UTF-8');
	}
	return preg_match('/^.{0,' . $max . '}/us', $s, $m) ? $m[0] : '';
}

/** Path to a file in assets/, with a version tag so browsers pick up changes. */
function asset(string $file): string
{
	$path = __DIR__ . '/assets/' . $file;
	$version = is_file($path) ? (string)filemtime($path) : '1';
	return 'assets/' . rawurlencode($file) . '?v=' . $version;
}

/** Formats a number with up to $maxDecimals places, dropping trailing zeros. */
function format_number(float $n, int $maxDecimals = 2): string
{
	$s = number_format($n, $maxDecimals, '.', ',');
	if (str_contains($s, '.')) {
		$s = rtrim(rtrim($s, '0'), '.');
	}
	return $s;
}

/** House style: one to nine as words, 10 upwards as figures. */
function number_words(int $n): string
{
	$words = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine'];
	return $n >= 0 && $n < 10 ? $words[$n] : (string)$n;
}

/** Joins a list with commas, the Oxford comma, and "and". */
function join_list(array $items): string
{
	$count = count($items);
	if ($count <= 1) {
		return $items[0] ?? '';
	}
	if ($count === 2) {
		return $items[0] . ' and ' . $items[1];
	}
	return implode(', ', array_slice($items, 0, -1)) . ', and ' . $items[$count - 1];
}

/** Returns hours as a float, or an error message. */
function parse_hours(string $raw): float|string
{
	$s = str_replace(',', '.', $raw);
	if (!preg_match('/^\d+(?:\.\d+)?$/', $s)) {
		return 'Enter a number of hours, such as 4 or 1.5';
	}
	$hours = (float)$s;
	if ($hours <= 0) {
		return 'Enter a duration greater than zero';
	}
	if ($hours > MAX_HOURS) {
		return 'Enter ' . format_number(MAX_HOURS) . ' hours or fewer';
	}
	return $hours;
}

// -----------------------------------------------------------------------------
// Calculation
// -----------------------------------------------------------------------------

function calculate(array $rows): array
{
	$totalWeeks = 0.0;
	$counted = 0;
	$incomplete = 0;
	$results = [];

	foreach (array_slice($rows, 0, MAX_ROWS) as $row) {
		$row = is_array($row) ? $row : [];
		$name = as_text($row['name'] ?? '');
		$hoursRaw = as_text($row['hours'] ?? '');
		$type = as_text($row['type'] ?? '');
		$effort = as_text($row['effort'] ?? '');

		if ($name === '' && $hoursRaw === '' && $type === '' && $effort === '') {
			$results[] = ['status' => 'empty'];
			continue;
		}

		$missing = [];
		$invalid = [];

		if ($name === '') {
			$missing[] = 'a course name';
		} elseif (text_length($name) > MAX_NAME_LENGTH) {
			$invalid['name'] = 'Use ' . MAX_NAME_LENGTH . ' characters or fewer';
		}

		$hours = null;
		if ($hoursRaw === '') {
			$missing[] = 'a duration';
		} else {
			$parsed = parse_hours($hoursRaw);
			if (is_string($parsed)) {
				$invalid['hours'] = $parsed;
			} else {
				$hours = $parsed;
			}
		}

		if ($type === '') {
			$missing[] = 'a content type';
		} elseif (!isset(CONTENT_TYPES[$type])) {
			$invalid['type'] = 'Choose a content type from the list';
		}

		if ($effort === '') {
			$missing[] = 'a level of effort';
		} elseif (!isset(EFFORT_LEVELS[$effort])) {
			$invalid['effort'] = 'Choose a level of effort from the list';
		}

		if ($missing || $invalid) {
			$incomplete++;
			$note = $missing ? 'Needs ' . join_list($missing) : '';
			$results[] = ['status' => 'incomplete', 'note' => $note, 'invalid' => (object)$invalid];
			continue;
		}

		$weeks = $hours * CONTENT_TYPES[$type]['weeks_per_hour'] * EFFORT_LEVELS[$effort]['factor'];
		$totalWeeks += $weeks;
		$counted++;
		$results[] = ['status' => 'ok', 'weeks' => format_number($weeks)];
	}

	$headcount = $totalWeeks / WORKING_WEEKS_PER_YEAR;

	if ($counted === 0 && $incomplete === 0) {
		$summary = 'Add a course to see an estimate.';
	} else {
		$summary = ucfirst(number_words($counted)) . ($counted === 1 ? ' course' : ' courses') . ' counted.';
		if ($incomplete > 0) {
			$summary .= ' ' . ucfirst(number_words($incomplete))
				. ($incomplete === 1 ? ' course is incomplete and is not counted.' : ' courses are incomplete and are not counted.');
		}
	}

	return [
		'ok'          => true,
		'rows'        => $results,
		'total_weeks' => format_number($totalWeeks),
		'headcount'   => number_format($headcount, 1, '.', ','),
		'counted'     => $counted,
		'incomplete'  => $incomplete,
		'summary'     => $summary,
	];
}

// -----------------------------------------------------------------------------
// Calculation endpoint: POST with a JSON body {"rows": [...]}
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

	echo json_encode(calculate(array_values($data['rows'])), JSON_UNESCAPED_UNICODE);
	exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
	http_response_code(405);
	header('Allow: GET, POST');
	exit;
}

header('X-Content-Type-Options: nosniff');

/**
 * The previous version of this calculator saved rows in a cookie using the
 * raw multipliers as values. Convert them once so nobody loses their list.
 */
function legacy_rows(): array
{
	$raw = $_COOKIE[LEGACY_COOKIE] ?? '';
	if (!is_string($raw) || $raw === '') {
		return [];
	}
	$data = json_decode($raw, true);
	if (!is_array($data) || !isset($data['rows']) || !is_array($data['rows'])) {
		return [];
	}
	$types = ['1' => 'ilt', '2' => 'self'];
	$efforts = ['0.25' => 'minor', '0.5' => 'half', '0.75' => 'major', '1' => 'new'];
	$rows = [];
	foreach (array_slice($data['rows'], 0, MAX_ROWS) as $row) {
		if (!is_array($row)) {
			continue;
		}
		$rows[] = [
			'name'   => text_cut(as_text($row['course_name'] ?? ''), MAX_NAME_LENGTH),
			'hours'  => text_cut(as_text($row['duration_hours'] ?? ''), 20),
			'type'   => $types[as_text($row['content_type'] ?? '')] ?? '',
			'effort' => $efforts[as_text($row['level_of_effort'] ?? '')] ?? '',
		];
	}
	return $rows;
}
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Capacity calculator · CEdMA Enterprise</title>
	<meta name="description" content="Estimate the weeks of development work and the developer headcount your course plans need.">
	<link rel="icon" type="image/svg+xml" href="<?= h(asset('cedma-logomark-rgb.svg')) ?>">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Albert+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="<?= h(asset('calculator.css')) ?>">
</head>
<body>

<header class="site-header">
	<div class="wrap">
		<img src="<?= h(asset('cedma-full-rgb-dark.svg')) ?>" alt="CEdMA Enterprise" width="156" height="48">
	</div>
</header>

<main>
	<div class="wrap">
		<div class="page-head">
			<p class="label">Planning tool</p>
			<h1>Capacity <em>calculator</em></h1>
			<p class="lead">Estimate how many weeks of development work your course plans need, and how many developers that represents over a year.</p>
		</div>

		<noscript>
			<p class="noscript">This calculator needs JavaScript. Turn it on in your browser settings, then reload the page.</p>
		</noscript>

		<section aria-labelledby="courses-heading">
			<h2 id="courses-heading" class="sr-only">Courses</h2>
			<div class="courses">
				<table>
					<colgroup>
						<col class="c-name"><col class="c-hours"><col class="c-type"><col class="c-effort"><col class="c-weeks"><col class="c-remove">
					</colgroup>
					<thead>
					<tr>
						<th scope="col" class="col-name">Course name</th>
						<th scope="col" class="col-hours">Duration (hours)</th>
						<th scope="col" class="col-type">Content type</th>
						<th scope="col" class="col-effort">Level of effort</th>
						<th scope="col" class="col-weeks num">Weeks</th>
						<th scope="col" class="col-remove"><span class="sr-only">Remove</span></th>
					</tr>
					</thead>
					<tbody id="rows"></tbody>
				</table>
			</div>

			<div class="actions">
				<button type="button" class="btn btn-primary" id="add-row">Add a course</button>
				<span class="row-limit" id="row-limit" role="status"></span>
				<button type="button" class="btn btn-secondary" id="clear-all">Clear all</button>
			</div>
		</section>

		<section class="results" aria-labelledby="results-heading">
			<img class="emblem" src="<?= h(asset('cedma-logomark-rgb-dark.svg')) ?>" alt="">
			<div class="results-content">
				<h2 id="results-heading" class="label">Your estimate</h2>
				<dl class="stats">
					<div class="stat">
						<dt>Total weeks of work</dt>
						<dd id="total-weeks">0</dd>
					</div>
					<div class="stat">
						<dt>Estimated developer headcount</dt>
						<dd id="headcount">0.0</dd>
					</div>
				</dl>
				<p class="summary" id="summary" role="status" aria-live="polite">Add a course to see an estimate.</p>
			</div>
		</section>

		<section class="method" aria-labelledby="method-heading">
			<h2 id="method-heading">How the estimate works</h2>
			<p class="formula">Weeks of work = duration × content type × level of effort</p>
			<p class="formula">Headcount = total weeks ÷ <?= WORKING_WEEKS_PER_YEAR ?> working weeks a year</p>
			<p>The figures are a planning guide, not a quote. They assume each developer spends their working year on development, so allow for reviews, meetings, and time off when you use them.</p>
			<p>For background on development ratios, <a href="https://www.td.org/content/atd-blog/how-long-does-it-take-to-develop-training-new-question-new-answers">read the ATD article on how long training takes to develop</a>.</p>
		</section>
	</div>
</main>

<footer class="site-footer">
	<div class="wrap">
		<span>CEdMA Enterprise · Setting the Standard for Customer Education</span>
		<a href="https://cedma-enterprise.org">cedma-enterprise.org</a>
	</div>
</footer>

<template id="row-template">
	<tr>
		<td class="col-name">
			<label class="cell-label" data-for="name">Course name<span class="sr-only row-ref"></span></label>
			<input type="text" data-field="name" maxlength="<?= MAX_NAME_LENGTH ?>" autocomplete="off" spellcheck="true">
			<p class="field-error" data-error="name"></p>
			<p class="row-note"></p>
		</td>
		<td class="col-hours">
			<label class="cell-label" data-for="hours">Duration (hours)<span class="sr-only row-ref"></span></label>
			<input type="text" data-field="hours" inputmode="decimal" autocomplete="off">
			<p class="field-error" data-error="hours"></p>
		</td>
		<td class="col-type">
			<label class="cell-label" data-for="type">Content type<span class="sr-only row-ref"></span></label>
			<select data-field="type">
				<option value="">Choose</option>
				<?php foreach (CONTENT_TYPES as $key => $item): ?>
				<option value="<?= h($key) ?>"><?= h($item['label']) ?></option>
				<?php endforeach; ?>
			</select>
			<p class="field-error" data-error="type"></p>
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
		<td class="col-weeks">
			<span class="cell-label" aria-hidden="true">Weeks</span>
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
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= h(asset('calculator.js')) ?>" defer></script>
</body>
</html>
