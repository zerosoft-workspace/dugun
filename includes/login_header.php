<?php
require_once __DIR__ . '/public_header.php';

if (!function_exists('login_header_styles')) {
    function login_header_styles(): string
    {
        return '';
    }
}

if (!function_exists('render_login_header')) {
    function render_login_header(?string $active = null): void
    {
        $activeMap = [
            'dealer' => 'home',
            'representative' => 'home',
            'guest' => 'home',
            'portal' => 'home',
        ];

        $target = $activeMap[$active] ?? 'home';
        site_public_header($target);
    }
}
