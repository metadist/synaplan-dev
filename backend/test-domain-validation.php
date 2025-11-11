<?php

$domains = ['test.com', 'localhost', '127.0.0.1', 'yusufsenel.de', 'subdomain.example.com', '*.wildcard.com', 'example.com/path'];

$pattern = '/^(?:\*\.)?[a-z0-9-]+(?:\.[a-z0-9-]+)*(?::\d+)?$/';

echo "Testing sanitizeAllowedDomains logic:\n\n";

foreach ($domains as $domain) {
    $normalized = strtolower(trim($domain));
    
    // Remove protocol if provided
    $normalized = preg_replace('~^https?://~', '', $normalized);
    $normalized = preg_replace('~^//~', '', $normalized);
    
    // Strip any path fragments - FIXED: use different delimiter
    $parts = preg_split('~[/?#]~', $normalized);
    $normalized = $parts[0] ?? '';
    
    // Validate
    $matches = preg_match($pattern, $normalized);
    
    echo sprintf("%-25s -> %-25s : %s\n", 
        $domain, 
        $normalized, 
        $matches ? '✅ VALID' : '❌ INVALID'
    );
}
