<?php

namespace App\Support;

/**
 * 견적 카드 PNG (1200×630, OG 미리보기용) — 서버 렌더.
 *
 * 카톡/왓츠앱은 링크 unfurl 시 og:image 를 서버에서 가져감(JS 미실행) → 견적카드를 서버에서 그려야 함.
 * 바이어페이지/전달드로어 카드와 동일 톤: 브랜드 퍼플 헤더 + Car/Shipping/Total 3줄.
 * 차량번호(한글)는 DejaVu 미지원이라 카드에 안 넣음 — og:title 에서 채팅앱이 네이티브로 렌더.
 */
class QuoteCardImage
{
    private const W = 1200;

    private const H = 630;

    /** 형식 완료된(통화기호 포함) 금액 문자열 3개를 받아 PNG 바이트 반환. */
    public function render(string $car, string $shipping, string $total): string
    {
        $reg = resource_path('fonts/DejaVuSans.ttf');
        $bold = resource_path('fonts/DejaVuSans-Bold.ttf');

        $im = imagecreatetruecolor(self::W, self::H);
        $purple = imagecolorallocate($im, 124, 111, 205);   // #7c6fcd
        $white = imagecolorallocate($im, 255, 255, 255);
        $dark = imagecolorallocate($im, 17, 24, 39);        // #111827
        $gray = imagecolorallocate($im, 107, 114, 128);     // #6b7280
        $line = imagecolorallocate($im, 229, 231, 235);     // #e5e7eb

        imagefilledrectangle($im, 0, 0, self::W, self::H, $white);

        // 헤더 바
        imagefilledrectangle($im, 0, 0, self::W, 150, $purple);
        imagettftext($im, 22, 0, 64, 60, $white, $bold, 'SSANCAR');
        imagettftext($im, 50, 0, 64, 120, $white, $bold, 'QUOTATION');

        // 본문 3줄
        $left = 64;
        $right = self::W - 64;
        $this->rowLeft($im, 40, $left, 280, $gray, $reg, 'Car Price');
        $this->rowRight($im, 40, $right, 280, $dark, $reg, $car);
        $this->rowLeft($im, 40, $left, 370, $gray, $reg, 'Shipping');
        $this->rowRight($im, 40, $right, 370, $dark, $reg, $shipping);

        // 구분선 + Total
        imagefilledrectangle($im, $left, 430, $right, 432, $line);
        $this->rowLeft($im, 52, $left, 520, $dark, $bold, 'Total');
        $this->rowRight($im, 56, $right, 522, $purple, $bold, $total);

        // 푸터
        $this->rowLeft($im, 22, $left, 600, $gray, $reg, 'ssancar.com');

        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return $png;
    }

    /** baseline 좌측 정렬. */
    private function rowLeft($im, int $size, int $x, int $y, int $color, string $font, string $text): void
    {
        imagettftext($im, $size, 0, $x, $y, $color, $font, $text);
    }

    /** baseline 우측 정렬(텍스트 폭만큼 왼쪽으로). */
    private function rowRight($im, int $size, int $rightX, int $y, int $color, string $font, string $text): void
    {
        $bbox = imagettfbbox($size, 0, $font, $text);
        $width = abs($bbox[2] - $bbox[0]);
        imagettftext($im, $size, 0, $rightX - $width, $y, $color, $font, $text);
    }
}
