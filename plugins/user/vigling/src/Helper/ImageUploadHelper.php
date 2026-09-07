<?php

namespace Joomla\Plugin\User\Vigling\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Filesystem\Folder;

final class ImageUploadHelper
{
    public const MAX_BYTES = 20971520;
    public const JPEG_QUALITY = 82;
    public const AVATAR_MAX_EDGE = 600;
    public const AVATAR_THUMB_EDGE = 240;
    public const PHOTO_MAX_EDGE = 1600;
    public const PHOTO_THUMB_EDGE = 640;
    public const ACCEPT_ATTR = '.jpg,.jpeg,.png,.webp,.gif,.heic,.heif,image/jpeg,image/png,image/webp,image/gif';

    private const ALLOWED_DIRS = [
        'images/profiler',
        'images/portfolio',
        'images/course',
        'images/search',
    ];

    /**
     * @return array{ok:bool, path:string, error:string}
     */
    public static function saveUploaded(
        string $tmpPath,
        string $originalName,
        int $size,
        string $relativeDir,
        string $filePrefix,
        int $maxEdge,
        int $thumbEdge
    ): array {
        $fail = static function (string $error): array {
            return ['ok' => false, 'path' => '', 'error' => $error];
        };

        $originalName = trim($originalName);
        $label = $originalName !== '' ? $originalName : 'файл';
        $relativeDir = self::normalizeDir($relativeDir);
        if ($relativeDir === '' || !self::isAllowedDir($relativeDir)) {
            return $fail('Не удалось сохранить «' . $label . '».');
        }
        if ($tmpPath === '' || !is_file($tmpPath)) {
            return $fail('Не удалось прочитать «' . $label . '».');
        }
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return $fail('Файл «' . $label . '» больше 20 МБ и не будет загружен.');
        }

        $detected = self::detectType($tmpPath, $originalName);
        if ($detected === 'heic') {
            $converted = self::heicToJpegBlob($tmpPath);
            if ($converted === null) {
                return $fail('Файл «' . $label . '» не загружен: формат HEIC/HEIF нужно сохранить как JPEG или PNG.');
            }
            $tmpJpeg = $tmpPath . '.conv.jpg';
            if (@file_put_contents($tmpJpeg, $converted) === false) {
                return $fail('Не удалось обработать «' . $label . '».');
            }
            $tmpPath = $tmpJpeg;
            $detected = 'jpeg';
        }
        if (!in_array($detected, ['jpeg', 'png', 'gif', 'webp'], true)) {
            return $fail('Файл «' . $label . '» не загружен. Допустимы JPEG, PNG, WebP и GIF.');
        }

        $root = self::root();
        $absDir = $root . '/' . $relativeDir;
        Folder::create($absDir);

        $baseName = $filePrefix . '_' . bin2hex(random_bytes(6));
        $relPath = $relativeDir . '/' . $baseName . '.jpg';
        $absPath = $root . '/' . $relPath;
        $thumbRel = $relativeDir . '/' . $baseName . '_t.jpg';
        $thumbAbs = $root . '/' . $thumbRel;

        $written = self::writeJpegPair($tmpPath, $detected, $absPath, $thumbAbs, $maxEdge, $thumbEdge);
        if (str_ends_with($tmpPath, '.conv.jpg')) {
            @unlink($tmpPath);
        }
        if (!$written) {
            @unlink($absPath);
            @unlink($thumbAbs);

            return $fail('Не удалось обработать «' . $label . '». Попробуйте JPEG или PNG.');
        }

