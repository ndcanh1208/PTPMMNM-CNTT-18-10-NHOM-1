<?php
/**
 * Test live submission end-to-end with background worker.
 */

declare(strict_types=1);

$baseUrl = 'http://localhost/BaiTapLon';
$cookieFile = sys_get_temp_dir() . '/live_test_cookie.txt';
@unlink($cookieFile);

// 1. Login
$ch = curl_init("{$baseUrl}/api/auth.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['action' => 'login', 'username' => 'learner1', 'password' => 'Password123@']));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$res = curl_exec($ch);
curl_close($ch);

// 2. Submit solution for Problem #1
$idempKey = 'live_test_' . bin2hex(random_bytes(8));
$pyCode = "import sys\n\ndef main():\n    data = sys.stdin.read().split()\n    if data:\n        print(int(data[0]) + int(data[1]))\n\nif __name__ == '__main__':\n    main()\n";

$ch = curl_init("{$baseUrl}/api/submit.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'problem_id' => 1,
    'language' => 'python',
    'source_code' => $pyCode,
    'idempotency_key' => $idempKey
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$subRes = json_decode((string)curl_exec($ch), true);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Submit response (HTTP {$status}): " . json_encode($subRes, JSON_UNESCAPED_UNICODE) . "\n";
if ($status !== 201 || empty($subRes['submission_id'])) {
    echo "Submit failed!\n";
    exit(1);
}

$subId = $subRes['submission_id'];
echo "Polling submission #{$subId} status from worker daemon...\n";

$maxRetries = 10;
$finalVerdict = 'Pending';

for ($i = 1; $i <= $maxRetries; $i++) {
    usleep(800000); // 0.8s
    $ch = curl_init("{$baseUrl}/api/submission_status.php?id={$subId}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    $checkData = json_decode((string)curl_exec($ch), true);
    curl_close($ch);

    $verdict = $checkData['submission']['final_verdict'] ?? 'Pending';
    echo "  Attempt #{$i}: Verdict = {$verdict}\n";
    if ($verdict !== 'Pending' && $verdict !== 'Running') {
        $finalVerdict = $verdict;
        echo "Finished! Pass count: {$checkData['submission']['pass_count']}/{$checkData['submission']['total_cases']}\n";
        break;
    }
}

if ($finalVerdict === 'AC') {
    echo ">>> LIVE SUBMISSION TEST PASSED 100%! Background worker graded solution successfully! <<<\n";
} else {
    echo ">>> FAILED: Verdict was {$finalVerdict} <<<\n";
    exit(1);
}
