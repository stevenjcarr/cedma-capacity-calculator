# CEdMA Enterprise capacity calculator

A small web app that estimates how much development work a list of training items needs. The estimate can be shown two ways:

- **Headcount** gives the weeks of work and the number of developers needed for a year, for making the case that building X will take Y people.
- **Work hours** gives the total hours of work and the weeks, for showing a backlog against the time a team actually has free.

Each person's choice of view is remembered in their browser.

People enter each item with its content type, its duration, the level of effort, and whether it is AI assisted. Durations are in hours, apart from video, which is in minutes. The content types are instructor-led, virtual instructor-led, three levels of e-learning (read, interactive, and simulation), and three kinds of video (recorded, produced, and AI avatar). A table at the bottom of the page describes each type and what the AI assisted option means for it. The page sends the list to `index.php`, which does the calculation and returns the results. The multipliers stay on the server and are never sent to the browser, although the page explains the formula.

## Files

| File | Purpose |
| --- | --- |
| `index.php` | The calculator page, and the calculation endpoint it posts to |
| `sources.php` | Where the figures come from: what each content type is based on, and the sources |
| `includes/calculator.php` | The settings (content types, ratios, AI savings), the calculation, and the CSV export, shared by both pages. Never sent to the browser |
| `includes/layout.php` | The page head, site header, and footer shared by both pages |
| `includes/.htaccess` | Stops the include files being requested directly on Apache servers. The files also refuse direct requests themselves |
| `assets/calculator.css` | Styles, following the CEdMA Enterprise Brand Guidelines v2.2 |
| `assets/calculator.js` | Table editing, saving in the browser, and calls to `index.php` |
| `assets/fonts.css` and `assets/fonts/` | The brand fonts (Cormorant Garamond, Albert Sans, and DM Mono) in the weights the page uses, with their SIL Open Font License files |
| `assets/*.svg` | Logo artwork (Color dark full logo, Color dark logomark for the emblem, Color logomark for the favicon) |

## Requirements

PHP 8.0 or later, with the standard JSON and PCRE support. It has been tested on PHP 8.3 and 8.5. The mbstring extension is used when it is available but is not required. There is no database and nothing to install.

## Deploying

Upload `index.php`, `sources.php`, and the `includes` and `assets` folders to the same directory on the web server. Everything the pages need is in those files, including the brand fonts, so nothing loads from another site.

## Changing the figures

The settings are at the top of `includes/calculator.php`:

- `CONTENT_TYPES` lists the content types. Each one has the unit its duration is entered in (`hours` or `minutes`), the hours of development per unit of finished new content (`dev_hours_per_unit`), the longest duration accepted (`max`), a description shown on the page, and the `basis` the figure rests on. The sources page shows the basis and the sources, but neither page ever shows the figures themselves:

  | Content type | Development hours | AI saving | Based on |
  | --- | --- | --- | --- |
  | Instructor-led | 40 per hour | 10% | Chapman Alliance 2010 (average 43), LearningSim 2024 (30 to 40), and the CEdMA Business Metrics Survey (median 40) |
  | Virtual instructor-led | 40 per hour | 10% | CEdMA Business Metrics Survey (median 40). LearningSim 2024 gives 20 to 30 |
  | E-learning, read | 50 per hour | 20% | CEdMA estimate, between the survey median (35) and Chapman Alliance's Level 1 average (79) |
  | E-learning, interactive | 80 per hour | 20% | CEdMA estimate, between the survey median (46) and Chapman Alliance's Level 2 average (184) |
  | E-learning, simulation | 200 per hour | 10% | CEdMA estimate, below Chapman Alliance's Level 3 range (217 to 716). Not surveyed |
  | Video, recorded | 1 per minute | 20% | CEdMA estimate. No independent published figures |
  | Video, produced | 5 per minute | 10% | ATD Blog, November 2015 ("about four to five hours" per finished minute) |
  | Video, AI avatar | 3 per minute | Does not apply | CEdMA estimate. No independent published figures |

  Most published e-learning figures predate today's authoring tools, which have made the build much quicker, so CEdMA's view is that analysis and design now take most of the time. That is why the e-learning estimates sit below the older published figures (Chapman Alliance 2010, and ATD 2021 converted from hours per unit). ATD's 2023 survey (TD Magazine, November 2023) reported lower times than its 2020 survey for every type, but it does not say what unit its figures are in or report module lengths, so it cannot be compared directly. The sources page says so.

  The full source list is on the sources page (`sources.php`). The AI savings are CEdMA planning estimates; LearningSim 2024 (20 to 35% for e-learning, and design time "about the same" for instructor-led) was used as a guide.

  Each type's `ai_saving` is the reduction when an item is marked AI assisted. These are planning estimates that allow for the extra review AI-generated content needs. Set it to `null` for a type where the option does not apply, and the checkbox is turned off for that type. The `description` and `ai_note` text appear in the content types table at the bottom of the page.

- `TYPE_ALIASES` maps content types from earlier versions to their new names, so saved lists still load. Self-paced became E-learning, interactive, and Video became Video, produced, at the same figures.
- `EFFORT_LEVELS` sets the share of the content being created or reworked for each level of effort.
- `HOURS_PER_WEEK` sets the hours of development in a working week, currently 32. That is less than a full 40-hour week, to allow for regular meetings and admin, so the headcount reflects the time developers actually spend developing. Development hours are divided by this to give weeks of work.
- `WORKING_WEEKS_PER_YEAR` sets the working year used for the headcount, currently 45, which allows for holidays and leave.

The calculation is: work hours = duration × development hours per unit × level of effort, less the type's `ai_saving` for AI-assisted items; weeks = work hours ÷ `HOURS_PER_WEEK`; headcount = weeks ÷ `WORKING_WEEKS_PER_YEAR`.

To add another content type, add a line to `CONTENT_TYPES`. A new unit also needs an entry in `UNITS`.

The labels in the dropdowns come from the same settings, so a change there updates the page as well. The explanation on the page, including which units each content type uses, is built from these settings too.

## Downloading a CSV file

The **Download CSV** button saves the list as a CSV file, named with the day's date, which opens in Excel, Google Sheets, or Numbers. The file is built in `index.php` from the same calculation as the page, so the figures always match. It has one row per item, with its content type, duration and unit, level of effort, whether it is AI assisted, its work hours and weeks, and whether it was counted (with the reason if not). Below the items are the totals (work hours, weeks, headcount, and how many items were and were not counted), followed by the hours a week and working weeks a year the estimate uses.

Numbers are written without thousands separators so spreadsheets treat them as numbers. Item names that a spreadsheet would read as a formula, such as one starting with `=`, are saved as text.

## Saved data

Each person's list is saved in their own browser (local storage), so it is still there when they come back. Nothing is stored on the server. Lists saved by the previous version of the calculator, which used a cookie, are converted the first time someone visits.

Questions: community@cedma-enterprise.org
