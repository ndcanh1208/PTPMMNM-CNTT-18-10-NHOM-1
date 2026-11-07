<?php
/**
 * ByteFlow Online Judge - Bộ So sánh Đầu ra (Comparator)
 * Chuẩn hóa ký tự xuống dòng (\r\n, \r, \n) và khoảng trắng cuối dòng
 * Tuân thủ tiêu chuẩn so sánh của các hệ thống Online Judge chuẩn quốc tế
 */

declare(strict_types=1);

class Comparator
{
    /**
     * Chuẩn hóa chuỗi văn bản đầu ra:
     * 1. Thống nhất ký tự xuống dòng về \n
     * 2. Xóa khoảng trắng và tab ở cuối mỗi dòng (trailing whitespace)
     * 3. Xóa các dòng trống ở cuối chuỗi văn bản
     */
    public static function normalize(string $text): string
    {
        // 1. Đồng nhất xuống dòng về \n
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // 2. Tách từng dòng và xóa khoảng trắng cuối mỗi dòng
        $lines = explode("\n", $text);
        $normalizedLines = array_map(function ($line) {
            return rtrim($line, " \t");
        }, $lines);

        // 3. Xóa các dòng trống ở cuối
        while (!empty($normalizedLines) && end($normalizedLines) === '') {
            array_pop($normalizedLines);
        }

        return implode("\n", $normalizedLines);
    }

    /**
     * So sánh đầu ra thực tế (actual) với đầu ra kỳ vọng (expected)
     *
     * @param string $actual
     * @param string $expected
     * @return array ['match' => bool, 'diff_line' => ?int, 'actual' => string, 'expected' => string]
     */
    public static function compare(string $actual, string $expected): array
    {
        $normActual = self::normalize($actual);
        $normExpected = self::normalize($expected);

        // So sánh trực tiếp chuỗi đã chuẩn hóa
        if ($normActual === $normExpected) {
            return [
                'match'       => true,
                'diff_line'   => null,
                'actual'      => $normActual,
                'expected'    => $normExpected
            ];
        }

        // Nếu khác nhau, tìm dòng khác nhau đầu tiên để hỗ trợ gỡ lỗi
        $actualLines   = explode("\n", $normActual);
        $expectedLines = explode("\n", $normExpected);
        $maxLines      = max(count($actualLines), count($expectedLines));

        $diffLine = null;
        for ($i = 0; $i < $maxLines; $i++) {
            $act = $actualLines[$i] ?? null;
            $exp = $expectedLines[$i] ?? null;

            if ($act !== $exp) {
                $diffLine = $i + 1; // 1-based line index
                break;
            }
        }

        return [
            'match'       => false,
            'diff_line'   => $diffLine,
            'actual'      => $normActual,
            'expected'    => $normExpected
        ];
    }
}
