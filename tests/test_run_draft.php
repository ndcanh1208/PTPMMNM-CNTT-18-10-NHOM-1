<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/ExecutionService.php';

// Test 1: Chạy thử tính tổng 2 số A + B
$code = "a, b = map(int, input().split())\nprint(a + b)";
$stdin = "15 27";

$result = ExecutionService::execute($code, 'python3', $stdin);

echo "Test Chay Thu Python:" . PHP_EOL;
echo "- Status : " . $result['status'] . PHP_EOL;
echo "- Output : " . trim($result['stdout']) . PHP_EOL;
echo "- Time   : " . $result['time_ms'] . "ms" . PHP_EOL;
echo "- Message: " . $result['message'] . PHP_EOL;

// Test 2: Chạy thử lỗi vòng lặp vô hạn (TLE)
$codeTLE = "while True: pass";
$resultTLE = ExecutionService::execute($codeTLE, 'python3', '');
echo PHP_EOL . "Test Chay Thu Vong Lap Vo Han (TLE):" . PHP_EOL;
echo "- Status : " . $resultTLE['status'] . PHP_EOL;
echo "- Message: " . $resultTLE['message'] . PHP_EOL;

// Test 3: Chạy thử lỗi chia cho 0 (RE)
$codeRE = "print(1 / 0)";
$resultRE = ExecutionService::execute($codeRE, 'python3', '');
echo PHP_EOL . "Test Chay Thu Chia Cho 0 (RE):" . PHP_EOL;
echo "- Status : " . $resultRE['status'] . PHP_EOL;
echo "- Stderr : " . trim($resultRE['stderr']) . PHP_EOL;
