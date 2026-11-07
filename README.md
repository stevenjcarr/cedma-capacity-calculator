# CEdMA Enterprise capacity calculator

A small web app that estimates how many weeks of development work a list of courses needs, and how many developers that represents over a year.

People enter each course with its duration in hours, its content type, and the level of effort. The page sends the list to `index.php`, which does the calculation and returns the results. The multipliers stay on the server and are never sent to the browser, although the page explains the formula.

## Files

| File | Purpose |
| --- | --- |
| `index.php` | The page, and the calculation endpoint it posts to |
| `assets/calculator.css` | Styles, following the CEdMA Enterprise Brand Guidelines v2.2 |
| `assets/calculator.js` | Table editing, saving in the browser, and calls to `index.php` |
| `assets/*.svg` | Logo artwork (Color dark full logo, Color dark logomark for the emblem, Color logomark for the favicon) |

## Requirements

PHP 8.0 or later, with the standard JSON and PCRE support. It has been tested on PHP 8.3 and 8.5. The mbstring extension is used when it is available but is not required. There is no database and nothing to install.

## Deploying

Upload `index.php` and the `assets` folder to the same directory on the web server. The fonts load from Google Fonts.

## Changing the figures

The settings are at the top of `index.php`:

- `CONTENT_TYPES` sets the weeks of development per hour of finished content for each content type.
- `EFFORT_LEVELS` sets the share of the content being created or reworked for each level of effort.
- `WORKING_WEEKS_PER_YEAR` sets the working year used for the headcount.

The labels in the dropdowns come from the same settings, so a change there updates the page as well. If you change the working year, the explanation on the page updates with it.

## Saved data

Each person's course list is saved in their own browser (local storage), so it is still there when they come back. Nothing is stored on the server. Lists saved by the previous version of the calculator, which used a cookie, are converted the first time someone visits.

Questions: community@cedma-enterprise.org
