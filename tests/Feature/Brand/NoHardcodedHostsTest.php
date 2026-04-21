<?php

namespace Tests\Feature\Brand;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class NoHardcodedHostsTest extends TestCase
{
    /**
     * The spec forbids hardcoded host strings anywhere in shipped templates,
     * mail, or frontend source. Deployment must be driven by APP_URL and
     * site_settings alone. See spec §10 "Deployment Portability."
     */
    public function test_no_hardcoded_host_strings_in_shipped_sources(): void
    {
        $finder = Finder::create()
            ->in([
                base_path('resources'),
                base_path('app'),
            ])
            ->files()
            ->name(['*.php', '*.vue', '*.ts', '*.js', '*.blade.php'])
            ->notPath('vendor')
            ->notPath('node_modules');

        $forbidden = [
            'improbica.com',
            'digitalseraph.com',
            'jobber.improbica.com',
            'jobber.digitalseraph.com',
        ];

        $hits = [];
        foreach ($finder as $file) {
            $contents = $file->getContents();
            foreach ($forbidden as $needle) {
                if (str_contains($contents, $needle)) {
                    $hits[] = $file->getRelativePathname().' contains "'.$needle.'"';
                }
            }
        }

        $this->assertEmpty($hits, "Hardcoded host strings found:\n".implode("\n", $hits));
    }
}
