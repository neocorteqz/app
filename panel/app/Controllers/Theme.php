<?php
namespace App\Controllers;
use DB;

class Theme {
    public function index() {
        \require_login();
        \view('theme/index', ['title'=>'Theme Customizer']);
    }
    public function save() {
        \check_csrf();
        $u = \require_login();
        $theme = \normalize_theme($_POST);
        ['accent'=>$accent, 'radius'=>$radius, 'density'=>$density, 'mode'=>$mode, 'font'=>$font] = $theme;
        DB::q('INSERT INTO user_themes (user_id, accent, radius, density, mode, font) VALUES (?,?,?,?,?,?) 
               ON DUPLICATE KEY UPDATE accent=VALUES(accent), radius=VALUES(radius), density=VALUES(density), mode=VALUES(mode), font=VALUES(font)',
            [$u['id'], $accent, $radius, $density, $mode, $font]);
        \log_activity('update-theme');
        \flash('success','Theme saved.');
        \redirect('/theme');
    }
    public function css() {
        header('Content-Type: text/css');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        $t = \user_theme();
        $hex = ltrim($t['accent'],'#');
        [$r,$g,$b] = [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
        $pad = ['compact'=>'10px','comfortable'=>'16px','spacious'=>'22px'][$t['density']] ?? '16px';
        echo ":root {\n";
        echo "  --accent: {$t['accent']};\n";
        echo "  --accent-glow: rgba($r,$g,$b,0.4);\n";
        echo "  --radius: {$t['radius']};\n";
        echo "  --pad: $pad;\n";
        echo "  --font-head: '{$t['font']}', sans-serif;\n";
        echo "}\n";
        if ($t['mode'] === 'light') {
            echo "html[data-theme=light]{--bg:#F5F6FA;--surface:#FFFFFF;--card:#FFFFFF;--hover:#EEF0F6;--border:#E4E6EF;--text:#0A0D14;--text-2:#4A5164;--muted:#6B7280;}\n";
        }
    }
}
