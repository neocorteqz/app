plugins { id("com.android.application") }
android {
    namespace = "com.apexnode.mobile"
    compileSdk = 35
    defaultConfig {
        applicationId = "com.apexnode.mobile"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "0.1.0"
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    buildTypes { release { isMinifyEnabled = false } }
    lint { abortOnError = true }
}
dependencies { testImplementation("junit:junit:4.13.2") }
