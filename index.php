<?php
/**
 * CEdMA Enterprise capacity calculator
 *
 * The browser sends the list of items to this file, and the calculation runs
 * here on the server. Styles, script, and logos are in assets/. The ratios
 * below are never sent to the browser; the page only ever receives results.
 */
declare(strict_types=1);

// -----------------------------------------------------------------------------
// Calculation settings (server-side only)
// -----------------------------------------------------------------------------

// Each content type has:
//   unit                the unit its duration is entered in
//   dev_hours_per_unit  hours of development per unit of finished new content,
//                       based on published industry figures
//   max                 the longest duration accepted
//   ai_saving           the reduction when an item is marked AI assisted, or
//                       null where the option does not apply
//   description, ai_note  shown in the content types table on the page
// For example, one minute of finished produced video takes five hours to develop.
// The AI savings are planning estimates that allow for the extra review
// AI-generated content needs.
const CONTENT_TYPES = [
	'ilt' => [
		'label' => 'Instructor-led', 'unit' => 'hours', 'dev_hours_per_unit' => 40.0, 'max' => 1000, 'ai_saving' => 0.1,
		'description' => 'In-person training with a facilitator guide, participant materials, and slides.',
		'ai_note' => 'AI helps draft slides, facilitator guides, and participant materials. Design and subject expert time stay much the same.',
	],
	'vilt' => [
		'label' => 'Virtual instructor-led', 'unit' => 'hours', 'dev_hours_per_unit' => 40.0, 'max' => 1000, 'ai_saving' => 0.1,
		'description' => 'Live online training designed for virtual delivery.',
		'ai_note' => 'AI helps draft slides, facilitator guides, and online activities. Design and subject expert time stay much the same.',
	],
	'elearning-read' => [
		'label' => 'E-learning, read', 'unit' => 'hours', 'dev_hours_per_unit' => 50.0, 'max' => 1000, 'ai_saving' => 0.2,
		'description' => 'Mostly text, images, and narration, with a quiz at the end.',
		'ai_note' => 'AI helps write the text, generate narration, and draft quiz questions, with time added back for review.',
	],
	'elearning-interactive' => [
		'label' => 'E-learning, interactive', 'unit' => 'hours', 'dev_hours_per_unit' => 80.0, 'max' => 1000, 'ai_saving' => 0.2,
		'description' => 'Regular activities such as drag and drop, knowledge checks, and short scenarios.',
		'ai_note' => 'AI helps write the content, narration, and knowledge checks. Designing and building the activities is still mostly manual.',
	],
	'elearning-simulation' => [
		'label' => 'E-learning, simulation', 'unit' => 'hours', 'dev_hours_per_unit' => 200.0, 'max' => 1000, 'ai_saving' => 0.1,
		'description' => 'Software simulations, branching scenarios, or games built around realistic practice.',
		'ai_note' => 'AI helps write scripts and scenarios. Building and testing the simulation is still mostly manual.',
	],
	'video-recorded' => [
		'label' => 'Video, recorded', 'unit' => 'minutes', 'dev_hours_per_unit' => 1.0, 'max' => 6000, 'ai_saving' => 0.2,
		'description' => 'Webinar recordings and screen captures with light editing.',
		'ai_note' => 'AI helps with editing, captions, and transcripts, and removes pauses and filler words.',
	],
	'video-produced' => [
		'label' => 'Video, produced', 'unit' => 'minutes', 'dev_hours_per_unit' => 5.0, 'max' => 6000, 'ai_saving' => 0.1,
		'description' => 'Scripted video with human presenters or actors, filmed and edited.',
		'ai_note' => 'AI helps with scripts, storyboards, captions, and rough cuts. Filming and editing are still mostly manual.',
	],
	'video-avatar' => [
		'label' => 'Video, AI avatar', 'unit' => 'minutes', 'dev_hours_per_unit' => 3.0, 'max' => 6000, 'ai_saving' => null,
		'description' => 'Video presented by an AI avatar with a synthetic voice, made with an AI video tool.',
		'ai_note' => 'Already made with AI, so the AI assisted option does not apply.',
	],
];

