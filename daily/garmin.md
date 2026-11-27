# Sync runs from Garmin

The private log page and Workout tab can fetch runs through Garmin's official Personal API. The button checks the last 30 calendar days, including today, and saves runs under their Garmin-recorded dates. Running subtypes such as treadmill running are included. Other activity types are excluded.

1. Create a Personal API key in [Garmin Connect settings](https://connect.garmin.com/app/settings/personalApiToken). Garmin displays the key once.
2. Save it as a text file **outside the public website directory**, for example `/home/ACCOUNT/daily-garmin-key.txt`. Give only your hosting account access to the file (`chmod 600` on Linux).
3. In your private Daily configuration, add `'garmin_key_file' => '/home/ACCOUNT/daily-garmin-key.txt'`. Keep this path and the key out of public source and browser code. The default, if omitted, is `daily-garmin-key.txt` beside `daily-config.php` above the public website directory.
4. Sign in to your log page. Tap **Sync Garmin run** after the activity has uploaded from your watch to Garmin Connect.

The server caches a short-lived Garmin access token in `daily-garmin-state.json` beside the key. This file also stays private. You can override its location with `garmin_cache_file`. Expired access tokens renew automatically; an expired or revoked Personal API key must be replaced in the private key file.

Runs are matched by Garmin activity ID. Repeated taps update the same activity rather than adding another run. Existing coach names and notes are retained, and missing measurements remain unknown. Calendar totals, workout history and the coach JSON feed use the saved runs immediately. This changes the hosted tracker, not a coaching JSON file on your computer.

This integration needs PHP cURL. It makes no AI calls and schedules no polling. The Personal API is pull-only; Garmin's business API offers separate push-based integrations. See [Garmin's program overview](https://developer.garmin.com/gc-developer-program/overview/).
