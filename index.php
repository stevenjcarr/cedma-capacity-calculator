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
//   dev_hours_per_unit  hours of development per unit of finished new content
//   basis               what the figure is based on, shown on the page (the
//                       figures themselves are never shown)
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
		'basis' => 'Published research (Chapman Alliance, LearningSim) and the CEdMA Business Metrics Survey',
		'ai_note' => 'AI helps draft slides, facilitator guides, and participant materials. Design and subject expert time stay much the same.',
	],
	'vilt' => [
		'label' => 'Virtual instructor-led', 'unit' => 'hours', 'dev_hours_per_unit' => 40.0, 'max' => 1000, 'ai_saving' => 0.1,
		'description' => 'Live online training designed for virtual delivery.',
		'basis' => 'CEdMA Business Metrics Survey',
		'ai_note' => 'AI helps draft slides, facilitator guides, and online activities. Design and subject expert time stay much the same.',
	],
	'elearning-read' => [
		'label' => 'E-learning, read', 'unit' => 'hours', 'dev_hours_per_unit' => 50.0, 'max' => 1000, 'ai_saving' => 0.2,
		'description' => 'Mostly text, images, and narration, with a quiz at the end.',
		'basis' => 'CEdMA estimate, between the CEdMA Business Metrics Survey and published research (Chapman Alliance)',
		'ai_note' => 'AI helps write the text, generate narration, and draft quiz questions, with time added back for review.',
	],
	'elearning-interactive' => [
		'label' => 'E-learning, interactive', 'unit' => 'hours', 'dev_hours_per_unit' => 80.0, 'max' => 1000, 'ai_saving' => 0.2,
		'description' => 'Regular activities such as drag and drop, knowledge checks, and short scenarios.',
		'basis' => 'CEdMA estimate, between the CEdMA Business Metrics Survey and published research (Chapman Alliance)',
		'ai_note' => 'AI helps write the content, narration, and knowledge checks. Designing and building the activities is still mostly manual.',
	],
	'elearning-simulation' => [
		'label' => 'E-learning, simulation', 'unit' => 'hours', 'dev_hours_per_unit' => 200.0, 'max' => 1000, 'ai_saving' => 0.1,
		'description' => 'Software simulations, branching scenarios, or games built around realistic practice.',
		'basis' => 'CEdMA estimate, below the published range (Chapman Alliance) for the most advanced simulations',
		'ai_note' => 'AI helps write scripts and scenarios. Building and testing the simulation is still mostly manual.',
	],
	'video-recorded' => [
		'label' => 'Video, recorded', 'unit' => 'minutes', 'dev_hours_per_unit' => 1.0, 'max' => 6000, 'ai_saving' => 0.2,
		'description' => 'Webinar recordings and screen captures with light editing.',
		'basis' => 'CEdMA estimate. No independent published figures',
		'ai_note' => 'AI helps with editing, captions, and transcripts, and removes pauses and filler words.',
	],
	'video-produced' => [
		'label' => 'Video, produced', 'unit' => 'minutes', 'dev_hours_per_unit' => 5.0, 'max' => 6000, 'ai_saving' => 0.1,
		'description' => 'Scripted video with human presenters or actors, filmed and edited.',
		'basis' => 'Practitioner figure (ATD)',
		'ai_note' => 'AI helps with scripts, storyboards, captions, and rough cuts. Filming and editing are still mostly manual.',
	],
	'video-avatar' => [
		'label' => 'Video, AI avatar', 'unit' => 'minutes', 'dev_hours_per_unit' => 3.0, 'max' => 6000, 'ai_saving' => null,
		'description' => 'Video presented by an AI avatar with a synthetic voice, made with an AI video tool.',
		'basis' => 'CEdMA estimate. No independent published figures',
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
// week, to allow for the regular meetings and admin every developer has.
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

/**
 * Works out the estimate. With $withDetail, each row also carries the values
 * the CSV export needs; the page itself never receives these.
 */
function calculate(array $rows, bool $withDetail = false): array
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
		$detail = ['name' => $name, 'type' => $type, 'duration_raw' => $durationRaw, 'effort' => $effort, 'ai' => $aiAssisted];

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
			$result = ['status' => 'incomplete', 'note' => $note, 'invalid' => (object)$invalid];
			if ($withDetail) {
				$result['detail'] = $detail + ['duration' => $duration, 'invalid' => $invalid, 'note' => $note];
			}
			$results[] = $result;
			continue;
		}

		$devHours = $duration * $typeSettings['dev_hours_per_unit'] * EFFORT_LEVELS[$effort]['factor'];
		if ($aiAssisted && $typeSettings['ai_saving'] !== null) {
			$devHours *= 1 - $typeSettings['ai_saving'];
		}
		$totalDevHours += $devHours;
		$counted++;
		$result = [
			'status' => 'ok',
			'weeks'  => format_number($devHours / HOURS_PER_WEEK),
			'hours'  => format_number($devHours, 1),
		];
		if ($withDetail) {
			$result['detail'] = $detail + ['duration' => $duration, 'dev_hours' => $devHours];
		}
		$results[] = $result;
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

	$output = [
		'ok'          => true,
		'rows'        => $results,
		'total_weeks' => format_number($totalWeeks),
		'total_hours' => format_number($totalDevHours, 1),
		'headcount'   => number_format($headcount, 1, '.', ','),
		'counted'     => $counted,
		'incomplete'  => $incomplete,
		'summary'     => $summary,
	];
	if ($withDetail) {
		$output['raw'] = ['dev_hours' => $totalDevHours, 'weeks' => $totalWeeks, 'headcount' => $headcount];
	}
	return $output;
}

