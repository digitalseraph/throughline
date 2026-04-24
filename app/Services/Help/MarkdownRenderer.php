<?php

namespace App\Services\Help;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use League\CommonMark\MarkdownConverter;

class MarkdownRenderer
{
    public function toHtml(string $markdown): string
    {
        $environment = new Environment([
            'heading_permalink' => [
                'symbol' => '¶',
                'title' => 'Permalink to this heading',
                'insert' => 'after',
                'html_class' => 'help-anchor',
            ],
            'table_of_contents' => [
                'html_class' => 'help-toc',
                'position' => 'placeholder',
                'placeholder' => '[TOC]',
                'min_heading_level' => 2,
                'max_heading_level' => 3,
            ],
        ]);

        $environment
            ->addExtension(new CommonMarkCoreExtension())
            ->addExtension(new GithubFlavoredMarkdownExtension())
            ->addExtension(new AutolinkExtension())
            ->addExtension(new HeadingPermalinkExtension())
            ->addExtension(new TableOfContentsExtension());

        $converter = new MarkdownConverter($environment);

        return (string) $converter->convert($markdown);
    }
}
