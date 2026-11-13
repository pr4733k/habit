# Habit: Minimal daily goal tracker

Write what you ate, drank, or did in one note. Habit puts your macros, water, workouts, and study on a calendar. Open a day for the details or check how often you hit your goals at the bottom of the month.

[Live demo at prateekm.com/daily](https://prateekm.com/daily)

<img src="docs/screenshots/calendar.jpg" alt="Mobile calendar with macro rings, water levels, and monthly goal counts" width="280" />
<img src="docs/screenshots/quick-log.jpg" alt="Quick log form with a date picker and text box" width="530" />

## Set up Habit with an AI tool

If Git and the terminal are new to you, paste this into an AI coding tool that can run commands on your computer.

```text
Read https://github.com/pr4733k/habit/blob/main/README.md and help me run Habit on this computer. I'm new to Git and the terminal. Check which prerequisites I already have, then follow the local setup steps and run the commands you can. If I need to click something or install a prerequisite, give me one step at a time. Have me enter my Habit password locally and keep it out of the chat. Verify that the calendar and quick log open, then give me their links and tell me how to start Habit again later. Keep this on my computer and skip Android setup.
```

## Run it locally

Use Node.js 20 (20.19 or later) or 22.12+, npm, and PHP 8.2+ with PDO SQLite enabled.

```sh
git clone https://github.com/pr4733k/habit.git
cd habit
npm ci
```

Choose a password. This creates a blank SQLite database and a separate token for processing notes. `DAILY_TIMEZONE` is optional and defaults to `UTC`.

```sh
DAILY_LOG_PASSWORD='choose-a-password' DAILY_TIMEZONE='Europe/London' php scripts/daily-local.php
```

In PowerShell, set `$env:DAILY_LOG_PASSWORD` and, if you want, `$env:DAILY_TIMEZONE` before running `php scripts/daily-local.php`.

Run `npm run api` in one terminal and `npm run dev` in another. Open [the calendar](http://127.0.0.1:5173/daily) or [quick log](http://127.0.0.1:5173/log). Set your targets under **Daily goals**. A change takes effect today and keeps the goals attached to earlier dates.

## Sync notes

New notes stay pending until a processor saves their structured entries. To process them with Codex, open a chat in the Habit checkout and ask it to follow [the note processing steps](daily/processor.md). You can also supply the JSON yourself.

For automatic sync, set `auto_sync` to `true` in `.daily-data/config.php` or your hosted config. With a Codex executable that supports `codex queue`, run:

```sh
node scripts/daily-bridge.mjs /path/to/codex YOUR_HABIT_CHAT_UUID
```

Use a chat opened in this checkout and keep the bridge and Codex running. The bridge queues a message when a note is saved. The note stays pending if your computer is offline.

## Host it

For a local build, run `npm run build` and `npm run serve`, then open `http://127.0.0.1:8080`. The development commands use port 8877 for the API. If you use the built site for processing, set the URL in `.daily-data/processor.json` to `http://127.0.0.1:8080/daily/api.php`.

For an HTTPS deployment on Apache, run `npm run build` and copy `dist/`, including the `.htaccess` files, into your document root. Run [the schema](daily/schema.sql) in a MySQL or MariaDB database. Copy [the example config](daily/config.example.php) to `daily-config.php` **above** the document root and fill it in. You can also set `DAILY_CONFIG` to its absolute path.

Generate a password hash with `php -r 'echo password_hash(getenv("DAILY_LOG_PASSWORD"), PASSWORD_DEFAULT), PHP_EOL;'` and a session key with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. Generate a separate token for the processor the same way and put its SHA-256 hash in `sync_token_hash`. Store the original token and your HTTPS `/daily/api.php` URL in the local `.daily-data/processor.json` file.

The calendar and quick log require the password by default. Set `public_calendar` to `true` if you want anyone with the link to see your processed entries. Raw notes stay behind the password.

## Android widget (optional)

The widget shows today’s macros, water, and recent entries. **Add a note** opens a text box. Notes use the same processing queue as the web form. Tap the refresh icon for an update, or wait for Android’s background refresh.

1. On Android 8 or later, download [Habit.apk from GitHub Releases](https://github.com/pr4733k/habit/releases/latest/download/Habit.apk) and open it. If Android asks, allow the app that opened the APK to install it.
2. Open **Habit** and enter your own Habit site’s HTTPS address and the password you use at `/log`. The phone needs to be able to reach that address. A site running at `127.0.0.1` on your computer is only available on that computer.
3. Touch and hold an empty spot on your home screen, choose **Widgets → Habit**, and drag the widget into place. To change sites, open Habit again and connect with the new address and password.

If you want help with the widget, paste this separate prompt into your AI tool.

```text
I already have Habit and want the optional Android widget. Read the Android section at https://github.com/pr4733k/habit#android-widget-optional. Help me find the APK on the official GitHub release, install it, and add the widget. Check that my phone can open my own Habit site over HTTPS. If my install is only on localhost, explain what I need to do to make it reachable and ask before changing my hosting or network settings. Let me type the site password on the phone. Walk me through refreshing the widget and saving a note.
```

See [the Android build instructions](android/README.md) to build the widget from source. The code is available under the [MIT license](LICENSE). The screenshots show the live demo.
