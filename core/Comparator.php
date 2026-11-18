<?php
/**
 * Nhom1 Online Judge - Bộ So sánh Đầu ra (Comparator)
 *
 * Mục đích của tệp này:
 * - Chuẩn hóa chuỗi kết quả đầu ra (output) của chương trình thí sinh và đáp án chuẩn.
 * - Loại bỏ các sai khác vô hại về mặt hiển thị như:
 *   + Khác biệt ký tự xuống dòng giữa các hệ điều hành (\r\n trên Windows, \r trên macOS cũ, \n trên Linux).
 *   + Khoảng trắng (space) và ký tự tab (\t) thừa ở cuối mỗi dòng.
 *   + Các dòng trống thừa ở cuối toàn bộ kết quả.
 * - So sánh chính xác hai chuỗi sau chuẩn hóa và chỉ ra dòng đầu tiên bị sai khác để hỗ trợ gỡ lỗi (debug).
 * - Tuân thủ tiêu chuẩn so sánh của các hệ thống Online Judge chuẩn quốc tế.
 */

// Bật chế độ kiểm tra kiểu dữ liệu nghiêm ngặt (strict_types):
// PHP sẽ không tự động ép kiểu ngầm khi truyền tham số vào hàm, giúp phát hiện lỗi kiểu dữ liệu sớm.
declare(strict_types=1);

/**
 * Lớp Comparator (Bộ so sánh đầu ra)
 *
 * Chứa các phương thức tĩnh (static) nên có thể gọi trực tiếp dạng Comparator::compare(...)
 * mà không cần khởi tạo đối tượng (new Comparator()).
 */
class Comparator
{
    /**
     * Chuẩn hóa chuỗi văn bản đầu ra trước khi so sánh.
     *
     * Thuật toán gồm 3 bước:
     * 1. Thống nhất tất cả ký tự xuống dòng (\r\n và \r) về chuẩn duy nhất là \n.
     * 2. Xóa khoảng trắng (" ") và ký tự tab ("\t") ở CUỐI mỗi dòng (trailing whitespace).
     *    Lưu ý: KHÔNG xóa khoảng trắng ở đầu dòng (leading whitespace) vì nhiều bài toán
     *    (như vẽ hình tam giác, in căn lề) yêu cầu giữ đúng khoảng trắng đầu dòng.
     * 3. Xóa toàn bộ các dòng trống nằm ở cuối chuỗi văn bản.
     *
     * @param string $text Chuỗi văn bản đầu ra gốc cần chuẩn hóa.
     * @return string Chuỗi văn bản đã được chuẩn hóa sạch sẽ.
     */
    public static function normalize(string $text): string
    {
        // Bước 1: Đồng nhất mọi kiểu ký tự xuống dòng (\r\n của Windows, \r của Mac cũ) về \n (chuẩn Unix/Linux)
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Bước 2: Tách chuỗi thành mảng các dòng theo dấu \n, sau đó duyệt từng dòng
        // và dùng rtrim() để cắt bỏ khoảng trắng (" ") và tab ("\t") thừa ở cuối mỗi dòng
        $lines = explode("\n", $text);
        $normalizedLines = array_map(function ($line) {
            return rtrim($line, " \t");
        }, $lines);

        // Bước 3: Xóa các dòng trống nằm ở cuối mảng kết quả.
        // end($normalizedLines) lấy phần tử cuối cùng; nếu là chuỗi rỗng '' thì loại bỏ bằng array_pop()
        while (!empty($normalizedLines) && end($normalizedLines) === '') {
            array_pop($normalizedLines);
        }

        // Ghép các dòng đã chuẩn hóa lại thành một chuỗi duy nhất, ngăn cách bởi \n
        return implode("\n", $normalizedLines);
    }

    /**
     * So sánh đầu ra thực tế (actual) của bài làm với đầu ra kỳ vọng (expected) của test case.
     *
     * @param string $actual   Đầu ra thực tế do chương trình của người dùng in ra (stdout).
     * @param string $expected Đầu ra chuẩn (đáp án kỳ vọng) của ca kiểm thử.
     * @return array Mảng kết quả gồm 4 khóa:
     *               - 'match'     (bool)  : true nếu khớp hoàn toàn, false nếu có sai khác.
     *               - 'diff_line' (?int)  : Số thứ tự dòng khác nhau đầu tiên (đếm từ 1), hoặc null nếu khớp hoàn toàn.
     *               - 'actual'    (string): Đầu ra thực tế sau khi đã chuẩn hóa.
     *               - 'expected'  (string): Đầu ra kỳ vọng sau khi đã chuẩn hóa.
     */
    public static function compare(string $actual, string $expected): array
    {
        // Chuẩn hóa cả hai chuỗi đầu vào bằng hàm normalize() để so sánh công bằng
        $normActual = self::normalize($actual);
        $normExpected = self::normalize($expected);

        // Trường hợp khớp hoàn toàn (so sánh nghiêm ngặt === cả giá trị lẫn kiểu dữ liệu):
        // Trả về ngay kết quả match = true để tối ưu hiệu năng, không cần tách dòng duyệt lại.
        if ($normActual === $normExpected) {
            return [
                'match'       => true,
                'diff_line'   => null,
                'actual'      => $normActual,
                'expected'    => $normExpected
            ];
        }

        // Nếu khác nhau: tách cả hai chuỗi thành mảng dòng để dò tìm vị trí dòng sai khác đầu tiên
        $actualLines   = explode("\n", $normActual);
        $expectedLines = explode("\n", $normExpected);
        // Lấy số lượng dòng lớn hơn giữa hai bên để không bỏ sót trường hợp một bên in thừa/thiếu dòng
        $maxLines      = max(count($actualLines), count($expectedLines));

        // Biến lưu số thứ tự dòng khác nhau đầu tiên (bắt đầu từ 1 cho thân thiện với người đọc)
        $diffLine = null;
        for ($i = 0; $i < $maxLines; $i++) {
            // Dùng toán tử null coalescing (?? null) phòng trường hợp một mảng ngắn hơn mảng kia
            $act = $actualLines[$i] ?? null;
            $exp = $expectedLines[$i] ?? null;

            // Ngay khi gặp cặp dòng không giống nhau hoàn toàn thì ghi nhận vị trí và dừng vòng lặp
            if ($act !== $exp) {
                $diffLine = $i + 1; // Chỉ số dòng đánh số từ 1 (1-based line index)
                break;
            }
        }

        // Trả về kết quả không khớp kèm vị trí dòng sai khác đầu tiên và nội dung đã chuẩn hóa
        return [
            'match'       => false,
            'diff_line'   => $diffLine,
            'actual'      => $normActual,
            'expected'    => $normExpected
        ];
    }
}
