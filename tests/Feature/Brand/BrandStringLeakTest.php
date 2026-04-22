<?php

namespace Tests\Feature\Brand;

use Tests\TestCase;

class BrandStringLeakTest extends TestCase
{
    public function test_throughline_literal_does_not_leak_outside_allowlist(): void
    {
        $base = base_path();
        $allowedPrefixes = [
            'app/Services/Brand/',
            'config/brand.php',
            'docs/',
            'tests/',
            'database/seeders/',
            'resources/views/mail/', // templated brand name in emails is fine
            'resources/js/components/brand/', // brand display components are the source of truth for rendering the name
            'README.md',
            'CLAUDE.md',
            'CHANGELOG.md',
            'package.json',
            'composer.json',
            '.github/',
        ];

        $offenders = [];
        foreach ($this->candidateFiles() as $file) {
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($base) + 1));
            if ($this->allowed($rel, $allowedPrefixes)) {
                continue;
            }

            $content = file_get_contents($file);
            if (preg_match('/\bThroughline\b/', $content)) {
                $offenders[] = $rel;
            }
        }

        $this->assertSame([], $offenders, 'Brand-string leak found. Route through BrandConfig or SiteSettings instead.');
    }

    protected function allowed(string $rel, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                return true;
            }
        }

        return false;
    }

    protected function candidateFiles(): iterable
    {
        $base = base_path();
        $extensions = ['php', 'vue', 'blade.php', 'ts', 'js'];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($it as $f) {
            $path = $f->getPathname();
            if (! $f->isFile()) {
                continue;
            }
            if (str_contains($path, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            if (str_contains($path, DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            if (str_contains($path, DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            if (str_contains($path, DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $hasExt = false;
            foreach ($extensions as $ext) {
                if (str_ends_with($path, '.'.$ext)) {
                    $hasExt = true;
                    break;
                }
            }
            if (! $hasExt) {
                continue;
            }

            yield $path;
        }
    }
}
