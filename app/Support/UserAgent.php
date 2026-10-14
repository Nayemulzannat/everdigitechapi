<?php

namespace App\Support;

/** Just enough user-agent parsing for the sign-in history. */
class UserAgent
{
    /** @return array{browser: string|null, platform: string|null} */
    public static function parse(?string $ua): array
    {
        if (! $ua) {
            return ['browser' => null, 'platform' => null];
        }

        // Order matters: Edge and Opera also say "Chrome", Chrome also says "Safari".
        $browser = match (true) {
            (bool) preg_match('~Edg(?:e|A|iOS)?/~', $ua) => 'Edge',
            (bool) preg_match('~OPR/|Opera~', $ua) => 'Opera',
            (bool) preg_match('~Firefox/|FxiOS/~', $ua) => 'Firefox',
            (bool) preg_match('~Chrome/|CriOS/~', $ua) => 'Chrome',
            (bool) preg_match('~Safari/~', $ua) => 'Safari',
            default => null,
        };

        $platform = match (true) {
            (bool) preg_match('~Windows~', $ua) => 'Windows',
            (bool) preg_match('~Android~', $ua) => 'Android',
            (bool) preg_match('~iPhone|iPad|iPod~', $ua) => 'iOS',
            (bool) preg_match('~Mac OS X|Macintosh~', $ua) => 'macOS',
            (bool) preg_match('~Linux|X11~', $ua) => 'Linux',
            default => null,
        };

        return ['browser' => $browser, 'platform' => $platform];
    }
}
