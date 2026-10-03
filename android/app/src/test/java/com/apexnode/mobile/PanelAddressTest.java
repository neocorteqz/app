package com.apexnode.mobile;

import org.junit.Test;
import static org.junit.Assert.*;

public class PanelAddressTest {
    @Test public void preservesPanelSubdirectory() {
        PanelAddress panel = PanelAddress.parse("https://example.com/games");
        assertEquals("https://example.com/games/servers", panel.route("servers"));
        assertTrue(panel.allows("https://example.com/games/servers/1?tab=console"));
        assertFalse(panel.allows("https://example.com/games-other/servers"));
        assertFalse(panel.allows("https://example.com/admin"));
    }
    @Test public void enforcesOriginAndScheme() {
        PanelAddress panel = PanelAddress.parse("https://example.com/");
        assertTrue(panel.allows("https://EXAMPLE.com:443/servers"));
        for (String url : new String[]{"http://example.com/servers", "https://example.com.evil.test/",
                "https://evil.test/", "file:///etc/passwd", "javascript:alert(1)",
                "https://example.com:444/", "https://user@example.com/"}) {
            assertFalse(url, panel.allows(url));
        }
    }
    @Test public void rejectsTraversal() {
        PanelAddress panel = PanelAddress.parse("https://example.com/games/");
        for (String path : new String[]{"../admin", "%2e%2e/admin", "%252e%252e/admin", "%2fadmin", "%5cadmin"}) {
            assertFalse(path, panel.allows("https://example.com/games/" + path));
        }
    }
    @Test public void rejectsUnsafeSetup() {
        for (String url : new String[]{"http://example.com", "https://u:p@example.com/",
                "https://example.com/?token=secret", "https://example.com/#fragment",
                "https://example.com/games/../admin", "https://example.com:0/", "https://example.com:70000/", ""}) {
            try { PanelAddress.parse(url); fail(url); } catch (IllegalArgumentException expected) { }
        }
    }
}
