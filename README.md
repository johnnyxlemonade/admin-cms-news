# Lemonade CMS News

[![PHPStan](https://github.com/johnnyxlemonade/admin-cms-news/actions/workflows/phpstan.yml/badge.svg)](https://github.com/johnnyxlemonade/admin-cms-news/actions/workflows/phpstan.yml)
[![Tests](https://github.com/johnnyxlemonade/admin-cms-news/actions/workflows/phpunit.yml/badge.svg)](https://github.com/johnnyxlemonade/admin-cms-news/actions/workflows/phpunit.yml)
[![Coding Standards](https://github.com/johnnyxlemonade/admin-cms-news/actions/workflows/coding-standards.yml/badge.svg)](https://github.com/johnnyxlemonade/admin-cms-news/actions/workflows/coding-standards.yml)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](composer.json)

`johnnyxlemonade/admin-cms-news` is an optional CMS module with the
`cms.news` code. When present in the Composer installation, it provides News
management at `<admin-base>/content/news` (for the standard `/admin` base path,
`/admin/content/news`).

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
application provides public presentation and enforces the module's public
publication semantics.

## Installation

The package requires PHP `>=8.3 <8.6`, `johnnyxlemonade/framework`,
`johnnyxlemonade/admin-platform`, and `johnnyxlemonade/cms-core`.

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
- The host owns public rendering and public request integration.

## Development and QA

From the package root, run:

```bash
composer cs:check
composer stan
composer test
composer qa
```
