# Lemonade CMS News

[![Tests](https://img.shields.io/github/actions/workflow/status/johnnyxlemonade/admin-cms-news/phpunit.yml?branch=main&label=Tests&logo=githubactions&logoColor=white&labelColor=0f766e)](https://github.com/johnnyxlemonade/admin-cms-news/actions/workflows/phpunit.yml)
[![PHP 8.3–8.6](https://img.shields.io/badge/PHP-8.3%20%7C%208.4%20%7C%208.5%20%7C%208.6-777BB4?logo=php&logoColor=white)](#requirements)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/johnnyxlemonade/admin-cms-news/phpstan.yml?branch=main&label=PHPStan&logo=php&logoColor=white&labelColor=5b4baf)](https://github.com/johnnyxlemonade/admin-cms-news/actions/workflows/phpstan.yml)
[![License](https://img.shields.io/badge/license-Apache--2.0-green.svg)](LICENSE)

## Introduction

Localized News management for Lemonade Admin. The package owns the News aggregate,
Admin integration and publication-scoped read API. The host application owns public
routing, controllers and rendering.

## Features

- Localized title, summary, rich content, SEO metadata and tags.
- Per-locale drafts, publication time and canonical CMS routes.
- Recommendation and shared display settings.
- Ownership, own/global permissions, audit and soft deletion.
- Thumbnail, gallery, attachments and rich-text uploads through Admin Platform.
- Public record, collection, recent, recommended and sitemap projections.
- Search, tag and recommendation filters, publication ranges, sorting and pagination.
- Czech and English Admin interface.

## Requirements

- PHP 8.3 or newer, tested on PHP 8.3–8.6.
- `johnnyxlemonade/framework:dev-main`.
- `johnnyxlemonade/admin-platform:dev-main`.
- `johnnyxlemonade/cms-core:dev-main`.

## Installation

```bash
composer require johnnyxlemonade/admin-cms-news:dev-main
```

Composer metadata registers the `cms.news` module automatically. Run the host's
migrations, permission sync and Admin asset publication before use. Permission sync
creates the News permissions but does not grant them to roles.

## Usage

Open `<admin-base>/cms/news` to create localized articles, reserve canonical URLs,
publish translations and manage media. Published translations have a title and an
active canonical route. The package does not register public frontend routes or views.

## Public News

Inject [PublishedNewsReadModel](src/Public/PublishedNewsReadModel.php) and pass the
`PublicLocaleResolution` produced by CMS routing.

```php
$article = $news->record($articleId, $locale);

$page = $news->collection(
    locale: $locale,
    page: $pageNumber,
    perPage: 12,
    tag: $tag,
    search: $query,
    publishedAt: ['from' => '2026-01-01', 'to' => '2026-12-31'],
    sortBy: 'publishedAt',
    sortDirection: 'desc',
);

foreach ($page->getItems() as $item) {
    $title = $item->getTitle();
    $url = $item->getPublicUrl();
    $thumbnail = $item->getThumbnail();
}

$pagination = $page->getPagination('/news', query: ['tag' => $tag]);
```

Reads enforce module availability, locale, publication time, soft deletion and
canonical-route visibility. `record()` returns `null` when the article is unavailable;
collections return empty results when nothing matches.

| Method | Use |
| --- | --- |
| `record()` / `collection()` | One article or a filtered page |
| `latest()` / `recommended()` | Recent public articles without pagination count |
| `others()` | Recent articles excluding the current article |
| `sitemapEntries()` | Canonical URLs and update times for the host sitemap |
| `isPublicInAnyLocale()` | Public file-access checks across enabled locales |

[PublishedNews](src/Public/PublishedNews.php) exposes localized content, SEO, display
settings, tags and ordered media through typed getters. The host chooses its public
page layout and renders sitemap entries through the framework's existing sitemap
provider mechanism.

## Development

From the package root:

```bash
composer install
composer test
composer stan
composer cs:check
composer qa
```

Database integration tests use an explicitly selected `cms_news_test_*` database and
are intentionally separate from the default PHPUnit suite.

## License

Lemonade CMS News is licensed under the [Apache License 2.0](LICENSE).
