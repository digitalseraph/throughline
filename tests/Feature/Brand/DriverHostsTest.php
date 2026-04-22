<?php

namespace Tests\Feature\Brand;

use Tests\TestCase;

class DriverHostsTest extends TestCase
{
    /**
     * Driver upstream hostnames must not leak outside the driver classes,
     * config/services.php, or test fixtures. This prevents accidental
     * hardcoding that would make swapping drivers painful.
     */
    public function test_driver_hosts_not_leaked_outside_driver_classes(): void
    {
        $hostPatterns = [
            'boards-api.greenhouse.io',
            'api.lever.co',
            'remoteok.com/api',
        ];

        $allowedPathFragments = [
            'app/Services/Jobs/Drivers/',
            'config/services.php',
            'tests/', // fixtures and driver tests mention the hosts
        ];

        $offenders = [];
        foreach ($this->projectPhpFiles() as $file) {
            $content = file_get_contents($file);
            foreach ($hostPatterns as $pattern) {
                if (! str_contains($content, $pattern)) {
                    continue;
                }
                $normalised = str_replace('\\', '/', $file);
                $allowed = collect($allowedPathFragments)->contains(
                    fn ($frag) => str_contains($normalised, $frag)
                );
                if (! $allowed) {
                    $offenders[] = "$file contains '$pattern'";
                }
            }
        }

        $this->assertSame([], $offenders, "Driver host strings found outside allowed paths:\n".implode("\n", $offenders));
    }

    /** @return string[] */
    protected function projectPhpFiles(): array
    {
        $base = base_path();
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        $out = [];
        foreach ($it as $f) {
            $path = $f->getPathname();
            if (! str_ends_with($path, '.php')) {
                continue;
            }
            $normalised = str_replace('\\', '/', $path);
            if (str_contains($normalised, '/vendor/') || str_contains($normalised, '/node_modules/')) {
                continue;
            }
            $out[] = $path;
        }

        return $out;
    }
}
