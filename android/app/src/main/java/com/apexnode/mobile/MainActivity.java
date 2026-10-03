package com.apexnode.mobile;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.app.AlertDialog;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.graphics.drawable.GradientDrawable;
import android.os.Build;
import android.os.Bundle;
import android.view.Gravity;
import android.view.View;
import android.view.WindowInsets;
import android.view.inputmethod.InputMethodManager;
import android.webkit.CookieManager;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebSettings;
import android.webkit.WebStorage;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;

/** Native controls around the panel's existing session/CSRF-aware interface. */
public final class MainActivity extends Activity {
    private static final int BACKGROUND = Color.rgb(11, 18, 32);
    private static final int SURFACE = Color.rgb(21, 34, 53);
    private static final int ACCENT = Color.rgb(56, 189, 248);
    private LinearLayout root;
    private WebView web;
    private ProgressBar progress;
    private TextView message;
    private PanelAddress panel;
    private SharedPreferences preferences;
    private boolean mainFrameFailed;

    @Override public void onCreate(Bundle state) {
        super.onCreate(state);
        preferences = getSharedPreferences("panel", MODE_PRIVATE);
        if (Build.VERSION.SDK_INT >= 33) {
            getOnBackInvokedDispatcher().registerOnBackInvokedCallback(0, this::navigateBack);
        }
        try {
            panel = PanelAddress.parse(preferences.getString("address", ""));
            showPanel();
        } catch (IllegalArgumentException error) {
            showSetup();
        }
    }

    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }

    // Android 8–10 require the guarded legacy inset accessors.
    @SuppressWarnings("deprecation")
    private void newScreen() {
        destroyWeb();
        root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(BACKGROUND);
        root.setPadding(dp(16), dp(12), dp(16), dp(12));
        root.setOnApplyWindowInsetsListener((view, insets) -> {
            if (Build.VERSION.SDK_INT >= 30) {
                android.graphics.Insets edges = insets.getInsets(WindowInsets.Type.systemBars() | WindowInsets.Type.ime());
                view.setPadding(dp(16) + edges.left, dp(12) + edges.top,
                        dp(16) + edges.right, dp(12) + edges.bottom);
            } else {
                view.setPadding(dp(16) + insets.getSystemWindowInsetLeft(),
                        dp(12) + insets.getSystemWindowInsetTop(),
                        dp(16) + insets.getSystemWindowInsetRight(), dp(12) + insets.getSystemWindowInsetBottom());
            }
            return insets;
        });
        setContentView(root);
        root.requestApplyInsets();
    }

    private TextView text(String value, int size) {
        TextView view = new TextView(this);
        view.setText(value);
        view.setTextSize(size);
        view.setTextColor(Color.rgb(226, 232, 240));
        view.setPadding(0, dp(8), 0, dp(8));
        return view;
    }

    private Button button(String label, Runnable action) {
        Button button = new Button(this);
        button.setText(label);
        button.setTextSize(13);
        button.setAllCaps(false);
        button.setTextColor(Color.WHITE);
        button.setOnClickListener(view -> action.run());
        return button;
    }

    private void showSetup() {
        newScreen();
        android.widget.ScrollView scroll = new android.widget.ScrollView(this);
        scroll.setFillViewport(true);
        LinearLayout content = new LinearLayout(this);
        content.setOrientation(LinearLayout.VERTICAL);
        content.setGravity(Gravity.CENTER_VERTICAL);
        scroll.addView(content, new android.widget.ScrollView.LayoutParams(-1, -1));
        root.addView(scroll, new LinearLayout.LayoutParams(-1, -1));
        content.addView(text("APEXNODE", 14));
        content.addView(text("Your servers.\nWithin reach.", 34));
        content.addView(text("Connect your panel to manage game servers from your phone or tablet.", 16));
        LinearLayout card = new LinearLayout(this);
        card.setOrientation(LinearLayout.VERTICAL);
        card.setPadding(dp(20), dp(16), dp(20), dp(16));
        GradientDrawable background = new GradientDrawable();
        background.setColor(SURFACE);
        background.setCornerRadius(dp(18));
        card.setBackground(background);
        card.addView(text("Panel address", 18));
        EditText input = new EditText(this);
        input.setSingleLine(true);
        input.setInputType(android.text.InputType.TYPE_CLASS_TEXT | android.text.InputType.TYPE_TEXT_VARIATION_URI);
        input.setHint("https://panel.example.com/games/");
        input.setTextColor(Color.WHITE);
        input.setHintTextColor(Color.LTGRAY);
        input.setText(preferences.getString("address", ""));
        card.addView(input);
        TextView error = text("", 14);
        error.setTextColor(Color.rgb(251, 113, 133));
        card.addView(error);
        Button connect = button("Connect to panel", () -> {
            try {
                PanelAddress selected = PanelAddress.parse(input.getText().toString());
                InputMethodManager keyboard = (InputMethodManager) getSystemService(INPUT_METHOD_SERVICE);
                keyboard.hideSoftInputFromWindow(input.getWindowToken(), 0);
                preferences.edit().putString("address", selected.toString()).apply();
                panel = selected;
                showPanel();
            } catch (IllegalArgumentException invalid) {
                error.setText(invalid.getMessage());
            }
        });
        connect.setBackgroundTintList(android.content.res.ColorStateList.valueOf(ACCENT));
        connect.setTextColor(BACKGROUND);
        card.addView(connect);
        content.addView(card);
        content.addView(text("Sign in with your existing panel account. Server permissions stay the same.", 14));
    }

    @SuppressLint("SetJavaScriptEnabled")
    private void showPanel() {
        newScreen();
        root.addView(text("ApexNode", 24));
        TextView address = text(panel.toString(), 12);
        address.setMaxLines(1);
        address.setEllipsize(android.text.TextUtils.TruncateAt.END);
        root.addView(address);
        progress = new ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal);
        progress.setMax(100);
        root.addView(progress, new LinearLayout.LayoutParams(-1, dp(3)));
        message = text("", 14);
        message.setVisibility(View.GONE);
        root.addView(message);
        web = new WebView(this);
        web.setBackgroundColor(SURFACE);
        WebSettings settings = web.getSettings();
        settings.setJavaScriptEnabled(true); // The trusted panel needs JS for status and console.
        settings.setDomStorageEnabled(true);
        settings.setAllowFileAccess(false);
        settings.setAllowContentAccess(false);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        settings.setSafeBrowsingEnabled(true);
        settings.setJavaScriptCanOpenWindowsAutomatically(false);
        settings.setSupportMultipleWindows(false);
        CookieManager.getInstance().setAcceptCookie(true);
        CookieManager.getInstance().setAcceptThirdPartyCookies(web, false);
        web.setWebChromeClient(new WebChromeClient() {
            @Override public void onProgressChanged(WebView view, int value) {
                progress.setProgress(value);
                progress.setVisibility(value == 100 ? View.GONE : View.VISIBLE);
            }
        });
        web.setWebViewClient(new WebViewClient() {
            @Override public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                if (!request.isForMainFrame()) { return false; }
                if (panel.allows(request.getUrl().toString())) { return false; }
                showMessage("This link is outside your configured panel and was blocked.");
                return true;
            }
            @Override public WebResourceResponse shouldInterceptRequest(WebView view, WebResourceRequest request) {
                if (request.isForMainFrame() && !panel.allows(request.getUrl().toString())) {
                    return new WebResourceResponse("text/plain", "UTF-8", 403, "Blocked",
                            java.util.Collections.emptyMap(), new java.io.ByteArrayInputStream(new byte[0]));
                }
                return null;
            }
            @Override public void onPageStarted(WebView view, String url, android.graphics.Bitmap icon) {
                mainFrameFailed = false;
                message.setVisibility(View.GONE);
                if (!panel.allows(url)) {
                    view.stopLoading();
                    showMessage("Navigation outside your panel was blocked.");
                }
            }
            @Override public void onPageFinished(WebView view, String url) {
                CookieManager.getInstance().flush();
                if (mainFrameFailed) { progress.setVisibility(View.GONE); }
            }
            @Override public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                if (request.isForMainFrame()) {
                    mainFrameFailed = true;
                    showMessage("Cannot reach the panel. Check the address or connection, then tap Refresh.");
                }
            }
            @Override public void onReceivedHttpError(WebView view, WebResourceRequest request, WebResourceResponse response) {
                if (request.isForMainFrame()) {
                    showMessage("Panel returned HTTP " + response.getStatusCode() + ". Try Refresh or check your panel service.");
                }
            }
            @Override public void onReceivedSslError(WebView view, android.webkit.SslErrorHandler handler,
                    android.net.http.SslError error) {
                handler.cancel();
                showMessage("The panel certificate could not be verified. Fix HTTPS on the server before connecting.");
            }
        });
        root.addView(web, new LinearLayout.LayoutParams(-1, 0, 1));
        LinearLayout navigation = new LinearLayout(this);
        navigation.setOrientation(LinearLayout.HORIZONTAL);
        navigation.addView(button("Home", () -> web.loadUrl(panel.route("dashboard"))), new LinearLayout.LayoutParams(0, -2, 1));
        navigation.addView(button("Servers", () -> web.loadUrl(panel.route("servers"))), new LinearLayout.LayoutParams(0, -2, 1));
        navigation.addView(button("Refresh", () -> web.reload()), new LinearLayout.LayoutParams(0, -2, 1));
        navigation.addView(button("Settings", this::showSettings), new LinearLayout.LayoutParams(0, -2, 1));
        root.addView(navigation);
        web.loadUrl(panel.route("dashboard"));
    }

    private void showMessage(String value) {
        if (message != null) {
            message.setText(value);
            message.setVisibility(View.VISIBLE);
        }
    }

    private void showSettings() {
        new AlertDialog.Builder(this).setTitle("Panel settings")
                .setItems(new String[]{"Reset local session", "Change panel"}, (dialog, which) -> {
                    new AlertDialog.Builder(this).setTitle(which == 0 ? "Reset session?" : "Change panel?")
                            .setMessage("This clears this app's cookies and cached panel data. It does not stop servers or revoke sessions on the server.")
                            .setNegativeButton("Cancel", null)
                            .setPositiveButton("Continue", (confirmation, selected) -> resetSession(which == 1))
                            .show();
                }).show();
    }

    private void resetSession(boolean changePanel) {
        destroyWeb();
        CookieManager.getInstance().removeAllCookies(removed -> {
            if (isFinishing() || isDestroyed()) { return; }
            CookieManager.getInstance().flush();
            WebStorage.getInstance().deleteAllData();
            if (changePanel) { showSetup(); } else { showPanel(); }
        });
    }

    private void destroyWeb() {
        if (web == null) { return; }
        web.stopLoading();
        web.clearCache(true);
        ((android.view.ViewGroup) web.getParent()).removeView(web);
        web.destroy();
        web = null;
    }

    private void navigateBack() {
        if (web != null && web.canGoBack()) { web.goBack(); } else { finish(); }
    }

    @SuppressWarnings("deprecation")
    @Override public void onBackPressed() { navigateBack(); }
    @Override protected void onResume() { super.onResume(); if (web != null) { web.onResume(); } }
    @Override protected void onPause() { if (web != null) { web.onPause(); } super.onPause(); }
    @Override protected void onDestroy() { destroyWeb(); super.onDestroy(); }
}
