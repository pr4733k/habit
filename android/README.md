# Habit for Android

Install [Habit.apk](https://github.com/pr4733k/habit/releases/latest/download/Habit.apk) on Android 8 or later. Open Habit, enter the HTTPS address of your own install and its log password, then add **Habit** from your home screen’s widget picker. The app encrypts the session with a key in Android Keystore and uses the site’s timezone for the day shown.

The app opens the same Daily interface as your website, including the calendar and Workout tab. **Add a note** opens a native text box. **Connection** lets you change your Habit server. Your password is used to sign in; the saved session is encrypted with Android Keystore.

Resize the widget vertically to show your exercise queue. Start a workout, then log the displayed previous sets or use **Edit sets** for different weights, reps, and set groups. Exercises save separately without an AI call. Pending saves stay on the phone and retry when connected. If an exercise changed elsewhere, review it before replacing its saved sets. Finish closes the session and leaves unlogged exercises incomplete. Deploy the current Habit PHP files to enable this flow.

Tap **+** beside Water to add 100 mL without AI. Notes use the server’s processing queue. The widget refreshes after your saves and requests background updates every 15 minutes; Android may delay those checks. Use the refresh icon for changes logged on another device. It does not require the computer to be running.

## Build from source

Use JDK 17 and Android SDK 35. From this directory run:

```sh
./gradlew :app:testReleaseUnitTest :app:assembleRelease
```

On Windows, use `.\gradlew.bat` in place of `./gradlew`. The build writes `app/build/outputs/apk/release/app-release-unsigned.apk`. For local development, `:app:assembleDebug` makes an APK signed with the local debug key. GitHub Releases publishes a separately signed APK. Android requires updates to use the same signing key as the installed app.
