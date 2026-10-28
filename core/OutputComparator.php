<?php
/**
 * BỘ SO SÁNH ĐẦU RA (OUTPUT COMPARATOR)
 * Đề tài 20: Tự viết hàm so sánh chuẩn hóa 3 bước theo yêu cầu đề bài
 */

class OutputComparator {
    /**
     * Chuẩn hóa chuỗi đầu ra theo quy tắc 3 bước:
     * 1. Đổi toàn bộ \r\n (Windows) thành \n (Linux).
     * 2. Cắt bỏ khoảng trắng thừa (trailing spaces) ở cuối mỗi dòng.
     * 3. Cắt bỏ các dòng trống thừa ở cuối cùng của toàn bộ chuỗi (rtrim).
     *
     * @param string $output
     * @return string
     */
    public static function normalize(string $output): string {
        // Bước 1: Chuẩn hóa ký tự xuống dòng (\r\n và \r thành \n)
        $normalized = str_replace(["\r\n", "\r"], "\n", $output);

        // Bước 2: Tách thành từng dòng và cắt khoảng trắng thừa ở cuối mỗi dòng
        $lines = explode("\n", $normalized);
        $trimmedLines = array_map(function($line) {
            return rtrim($line, " \t");
        }, $lines);
        $normalized = implode("\n", $trimmedLines);

        // Bước 3: Cắt bỏ các dòng trống và khoảng trắng thừa ở cuối cùng của toàn bộ chuỗi
        return rtrim($normalized);
    }

    /**
     * So sánh tuyệt đối (===) giữa output của học viên và output mong đợi
     *
     * @param string $actualOutput Output thực tế của người học
     * @param string $expectedOutput Output đáp án chuẩn từ test case
     * @return bool True nếu khớp hoàn toàn, False nếu sai khác
     */
    public static function compare(string $actualOutput, string $expectedOutput): bool {
        return self::normalize($actualOutput) === self::normalize($expectedOutput);
    }
}
