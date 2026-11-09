<?php
/**
 * Test C++ live submission end-to-end with background worker.
 */

declare(strict_types=1);

$baseUrl = 'http://localhost/BaiTapLon';
$cookieFile = sys_get_temp_dir() . '/live_test_cookie.txt';

$idempKey = 'cpp_test_' . bin2hex(random_bytes(8));
$cppCode = "#include <iostream>\nusing namespace std;\n\nint main() {\n    ios_base::sync_with_stdio(false);\n    cin.tie(NULL);\n    long long a, b;\n    if (cin >> a >> b) {\n        cout << (a + b) << \"\\n\";\n    }\n    return 0;\n}\n";

$ch = curl_init("{$baseUrl}/api/submit.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'problem_id' => 1,
    'language' => 'cpp',
    'source_code' => $cppCode,
    'idempotency_key' => $idempKey
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$subRes = json_decode((string)curl_exec($ch), true);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "C++ Submit response (HTTP {$status}): " . json_encode($subRes, JSON_UNESCAPED_UNICODE) . "\n";
if ($status !== 201 || empty($subRes['submission_id'])) {
    echo "C++ Submit failed!\n";
    exit(1);
}

$subId = $subRes['submission_id'];
echo "Polling C++ submission #{$subId} status from worker daemon...\n";

$maxRetries = 10;
$finalVerdict = 'Pending';

for ($i = 1; $i <= $maxRetries; $i++) {
    usleep(1000000); // 1.0s (C++ needs compilation)
    $ch = curl_init("{$baseUrl}/api/submission_status.php?id={$subId}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    $checkData = json_decode((string)curl_exec($ch), true);
    curl_close($ch);

    $verdict = $checkData['submission']['final_verdict'] ?? 'Pending';
    echo "  Attempt #{$i}: Verdict = {$verdict}\n";
    if ($verdict !== 'Pending' && $verdict !== 'Running') {
        $finalVerdict = $verdict;
        echo "Finished! Pass count: {$checkData['submission']['pass_count']}/{$checkData['submission']['total_cases']}, Max Time: {$checkData['submission']['execution_time_ms']}ms\n";
        break;
    }
}

if ($finalVerdict === 'AC') {
    echo ">>> LIVE C++ SUBMISSION TEST PASSED 100%! G++ compiled and evaluated correctly! <<<\n";
} else {
    echo ">>> FAILED: Verdict was {$finalVerdict} <<<\n";
    exit(1);
}
