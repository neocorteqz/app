# Build validation — 2026-10-03

A clean Gradle build completed successfully with Android Gradle Plugin 8.9.2, Gradle 8.11.1, Java 17, Android Platform 35 and Build Tools 35.0.0.

- `clean testDebugUnitTest lintDebug assembleDebug`: successful, all 48 tasks executed.
- 4 JUnit tests passed; no skipped tests, failures or errors.
- Android lint: 0 errors and 0 warnings.
- APK signature verified with `apksigner` using APK Signature Scheme v2.
- Package: `com.apexnode.mobile`, version 0.1.0, minimum Android 8.0/API 26, target API 35.
- XML syntax checks and 24 standalone URL-policy regressions also passed during source setup.

Lint required an API 27 theme override and explicit cloud/device-transfer backup exclusions, including rules for older Android versions. These corrections are included in the updated source. A clean rebuild eliminated stale incremental resource dex outputs.

The APK is a debug-signed development build, not a store release. Emulator rendering and live-panel device testing have not been run. Release distribution requires your own signing key and appropriate store review.

Device checklist: root and subdirectory URLs; login/logout; permission-denied commands; start/stop/restart on a disposable server; console polling; certificate failures; offline/retry; rotation; keyboard/insets on a tablet; back gesture; local session reset; switching panels; and blocked external navigation.

Debug APK SHA256: `49c2560847d86b2d4ec98331824fc985715292ed22fa7586d95834f95c085d12`
