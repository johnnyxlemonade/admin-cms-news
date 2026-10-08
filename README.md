# Lemonade CMS News

[![PHPStan](https://github.com/johnnyxlemonade/admin-cms-news/actions/workflows/phpstan.yml/badge.svg)](https://github.com/johnnyxlemonade/admin-cms-news/actions/workflows/phpstan.yml)
[![Tests](https://github.com/johnnyxlemonade/admin-cms-news/actions/workflows/phpunit.yml/badge.svg)](https://github.com/johnnyxlemonade/admin-cms-news/actions/workflows/phpunit.yml)
[![Coding Standards](https://github.com/johnnyxlemonade/admin-cms-news/actions/workflows/coding-standards.yml/badge.svg)](https://github.com/johnnyxlemonade/admin-cms-news/actions/workflows/coding-standards.yml)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](composer.json)

`johnnyxlemonade/admin-cms-news` is an optional CMS module with the
`cms.news` code. After installation and explicit enablement, it provides News
management at `/admin/news`.

An article contains localized title, URL address, summary, content, and SEO
metadata. Publication and media belong to the article root aggregate. Galleries
and attachments use shared Admin Platform file collections; this package does
not introduce its own upload transport or storage. Canonical public URLs are
reserved through the shared `cms_route` contract.

The package does not own public frontend HTML or article rendering. The host
application provides public presentation and enforces the module's public
publication semantics.

## Installation

The package requires PHP `>=8.3 <8.6`, `johnnyxlemonade/framework`, and
`johnnyxlemonade/admin-platform`.

```bash
composer require johnnyxlemonade/admin-cms-news:dev-main
```

Composer package metadata discovers the module. Install and enable it through
the host application's standard module lifecycle.

## Package boundaries

- CMS News owns the localized News aggregate, Admin module contribution,
  publication workflow, and News-specific permissions.
- Admin Platform owns shared Admin file collections, upload transport, and
  `system_file` metadata.
- CMS Core owns public CMS route contracts and canonical route reservations.
- The host owns public rendering and public request integration.

## Development and QA

From the package root, run:

```bash
composer cs:check
composer stan
composer test
composer qa
```