// Content types from earlier versions, and the type each now maps to.
const TYPE_ALIASES = [
	'self'  => 'elearning-interactive',
	'video' => 'video-produced',
];


// Words for each unit, for labels and messages.
const UNITS = [
	'hours'   => ['plural' => 'hours',   'short' => 'hrs'],
	'minutes' => ['plural' => 'minutes', 'short' => 'mins'],
];

// Share of the content that is being created or reworked.
const EFFORT_LEVELS = [
	'minor' => ['label' => 'Minor updates (25%)',                  'factor' => 0.25],
	'half'  => ['label' => 'Updates to half of the content (50%)', 'factor' => 0.5],
	'major' => ['label' => 'Major updates to content (75%)',       'factor' => 0.75],
	'new'   => ['label' => 'New content from scratch (100%)',      'factor' => 1.0],
];

const WORKING_WEEKS_PER_YEAR = 45;

// Hours of development in one working week. This is less than a full 40-hour
// week, to allow for the meetings, reviews, and admin every developer has.
// Development hours are divided by this to give weeks of work, which the
// headcount is based on. Holidays and leave are covered by WORKING_WEEKS_PER_YEAR.
const HOURS_PER_WEEK = 32;

// Limits
const MAX_ROWS = 200;
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

/**
 * Returns the duration as a float, or an error message. $type is the content
 * type's settings, or null when no valid type has been chosen yet.
 */
function parse_duration(string $raw, ?array $type): float|string
{
	$unit = $type ? UNITS[$type['unit']]['plural'] : null;
	$s = str_replace(',', '.', $raw);
	if (!preg_match('/^\d+(?:\.\d+)?$/', $s)) {
		return $unit ? "Enter a number of $unit, such as 4 or 1.5" : 'Enter a number, such as 4 or 1.5';
	}
	$value = (float)$s;
	if ($value <= 0) {
		return 'Enter a duration greater than zero';
	}
	if ($type && $value > $type['max']) {
		return 'Enter ' . format_number((float)$type['max']) . " $unit or fewer";
	}
	return $value;
}

// -----------------------------------------------------------------------------
// Calculation
// -----------------------------------------------------------------------------

