<?php
/**
 * CEdMA Enterprise capacity calculator: where the figures come from.
 *
 * Shows what each content type's development hours are based on, and the
 * sources. The figures themselves stay on the server and are never shown.
 */
declare(strict_types=1);

require __DIR__ . '/includes/calculator.php';
require __DIR__ . '/includes/layout.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
	http_response_code(405);
	header('Allow: GET');
	exit;
}

header('X-Content-Type-Options: nosniff');
?>
<?php page_start('Sources for the capacity calculator', 'Where the development hours behind the CEdMA Enterprise capacity calculator come from.'); ?>

<main>
	<div class="wrap">
		<p class="back-link"><a href="./">Back to the calculator</a></p>

		<div class="page-head">
			<p class="label">Capacity calculator</p>
			<h1>Where the <em>figures</em> come from</h1>
			<p class="lead">The development hours behind each content type, what they are based on, and the sources used.</p>
		</div>

		<section class="method" aria-labelledby="basis-heading">
			<h2 id="basis-heading">What each content type is based on</h2>
			<p>Where no reliable published figure exists, CEdMA has set an estimate. The AI savings, the <?= HOURS_PER_WEEK ?> development hours a week, and the <?= WORKING_WEEKS_PER_YEAR ?> working weeks a year are also CEdMA estimates.</p>
			<div class="types-wrap">
				<table class="types-table basis-table">
					<thead>
					<tr>
						<th scope="col">Content type</th>
						<th scope="col">Based on</th>
					</tr>
					</thead>
					<tbody>
					<?php foreach (CONTENT_TYPES as $item): ?>
					<tr>
						<th scope="row" data-label="Content type"><?= h($item['label']) ?></th>
						<td data-label="Based on"><?= h($item['basis']) ?></td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p>Most published figures for e-learning predate today's authoring tools, which have made building e-learning much quicker. The bulk of the time now goes on analysis and design rather than the build, which is why CEdMA's e-learning estimates sit below older published figures such as Chapman Alliance (2010) and ATD (2021). ATD's 2023 survey also reported shorter development times than its 2020 survey for every type of training, though it does not say why.</p>

			<h2 id="sources-heading">Sources</h2>
			<ul class="sources">
				<li>Bryan Chapman, <a href="https://www.slideshare.net/slideshow/how-long-does-it-take-to-create-learning/5198860"><cite>How Long Does It Take to Create Learning?</cite></a>, Chapman Alliance, September 2010. A study of 249 organizations, counting time from everyone involved, including subject experts, media staff, and project managers. Used for instructor-led training, and as the upper reference for e-learning.</li>
				<li>Steve Semler, <a href="https://www.learningsim.com/2024-cost-estimates-for-learning-design/"><cite>2024 Cost Estimates for Learning Design</cite></a>, LearningSim, February 2024. Used for instructor-led and virtual instructor-led training, and as a guide to the AI savings.</li>
				<li><cite>CEdMA Business Metrics Survey</cite>, CEdMA Enterprise, November 2026. Results from CEdMA members. Used for instructor-led and virtual instructor-led training, and as the lower reference for e-learning.</li>
				<li><a href="https://www.td.org/insights/dont-waste-your-money-when-developing-training-videos"><cite>Don't Waste Your Money When Developing Training Videos</cite></a>, ATD Blog, November 2015. Used for produced video.</li>
				<li>Robyn A. Defelice, <a href="https://td.org/insights/how-long-does-it-take-to-develop-training-new-question-new-answers"><cite>How Long Does It Take to Develop Training? New Question, New Answers</cite></a>, ATD, January 2021. A survey of 264 respondents, using 2020 data. It reports the hours to develop a typical unit of 17 to 26 minutes, not an hour. Converted to an hour, its figures are well above the calculator's, but the conversion is too uncertain to use them directly, so it is background reading.</li>
				<li>Robyn A. Defelice, <a href="https://www.td.org/content/td-magazine/want-to-speed-up-training-development-time"><cite>Want to Speed Up Training Development Time?</cite></a>, TD Magazine, ATD, November 2023. A survey of 115 respondents, using early 2023 data. It reports the hours to create each type of training product, but not the module lengths, so its figures cannot be converted to an hour. Background reading.</li>
			</ul>
		</section>
	</div>
</main>

<?php page_footer(); ?>
</body>
</html>
