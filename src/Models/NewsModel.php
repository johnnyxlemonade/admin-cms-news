<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Models;

use Lemonade\Admin\DataGrid\Query\DataGridQuery;
use Lemonade\Admin\DataGrid\Query\QueryPage;
use Lemonade\Cms\CmsPublicationState;
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
        'created_by',
        'updated_by',
        'deleted_by',
        'owner_id',
        'author_name',
        'recommended',
        'show_author',
        'sharing_enabled',
        'show_published_at',
        'show_thumbnail',
        'show_reading_time',
        'show_tags',
    ];

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
     * Vrati aktualniho vlastnika vcetne soft-delete stavu
     */
    public function ownerId(int $articleId): ?int
    {
        $article = $this->withDeleted()
            ->query()
            ->select(['owner_id'])
            ->where('id', $articleId)
            ->first();

        return $article === null || $article['owner_id'] === null
            ? null
            : (int) $article['owner_id'];
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
                'show_thumbnail',
                'show_reading_time',
                'show_tags',
            ])
            ->where('id', $articleId)
            ->first();
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
                'id',
                'article_id',
                'locale',
                'title',
                'page_title',
                'summary',
                'content',
                'meta_description',
                'publication_state',
                'published_at',
            ])
            ->where('article_id', $articleId)
            ->where('locale', $locale)
            ->first();
    }

    /**
     * Vrati serazene unikatni nazvy stitku pro zvolenou content lokalizaci
     *
     * @return list<string>
     */
    public function tagNamesForLocale(string $locale): array
    {
        /** @var list<array{name:string}> $tags */
        $tags = $this->translationTagsQuery()
            ->select(['tag.name'])
            ->from('cms_news_article_translation_tag tag')
            ->joinOn(
                'cms_news_article_translation translation',
                'translation.id',
                '=',
                'tag.translation_id',
            )
            ->where('translation.locale', $locale)
            ->groupBy('tag.name')
            ->orderBy('tag.name', 'ASC')
            ->getArray();

        return array_map(
            static fn(array $tag): string => (string) $tag['name'],
            $tags,
        );
    }

    /**
     * Vrati publication metadata jedne lokalizovane mutace clanku
     *
     * @return array<string,mixed>|null
     */
    public function publication(int $articleId, string $locale): ?array
    {
        return $this->translations()
            ->select(['publication_state', 'published_at'])
            ->where('article_id', $articleId)
            ->where('locale', $locale)
            ->first();
    }

    /**
     * Vrati admin projekci clanku bez skrytych filtru mimo DataGrid definici
     *
     * @return QueryPage<array<string,mixed>>
     */
    public function listForDataGrid(
        DataGridQuery $query,
        string $defaultLocale,
        ?int $ownerId = null,
    ): QueryPage {
        $locale = $query->filter('locale') ?? $defaultLocale;
        $total = $this->dataGridQuery($query, $locale, $ownerId)->countAllResults();
        $sorts = [
            'title' => 't.title',
            'publishedAt' => 't.published_at',
            'updatedAt' => 'a.updated_at',
            'state' => 't.publication_state',
        ];
        $sort = $sorts[$query->sortKey()] ?? $sorts['publishedAt'];
        $direction = $query->sortDirection() === 'asc' ? 'ASC' : 'DESC';
        /** @var list<array<string,mixed>> $articles */
        $articles = $this->dataGridQuery($query, $locale, $ownerId)
            ->select([
                'a.id',
                'a.owner_id',
                'a.created_at',
                'a.created_by',
                'a.updated_by',
                'a.deleted_by',
                't.publication_state',
                't.published_at',
                'a.updated_at',
                'a.deleted_at',
                't.locale',
                't.title',
            ])
            ->selectRaw('route.path AS canonical_path')
            ->selectRaw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', creator.first_name, creator.last_name)), ''), creator.email, '—') AS created_by_name")
            ->selectRaw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', updater.first_name, updater.last_name)), ''), updater.email, '—') AS updated_by_name")
            ->selectRaw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', deleter.first_name, deleter.last_name)), ''), deleter.email, '—') AS deleted_by_name")
            ->selectRaw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', owner.first_name, owner.last_name)), ''), owner.email, '—') AS owner_name")
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
                'created_by',
                'updated_by',
                'deleted_by',
                'owner_id',
                'created_at',
                'updated_at',
                'deleted_at',
                'author_name',
                'recommended',
                'show_author',
                'sharing_enabled',
                'show_published_at',
                'show_thumbnail',
                'show_reading_time',
                'show_tags',
            ])
            ->selectRaw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', (SELECT first_name FROM system_user WHERE id = cms_news_article.created_by), (SELECT last_name FROM system_user WHERE id = cms_news_article.created_by))), ''), (SELECT email FROM system_user WHERE id = cms_news_article.created_by), '—') AS created_by_name")
            ->selectRaw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', (SELECT first_name FROM system_user WHERE id = cms_news_article.updated_by), (SELECT last_name FROM system_user WHERE id = cms_news_article.updated_by))), ''), (SELECT email FROM system_user WHERE id = cms_news_article.updated_by), '—') AS updated_by_name")
            ->selectRaw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', (SELECT first_name FROM system_user WHERE id = cms_news_article.deleted_by), (SELECT last_name FROM system_user WHERE id = cms_news_article.deleted_by))), ''), (SELECT email FROM system_user WHERE id = cms_news_article.deleted_by), '—') AS deleted_by_name")
            ->selectRaw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', (SELECT first_name FROM system_user WHERE id = cms_news_article.owner_id), (SELECT last_name FROM system_user WHERE id = cms_news_article.owner_id))), ''), (SELECT email FROM system_user WHERE id = cms_news_article.owner_id), '—') AS owner_name")
            ->where('id', $articleId)
            ->first();
        if ($article === null) {
            return null;
        }

        /** @var list<array<string,mixed>> $translations */
        $translations = $this->translations()
            ->from('cms_news_article_translation translation')
            ->select([
                'translation.id',
                'translation.locale',
                'translation.title',
                'translation.page_title',
                'translation.summary',
                'translation.content',
                'translation.meta_description',
                'translation.publication_state',
                'translation.published_at',
            ])
            ->selectRaw('route.path AS canonical_path')
            ->joinRaw(
                table: 'cms_route route',
                condition: 'route.module_code = ? AND route.entity_id = translation.article_id AND route.locale = translation.locale',
                bindings: ['cms.news'],
                type: 'LEFT',
            )
            ->where('translation.article_id', $articleId)
            ->orderBy('translation.locale', 'ASC')
            ->getArray();
        $article['translations'] = $translations;
        $tagsByTranslation = [];
        foreach ($this->translationTagsQuery()
            ->selectRaw('translation.id AS translation_id, tag.name')
            ->from('cms_news_article_translation_tag tag')
            ->joinOn(
                'cms_news_article_translation translation',
                'translation.id',
                '=',
                'tag.translation_id',
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
            ->joinRaw(
                table: 'cms_news_article_translation translation',
                condition: 'translation.locale = language.code AND translation.article_id = ?',
                bindings: [$articleId],
                type: 'LEFT',
            )
            ->whereRaw('language.enabled = 1 OR translation.id IS NOT NULL')
            ->orderBy('language.sort_order', 'ASC')
            ->orderBy('language.code', 'ASC')
            ->getArray();

        return $locales;
    }

    /**
     * Vrati aktivni locale a deaktivovane locale s existujici News mutaci
     *
     * @return list<array{code:string,name:string}>
     */
    public function dataGridLocales(): array
    {
        /** @var list<array{code:string,name:string}> $locales */
        $locales = QueryBuilder::make($this->db)
            ->table('system_language language')
            ->select(['language.code', 'language.name'])
            ->joinOn(
                'cms_news_article_translation translation',
                'translation.locale',
                '=',
                'language.code',
                'LEFT',
            )
            ->whereRaw('language.enabled = 1 OR translation.id IS NOT NULL')
            ->groupBy('language.code')
            ->groupBy('language.name')
            ->groupBy('language.sort_order')
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
    private function dataGridQuery(DataGridQuery $query, string $locale, ?int $ownerId): QueryBuilder
    {
        $builder = $this->withDeleted()
            ->query()
            ->from('cms_news_article a')
            ->joinOn('cms_news_article_translation t', 't.article_id', '=', 'a.id')
            ->joinRaw(
                table: 'cms_route route',
                condition: 'route.module_code = ? AND route.entity_id = a.id AND route.locale = t.locale',
                bindings: ['cms.news'],
                type: 'LEFT',
            )
            ->joinOn('system_user creator', 'creator.id', '=', 'a.created_by', 'LEFT')
            ->joinOn('system_user updater', 'updater.id', '=', 'a.updated_by', 'LEFT')
            ->joinOn('system_user deleter', 'deleter.id', '=', 'a.deleted_by', 'LEFT')
            ->joinOn('system_user owner', 'owner.id', '=', 'a.owner_id', 'LEFT')
            ->where('t.locale', $locale);
        if ($ownerId !== null) {
            $builder = $builder->where('a.owner_id', $ownerId);
        }
        $builder = $query->filter('status') === 'deleted'
            ? $builder->whereRaw('a.deleted_at IS NOT NULL')
            : $builder->where('a.deleted_at', null);
        if (trim($query->search()) !== '') {
            $search = '%' . trim($query->search()) . '%';
            $builder = $builder->whereRaw(
                '(t.title LIKE ? OR route.path LIKE ?)',
                [$search, $search],
            );
        }
        if (in_array($query->filter('state'), ['new', 'draft', 'published'], true)) {
            $states = [
                'new' => CmsPublicationState::New,
                'draft' => CmsPublicationState::Draft,
                'published' => CmsPublicationState::Published,
            ];
            $builder = $builder->where('t.publication_state', $states[$query->filter('state')]->value);
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
}
