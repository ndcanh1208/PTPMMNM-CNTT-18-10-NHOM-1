<?php
/**
 * Test script to audit all front-end pages for PHP notices, warnings, and errors.
 */

declare(strict_types=1);

$baseUrl = 'http://localhost/BaiTapLon';
$cookieDir = sys_get_temp_dir() . '/byteflow_audit_cookies';
if (!is_dir($cookieDir)) mkdir($cookieDir, 0777, true);

$cookieLearner = $cookieDir . '/audit_learner.txt';
$cookieAdmin   = $cookieDir . '/audit_admin.txt';

@unlink($cookieLearner);
@unlink($cookieAdmin);

function curlPage(string $url, ?string $cookieFile = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return ['status' => $status, 'body' => (string)$body, 'redirect' => $redirect];
}

function login(string $username, string $password, string $cookieFile, string $baseUrl): bool {
    $ch = curl_init("{$baseUrl}/api/auth.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['action' => 'login', 'username' => $username, 'password' => $password]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$res, true);
    return ($status === 200 && !empty($data['success']));
}

echo "Logging in as learner1...\n";
$ok1 = login('learner1', 'Password123@', $cookieLearner, $baseUrl);
echo "Learner login: " . ($ok1 ? "OK" : "FAILED") . "\n";

echo "Logging in as admin1...\n";
$ok2 = login('admin1', 'Password123@', $cookieAdmin, $baseUrl);
echo "Admin login: " . ($ok2 ? "OK" : "FAILED") . "\n";

$pages = [
    'index.php' => ['guest' => 200, 'learner' => 200, 'admin' => 200],
    'login.php' => ['guest' => 200, 'learner' => [200, 302], 'admin' => [200, 302]],
    'problems.php' => ['guest' => 200, 'learner' => 200, 'admin' => 200],
    'problems.php?search=two' => ['guest' => 200, 'learner' => 200, 'admin' => 200],
    'problems.php?difficulty=Easy' => ['guest' => 200, 'learner' => 200, 'admin' => 200],
    'solve.php?id=1' => ['guest' => 200, 'learner' => 200, 'admin' => 200],
    'solve.php?slug=two-sum' => ['guest' => 200, 'learner' => 200, 'admin' => 200],
    'submissions.php' => ['guest' => [200, 302], 'learner' => 200, 'admin' => 200],
    'submissions.php?status=AC' => ['guest' => [200, 302], 'learner' => 200, 'admin' => 200],
    'submissions.php?problem_id=1' => ['guest' => [200, 302], 'learner' => 200, 'admin' => 200],
    'manage_problems.php' => ['guest' => 302, 'learner' => [302, 403], 'admin' => 200],
    'rejudge_compare.php' => ['guest' => 302, 'learner' => [302, 403], 'admin' => 200],
    'rejudge_compare.php?problem_id=1' => ['guest' => 302, 'learner' => [302, 403], 'admin' => 200],
];

$errorsFound = 0;

foreach ($pages as $path => $expectedStatuses) {
    foreach (['guest' => null, 'learner' => $cookieLearner, 'admin' => $cookieAdmin] as $role => $cookie) {
        $res = curlPage("{$baseUrl}/{$path}", $cookie);
        $expected = $expectedStatuses[$role];
        $isOk = is_array($expected) ? in_array($res['status'], $expected) : ($res['status'] === $expected);
        
        // Scan for PHP errors/warnings in HTML
        $hasPhpError = false;
        $errorSnippet = '';
        if (preg_match('/(<b>Fatal error<\/b>|<b>Warning<\/b>|<b>Notice<\/b>|<b>Deprecated<\/b>|PHP Fatal error|PHP Warning|PHP Notice|Parse error:)/i', $res['body'], $matches)) {
            $hasPhpError = true;
            $pos = strpos($res['body'], $matches[0]);
            $errorSnippet = substr($res['body'], max(0, $pos - 20), 200);
        }

        if (!$isOk || $hasPhpError) {
            echo "  [FAIL] Page: {$path} | Role: {$role} | Status: {$res['status']} (Expected: " . json_encode($expected) . ")\n";
            if ($hasPhpError) {
                echo "         PHP Error detected: {$errorSnippet}\n";
            }
            $errorsFound++;
        } else {
            echo "  [PASS] Page: {$path} | Role: {$role} | Status: {$res['status']}\n";
        }
    }
}

echo "\nTotal page audit issues: {$errorsFound}\n";
if ($errorsFound === 0) {
    echo ">>> ALL 13 PAGE ROUTES ACROSS 3 ROLES PASSED WITH 0 PHP ERRORS/WARNINGS! <<<\n";
}
