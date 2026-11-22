# Automatic processing with OpenRouter

Habit saves the original note before calling the model. The server validates the returned JSON and saves it against the note ID and revision. Editing a note recalculates that note; retries cannot add a second copy.

## Setup

Use PHP 8.2+ with PDO, cURL and mbstring. In the private configuration file, set:

```php
'processor' => 'openrouter',
'openrouter_api_key' => 'YOUR_PRIVATE_KEY',
'openrouter_model' => 'google/gemini-3.8-flash',
'openrouter_monthly_budget' => 5,
'workout_minutes' => 60,
'training_goals' => [],
```

Keep that file outside the web root and Git. Create a dedicated OpenRouter key with a $5 monthly limit. Habit also checks its recorded monthly usage before each request. Provider pricing can change; usage and token counts are recorded in the private daily_ai_usage table. Requests use structured outputs and paid providers that do not use the data for training; this is not a zero-retention guarantee.

If PHP cURL reports a certificate error, configure curl.cainfo with a CA bundle, or set openrouter_ca_file to its absolute path in the private Habit config.

Apply daily/schema.sql when initializing the database. Existing installations get the three additive processing, plan, and usage tables on their first processing request or worker run. Existing processed notes are not recalculated.

On LiteSpeed or PHP-FPM, the save receipt returns before processing finishes. Other PHP servers flush the saved response before processing; their ability to serve concurrent requests depends on the server. Original notes remain saved if the request is interrupted.

Schedule the following command every minute as a recovery task, using absolute paths:

```sh
DAILY_CONFIG=/private/path/daily-config.php /usr/bin/php /website/public/daily/worker.php
```

The worker can also find the standard daily-config.php above the web root, or .daily-data/config.php in a local checkout. It only runs from the command line. It retries temporary failures up to three times. Failed notes show a private Retry control. Water-only notes and the Android water button do not call a model.

For local use, run php public/daily/worker.php when you want to recover an interrupted request. Keep the API server running for automatic processing on save.

## Food values

User-supplied label values take priority. The model returns a nutrition basis and the amount eaten in the same unit; PHP scales calories and macros. A small matching selection of previous foods helps consistency, but previous estimates remain estimates. Optionally configure food_reference_file with a private JSON object containing a foods array; each item has id, name, quantity, nutrition, and basis (amount and unit). Use Edit for corrections to existing notes.

## Today and workout suggestions

/today follows public_calendar. Visitors can read processed entries and a saved suggestion when the calendar is public. Only an unlocked owner can generate or replace a suggestion. Opening the page never generates one.

The generator reads the latest seven training dates, fixed sessions, recorded recovery, preferences and an optional equipment_file. Configure your own goals and session length; personal goals, gym inventory, schedules and logs are not bundled. A generated suggestion stays planned and appears in each day’s optional recommendation field in /daily/coach.json?days=7; existing completed and planned session formats stay unchanged. It does not become a completed workout.

The API adds GET today and authenticated POST generate-workout and retry-note. Existing note responses optionally include processing.state, attempts and error. Android clients continue using the existing save and widget actions.

## External processing

Set processor to external to use Codex or another processor. An installation without processor retains this mode for compatibility. Follow processor.md for pending and commit. The OpenRouter worker and external bridge cannot process the queue at the same time. The optional auto_sync bridge setting applies to external mode only.

Image input is supported by the selected model, but photo upload is not included in this release.
