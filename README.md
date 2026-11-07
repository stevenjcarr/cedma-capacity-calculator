# CEdMA Enterprise capacity calculator

A small web app that estimates how much development work a list of courses needs. The estimate can be shown two ways:

- **Headcount** gives the weeks of work and the number of developers needed for a year, for making the case that building X will take Y people.
- **Work hours** gives the total hours of work and the weeks, for showing a backlog against the time a team actually has free.

Each person's choice of view is remembered in their browser.

People enter each course with its content type, its duration, and the level of effort. Durations are in hours, apart from video, which is in minutes. The page sends the list to `index.php`, which does the calculation and returns the results. The multipliers stay on the server and are never sent to the browser, although the page explains the formula.

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

- `CONTENT_TYPES` lists the content types. Each one has the unit its duration is entered in (`hours` or `minutes`), the hours of development per unit of finished content (`dev_hours_per_unit`), and the longest duration accepted (`max`). Instructor-led is 40 development hours per hour, self-paced is 80 per hour, and video is five per minute.
- `EFFORT_LEVELS` sets the share of the content being created or reworked for each level of effort.
- `HOURS_PER_WEEK` sets the hours of development in a working week, currently 32. That is less than a full 40-hour week, to allow for meetings, reviews, and admin, so the headcount reflects the time developers actually spend developing. Development hours are divided by this to give weeks of work.
- `WORKING_WEEKS_PER_YEAR` sets the working year used for the headcount, currently 45, which allows for holidays and leave.

The calculation is: work hours = duration × development hours per unit × level of effort; weeks = work hours ÷ `HOURS_PER_WEEK`; headcount = weeks ÷ `WORKING_WEEKS_PER_YEAR`.

To add another content type, add a line to `CONTENT_TYPES`. A new unit also needs an entry in `UNITS`.

The labels in the dropdowns come from the same settings, so a change there updates the page as well. The explanation on the page, including which units each content type uses, is built from these settings too.

## Saved data

Each person's course list is saved in their own browser (local storage), so it is still there when they come back. Nothing is stored on the server. Lists saved by the previous version of the calculator, which used a cookie, are converted the first time someone visits.

Questions: community@cedma-enterprise.org
