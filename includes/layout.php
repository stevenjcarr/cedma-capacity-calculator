<?php
/**
 * Shared page layout: the document head, the site header, and the footer.
 */
declare(strict_types=1);

// This file is only ever included. Asked for directly, it shows nothing.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
	http_response_code(404);
	exit;
}

/** Outputs everything up to the start of the page content. */
function page_start(string $title, string $description): void
{
	?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= h($title) ?> · CEdMA Enterprise</title>
	<meta name="description" content="<?= h($description) ?>">
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
<?php
}

/** Outputs the footer. Pages add their own scripts and close body and html. */
function page_footer(): void
{
	?>
<footer class="site-footer">
	<div class="wrap">
		<span>CEdMA Enterprise · Setting the Standard for Customer Education</span>
		<nav class="footer-links" aria-label="Footer">
			<a href="sources.php">Sources for the figures</a>
			<a href="https://cedma-enterprise.org">cedma-enterprise.org</a>
		</nav>
	</div>
</footer>
<?php
}
