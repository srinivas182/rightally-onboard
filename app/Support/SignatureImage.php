<?php

namespace App\Support;

/**
 * Crops a drawn signature (transparent PNG from the signature pad) to the
 * ink, with a little padding, so it prints at a readable size.
 */
final class SignatureImage
{
    public static function trim(string $png, int $padding = 12): string
    {
        $img = @imagecreatefromstring($png);
        if ($img === false) {
            return $png;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $minX = $w;
        $minY = $h;
        $maxX = -1;
        $maxY = -1;

        // Sample every 2nd pixel: plenty for pen strokes and much faster.
        for ($y = 0; $y < $h; $y += 2) {
            for ($x = 0; $x < $w; $x += 2) {
                $alpha = (imagecolorat($img, $x, $y) >> 24) & 0x7F; // 127 = fully transparent
                if ($alpha < 100) {
                    $minX = min($minX, $x);
                    $maxX = max($maxX, $x);
                    $minY = min($minY, $y);
                    $maxY = max($maxY, $y);
                }
            }
        }
        if ($maxX < 0) {
            imagedestroy($img);

            return $png;
        }

        $x0 = max(0, $minX - $padding);
        $y0 = max(0, $minY - $padding);
        $cw = min($w, $maxX + $padding + 2) - $x0;
        $ch = min($h, $maxY + $padding + 2) - $y0;

        $out = imagecreatetruecolor($cw, $ch);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopy($out, $img, 0, 0, $x0, $y0, $cw, $ch);

        ob_start();
        imagepng($out);
        $result = (string) ob_get_clean();
        imagedestroy($img);
        imagedestroy($out);

        return $result;
    }
}
