<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Public\Internal;

use DateTimeImmutable;
use InvalidArgumentException;
use Lemonade\Cms\Content\PublishedContentScope;
use Lemonade\Cms\News\CmsNewsServiceProvider;
use Lemonade\Cms\News\ModuleManifest;
use Lemonade\Cms\News\Public\PublishedNews;
use Lemonade\Cms\News\Public\PublishedNewsCollection;
use Lemonade\Cms\News\Public\PublishedNewsSitemapEntry;
use Lemonade\Cms\Routing\Cms\PublicCmsUrlBuilder;
use Lemonade\Cms\Routing\Locale\PublicLocaleResolution;
use Lemonade\Cms\Routing\Module\PublicModuleStateResolverInterface;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\QueryBuilder;

/**
 * Selects News through the shared CMS publication scope
 *
 * @internal
 */
final readonly class PublishedNewsSelector
{
    public function __construct(
        private Database $database,
        private PublicCmsUrlBuilder $urls,
        private PublicModuleStateResolverInterface $modules,
        private PublishedNewsHydrator $hydrator,
    ) {}

    public function record(int $id, PublicLocaleResolution $locale): ?PublishedNews
    {
        $language = $this->visibleLocale($locale);
        if ($language === null) {
            return null;
        }

        $row = $this->publishedQuery($language)->where('article.id', $id)->first();

        return $row === null
            ? null
            : $this->hydrator->hydrate([PublishedNewsRow::fromRow($row)], $locale)[0];
    }

    public function collection(
        PublicLocaleResolution $locale,
        string $language,
        PublishedNewsCriteria $criteria,
    ): PublishedNewsCollection {
        $query = $this->publishedQuery($language);
        $query = $this->withFilters($query, $criteria->getTag(), $criteria->getRecommended());
        $query = $this->withSearch($query, $criteria->getSearchTerm());
        $query = $this->withPublishedAt($query, $criteria->getPublishedAt());

        $total = $query->countAllResults();
        $page = $criteria->getPage();
        $perPage = $criteria->getPerPage();
        if ($total === 0 || $page - 1 > intdiv($total - 1, $perPage)) {
            return new PublishedNewsCollection([], $total, $page, $perPage);
        }

        $rows = $query->orderBy(
            $this->sortExpression($criteria->getSortBy()),
            strtoupper($criteria->getSortDirection()),
        )
            ->orderBy('article.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->getArray();

        return new PublishedNewsCollection(
            $this->hydrator->hydrate($this->rows($rows), $locale),
            $total,
            $page,
            $perPage,
        );
    }

    /**
     * @return list<PublishedNews>
     */
    public function recent(
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

        return $this->hydrator->hydrate($this->rows($rows), $locale);
    }

    /**
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

    public function isPublicInAnyLocale(int $id): bool
    {
        if (!$this->modules->isDiscoveredInstalledAndEnabled(ModuleManifest::CODE)) {
            return false;
        }

        return $this->publishedScope()->where('article.id', $id)->countAllResults() > 0;
    }

    public function visibleLocale(PublicLocaleResolution $locale): ?string
    {
        $language = $locale->getLocale();

        return $language !== null && $this->modules->isDiscoveredInstalledAndEnabled(ModuleManifest::CODE)
            ? $language
            : null;
    }

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

    private function withSearch(QueryBuilder $query, ?string $term): QueryBuilder
    {
        if ($term === null) {
            return $query;
        }

        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';

        return $query->whereRaw(
            "(translation.title LIKE ? ESCAPE '!' OR translation.summary LIKE ? ESCAPE '!' OR translation.content LIKE ? ESCAPE '!')",
            [$pattern, $pattern, $pattern],
        );
    }

    /**
     * @param list<PublishedNewsRangeBound> $bounds
     */
    private function withPublishedAt(QueryBuilder $query, array $bounds): QueryBuilder
    {
        foreach ($bounds as $bound) {
            $query = $query->where(
                'translation.published_at ' . ($bound->isFrom() ? '>=' : '<='),
                $bound->getValue()->format('Y-m-d H:i:s'),
            );
        }

        return $query;
    }

    private function sortExpression(string $sortBy): string
    {
        return match ($sortBy) {
            'publishedAt' => 'translation.published_at',
            'title' => 'translation.title',
            default => throw new InvalidArgumentException('Unsupported News sort key.'),
        };
    }

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

    private function publishedScope(?string $language = null): QueryBuilder
    {
        return PublishedContentScope::query(
            $this->database,
            CmsNewsServiceProvider::contentDefinition(),
            $language,
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<PublishedNewsRow>
     */
    private function rows(array $rows): array
    {
        return array_map(PublishedNewsRow::fromRow(...), $rows);
    }
}