// -----------------------------------------------------------------------------
// CSV export
// -----------------------------------------------------------------------------

/** A number for a spreadsheet: no thousands separator, trailing zeros dropped. */
function plain_number(float $n, int $maxDecimals): string
{
	$s = number_format($n, $maxDecimals, '.', '');
	if (str_contains($s, '.')) {
		$s = rtrim(rtrim($s, '0'), '.');
	}
	return $s;
}

/**
 * One CSV cell. Text that a spreadsheet would treat as a formula is prefixed
 * with an apostrophe, so a value like "=SUM(A1)" stays as text.
 */
function csv_cell(string $value, bool $isText = true): string
{
	if ($isText && preg_match('/^[=+\-@\t\r]/', $value)) {
		$value = "'" . $value;
	}
	if (preg_match('/[",\r\n]/', $value)) {
		$value = '"' . str_replace('"', '""', $value) . '"';
	}
	return $value;
}

/** Each row is a list of [value, isText] pairs, or plain strings for text. */
function csv_line(array $cells): string
{
	return implode(',', array_map(
		fn($c) => is_array($c) ? csv_cell((string)$c[0], (bool)$c[1]) : csv_cell((string)$c),
		$cells
	)) . "\r\n";
}

function build_csv(array $rows): string
{
	$result = calculate($rows, true);
	$csv = "\xEF\xBB\xBF"; // byte order mark, so Excel reads the file as UTF-8
	$csv .= csv_line(['Item name', 'Content type', 'Duration', 'Duration unit', 'Level of effort', 'AI assisted', 'Work hours', 'Weeks of work', 'Status']);

	foreach ($result['rows'] as $row) {
		if ($row['status'] === 'empty') {
			continue;
		}
		$d = $row['detail'];
		$type = CONTENT_TYPES[$d['type']] ?? null;
		$effort = EFFORT_LEVELS[$d['effort']] ?? null;
		$duration = $d['duration'] !== null ? [plain_number($d['duration'], 2), false] : $d['duration_raw'];
		$ai = $type && $type['ai_saving'] === null ? 'Does not apply' : ($d['ai'] ? 'Yes' : 'No');

		if ($row['status'] === 'ok') {
			$hours = [plain_number($d['dev_hours'], 1), false];
			$weeks = [plain_number($d['dev_hours'] / HOURS_PER_WEEK, 2), false];
			$status = 'Counted';
		} else {
			$hours = '';
			$weeks = '';
			$reasons = array_filter(array_merge([$d['note']], array_values($d['invalid'])));
			$status = 'Not counted. ' . implode('. ', $reasons);
		}

		$csv .= csv_line([
			$d['name'],
			$type ? $type['label'] : $d['type'],
			$duration,
			$type ? ucfirst(UNITS[$type['unit']]['plural']) : '',
			$effort ? $effort['label'] : $d['effort'],
			$ai,
			$hours,
			$weeks,
			$status,
		]);
	}

	$raw = $result['raw'];
	$csv .= "\r\n";
	$csv .= csv_line(['Total work hours', [plain_number($raw['dev_hours'], 1), false]]);
	$csv .= csv_line(['Total weeks of work', [plain_number($raw['weeks'], 2), false]]);
	$csv .= csv_line(['Estimated developer headcount', [number_format($raw['headcount'], 1, '.', ''), false]]);
	$csv .= csv_line(['Items counted', [(string)$result['counted'], false]]);
	$csv .= csv_line(['Items not counted', [(string)$result['incomplete'], false]]);
	$csv .= "\r\n";
	$csv .= csv_line(['Development hours a week', [(string)HOURS_PER_WEEK, false]]);
	$csv .= csv_line(['Working weeks a year', [(string)WORKING_WEEKS_PER_YEAR, false]]);
	$csv .= csv_line(['Exported', gmdate('j F Y')]);
	$csv .= csv_line(['Source', 'CEdMA Enterprise capacity calculator']);
	return $csv;
}

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
			<p class="formula">Work hours = duration × development hours for the content type × level of effort</p>
			<p class="formula">AI-assisted items = work hours less the AI saving for the content type</p>
			<p class="formula">Weeks of work = work hours ÷ <?= HOURS_PER_WEEK ?> development hours a week</p>
			<p class="formula">Headcount = weeks of work ÷ <?= WORKING_WEEKS_PER_YEAR ?> working weeks a year</p>
			<p>The figures are a planning guide, not a quote. The development hours for each content type are for new content. The table below shows what each one is based on, and the sources are listed at the end of the page. Where no reliable published figure exists, CEdMA has set an estimate. The AI savings are CEdMA planning estimates, not measured figures, and allow for the extra review AI-generated content needs.</p>
			<p>A week of work is <?= HOURS_PER_WEEK ?> hours of development, which allows for the regular meetings and admin in a normal working week. The <?= WORKING_WEEKS_PER_YEAR ?> working weeks a year allow for holidays and leave. To show a backlog, compare the work hours with the time your team actually has free for development.</p>
			<h2 id="types-heading">Content types</h2>
			<p>What each content type covers, what its development hours are based on, and what the AI assisted option means for it.</p>
			<div class="types-wrap">
				<table class="types-table">
					<thead>
					<tr>
						<th scope="col">Content type</th>
						<th scope="col">Duration in</th>
						<th scope="col">What it covers</th>
						<th scope="col">Based on</th>
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
						<td data-label="Based on"><?= h($item['basis']) ?></td>
						<td data-label="With AI assisted"><?= h($item['ai_note']) ?></td>
						<td data-label="AI saving" class="num<?= $item['ai_saving'] === null ? ' na' : '' ?>"><?= $item['ai_saving'] === null ? 'Does not apply' : h((string)(int)round($item['ai_saving'] * 100)) . '%' ?></td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<h2 id="sources-heading">Sources</h2>
			<ul class="sources">
				<li>Bryan Chapman, <a href="https://www.slideshare.net/slideshow/how-long-does-it-take-to-create-learning/5198860"><cite>How Long Does It Take to Create Learning?</cite></a>, Chapman Alliance, September 2010. A study of 249 organizations, counting time from everyone involved, including subject experts, media staff, and project managers. Used for instructor-led training, and as the upper reference for e-learning.</li>
				<li>Steve Semler, <a href="https://www.learningsim.com/2024-cost-estimates-for-learning-design/"><cite>2024 Cost Estimates for Learning Design</cite></a>, LearningSim, February 2024. Used for instructor-led and virtual instructor-led training, and as a guide to the AI savings.</li>
				<li><cite>CEdMA Business Metrics Survey</cite>, CEdMA Enterprise, November 2026. Results from CEdMA members. Used for instructor-led and virtual instructor-led training, and as the lower reference for e-learning.</li>
				<li><a href="https://www.td.org/insights/dont-waste-your-money-when-developing-training-videos"><cite>Don't Waste Your Money When Developing Training Videos</cite></a>, ATD Blog, November 2015. Used for produced video.</li>
				<li>Robyn A. Defelice, <a href="https://td.org/insights/how-long-does-it-take-to-develop-training-new-question-new-answers"><cite>How Long Does It Take to Develop Training? New Question, New Answers</cite></a>, ATD, January 2021. Background reading. It reports time to develop a typical unit of training rather than an hour, so it is not used for the figures.</li>
			</ul>
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
