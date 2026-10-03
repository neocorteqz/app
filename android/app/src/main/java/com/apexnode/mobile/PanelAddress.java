package com.apexnode.mobile;

import java.net.URI;
import java.net.URISyntaxException;
import java.util.Locale;

/** One validated HTTPS panel and its directory; shared by setup and navigation. */
public final class PanelAddress {
    private final URI base;

    private PanelAddress(URI base) { this.base = base; }

    public static PanelAddress parse(String input) {
        try {
            URI uri = new URI(input.trim());
            if (!"https".equalsIgnoreCase(uri.getScheme()) || uri.getHost() == null
                    || uri.getRawUserInfo() != null || uri.getRawQuery() != null
                    || uri.getRawFragment() != null || uri.getPort() == 0 || uri.getPort() > 65535) {
                throw new IllegalArgumentException("Enter an HTTPS panel address, without credentials or a query.");
            }
            String path = uri.getRawPath();
            if (path == null || path.isEmpty()) { path = "/"; }
            rejectUnsafePath(path);
            if (!uri.normalize().getRawPath().equals(uri.getRawPath())) {
                throw new IllegalArgumentException("Use the panel's directory, without dot segments.");
            }
            if (!path.endsWith("/")) { path += "/"; }
            return new PanelAddress(new URI("https://" + uri.getRawAuthority() + path));
        } catch (URISyntaxException | NullPointerException error) {
            throw new IllegalArgumentException("Enter a valid HTTPS panel address.", error);
        }
    }

    private static void rejectUnsafePath(String path) {
        String lower = path.toLowerCase(Locale.ROOT);
        if (lower.contains("%2e") || lower.contains("%2f") || lower.contains("%5c")
                || lower.contains("%25") || path.contains("\\") || path.contains("//")) {
            throw new IllegalArgumentException("Use a plain panel directory path.");
        }
    }

    private static int port(URI uri) { return uri.getPort() == -1 ? 443 : uri.getPort(); }

    public boolean allows(String value) {
        try {
            URI candidate = new URI(value);
            rejectUnsafePath(candidate.getRawPath() == null ? "" : candidate.getRawPath());
            return "https".equalsIgnoreCase(candidate.getScheme())
                    && candidate.getHost() != null && candidate.getRawUserInfo() == null
                    && base.getHost().equalsIgnoreCase(candidate.getHost())
                    && port(base) == port(candidate)
                    && candidate.normalize().getRawPath().startsWith(base.getRawPath());
        } catch (IllegalArgumentException | URISyntaxException error) {
            return false;
        }
    }

    public String route(String route) {
        if (!route.equals("dashboard") && !route.equals("servers") && !route.equals("login")) {
            throw new IllegalArgumentException("Unknown panel shortcut.");
        }
        return base.resolve(route).toASCIIString();
    }

    @Override public String toString() { return base.toASCIIString(); }
}
