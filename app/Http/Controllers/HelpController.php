<?php

namespace App\Http\Controllers;

use App\Services\Brand\BrandConfig;
use App\Services\Help\DocsRepository;
use App\Services\Help\MarkdownRenderer;
use Inertia\Inertia;
use Inertia\Response;

class HelpController extends Controller
{
    public function __construct(
        protected DocsRepository $docs,
        protected MarkdownRenderer $markdown,
        protected BrandConfig $brand,
    ) {
    }

    public function index(): Response
    {
        $categories = $this->docs->categories()->map(function (array $cat) {
            $articles = $this->docs->articlesIn($cat['slug']);
            return $cat + ['articles' => $articles->all()];
        });

        return Inertia::render('help/Index', [
            'siteName' => $this->brand->name(),
            'categories' => $categories->all(),
            'searchIndex' => $this->docs->allArticles()->all(),
        ]);
    }

    public function category(string $category): Response
    {
        $categoryMeta = $this->docs->categories()->firstWhere('slug', $category);
        abort_unless($categoryMeta, 404);

        $articles = $this->docs->articlesIn($category);

        return Inertia::render('help/Category', [
            'siteName' => $this->brand->name(),
            'category' => $categoryMeta,
            'articles' => $articles->all(),
            'searchIndex' => $this->docs->allArticles()->all(),
        ]);
    }

    public function article(string $category, string $slug): Response
    {
        $article = $this->docs->article($category, $slug);
        abort_unless($article, 404);

        $categoryMeta = $this->docs->categories()->firstWhere('slug', $category);
        abort_unless($categoryMeta, 404);

        $article['html'] = $this->markdown->toHtml($article['body']);
        unset($article['body']); // don't ship raw markdown to the client

        // Sibling articles for prev/next navigation
        $siblings = $this->docs->articlesIn($category);
        $currentIdx = $siblings->search(fn ($a) => $a['slug'] === $slug);
        $prev = $currentIdx > 0 ? $siblings->get($currentIdx - 1) : null;
        $next = $currentIdx !== false && $currentIdx < $siblings->count() - 1 ? $siblings->get($currentIdx + 1) : null;

        return Inertia::render('help/Article', [
            'siteName' => $this->brand->name(),
            'category' => $categoryMeta,
            'article' => $article,
            'prev' => $prev,
            'next' => $next,
            'searchIndex' => $this->docs->allArticles()->all(),
        ]);
    }
}
