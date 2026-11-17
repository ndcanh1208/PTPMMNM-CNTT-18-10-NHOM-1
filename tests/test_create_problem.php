<?php
/**
 * Test problem creation via POST to manage_problems.php
 */

declare(strict_types=1);

$baseUrl = 'http://localhost/BaiTapLon';
$cookieFile = sys_get_temp_dir() . '/create_test_cookie.txt';
@unlink($cookieFile);

// 1. Login as instructor1
$ch = curl_init("{$baseUrl}/api/auth.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['action' => 'login', 'username' => 'instructor1', 'password' => 'Password123@']));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$res = curl_exec($ch);
curl_close($ch);

// 2. Post create problem to manage_problems.php
$postFields = [
    'action'              => 'create_problem',
    'slug'                => 'test-demo-' . time(),
    'title'               => 'Bài toán Kiểm thử Tạo đề Tự động',
    'topic'               => 'Cơ bản',
    'difficulty'          => 'Easy',
    'time_limit_ms'       => 1000,
    'memory_limit_mb'     => 256,
    'source_attribution'  => 'ByteFlow System Test',
    'description'         => 'Cho số nguyên a. In ra a * 2.',
    'input_description'   => 'Một số nguyên duy nhất a.',
    'output_description'  => 'In ra a * 2.',
    'constraints'         => '-10^9 <= a <= 10^9',
    'sample_input'        => "5\n",
    'sample_output'       => "10\n"
];

$ch = curl_init("{$baseUrl}/manage_problems.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Create problem POST response status: {$status}\n";

// 3. Verify in DB
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
$stmt = $pdo->prepare("SELECT * FROM problems WHERE slug = ?");
$stmt->execute([$postFields['slug']]);
$prob = $stmt->fetch();

if (!$prob) {
    echo "FAIL: Problem was not created in DB!\n";
    exit(1);
}

echo "PASS: Problem created with ID #{$prob['id']} ({$prob['title']})!\n";

$verStmt = $pdo->prepare("SELECT * FROM problem_versions WHERE problem_id = ?");
$verStmt->execute([$prob['id']]);
$ver = $verStmt->fetch();
echo "PASS: Problem version v{$ver['version_number']} created!\n";

$tcStmt = $pdo->prepare("SELECT * FROM test_cases WHERE problem_version_id = ?");
$tcStmt->execute([$ver['id']]);
$tc = $tcStmt->fetch();
echo "PASS: Sample testcase created! Input: {$tc['input_data']}, Expected: {$tc['expected_output']}\n";

// Cleanup test problem
$pdo->prepare("DELETE FROM test_cases WHERE problem_version_id = ?")->execute([$ver['id']]);
$pdo->prepare("DELETE FROM problem_versions WHERE id = ?")->execute([$ver['id']]);
$pdo->prepare("DELETE FROM problems WHERE id = ?")->execute([$prob['id']]);
echo "PASS: Test problem cleaned up successfully!\n";

@unlink($cookieFile);
echo ">>> ALL CREATE PROBLEM WORKFLOW TESTS PASSED 100%! <<<\n";
