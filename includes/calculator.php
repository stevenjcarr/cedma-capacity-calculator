<?php
/**
 * CEdMA Enterprise capacity calculator: settings, calculation, and CSV export.
 *
 * Included by index.php and sources.php. The ratios here are never sent to the
 * browser; pages only ever receive results.
 */
declare(strict_types=1);

// This file is only ever included. Asked for directly, it shows nothing.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
	http_response_code(404);
	exit;
}

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
	$path = dirname(__DIR__) . '/assets/' . $file;
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
// Lists saved by the previous version
// -----------------------------------------------------------------------------

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
