<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Public;

use DateTimeImmutable;
use InvalidArgumentException;
use Lemonade\Admin\File\FileMetadata;
use Lemonade\Admin\File\FileReadModel;
use Lemonade\Cms\Content\PublishedContentScope;
use Lemonade\Cms\News\CmsNewsServiceProvider;
use Lemonade\Cms\News\ModuleManifest;
use Lemonade\Cms\Routing\Cms\PublicCmsUrlBuilder;
use Lemonade\Cms\Routing\Locale\PublicLocaleResolution;
use Lemonade\Cms\Routing\Module\PublicModuleStateResolverInterface;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\QueryBuilder;

/**
 * Reads publicly available News through one shared publication scope
 */
final readonly class PublishedNewsReadModel
{
    /**
     * Creates the News reader with CMS routing and shared file metadata
     */
    public function __construct(
        private Database $database,
        private FileReadModel $files,
        private PublicCmsUrlBuilder $urls,
        private PublicModuleStateResolverInterface $modules,
    ) {}

    /**
     * Returns one visible News translation or null when it is unavailable
     */
    public function record(int $id, PublicLocaleResolution $locale): ?PublishedNews
    {
        $language = $this->visibleLocale($locale);
        if ($language === null) {
            return null;
        }

        $row = $this->publishedQuery($language)->where('article.id', $id)->first();

        return $row === null ? null : $this->hydrate([$row], $locale)[0];
    }

    /**
     * Returns a page of visible News filtered by localized tag and recommendation
     *
     * @param array{from?:string,to?:string} $publishedAt Publication date bounds
     */
    public function collection(
        PublicLocaleResolution $locale,
        int $page = 1,
        int $perPage = 25,
        ?string $tag = null,
        ?bool $recommended = null,
        ?string $search = null,
        array $publishedAt = [],
        string $sortBy = 'publishedAt',
        string $sortDirection = 'desc',
    ): PublishedNewsCollection {
        if ($page < 1 || $perPage < 1) {
            throw new InvalidArgumentException('Page and page size must be positive.');
        }

        $language = $this->visibleLocale($locale);
        if ($language === null) {
            return new PublishedNewsCollection([], 0, $page, $perPage);
        }

        $sortColumn = match ($sortBy) {
            'publishedAt' => 'translation.published_at',
            'title' => 'translation.title',
            default => throw new InvalidArgumentException('Unsupported News sort key.'),
        };
        $direction = strtoupper($sortDirection);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Sort direction must be asc or desc.');
        }
        $query = $this->withFilters($this->publishedQuery($language), $tag, $recommended);
        $term = trim($search ?? '');
        if ($term !== '') {
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
            $query = $query->whereRaw(
                "(translation.title LIKE ? ESCAPE '!' OR translation.summary LIKE ? ESCAPE '!' OR translation.content LIKE ? ESCAPE '!')",
                [$pattern, $pattern, $pattern],
            );
        }
        if (array_diff(array_keys($publishedAt), ['from', 'to']) !== []) {
            throw new InvalidArgumentException('Publication range accepts only from and to bounds.');
        }
        foreach ($publishedAt as $bound => $value) {
            if (preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}(?:T[0-9]{2}:[0-9]{2})?$/D', $value) !== 1) {
                throw new InvalidArgumentException('Invalid publication time bound.');
            }
            $format = strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d\TH:i';
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date === false || $date->format($format) !== $value) {
                throw new InvalidArgumentException('Invalid publication time bound.');
            }
            $normalized = str_replace('T', ' ', $value);
            $normalized .= strlen($value) === 10 ? ($bound === 'from' ? ' 00:00:00' : ' 23:59:59') : ':00';
            $query = $query->where('translation.published_at ' . ($bound === 'from' ? '>=' : '<='), $normalized);
        }
        $total = $query->countAllResults();
        if ($total === 0 || $page - 1 > intdiv($total - 1, $perPage)) {
            return new PublishedNewsCollection([], $total, $page, $perPage);
        }

        $rows = $query->orderBy($sortColumn, $direction)
            ->orderBy('article.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->getArray();

        return new PublishedNewsCollection($this->hydrate($rows, $locale), $total, $page, $perPage);
    }

    /**
     * Returns the newest visible News without an unnecessary count query
     *
     * @return list<PublishedNews>
     */
    public function latest(PublicLocaleResolution $locale, int $limit = 5, ?string $tag = null): array
    {
        return $this->recent($locale, $limit, $tag, recommended: null, excludedId: null);
    }

    /**
     * Returns the newest visible News except the current article
     *
     * @return list<PublishedNews>
     */
    public function others(int $id, PublicLocaleResolution $locale, int $limit = 5, ?string $tag = null): array
    {
        return $this->recent($locale, $limit, $tag, recommended: null, excludedId: $id);
    }

    /**
     * Returns the newest visible News marked as recommended
     *
     * @return list<PublishedNews>
     */
    public function recommended(PublicLocaleResolution $locale, int $limit = 5, ?string $tag = null): array
    {
        return $this->recent($locale, $limit, $tag, recommended: true, excludedId: null);
    }

    /**
     * Yields visible News candidates with their canonical URL and last public update
     *
     * @return iterable<PublishedNewsSitemapEntry>
     */
    public function sitemapEntries(PublicLocaleResolution $locale): iterable
    {
        $language = $this->visibleLocale($locale);
        if ($language === null) {
            return;
        }

        $query = $this->publishedScope($language)
            ->select(['route.path'])
            ->selectRaw('GREATEST(article.updated_at, translation.updated_at, route.updated_at) AS last_modified_at')
            ->orderBy('article.id', 'ASC');

        foreach ($query->cursor() as $row) {
            yield new PublishedNewsSitemapEntry(
                $this->urls->build($locale, $language, (string) $row['path']),
                new DateTimeImmutable((string) $row['last_modified_at']),
            );
        }
    }

    /**
     * Reports whether an article is publicly visible in any enabled locale
     */
    public function isPublicInAnyLocale(int $id): bool
    {
        if (!$this->modules->isDiscoveredInstalledAndEnabled(ModuleManifest::CODE)) {
            return false;
        }

        return $this->publishedScope()->where('article.id', $id)->countAllResults() > 0;
    }

    /**
     * Restricts a recent list to one optional localized tag and recommendation state
     *
     * @return list<PublishedNews>
     */
    private function recent(
        PublicLocaleResolution $locale,
        int $limit,
        ?string $tag,
        ?bool $recommended,
        ?int $excludedId,
    ): array {
        if ($limit < 1) {
            throw new InvalidArgumentException('News limit must be positive.');
        }

        $language = $this->visibleLocale($locale);
        if ($language === null) {
            return [];
        }

        $query = $this->withFilters($this->publishedQuery($language), $tag, $recommended);
        if ($excludedId !== null) {
            $query = $query->where('article.id !=', $excludedId);
        }

        $rows = $query
            ->orderBy('translation.published_at', 'DESC')
            ->orderBy('article.id', 'DESC')
            ->limit($limit)
            ->getArray();

        return $this->hydrate($rows, $locale);
    }

    /**
     * Returns the requested public locale when the News module is available
     */
    private function visibleLocale(PublicLocaleResolution $locale): ?string
    {
        $language = $locale->getLocale();

        return $language !== null && $this->modules->isDiscoveredInstalledAndEnabled(ModuleManifest::CODE)
            ? $language
            : null;
    }

    /**
     * Applies optional content filters to the shared publication query
     */
    private function withFilters(QueryBuilder $query, ?string $tag, ?bool $recommended): QueryBuilder
    {
        if ($tag !== null) {
            $query = $query->whereRaw(
                'EXISTS (SELECT 1 FROM cms_news_article_translation_tag tag WHERE tag.translation_id = translation.id AND tag.name = ?)',
                [$tag],
            );
        }
        if ($recommended !== null) {
            $query = $query->where('article.recommended', $recommended ? 1 : 0);
        }

        return $query;
    }

    /**
     * Selects the system content needed to construct published News objects
     */
    private function publishedQuery(string $language): QueryBuilder
    {
        return $this->publishedScope($language)
            ->select([
                'article.id', 'article.author_name', 'article.recommended',
                'article.show_author', 'article.sharing_enabled', 'article.show_published_at',
                'article.show_thumbnail', 'article.show_reading_time', 'article.show_tags',
                'translation.locale', 'translation.title', 'translation.summary', 'translation.content',
                'translation.page_title', 'translation.meta_description',
                'translation.published_at', 'translation.updated_at',
            ])
            ->selectRaw('translation.id AS translation_id, route.path AS canonical_path');
    }

    /**
     * Applies the shared public visibility rules for routed News content
     */
    private function publishedScope(?string $language = null): QueryBuilder
    {
        return PublishedContentScope::query(
            $this->database,
            CmsNewsServiceProvider::contentDefinition(),
            $language,
        );
    }

    /**
     * Hydrates visible articles with one tag query and one shared file query
     *
     * @param list<array<string,mixed>> $rows
     * @return list<PublishedNews>
     */
    private function hydrate(array $rows, PublicLocaleResolution $locale): array
    {
        if ($rows === []) {
            return [];
        }

        $translationIds = array_map(static fn(array $row): int => (int) $row['translation_id'], $rows);
        $tags = [];
        foreach ($this->database->table('cms_news_article_translation_tag tag')
            ->select(['tag.translation_id', 'tag.name'])
            ->whereIn('tag.translation_id', $translationIds)
            ->orderBy('tag.translation_id')
            ->orderBy('tag.sort_order')
            ->orderBy('tag.id')
            ->getArray() as $tag) {
            $tags[(int) $tag['translation_id']][] = (string) $tag['name'];
        }

        $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
        $media = [];
        foreach ($this->files->listForEntitiesUsages(ModuleManifest::CODE, $ids, ['thumbnail', 'gallery', 'attachment']) as $file) {
            if (!$this->isReadableFile($file)) {
                continue;
            }
            $media[$file->entityId()][$file->usage()][] = $file;
        }

        $articles = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $files = $media[$id] ?? [];
            $articles[] = new PublishedNews(
                id: $id,
                locale: (string) $row['locale'],
                title: (string) $row['title'],
                summary: $row['summary'] === null ? null : (string) $row['summary'],
                content: $row['content'] === null ? null : (string) $row['content'],
                pageTitle: $row['page_title'] === null ? null : (string) $row['page_title'],
                metaDescription: $row['meta_description'] === null ? null : (string) $row['meta_description'],
                publishedAt: new DateTimeImmutable((string) $row['published_at']),
                updatedAt: new DateTimeImmutable((string) $row['updated_at']),
                publicUrl: $this->urls->build($locale, (string) $row['locale'], (string) $row['canonical_path']),
                authorName: $row['author_name'] === null ? null : (string) $row['author_name'],
                tags: $tags[(int) $row['translation_id']] ?? [],
                recommended: (bool) $row['recommended'],
                showAuthor: (bool) $row['show_author'],
                sharingEnabled: (bool) $row['sharing_enabled'],
                showPublishedAt: (bool) $row['show_published_at'],
                showThumbnail: (bool) $row['show_thumbnail'],
                showReadingTime: (bool) $row['show_reading_time'],
                showTags: (bool) $row['show_tags'],
                thumbnail: $files['thumbnail'][0] ?? null,
                gallery: $files['gallery'] ?? [],
                attachments: $files['attachment'] ?? [],
            );
        }

        return $articles;
    }

    /**
     * Checks the backing data required to make one stored file publicly readable
     */
    private function isReadableFile(FileMetadata $file): bool
    {
        if (in_array($file->usage(), ['thumbnail', 'gallery'], true) && $file->kind() !== 'image') {
            return false;
        }
        if ($file->kind() === 'image') {
            return $file->assetId() !== null && $file->sourceVersion() !== null;
        }

        return $file->kind() === 'file' && $file->storagePath() !== null;
    }
}
