<?php

namespace App\Services\Help;

use Illuminate\Support\Collection;
use Spatie\YamlFrontMatter\YamlFrontMatter;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class DocsRepository
{
    /**
     * Ordered category slugs and their metadata. Categories are directories
     * under resources/docs/. Titles and order are derived from the category's
     * optional _index.md frontmatter, falling back to the slug.
     */
    public function categories(): Collection
    {
        $root = $this->rootPath();
        if (! is_dir($root)) {
            return collect();
        }

        $categories = collect();
        foreach (new \DirectoryIterator($root) as $entry) {
            if ($entry->isDot() || ! $entry->isDir()) {
                continue;
            }

            $slug = $entry->getFilename();
            $indexPath = $entry->getPathname().'/_index.md';
            $meta = is_file($indexPath) ? $this->parseFrontMatter($indexPath) : [];

            $categories->push([
                'slug' => $slug,
                'title' => $meta['title'] ?? $this->slugToTitle($slug),
                'summary' => $meta['summary'] ?? null,
                'order' => (int) ($meta['order'] ?? 999),
            ]);
        }

        return $categories->sortBy('order')->values();
    }

    /**
     * Articles inside a given category. Each item: {slug, title, summary, order, tags}.
     */
    public function articlesIn(string $category): Collection
    {
        $dir = $this->rootPath().DIRECTORY_SEPARATOR.$category;
        if (! is_dir($dir)) {
            return collect();
        }

        $articles = collect();

        $finder = (new Finder())->files()->in($dir)->name('*.md')->depth('== 0');
        foreach ($finder as $file) {
            /** @var SplFileInfo $file */
            $slug = $file->getBasename('.md');
            if ($slug === '_index') {
                continue;
            }

            $meta = $this->parseFrontMatter($file->getPathname());

            $articles->push([
                'slug' => $slug,
                'title' => $meta['title'] ?? $this->slugToTitle($slug),
                'summary' => $meta['summary'] ?? null,
                'order' => (int) ($meta['order'] ?? 999),
                'tags' => (array) ($meta['tags'] ?? []),
            ]);
        }

        return $articles->sortBy([fn ($a, $b) => $a['order'] <=> $b['order'] ?: strcmp($a['title'], $b['title'])])->values();
    }

    /**
     * Load a single article. Returns metadata plus the raw markdown body,
     * or null if the article doesn't exist.
     */
    public function article(string $category, string $slug): ?array
    {
        $path = $this->rootPath().DIRECTORY_SEPARATOR.$category.DIRECTORY_SEPARATOR.$slug.'.md';
        if (! is_file($path)) {
            return null;
        }

        $parsed = YamlFrontMatter::parseFile($path);

        return [
            'slug' => $slug,
            'category' => $category,
            'title' => $parsed->matter('title') ?? $this->slugToTitle($slug),
            'summary' => $parsed->matter('summary'),
            'order' => (int) ($parsed->matter('order') ?? 999),
            'tags' => (array) ($parsed->matter('tags') ?? []),
            'updated' => $parsed->matter('updated'),
            'body' => $parsed->body(),
        ];
    }

    /**
     * Everything flat — useful for search indexing.
     */
    public function allArticles(): Collection
    {
        return $this->categories()->flatMap(function (array $cat) {
            return $this->articlesIn($cat['slug'])->map(function (array $art) use ($cat) {
                return $art + ['category' => $cat['slug'], 'categoryTitle' => $cat['title']];
            });
        });
    }

    protected function rootPath(): string
    {
        return resource_path('docs');
    }

    protected function parseFrontMatter(string $path): array
    {
        return YamlFrontMatter::parseFile($path)->matter();
    }

    protected function slugToTitle(string $slug): string
    {
        // Strip leading order prefix like "01-" and convert kebab-case to Title Case.
        $clean = preg_replace('/^\d+-/', '', $slug);

        return ucwords(str_replace('-', ' ', $clean));
    }
}
