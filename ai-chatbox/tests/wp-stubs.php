<?php
// Minimal stand-ins for the WordPress functions our pure-logic classes call.
// These are NOT full WordPress - just enough behavior to unit test our own code.

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        $str = wp_check_invalid_utf8_stub($str);
        $str = preg_replace('/<[^>]*>/', '', (string) $str);
        return trim(preg_replace('/[\r\n\t ]+/', ' ', $str));
    }
}

if (!function_exists('wp_check_invalid_utf8_stub')) {
    function wp_check_invalid_utf8_stub($str) {
        return (string) $str;
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($str) {
        return trim((string) $str);
    }
}

if (!function_exists('sanitize_hex_color')) {
    function sanitize_hex_color($color) {
        if ('' === $color) {
            return '';
        }
        return preg_match('/^#[0-9a-fA-F]{3,6}$/', $color) ? $color : '';
    }
}

if (!function_exists('absint')) {
    function absint($maybeint) {
        return abs((int) $maybeint);
    }
}
