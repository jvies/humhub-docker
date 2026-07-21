<?php

/**
 * Generates Caddy IP whitelist configuration snippet from HUMHUB_REVERSEPROXY_WHITELIST env var.
 * Replaces 70-nginx-allowedips.sh for Distroless / FrankenPHP execution.
 */

declare(strict_types=1);

$whitelistRaw = getenv('HUMHUB_REVERSEPROXY_WHITELIST');
if ($whitelistRaw === false || trim($whitelistRaw) === '') {
    // Default IPs if not provided in environment
    $whitelistRaw = "127.0.0.1 ::1";
}

// Split by space, semicolon, or newline
$ips = preg_split('/[\s;]+/', trim($whitelistRaw), -1, PREG_SPLIT_NO_EMPTY);

$caddySnippetPath = '/etc/frankenphp/allowedips.caddy';

if (!empty($ips)) {
    echo "[hook 70-caddy-allowedips] Setting HUMHUB_REVERSEPROXY_WHITELIST...\n";

    $ipListStr = implode(' ', $ips);

    // Caddy syntax to abort/block any IP that is NOT in the list
    $caddyConfig = "# Auto-generated Reverse Proxy Whitelist\n";
    $caddyConfig .= "@blocked not remote_ip {$ipListStr}\n";
    $caddyConfig .= "abort @blocked\n";

    // Ensure the destination directory exists
    @mkdir(dirname($caddySnippetPath), 0755, true);
    file_put_contents($caddySnippetPath, $caddyConfig);

    foreach ($ips as $ip) {
        echo "[hook 70-caddy-allowedips] Added {$ip} to Reverseproxy Whitelist\n";
    }
} else {
    // If empty, create an empty file so Caddy's 'import' directive doesn't fail
    file_put_contents($caddySnippetPath, "# Empty Reverse Proxy Whitelist\n");
}