function calculate(array $rows): array
{
	$totalDevHours = 0.0;
	$counted = 0;
	$incomplete = 0;
	$results = [];

	foreach (array_slice($rows, 0, MAX_ROWS) as $row) {
		$row = is_array($row) ? $row : [];
		$name = as_text($row['name'] ?? '');
		// "hours" is the field name used before durations could be in minutes
		$durationRaw = as_text($row['duration'] ?? $row['hours'] ?? '');
		$type = as_text($row['type'] ?? '');
		$type = TYPE_ALIASES[$type] ?? $type;
		$aiAssisted = in_array(as_text($row['ai'] ?? ''), ['1', 'true'], true);
		$effort = as_text($row['effort'] ?? '');

		if ($name === '' && $durationRaw === '' && $type === '' && $effort === '') {
			$results[] = ['status' => 'empty'];
			continue;
		}

		$missing = [];
		$invalid = [];

		if ($name === '') {
			$missing[] = 'an item name';
		} elseif (text_length($name) > MAX_NAME_LENGTH) {
			$invalid['name'] = 'Use ' . MAX_NAME_LENGTH . ' characters or fewer';
		}

		if ($type === '') {
			$missing[] = 'a content type';
		} elseif (!isset(CONTENT_TYPES[$type])) {
			$invalid['type'] = 'Choose a content type from the list';
		}
		$typeSettings = CONTENT_TYPES[$type] ?? null;

		$duration = null;
		if ($durationRaw === '') {
			$missing[] = 'a duration';
		} else {
			$parsed = parse_duration($durationRaw, $typeSettings);
			if (is_string($parsed)) {
				$invalid['duration'] = $parsed;
			} else {
				$duration = $parsed;
			}
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

		$devHours = $duration * $typeSettings['dev_hours_per_unit'] * EFFORT_LEVELS[$effort]['factor'];
		if ($aiAssisted && $typeSettings['ai_saving'] !== null) {
			$devHours *= 1 - $typeSettings['ai_saving'];
		}
		$totalDevHours += $devHours;
		$counted++;
		$results[] = [
			'status' => 'ok',
			'weeks'  => format_number($devHours / HOURS_PER_WEEK),
			'hours'  => format_number($devHours, 1),
		];
	}

	$totalWeeks = $totalDevHours / HOURS_PER_WEEK;
	$headcount = $totalWeeks / WORKING_WEEKS_PER_YEAR;

	if ($counted === 0 && $incomplete === 0) {
		$summary = 'Add an item to see an estimate.';
	} else {
		$summary = $counted === 0
			? 'No items counted.'
			: ucfirst(number_words($counted)) . ($counted === 1 ? ' item' : ' items') . ' counted.';
		if ($incomplete > 0) {
			$summary .= ' ' . ucfirst(number_words($incomplete))
				. ($incomplete === 1 ? ' item is incomplete and is not counted.' : ' items are incomplete and are not counted.');
		}
	}

	return [
		'ok'          => true,
		'rows'        => $results,
		'total_weeks' => format_number($totalWeeks),
		'total_hours' => format_number($totalDevHours, 1),
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
	$types = ['1' => 'ilt', '2' => 'elearning-interactive'];
	$efforts = ['0.25' => 'minor', '0.5' => 'half', '0.75' => 'major', '1' => 'new'];
	$rows = [];
	foreach (array_slice($data['rows'], 0, MAX_ROWS) as $row) {
		if (!is_array($row)) {
			continue;
		}
		$rows[] = [
			'name'     => text_cut(as_text($row['course_name'] ?? ''), MAX_NAME_LENGTH),
			'duration' => text_cut(as_text($row['duration_hours'] ?? ''), 20),
			'type'     => $types[as_text($row['content_type'] ?? '')] ?? '',
			'effort'   => $efforts[as_text($row['level_of_effort'] ?? '')] ?? '',
			'ai'       => false,
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
	<meta name="description" content="Estimate the development work your training plans need, as work hours or as developer headcount.">
	<link rel="icon" type="image/svg+xml" href="<?= h(asset('cedma-logomark-rgb.svg')) ?>">
	<link rel="preload" href="assets/fonts/albert-sans-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
	<link rel="preload" href="assets/fonts/cormorant-garamond-latin-600-normal.woff2" as="font" type="font/woff2" crossorigin>
	<link rel="stylesheet" href="<?= h(asset('fonts.css')) ?>">
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
				<button type="button" class="btn btn-secondary" id="clear-all">Clear all</button>
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
			<p class="formula">Work hours = duration × development hours for the content type × level of effort</p>
			<p class="formula">AI-assisted items = work hours less the AI saving for the content type</p>
			<p class="formula">Weeks of work = work hours ÷ <?= HOURS_PER_WEEK ?> development hours a week</p>
			<p class="formula">Headcount = weeks of work ÷ <?= WORKING_WEEKS_PER_YEAR ?> working weeks a year</p>
			<p>The figures are a planning guide, not a quote. The development hours for each content type are based on published industry figures for new content. The AI savings are planning estimates, not measured figures, and allow for the extra review AI-generated content needs.</p>
			<p>A week of work is <?= HOURS_PER_WEEK ?> hours of development, which allows for the meetings, reviews, and admin in a normal working week. The <?= WORKING_WEEKS_PER_YEAR ?> working weeks a year allow for holidays and leave. To show a backlog, compare the work hours with the time your team actually has free for development.</p>
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
