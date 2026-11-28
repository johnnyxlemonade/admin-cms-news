<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Public;

use Lemonade\Admin\File\FileReadModel;
use Lemonade\Cms\News\Public\Internal\PublishedNewsCriteria;
use Lemonade\Cms\News\Public\Internal\PublishedNewsHydrator;
use Lemonade\Cms\News\Public\Internal\PublishedNewsSelector;
use Lemonade\Cms\Routing\Cms\PublicCmsUrlBuilder;
use Lemonade\Cms\Routing\Locale\PublicLocaleResolution;
use Lemonade\Cms\Routing\Module\PublicModuleStateResolverInterface;
use Lemonade\Framework\Database\Database;

/**
 * Provides the public API for publication-scoped News reads
 */
final readonly class PublishedNewsReadModel
{
    private PublishedNewsSelector $selector;

    /**
     * Preserves public construction while separating selection from hydration
     */
    public function __construct(
        Database $database,
        FileReadModel $files,
        PublicCmsUrlBuilder $urls,
        PublicModuleStateResolverInterface $modules,
    ) {
        $this->selector = new PublishedNewsSelector(
            database: $database,
            urls: $urls,
            modules: $modules,
            hydrator: new PublishedNewsHydrator($database, $files, $urls),
        );
    }

    /**
     * Returns one visible News translation or null when it is unavailable
     */
    public function record(int $id, PublicLocaleResolution $locale): ?PublishedNews
    {
        return $this->selector->record($id, $locale);
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
        PublishedNewsCriteria::validatePagination($page, $perPage);

        $language = $this->selector->visibleLocale($locale);
        if ($language === null) {
            return new PublishedNewsCollection([], 0, $page, $perPage);
        }

        $criteria = PublishedNewsCriteria::fromInput(
            page: $page,
            perPage: $perPage,
            tag: $tag,
            recommended: $recommended,
            search: $search,
            publishedAt: $publishedAt,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );

        return $this->selector->collection($locale, $language, $criteria);
    }

    /**
     * Returns the newest visible News without an unnecessary count query
     *
     * @return list<PublishedNews>
     */
    public function latest(PublicLocaleResolution $locale, int $limit = 5, ?string $tag = null): array
    {
        return $this->selector->recent($locale, $limit, $tag, recommended: null, excludedId: null);
    }

    /**
     * Returns the newest visible News except the current article
     *
     * @return list<PublishedNews>
     */
    public function others(int $id, PublicLocaleResolution $locale, int $limit = 5, ?string $tag = null): array
    {
        return $this->selector->recent($locale, $limit, $tag, recommended: null, excludedId: $id);
    }

    /**
     * Returns the newest visible News marked as recommended
     *
     * @return list<PublishedNews>
     */
    public function recommended(PublicLocaleResolution $locale, int $limit = 5, ?string $tag = null): array
    {
        return $this->selector->recent($locale, $limit, $tag, recommended: true, excludedId: null);
    }

    /**
     * Yields visible News candidates with their canonical URL and last public update
     *
     * @return iterable<PublishedNewsSitemapEntry>
     */
    public function sitemapEntries(PublicLocaleResolution $locale): iterable
    {
        return $this->selector->sitemapEntries($locale);
    }

    /**
     * Reports whether an article is publicly visible in any enabled locale
     */
    public function isPublicInAnyLocale(int $id): bool
    {
        return $this->selector->isPublicInAnyLocale($id);
    }
}
