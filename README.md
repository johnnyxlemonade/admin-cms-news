# Lemonade CMS News

[![Tests](https://img.shields.io/github/actions/workflow/status/johnnyxlemonade/admin-cms-news/phpunit.yml?branch=main&label=Tests&logo=githubactions&logoColor=white&labelColor=0f766e)](https://github.com/johnnyxlemonade/admin-cms-news/actions/workflows/phpunit.yml)
[![PHP 8.3–8.6](https://img.shields.io/badge/PHP-8.3%20%7C%208.4%20%7C%208.5%20%7C%208.6-777BB4?logo=php&logoColor=white)](#requirements)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/johnnyxlemonade/admin-cms-news/phpstan.yml?branch=main&label=PHPStan&logo=php&logoColor=white&labelColor=5b4baf)](https://github.com/johnnyxlemonade/admin-cms-news/actions/workflows/phpstan.yml)
[![License](https://img.shields.io/badge/license-Apache--2.0-green.svg)](LICENSE)

`johnnyxlemonade/admin-cms-news` is an optional CMS module with the
`cms.news` code. When present in the Composer installation, it provides News
management at `<admin-base>/cms/news` (for the standard `/admin` base path,
`/admin/cms/news`).

An article contains localized title, summary, content, and SEO metadata.
Publication state and publication time belong to each localized translation;
media belongs to the article root aggregate. Galleries and attachments use
shared Admin Platform file collections; this package does not introduce its
own upload transport or storage. Canonical public URLs are reserved as
locale-unique slug paths through the shared `cms_route` contract; they do not
inherit or require a public collection path.

The article root also stores an author name and shared display settings for
recommendation, author, sharing, publication date, main image, reading time,
and tags. The author name is text metadata, separate from the audit owner.
Display settings apply across translations and are metadata for the host to
interpret.

The package does not own public frontend HTML or article rendering. The host
application provides public presentation, while the package enforces publication
and canonical-route rules for public reads.

## Public News

The package registers `PublishedNewsReadModel` in DI. `record()` returns one
published article or `null`. `collection()` returns a `PublishedNewsCollection`
with typed items, total count and page information. Both require a
`PublicLocaleResolution` from CMS routing. The reader excludes disabled languages,
future or unpublished translations, soft-deleted articles, missing or inactive
canonical routes and an unavailable News module.

```php
use Lemonade\Cms\News\Public\PublishedNewsReadModel;
use Lemonade\Cms\Routing\Locale\PublicLocaleResolution;

function newsData(PublishedNewsReadModel $news, int $articleId, PublicLocaleResolution $locale): array
{
    $article = $news->record($articleId, $locale);
    $page = $news->collection($locale, page: 1, perPage: 12, tag: 'community');
    $featured = $news->recommended($locale, limit: 4);

    return [
        'article' => $article,
        'items' => $page->getItems(),
        'total' => $page->getTotal(),
        'featured' => $featured,
        'thumbnail' => $article?->getThumbnail(),
        'gallery' => $article?->getGallery() ?? [],
        'attachments' => $article?->getAttachments() ?? [],
    ];
}
```

`latest($locale, $limit)`, `recommended($locale, $limit)`, and
`others($id, $locale, $limit)` return lists in publication order without a
separate count query. `others()` excludes the current article in SQL before
`LIMIT`. `collection()` also supports
`recommended: true` or `recommended: false` and an optional localized tag.
The collection's `getPagination()` creates the existing framework
`PaginationState` from its database count and requested page. Supply the host's
route and query context when rendering navigation:

```php
$page = $news->collection($locale, page: $pageNumber, perPage: 12, tag: $tag);
$searched = $news->collection(
    $locale,
    page: $pageNumber,
    perPage: 12,
    search: $query,
    publishedAt: ['from' => '2026-01-01', 'to' => '2026-12-31'],
    sortBy: 'publishedAt',
    sortDirection: 'desc',
);
$otherNews = $news->others($id, $locale, limit: 3, tag: $tag);
$navigation = $paginationComponent->render($page->getPagination(
    basePath: $request->getUri()->getPath(),
    query: ['tag' => $tag],
));
```

The reader still uses `COUNT` and `LIMIT/OFFSET` in SQL; pagination does not
load the entire result set. The host chooses the public URL path and any filters
to retain in navigation links. Search covers the title, summary and content of
the requested published translation. Inclusive publication bounds and sorting
combine with the existing tag and recommendation filters before both SQL queries.
`sortBy` accepts `publishedAt` or `title`; `sortDirection` accepts `asc` or `desc`.

`PublishedNews` exposes typed getters for title, system summary and content, SEO,
publication time, author, tags, display settings and canonical URL. It implements
`PublishedMediaInterface`; gallery and attachment getters return ordered
`list<FileMetadata>`. The reader loads tags and media in batches. The host creates
its own HTML and presentation URLs for files. `isPublicInAnyLocale($articleId)`
supports a host's public attachment access check.

The host selects locales and URL inclusion rules in its existing framework sitemap
provider. The framework resolves the base URL and generates the XML:

```php
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Cms\News\Public\PublishedNewsReadModel;
use Lemonade\Cms\Routing\Locale\PublicLocaleResolution;
use Lemonade\Framework\Discovery\Sitemap\SitemapProviderInterface;
use Lemonade\Framework\Discovery\Sitemap\SitemapUrl;

/**
 * Supplies published News to the framework sitemap
 */
final readonly class PublicNewsSitemapProvider implements SitemapProviderInterface
{
    /**
     * Receives public News and configured content languages
     */
    public function __construct(
        private PublishedNewsReadModel $news,
        private LanguageRegistry $languages,
    ) {}

    /**
     * Yields URLs selected by the host for each enabled locale
     *
     * @return iterable<SitemapUrl>
     */
    public function urls(): iterable
    {
        $defaultLocale = $this->languages->publicLocaleSnapshot()->getDefaultLocale();
        foreach ($this->languages->enabledLocales() as $language) {
            $locale = PublicLocaleResolution::route($defaultLocale, $language['code'], '');
            foreach ($this->news->sitemapEntries($locale) as $entry) {
                yield SitemapUrl::create($entry->getPublicUrl(), $entry->getUpdatedAt());
            }
        }
    }
}
```

Register this provider under `config.sitemap.providers` in the host's
`Discovery.yaml`. Sitemap candidates use a narrow query without hydrating News,
tags or media. Their `getUpdatedAt()` is the latest change to the article root,
translation or canonical route. No CMS-specific sitemap generator or XML writer is
installed.

## Requirements

- PHP 8.3 or newer, tested on PHP 8.3, 8.4, 8.5 and 8.6.
- `johnnyxlemonade/framework:dev-main`.
- `johnnyxlemonade/admin-platform:dev-main`.
- `johnnyxlemonade/cms-core:dev-main`.

## Installation

```bash
composer require johnnyxlemonade/admin-cms-news:dev-main
```

Composer package metadata discovers and activates the module; there is no
separate installed or enabled state. Run the host application's controlled
migration and permission-sync deployment flow before serving requests.

## Package boundaries

- CMS News owns the localized News aggregate, Admin module contribution,
  publication workflow, and News-specific permissions.
- Admin Platform owns shared Admin file collections, upload transport, and
  `system_file` metadata.
- CMS Core owns the shared content lifecycle, public CMS route contracts, and
  canonical route reservations.
- The host owns public rendering, request integration and sitemap selection.

## Development and QA

From the package root, run:

```bash
composer cs:check
composer stan
composer test
composer qa
```