        return ['ok' => true, 'path' => $relPath, 'error' => ''];
    }

    public static function deleteStored(string $storedPath): void
    {
        $relative = self::storedToRelative($storedPath);
        if ($relative === '' || !self::isAllowedRelative($relative)) {
            return;
        }
        $root = self::root();
        $abs = $root . '/' . $relative;
        if (is_file($abs)) {
            @unlink($abs);
        }
        $thumb = self::thumbRelative($relative);
        if ($thumb !== $relative && is_file($root . '/' . $thumb)) {
            @unlink($root . '/' . $thumb);
        }
        $main = self::mainRelativeFromThumb($relative);
        if ($main !== $relative && is_file($root . '/' . $main)) {
            @unlink($root . '/' . $main);
        }
    }

    public static function webUrl(string $urlOrPath, bool $preferThumb = false): string
    {
        $raw = trim($urlOrPath);
        if ($raw === '') {
            return '';
        }
        if (stripos($raw, 'http://') === 0 || stripos($raw, 'https://') === 0) {
            $path = (string) (parse_url($raw, PHP_URL_PATH) ?: '');
            $relative = ltrim(str_replace('\\', '/', $path), '/');
        } else {
            $relative = ltrim(str_replace('\\', '/', $raw), '/');
        }
        if ($relative === '') {
            return $raw;
        }
        if (!self::isAllowedRelative($relative)) {
            return $raw[0] === '/' || stripos($raw, 'http') === 0 ? $raw : '/' . $relative;
        }
        if ($preferThumb) {
            $thumb = self::thumbRelative($relative);
            if (is_file(self::root() . '/' . $thumb)) {
                return '/' . $thumb;
            }
        }

        return '/' . $relative;
    }

    public static function warn(string $message): void
    {
        $message = trim($message);
        if ($message === '') {
            return;
        }
        try {
            Factory::getApplication()->enqueueMessage($message, 'warning');
        } catch (\Throwable $e) {
        }
    }

    public static function canProcess(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagejpeg');
    }

    private static function writeJpegPair(
        string $srcPath,
        string $detected,
        string $destAbs,
        string $thumbAbs,
        int $maxEdge,
        int $thumbEdge
    ): bool {
        $gd = self::loadGd($srcPath, $detected);
        if ($gd === null) {
            if (class_exists(\Imagick::class)) {
                return self::writeJpegPairImagick($srcPath, $destAbs, $thumbAbs, $maxEdge, $thumbEdge);
            }

            return false;
        }

        $gd = self::applyExifOrientation($gd, $srcPath, $detected);
        $full = self::resampleToEdge($gd, $maxEdge);
        if ($full === null) {
            imagedestroy($gd);

            return false;
        }
        $ok = imagejpeg($full, $destAbs, self::JPEG_QUALITY);
        if ($ok) {
            $thumb = self::resampleToEdge($full, $thumbEdge);
            if ($thumb !== null) {
                imagejpeg($thumb, $thumbAbs, self::JPEG_QUALITY);
                if ($thumb !== $full) {
                    imagedestroy($thumb);
                }
            } else {
                @copy($destAbs, $thumbAbs);
            }
        }
        if ($full !== $gd) {
            imagedestroy($full);
        }
        imagedestroy($gd);

        return $ok && is_file($destAbs);
    }

    private static function writeJpegPairImagick(
        string $srcPath,
        string $destAbs,
        string $thumbAbs,
        int $maxEdge,
        int $thumbEdge
    ): bool {
        try {
            $im = new \Imagick($srcPath);
            if (method_exists($im, 'autoOrient')) {
                $im->autoOrient();
            }
            self::flattenImagick($im);
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(self::JPEG_QUALITY);
            self::fitImagick($im, $maxEdge);
            $im->writeImage($destAbs);
            $thumb = clone $im;
            self::fitImagick($thumb, $thumbEdge);
            $thumb->writeImage($thumbAbs);
            $thumb->clear();
            $im->clear();

            return is_file($destAbs);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function flattenImagick(\Imagick $im): void
    {
        $im->setImageBackgroundColor(new \ImagickPixel('white'));
        if (defined('Imagick::ALPHACHANNEL_REMOVE') && method_exists($im, 'setImageAlphaChannel')) {
            $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
        }
    }

    private static function fitImagick(\Imagick $im, int $maxEdge): void
    {
        $maxEdge = max(1, $maxEdge);
        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        if ($w <= $maxEdge && $h <= $maxEdge) {
            return;
        }
        $im->thumbnailImage($maxEdge, $maxEdge, true);
    }

    /**
     * @return \GdImage|resource|null
     */
    private static function loadGd(string $path, string $detected)
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $blob = @file_get_contents($path);
        if (!is_string($blob) || $blob === '') {
            return null;
        }
        $img = @imagecreatefromstring($blob);
        if ($img) {
            return $img;
        }
        if ($detected === 'jpeg' && function_exists('imagecreatefromjpeg')) {
            return @imagecreatefromjpeg($path) ?: null;
        }
        if ($detected === 'png' && function_exists('imagecreatefrompng')) {
            return @imagecreatefrompng($path) ?: null;
        }
        if ($detected === 'gif' && function_exists('imagecreatefromgif')) {
            return @imagecreatefromgif($path) ?: null;
        }
        if ($detected === 'webp' && function_exists('imagecreatefromwebp')) {
            return @imagecreatefromwebp($path) ?: null;
        }

        return null;
    }

    /**
     * @param \GdImage|resource $gd
     * @return \GdImage|resource
     */
    private static function applyExifOrientation($gd, string $path, string $detected)
    {
        if ($detected !== 'jpeg' || !function_exists('exif_read_data') || !function_exists('imagerotate')) {
            return $gd;
        }
        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 0) : 0;
        $rotated = null;
        if ($orientation === 3) {
            $rotated = imagerotate($gd, 180, 0);
        } elseif ($orientation === 6) {
            $rotated = imagerotate($gd, -90, 0);
        } elseif ($orientation === 8) {
            $rotated = imagerotate($gd, 90, 0);
        }
        if ($rotated) {
            imagedestroy($gd);

            return $rotated;
        }

        return $gd;
    }

    /**
     * @param \GdImage|resource $src
     * @return \GdImage|resource|null
     */
    private static function resampleToEdge($src, int $maxEdge)
    {
        $maxEdge = max(1, $maxEdge);
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w <= 0 || $h <= 0) {
            return null;
        }
        if (($w * $h) > 40000000) {
            return null;
        }
        $scale = min(1, $maxEdge / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if (!$dst) {
            return null;
        }
        imagealphablending($dst, true);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $dst;
    }

    private static function heicToJpegBlob(string $path): ?string
    {
        if (!class_exists(\Imagick::class)) {
            return null;
        }
        try {
            $im = new \Imagick($path);
            if (method_exists($im, 'autoOrient')) {
                $im->autoOrient();
            }
            self::flattenImagick($im);
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(self::JPEG_QUALITY);
            $blob = $im->getImageBlob();
            $im->clear();

            return is_string($blob) && $blob !== '' ? $blob : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function detectType(string $path, string $originalName = ''): string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 16);
        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
            return 'jpeg';
        }
        if (strncmp($head, "\x89PNG\x0D\x0A\x1A\x0A", 8) === 0) {
            return 'png';
        }
        if (strncmp($head, 'GIF8', 4) === 0) {
            return 'gif';
        }
        if (strncmp($head, 'RIFF', 4) === 0 && strpos($head, 'WEBP') !== false) {
            return 'webp';
        }
        if (self::looksLikeHeic($head, $originalName)) {
            return 'heic';
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $map = [
            'jpg' => 'jpeg',
            'jpeg' => 'jpeg',
            'png' => 'png',
            'gif' => 'gif',
            'webp' => 'webp',
            'heic' => 'heic',
            'heif' => 'heic',
        ];

        return $map[$ext] ?? '';
    }

    private static function looksLikeHeic(string $head, string $originalName): bool
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (in_array($ext, ['heic', 'heif'], true)) {
            return true;
        }
        if (strlen($head) >= 12 && strpos($head, 'ftyp') !== false) {
            $probe = strtolower($head);

            return strpos($probe, 'heic') !== false
                || strpos($probe, 'heif') !== false
                || strpos($probe, 'mif1') !== false;
        }

        return false;
    }

    public static function thumbRelative(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if (preg_match('/_t\.(jpe?g|png|webp|gif)$/i', $relative)) {
            return preg_replace('/\.(jpe?g|png|webp|gif)$/i', '.jpg', $relative) ?: $relative;
        }

        return preg_replace('/\.(jpe?g|png|webp|gif)$/i', '_t.jpg', $relative) ?: $relative;
    }

    private static function mainRelativeFromThumb(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if (preg_match('/_t\.(jpe?g|png|webp|gif)$/i', $relative)) {
            return preg_replace('/_t\.(jpe?g|png|webp|gif)$/i', '.jpg', $relative) ?: $relative;
        }

        return $relative;
    }

    private static function storedToRelative(string $storedPath): string
    {
        $storedPath = trim($storedPath);
        if ($storedPath === '') {
            return '';
        }
        if (stripos($storedPath, 'http://') === 0 || stripos($storedPath, 'https://') === 0) {
            $storedPath = (string) (parse_url($storedPath, PHP_URL_PATH) ?: '');
        }
        $relative = ltrim(str_replace('\\', '/', $storedPath), '/');
        if (self::isAllowedRelative($relative)) {
            return $relative;
        }
        if ($relative !== '' && strpos($relative, '/') === false && $relative !== '.' && $relative !== '..') {
            foreach (self::ALLOWED_DIRS as $dir) {
                $candidate = $dir . '/' . $relative;
                if (is_file(self::root() . '/' . $candidate)) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    private static function isAllowedDir(string $dir): bool
    {
        return in_array($dir, self::ALLOWED_DIRS, true);
    }

    private static function isAllowedRelative(string $relative): bool
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        foreach (self::ALLOWED_DIRS as $dir) {
            if (strpos($relative, $dir . '/') === 0) {
                $rest = substr($relative, strlen($dir) + 1);
                if ($rest !== '' && strpos($rest, '/') === false && strpos($rest, '..') === false) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function normalizeDir(string $dir): string
    {
        return trim(str_replace('\\', '/', $dir), '/');
    }

    private static function root(): string
    {
        return defined('JPATH_ROOT') ? rtrim((string) JPATH_ROOT, '/') : rtrim((string) (getcwd() ?: ''), '/');
    }
}
