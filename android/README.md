# ApexNode Android

A separate Android companion project for the ApexNode PHP game-server panel. Version 0.1.0 is a native Java Android shell with a secured WebView, not a fully native API client. It uses the existing panel sign-in, session cookies, CSRF checks and server permissions. No panel changes, daemon exposure, or embedded credentials are required.

## First version

- Native HTTPS panel-address setup, including subdirectory installations.
- Dashboard and server shortcuts, refresh, and Android back navigation.
- Existing server list, start/stop/restart, console, status and other panel pages inside the app.
- Connection/certificate error messages, local session reset and panel switching.
- Origin/directory restricted top-level navigation; external links are blocked.
- Cleartext, mixed content, third-party cookies and local file/content access disabled.
- No JavaScript bridge, password storage, analytics or advertising SDK.

The app requires a working, trusted HTTPS panel. Sign in on its existing login page. Server commands work only when your panel account has permission and the panel's local daemon is configured. The app never connects to port 8001 directly.

## Open and build

1. Extract the source ZIP and open this directory in Android Studio.
2. Use JDK 17, Android SDK Platform 35 and Build Tools 35.0.0.
3. Allow Android Studio to sync the included Gradle 8.11.1 wrapper. The Android plugin is pinned to 8.9.2. Gradle verifies the distribution SHA256.
4. Run `./gradlew testDebugUnitTest lintDebug assembleDebug` or build/run from Android Studio on an Android 8.0+ device.
5. Debug APK output: `app/build/outputs/apk/debug/app-debug.apk`. Release publishing requires your own signing key and Play policy review.

The project is hosted in `android/` in [neocorteqz/app](https://github.com/neocorteqz/app). Open this directory in Android Studio. The repository-level Android companion workflow tests, lints, builds and retains a debug APK. The workflow inside this project can also be used if it is moved to its own repository.

## Security and limits

Only the address is stored in preferences; WebView manages session cookies in app-private storage. Backups are disabled. Reset local session clears cookies/cache/web storage but does not revoke the server session; use the panel logout action for server-side logout. SSL certificate errors are cancelled. Never enter a panel URL you do not trust: its JavaScript runs inside this app.

File uploads, downloads, popup windows, external sign-in/SSO and push notifications are not implemented. The console and server controls use the panel's existing mobile layout and polling. The debug APK has passed a clean build, unit tests, lint and signature verification. UI/device validation remains required before distribution. Address-policy tests are included; see VALIDATION.md for checks actually run.

## Next milestones

1. Device testing against your panel URL and user account.
2. Native server cards and console using an explicit versioned mobile API on the panel.
3. Scoped, revocable authentication for that API and optional biometric lock.
4. Notifications, file transfers, multiple saved panels and release signing.

Do not scrape CSRF tokens or ship an admin token in the APK to implement the future native API.
