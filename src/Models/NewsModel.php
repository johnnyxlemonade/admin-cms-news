<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Models;

use Lemonade\Admin\DataGrid\Query\DataGridQuery;
use Lemonade\Admin\DataGrid\Query\QueryPage;
use Lemonade\Framework\Database\Model;
use Lemonade\Framework\Database\QueryBuilder;

/**
 * Zprostredkuje persistenci aggregate Aktualit, jeho mutaci a DataGrid projekci
 */
final class NewsModel extends Model
{
    protected string $table = 'cms_news_article';

    protected bool $useTimestamps = true;

    protected bool $useSoftDeletes = true;

    /**
     * @var list<string>
     */
    protected array $allowedFields = [
        'author_name',
        'recommended',
        'show_author',
        'sharing_enabled',
        'show_published_at',
        'show_featured_image',
        'show_reading_time',
        'state',
        'published_at',
    ];

    /**
     * Vytvori root draftu a vrati jeho stabilni identifikator
     *
     * @param array<string,bool> $displaySettings
     */
    public function createDraft(?string $authorName, bool $recommended, array $displaySettings, string $now): int
    {
        $articleId = $this->insert([
            'author_name' => $authorName,
            'recommended' => $recommended ? 1 : 0,
            'show_author' => $displaySettings['show_author'] ? 1 : 0,
            'sharing_enabled' => $displaySettings['sharing_enabled'] ? 1 : 0,
            'show_published_at' => $displaySettings['show_published_at'] ? 1 : 0,
            'show_featured_image' => $displaySettings['show_featured_image'] ? 1 : 0,
            'show_reading_time' => $displaySettings['show_reading_time'] ? 1 : 0,
            'state' => 'draft',
            'published_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $articleId;
    }

    /**
     * Overi existenci aktivni root identity clanku
     */
    public function articleExists(int $articleId): bool
    {
        return $this->query()
            ->where('id', $articleId)
            ->exists();
    }

    /**
     * Vrati root aggregate vcetne soft-delete stavu pro lifecycle akce
     *
     * @return array<string,mixed>|null
     */
    public function lifecycle(int $articleId): ?array
    {
        return $this->withDeleted()
            ->query()
            ->select(['id', 'deleted_at'])
            ->where('id', $articleId)
            ->first();
    }

    /**
     * Oznaci aggregate jako smazany bez mazani jeho translations a rout
     */
    public function softDelete(int $articleId, string $now): void
    {
        $this->query()
            ->where('id', $articleId)
            ->set([
                'deleted_at' => $now,
                'updated_at' => $now,
            ])
            ->update();
    }

    /**
     * Obnovi aggregate jako draft bez automatickeho zverejneni
     */
    public function restoreDraft(int $articleId, string $now): void
    {
        $this->withDeleted()
            ->query()
            ->where('id', $articleId)
            ->whereRaw('deleted_at IS NOT NULL')
            ->set([
                'deleted_at' => null,
                'state' => 'draft',
                'published_at' => null,
                'updated_at' => $now,
            ])
            ->update();
    }

    /**
     * Vrati root metadata pro save a no-op rozhodnuti
     *
     * @return array<string,mixed>|null
     */
    public function metadata(int $articleId): ?array
    {
        return $this->query()
            ->select([
                'author_name',
                'recommended',
                'show_author',
                'sharing_enabled',
                'show_published_at',
                'show_featured_image',
                'show_reading_time',
            ])
            ->where('id', $articleId)
            ->first();
    }

    /**
     * Ulozi zmenena root metadata clanku
     *
     * @param array<string,bool> $displaySettings
     */
    public function saveMetadata(int $articleId, ?string $authorName, bool $recommended, array $displaySettings, string $now): void
    {
        $this->query()
            ->where('id', $articleId)
            ->set([
                'author_name' => $authorName,
                'recommended' => $recommended ? 1 : 0,
                'show_author' => $displaySettings['show_author'] ? 1 : 0,
                'sharing_enabled' => $displaySettings['sharing_enabled'] ? 1 : 0,
                'show_published_at' => $displaySettings['show_published_at'] ? 1 : 0,
                'show_featured_image' => $displaySettings['show_featured_image'] ? 1 : 0,
                'show_reading_time' => $displaySettings['show_reading_time'] ? 1 : 0,
                'updated_at' => $now,
            ])
            ->update();
    }

    /**
     * Vrati aktualni localized row pro rozhodnuti o no-op
     *
     * @return array<string,mixed>|null
     */
    public function translation(int $articleId, string $locale): ?array
    {
        return $this->translations()
            ->select([
                'article_id',
                'locale',
                'title',
                'page_title',
                'slug',
                'summary',
                'content',
                'meta_description',
            ])
            ->where('article_id', $articleId)
            ->where('locale', $locale)
            ->first();
    }

    /**
     * Vlozi nebo upravi jednu content mutation
     */
    public function saveTranslation(
        int $articleId,
        string $locale,
        string $title,
        ?string $pageTitle,
        ?string $slug,
        ?string $summary,
        ?string $content,
        ?string $metaDescription,
        string $now,
    ): int {
        $sql = implode(' ', [
            'INSERT INTO cms_news_article_translation(article_id,locale,title,page_title,slug,summary,content,meta_description,created_at,updated_at)',
            'VALUES (?,?,?,?,?,?,?,?,?,?)',
            'ON DUPLICATE KEY UPDATE',
            'id=LAST_INSERT_ID(id),',
            'title=VALUES(title),',
            'page_title=VALUES(page_title),',
            'slug=VALUES(slug),',
            'summary=VALUES(summary),',
            'content=VALUES(content),',
            'meta_description=VALUES(meta_description),',
            'updated_at=VALUES(updated_at)',
        ]);
        $this->db->query(
            $sql,
            [
                $articleId,
                $locale,
                $title,
                $pageTitle,
                $slug,
                $summary,
                $content,
                $metaDescription,
                $now,
                $now,
            ],
        );

        return (int) $this->db->insert_id();
    }

    /**
     * Nahradi lokalizovane stitky jedne content mutation v editorovem poradi
     *
     * @param list<string> $tags
     */
    public function syncTranslationTags(int $translationId, array $tags, string $now): void
    {
        $this->translationTagsQuery()
            ->where('translation_id', $translationId)
            ->delete();
        foreach ($tags as $position => $tag) {
            $this->translationTagsQuery()->insert([
                'translation_id' => $translationId,
                'name' => $tag,
                'sort_order' => $position,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Vrati aktualni lokalizovane stitky pro no-op rozhodnuti
     *
     * @return list<string>
     */
    public function translationTags(int $articleId, string $locale): array
    {
        /** @var list<array{name:string}> $tags */
        $tags = $this->translationTagsQuery()
            ->select(['tag.name'])
            ->from('cms_news_article_translation_tag tag')
            ->join(
                'cms_news_article_translation translation',
                'translation.id = tag.translation_id',
            )
            ->where('translation.article_id', $articleId)
            ->where('translation.locale', $locale)
            ->orderBy('tag.sort_order', 'ASC')
            ->orderBy('tag.id', 'ASC')
            ->getArray();

        return array_map(
            static fn(array $tag): string => (string) $tag['name'],
            $tags,
        );
    }

    /**
     * Skryje vsechny canonical routy smazaneho aggregate bez uvolneni URL
     */
    public function softDeleteRoutes(int $articleId, string $now): void
    {
        $this->routes()
            ->where('module_code', 'cms.news')
            ->where('entity_id', $articleId)
            ->where('deleted_at', null)
            ->set([
                'deleted_at' => $now,
                'updated_at' => $now,
            ])
            ->update();
    }

    /**
     * Znovu aktivuje rezervovane canonical routy obnoveneho aggregate
     */
    public function restoreRoutes(int $articleId, string $now): void
    {
        $this->routes()
            ->where('module_code', 'cms.news')
            ->where('entity_id', $articleId)
            ->whereRaw('deleted_at IS NOT NULL')
            ->set([
                'deleted_at' => null,
                'updated_at' => $now,
            ])
            ->update();
    }

    /**
     * Vrati publication metadata clanku
     *
     * @return array<string,mixed>|null
     */
    public function publication(int $articleId): ?array
    {
        return $this->query()
            ->select(['state', 'published_at'])
            ->where('id', $articleId)
            ->first();
    }

    /**
     * Ulozi zmeneny publication lifecycle root aggregate
     */
    public function savePublication(int $articleId, string $state, ?string $publishedAt, string $now): void
    {
        $this->query()
            ->where('id', $articleId)
            ->set([
                'state' => $state,
                'published_at' => $publishedAt,
                'updated_at' => $now,
            ])
            ->update();
    }

    /**
     * Vrati admin projekci clanku bez skrytych filtru mimo DataGrid definici
     *
     * @return QueryPage<array<string,mixed>>
     */
    public function listForDataGrid(DataGridQuery $query, string $defaultLocale): QueryPage
    {
        $locale = $query->filter('locale') ?? $defaultLocale;
        $total = $this->dataGridQuery($query, $locale)->countAllResults();
        $sorts = [
            'title' => 't.title',
            'publishedAt' => 'a.published_at',
            'updatedAt' => 'a.updated_at',
            'state' => 'a.state',
        ];
        $sort = $sorts[$query->sortKey()] ?? $sorts['publishedAt'];
        $direction = $query->sortDirection() === 'asc' ? 'ASC' : 'DESC';
        /** @var list<array<string,mixed>> $articles */
        $articles = $this->dataGridQuery($query, $locale)
            ->select([
                'a.id',
                'a.state',
                'a.published_at',
                'a.updated_at',
                'a.deleted_at',
                't.locale',
                't.title',
                't.slug',
            ])
            ->orderBy($sort, $direction)
            ->orderBy('a.id', 'DESC')
            ->limit($query->pageSize(), ($query->page() - 1) * $query->pageSize())
            ->getArray();

        return new QueryPage(
            $articles,
            $query->page(),
            $query->pageSize(),
            $total,
        );
    }

    /**
     * Nacte aggregate a vsechny jeho mutace pro full-page editor
     *
     * @return array<string,mixed>|null
     */
    public function findForEditor(int $articleId): ?array
    {
        $article = $this->query()
            ->select([
                'id',
                'author_name',
                'recommended',
                'show_author',
                'sharing_enabled',
                'show_published_at',
                'show_featured_image',
                'show_reading_time',
                'state',
                'published_at',
            ])
            ->where('id', $articleId)
            ->first();
        if ($article === null) {
            return null;
        }

        /** @var list<array<string,mixed>> $translations */
        $translations = $this->translations()
            ->select([
                'id',
                'locale',
                'title',
                'page_title',
                'slug',
                'summary',
                'content',
                'meta_description',
            ])
            ->where('article_id', $articleId)
            ->orderBy('locale', 'ASC')
            ->getArray();
        $article['translations'] = $translations;
        $tagsByTranslation = [];
        foreach ($this->translationTagsQuery()
            ->selectRaw('translation.id AS translation_id, tag.name')
            ->from('cms_news_article_translation_tag tag')
            ->join(
                'cms_news_article_translation translation',
                'translation.id = tag.translation_id',
            )
            ->where('translation.article_id', $articleId)
            ->orderBy('tag.sort_order', 'ASC')
            ->orderBy('tag.id', 'ASC')
            ->getArray() as $tag) {
            $tagsByTranslation[(int) $tag['translation_id']][] = (string) $tag['name'];
        }
        foreach ($article['translations'] as &$translation) {
            $translation['tags'] = $tagsByTranslation[(int) $translation['id']] ?? [];
            unset($translation['id']);
        }
        unset($translation);

        return $article;
    }

    /**
     * Vrati locale dostupne pro editor vcetne ulozenych neaktivnich mutaci
     *
     * @return list<array{code:string,name:string,enabled:int,is_default:int,has_translation:int}>
     */
    public function editorLocales(int $articleId): array
    {
        /** @var list<array{code:string,name:string,enabled:int,is_default:int,has_translation:int}> $locales */
        $locales = QueryBuilder::make($this->db)
            ->table('system_language language')
            ->select([
                'language.code',
                'language.name',
                'language.enabled',
                'language.is_default',
            ])
            ->selectRaw('CASE WHEN translation.id IS NULL THEN 0 ELSE 1 END AS has_translation')
            ->join(
                'cms_news_article_translation translation',
                'translation.locale = language.code AND translation.article_id = ' . $articleId,
                'LEFT',
            )
            ->whereRaw('language.enabled = 1 OR translation.id IS NOT NULL')
            ->orderBy('language.sort_order', 'ASC')
            ->orderBy('language.code', 'ASC')
            ->getArray();

        return $locales;
    }

    /**
     * Vrati enabled content locale pro taby editoru a deklarovany filter
     *
     * @return list<array<string,mixed>>
     */
    public function enabledLocales(): array
    {
        return QueryBuilder::make($this->db)
            ->table('system_language')
            ->select(['code', 'name'])
            ->where('enabled', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('code', 'ASC')
            ->getArray();
    }

    /**
     * Sestavi DataGrid projection omezenou lokalizaci, stavem a hledanim
     */
    private function dataGridQuery(DataGridQuery $query, string $locale): QueryBuilder
    {
        $builder = $this->withDeleted()
            ->query()
            ->from('cms_news_article a')
            ->join('cms_news_article_translation t', 't.article_id = a.id')
            ->where('t.locale', $locale);
        $builder = $query->filter('status') === 'deleted'
            ? $builder->whereRaw('a.deleted_at IS NOT NULL')
            : $builder->where('a.deleted_at', null);
        if (trim($query->search()) !== '') {
            $search = '%' . trim($query->search()) . '%';
            $builder = $builder->whereRaw(
                '(t.title LIKE ? OR t.slug LIKE ?)',
                [$search, $search],
            );
        }
        if (in_array($query->filter('state'), ['draft', 'published'], true)) {
            $builder = $builder->where('a.state', $query->filter('state'));
        }

        return $builder;
    }

    /**
     * Vrati query builder nad lokalizovanymi mutacemi
     */
    private function translations(): QueryBuilder
    {
        return QueryBuilder::make($this->db)
            ->table('cms_news_article_translation');
    }

    /**
     * Vrati query builder nad lokalizovanymi stitky
     */
    private function translationTagsQuery(): QueryBuilder
    {
        return QueryBuilder::make($this->db)
            ->table('cms_news_article_translation_tag');
    }

    /**
     * Vrati query builder nad canonical CMS routami
     */
    private function routes(): QueryBuilder
    {
        return QueryBuilder::make($this->db)
            ->table('cms_route');
    }
}
