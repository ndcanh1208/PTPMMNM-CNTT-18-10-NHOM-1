<?php
require_once __DIR__ . '/../core/ExecutionService.php';

echo "Dang kiem tra ket noi toi dich vu thuc thi cach ly (Piston API)..." . PHP_EOL;

// Test Python execution
$resPy = ExecutionService::execute("print('Ket qua:', 10 + 25)", 'python3');
echo "[Python Test] Status: " . $resPy['status'] . " | Output: " . trim($resPy['stdout']) . " | Time: " . $resPy['time_ms'] . "ms" . PHP_EOL;

// Test C++ execution
$codeCpp = "#include <iostream>\nusing namespace std;\nint main() { cout << \"C++ Chay Tot!\" << endl; return 0; }";
$resCpp = ExecutionService::execute($codeCpp, 'cpp');
echo "[C++ Test]    Status: " . $resCpp['status'] . " | Output: " . trim($resCpp['stdout']) . " | Time: " . $resCpp['time_ms'] . "ms" . PHP_EOL;

if ($resPy['status'] === 'SUCCESS' && $resCpp['status'] === 'SUCCESS') {
    echo ">>> DICH VU THUC THI CACH LY HOAT DONG HOAN HAO 100%! <<<" . PHP_EOL;
} else {
    echo ">>> Phap hien trang thai: " . $resPy['status'] . " | Thong bao: " . $resPy['message'] . PHP_EOL;
}
