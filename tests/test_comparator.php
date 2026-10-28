<?php
require_once __DIR__ . '/../core/OutputComparator.php';

// Test 1: Chuẩn hóa \r\n thành \n
$t1_actual = "Hello\r\nWorld\r\n";
$t1_expected = "Hello\nWorld";
assert(OutputComparator::compare($t1_actual, $t1_expected), "Test 1 failed: Newline normalization");

// Test 2: Khoảng trắng thừa cuối dòng
$t2_actual = "Line 1   \nLine 2 \t \nLine 3";
$t2_expected = "Line 1\nLine 2\nLine 3";
assert(OutputComparator::compare($t2_actual, $t2_expected), "Test 2 failed: Trailing spaces per line");

// Test 3: Dòng trống thừa ở cuối chuỗi
$t3_actual = "Result: 42\n\n\n   \n";
$t3_expected = "Result: 42";
assert(OutputComparator::compare($t3_actual, $t3_expected), "Test 3 failed: Trailing blank lines");

// Test 4: Sai kết quả phải trả về false
$t4_actual = "Result: 42";
$t4_expected = "Result: 43";
assert(!OutputComparator::compare($t4_actual, $t4_expected), "Test 4 failed: Difference detection");

echo "ALL 4 COMPARATOR TESTS PASSED 100%!" . PHP_EOL;
