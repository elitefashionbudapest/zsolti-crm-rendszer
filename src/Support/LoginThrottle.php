<?php

declare(strict_types=1);

namespace App\Support;

/**
 * IP-alapú belépési sebességkorlátozó (brute-force ellen). Fájl-alapú, ezért
 * NEM kerülhető meg a kliens session-cookie-jának eldobásával.
 */
final class LoginThrottle
{
    private const MAX = 10;
    private const WINDOW = 900; // 15 perc

    public function __construct(private string $cacheDir)
    {
    }

    public function tooMany(string $ip): bool
    {
        $d = $this->read($ip);
        if ($d === null || (time() - $d['first']) > self::WINDOW) {
            return false;
        }

        return $d['count'] >= self::MAX;
    }

    public function hit(string $ip): void
    {
        $d = $this->read($ip);
        if ($d === null || (time() - $d['first']) > self::WINDOW) {
            $d = ['count' => 0, 'first' => time()];
        }
        $d['count']++;
        @file_put_contents($this->file($ip), json_encode($d));
    }

    public function clear(string $ip): void
    {
        $f = $this->file($ip);
        if (is_file($f)) {
            @unlink($f);
        }
    }

    private function file(string $ip): string
    {
        $dir = $this->cacheDir . '/throttle';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . '/login_' . sha1($ip) . '.json';
    }

    /** @return array{count:int, first:int}|null */
    private function read(string $ip): ?array
    {
        $f = $this->file($ip);
        if (!is_file($f)) {
            return null;
        }
        $j = json_decode((string) @file_get_contents($f), true);
        if (!is_array($j) || !isset($j['count'], $j['first'])) {
            return null;
        }

        return ['count' => (int) $j['count'], 'first' => (int) $j['first']];
    }
}
