# Habit for Android

Install [Habit.apk](https://github.com/pr4733k/habit/releases/latest/download/Habit.apk) on Android 8 or later. Open Habit, enter the HTTPS address of your own install and its log password, then add **Habit** from your home screen’s widget picker. The app encrypts the session with a key in Android Keystore and uses the site’s timezone for the day shown.

Habit requests a refresh every 15 minutes. Android can delay background work. Tap the refresh icon to request an update now.

## Build from source

Use JDK 17 and Android SDK 35. From this directory run:

```sh
./gradlew :app:testReleaseUnitTest :app:assembleRelease
```

On Windows, use `.\gradlew.bat` in place of `./gradlew`. The build writes `app/build/outputs/apk/release/app-release-unsigned.apk`. For local development, `:app:assembleDebug` makes an APK signed with the local debug key. GitHub Releases publishes a separately signed APK. Android requires updates to use the same signing key as the installed app.
