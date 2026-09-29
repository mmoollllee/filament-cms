<?php

namespace Mmoollllee\Cms\Support\Media;

/**
 * Removes metadata from encoded JPEG, WebP and PNG files without re-encoding them.
 *
 * Camera metadata has no business in a public derivative: phone photos carry GPS coordinates,
 * device and capture time, often an embedded preview JPEG as well. The uploaded original keeps
 * all of it; conversions, srcset candidates and placeholders drop it. Working on the container
 * level (segments and chunks) keeps the pixels bit-identical — no second lossy encode.
 *
 * The colour profile survives unless asked otherwise: without it an iPhone's Display P3 photo
 * renders as if it were sRGB, visibly duller. Anything that is not one of the three formats is
 * returned untouched.
 */
class ImageMetadata
{
    /**
     * @param  bool  $keepColorProfile  keep the ICC profile (JPEG APP2, WebP ICCP, PNG iCCP/sRGB)
     */
    public static function strip(string $bytes, bool $keepColorProfile = true): string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8") => static::stripJpeg($bytes, $keepColorProfile),
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => static::stripWebp($bytes, $keepColorProfile),
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => static::stripPng($bytes, $keepColorProfile),
            default => $bytes,
        };
    }

    public static function has(string $bytes, bool $keepColorProfile = true): bool
    {
        return static::strip($bytes, $keepColorProfile) !== $bytes;
    }

    /**
     * Strip a file in place. Returns whether anything was removed.
     */
    public static function stripFile(string $path, bool $keepColorProfile = true): bool
    {
        $bytes = (string) file_get_contents($path);
        $stripped = static::strip($bytes, $keepColorProfile);

        if ($stripped === $bytes) {
            return false;
        }

        file_put_contents($path, $stripped);

        return true;
    }

    /**
     * Drops EXIF/XMP (APP1), the application segments APP3–APP13 and APP15 (IPTC among them)
     * and comments. Keeps JFIF (APP0), the Adobe segment (APP14, which decides how CMYK data
     * decodes) and — if asked — the ICC profile, the only APP2 payload kept.
     */
    protected static function stripJpeg(string $jpeg, bool $keepColorProfile): string
    {
        $kept = "\xFF\xD8";
        $offset = 2;
        $length = strlen($jpeg);

        while ($offset + 4 <= $length && $jpeg[$offset] === "\xFF") {
            // Fill bytes: any number of 0xFF may pad the space before a marker.
            if ($jpeg[$offset + 1] === "\xFF") {
                $offset++;

                continue;
            }

            $marker = ord($jpeg[$offset + 1]);

            // Start of scan: the entropy-coded image data runs to the end of the file.
            if ($marker === 0xDA) {
                return $kept.substr($jpeg, $offset);
            }

            $segmentLength = unpack('n', substr($jpeg, $offset + 2, 2))[1] + 2;
            $segment = substr($jpeg, $offset, $segmentLength);

            $isColorProfile = $marker === 0xE2 && str_starts_with(substr($segment, 4), "ICC_PROFILE\0");
            $isMetadata = $marker === 0xFE
                || $marker === 0xE1
                || ($marker >= 0xE3 && $marker <= 0xED)
                || $marker === 0xEF
                || ($marker === 0xE2 && ! ($isColorProfile && $keepColorProfile));

            if (! $isMetadata) {
                $kept .= $segment;
            }

            $offset += $segmentLength;
        }

        // Not a JPEG this parser understands: leave it as it came.
        return $jpeg;
    }

    /**
     * Drops the EXIF and XMP chunks (and ICCP unless kept) and clears their flags in VP8X.
     */
    protected static function stripWebp(string $webp, bool $keepColorProfile): string
    {
        $drop = $keepColorProfile ? ['EXIF', 'XMP '] : ['EXIF', 'XMP ', 'ICCP'];
        $chunks = '';
        $offset = 12;
        $length = strlen($webp);
        $removed = false;

        while ($offset + 8 <= $length) {
            $fourCc = substr($webp, $offset, 4);
            $size = unpack('V', substr($webp, $offset + 4, 4))[1];
            $chunkLength = 8 + $size + ($size % 2);

            if (in_array($fourCc, $drop, true)) {
                $removed = true;
            } else {
                $chunks .= substr($webp, $offset, $chunkLength);
            }

            $offset += $chunkLength;
        }

        if (! $removed) {
            return $webp;
        }

        // VP8X announces the optional chunks in its flags byte: ICC 0x20, EXIF 0x08, XMP 0x04.
        if (str_starts_with($chunks, 'VP8X')) {
            $cleared = 0x08 | 0x04 | ($keepColorProfile ? 0 : 0x20);
            $chunks[8] = chr(ord($chunks[8]) & ~$cleared);
        }

        return 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;
    }

    /**
     * Drops the text, EXIF and timestamp chunks (and the colour chunks unless kept).
     */
    protected static function stripPng(string $png, bool $keepColorProfile): string
    {
        $drop = ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'];

        if (! $keepColorProfile) {
            array_push($drop, 'iCCP', 'sRGB');
        }

        $kept = substr($png, 0, 8);
        $offset = 8;
        $length = strlen($png);

        while ($offset + 12 <= $length) {
            $size = unpack('N', substr($png, $offset, 4))[1];
            $type = substr($png, $offset + 4, 4);
            $chunkLength = 12 + $size;

            if (! in_array($type, $drop, true)) {
                $kept .= substr($png, $offset, $chunkLength);
            }

            $offset += $chunkLength;

            if ($type === 'IEND') {
                return $kept;
            }
        }

        return $png;
    }
}
