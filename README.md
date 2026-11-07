# CEdMA Enterprise capacity calculator

A small web app that estimates how much development work a list of training items needs. The estimate can be shown two ways:

- **Headcount** gives the weeks of work and the number of developers needed for a year, for making the case that building X will take Y people.
- **Work hours** gives the total hours of work and the weeks, for showing a backlog against the time a team actually has free.

Each person's choice of view is remembered in their browser.

People enter each item with its content type, its duration, the level of effort, and whether it is AI assisted. Durations are in hours, apart from video, which is in minutes. The content types are instructor-led, virtual instructor-led, three levels of e-learning (read, interactive, and simulation), and three kinds of video (recorded, produced, and AI avatar). A table at the bottom of the page describes each type and what the AI assisted option means for it. The page sends the list to `index.php`, which does the calculation and returns the results. The multipliers stay on the server and are never sent to the browser, although the page explains the formula.

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

- `CONTENT_TYPES` lists the content types. Each one has the unit its duration is entered in (`hours` or `minutes`), the hours of development per unit of finished new content (`dev_hours_per_unit`), the longest duration accepted (`max`), and a description shown on the page. The figures are based on published industry figures:

  | Content type | Development hours | AI saving |
  | --- | --- | --- |
  | Instructor-led | 40 per hour | 10% |
  | Virtual instructor-led | 40 per hour | 10% |
  | E-learning, read | 50 per hour | 20% |
  | E-learning, interactive | 80 per hour | 20% |
  | E-learning, simulation | 200 per hour | 10% |
  | Video, recorded | 1 per minute | 20% |
  | Video, produced | 5 per minute | 10% |
  | Video, AI avatar | 3 per minute | Does not apply |

  Each type's `ai_saving` is the reduction when an item is marked AI assisted. These are planning estimates that allow for the extra review AI-generated content needs. Set it to `null` for a type where the option does not apply, and the checkbox is turned off for that type. The `description` and `ai_note` text appear in the content types table at the bottom of the page.

- `TYPE_ALIASES` maps content types from earlier versions to their new names, so saved lists still load. Self-paced became E-learning, interactive, and Video became Video, produced, at the same figures.
- `EFFORT_LEVELS` sets the share of the content being created or reworked for each level of effort.
- `HOURS_PER_WEEK` sets the hours of development in a working week, currently 32. That is less than a full 40-hour week, to allow for meetings, reviews, and admin, so the headcount reflects the time developers actually spend developing. Development hours are divided by this to give weeks of work.
- `WORKING_WEEKS_PER_YEAR` sets the working year used for the headcount, currently 45, which allows for holidays and leave.

The calculation is: work hours = duration × development hours per unit × level of effort, less the type's `ai_saving` for AI-assisted items; weeks = work hours ÷ `HOURS_PER_WEEK`; headcount = weeks ÷ `WORKING_WEEKS_PER_YEAR`.

To add another content type, add a line to `CONTENT_TYPES`. A new unit also needs an entry in `UNITS`.

The labels in the dropdowns come from the same settings, so a change there updates the page as well. The explanation on the page, including which units each content type uses, is built from these settings too.

## Saved data

Each person's list is saved in their own browser (local storage), so it is still there when they come back. Nothing is stored on the server. Lists saved by the previous version of the calculator, which used a cookie, are converted the first time someone visits.

Questions: community@cedma-enterprise.org
