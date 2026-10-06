<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\DataGrid;

use Lemonade\Admin\Action\Presentation\ModuleActionPresentationFactory;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\DataGrid\Action\DataGridRowActionDefinition;
use Lemonade\Admin\DataGrid\Cell\ScalarCell;
use Lemonade\Admin\DataGrid\Cell\StatusCell;
use Lemonade\Admin\DataGrid\Cell\StatusVariant;
use Lemonade\Admin\DataGrid\Cell\ThumbnailCell;
use Lemonade\Admin\DataGrid\Contract\DataGridProviderInterface;
use Lemonade\Admin\DataGrid\DataGridColumnDefinition;
use Lemonade\Admin\DataGrid\DataGridDefinition;
use Lemonade\Admin\DataGrid\DataGridFilterDefinition;
use Lemonade\Admin\DataGrid\DataGridResult;
use Lemonade\Admin\DataGrid\DataGridRowDefinition;
use Lemonade\Admin\DataGrid\Query\DataGridQuery;
use Lemonade\Admin\Icon\AdminIcon;
use Lemonade\Admin\Presentation\AdminThumbnailComponent;
use Lemonade\Admin\Presentation\Models\AdminFileModel;
use Lemonade\Admin\Select\SelectOptionDefinition;
use Lemonade\Admin\Select\StaticSelectOptionSource;
use Lemonade\Cms\News\Models\NewsModel;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\UrlGenerator;

/**
 * Promita News clanky do shared Admin DataGridu bez translation N+1 dotazu
 */
final class NewsDataGrid implements DataGridProviderInterface
{
    /**
     * Nastavuje News persistence projekci pro DataGrid
     */
    public function __construct(
        private readonly NewsModel $news,
        private readonly AuthorizationService $authorization,
        private readonly TranslatorInterface $translator,
        private readonly ModuleActionPresentationFactory $actionPresentation,
        private readonly UrlGenerator $urls,
        private readonly AdminFileModel $files,
        private readonly AdminThumbnailComponent $thumbnails,
    ) {}

    /**
     * Vrati pravo potrebne pro nacteni News prehledu
     */
    public function permission(): string
    {
        return 'cms.news.view';
    }

    /**
     * Deklaruje sloupce, search, filtry a deterministic razeni prehledu
     */
    public function dataGridDefinition(): DataGridDefinition
    {
        return new DataGridDefinition(
            id: 'cms-news',
            columns: [
                new DataGridColumnDefinition('title', 'news.fields.title', 'title'),
                new DataGridColumnDefinition('state', 'news.fields.state', 'state'),
                new DataGridColumnDefinition('publishedAt', 'news.fields.published_at', 'publishedAt'),
                new DataGridColumnDefinition('updatedAt', 'news.fields.updated_at', 'updatedAt'),
                new DataGridColumnDefinition('actions', 'admin.common.actions'),
            ],
            searchEnabled: true,
            filters: [
                new DataGridFilterDefinition(
                    key: 'status',
                    optionSource: new StaticSelectOptionSource(
                        options: [
                            new SelectOptionDefinition('active', $this->translator->get('news.list.active')),
                            new SelectOptionDefinition('deleted', $this->translator->get('news.list.deleted')),
                        ],
                    ),
                ),
                new DataGridFilterDefinition('state', new StaticSelectOptionSource([
                    new SelectOptionDefinition('draft', $this->translator->get('news.state.draft')),
                    new SelectOptionDefinition('published', $this->translator->get('news.state.published')),
                ])),
                new DataGridFilterDefinition('locale', new StaticSelectOptionSource(array_map(
                    fn(array $language): SelectOptionDefinition => new SelectOptionDefinition($language['code'], $language['name']),
                    $this->news->enabledLocales(),
                ))),
            ],
            defaultSortKey: 'publishedAt',
            defaultSortDirection: 'desc',
            defaultPageSize: 25,
            maximumPageSize: 100,
            defaultView: 'active',
            showAllView: false,
        );
    }

    /**
     * Vrati typed radky z jedine batch projekce News modelu
     */
    public function execute(DataGridQuery $query): DataGridResult
    {
        $locales = $this->news->enabledLocales();
        $page = $this->news->listForDataGrid($query, $locales[0]['code'] ?? 'cs');
        $featuredImages = $this->files->findForEntities(
            'cms.news',
            array_map(static fn(array $article): int => (int) $article['id'], $page->items()),
            'featured_image',
        );
        $rows = array_map(
            fn(array $article): DataGridRowDefinition => $this->row(
                $article,
                $featuredImages[(int) $article['id']] ?? null,
            ),
            $page->items(),
        );

        return new DataGridResult($rows, $page->page(), $page->perPage(), $page->total());
    }

    /**
     * Promita jeden clanek na odkazovatelny aktivni nebo read-only smazany radek
     *
     * @param array<string,mixed> $article
     * @param array{
     *     id:int,
     *     module_code:string,
     *     entity_id:int,
     *     usage:string,
     *     kind:string,
     *     original_filename:string,
     *     display_name:string|null,
     *     caption:string|null,
     *     extension:string,
     *     mime_type:string,
     *     file_size:int,
     *     width:int|null,
     *     height:int|null,
     * }|null $featuredImage
     */
    private function row(array $article, ?array $featuredImage): DataGridRowDefinition
    {
        $articleId = (int) $article['id'];
        $deleted = $article['deleted_at'] !== null;
        $editable = !$deleted && $this->authorization->hasPermission('cms.news.edit');

        return new DataGridRowDefinition(
            id: $articleId,
            cells: [
                'title' => new ThumbnailCell(
                    value: (string) $article['title'],
                    thumbnail: $featuredImage === null
                        ? $this->thumbnails->fallback(
                            fallback: null,
                            size: 'compact',
                            shape: 'rounded',
                        )
                        : $this->thumbnails->image(
                            module: 'cms.news',
                            imageId: (string) $featuredImage['id'],
                            alt: (string) $article['title'],
                            fallback: null,
                            size: 'compact',
                            shape: 'rounded',
                            presentation: 'datagrid',
                        ),
                    secondary: (string) $article['slug'],
                    url: $editable ? $this->editUrl($articleId, (string) $article['locale']) : null,
                ),
                'state' => new StatusCell((string) $article['state'], $article['state'] === 'published' ? StatusVariant::Success : StatusVariant::Muted),
                'publishedAt' => new ScalarCell((string) ($article['published_at'] ?? '—')),
                'updatedAt' => new ScalarCell((string) $article['updated_at']),
            ],
            actions: $this->rowActions($articleId, $deleted, (string) $article['locale']),
        );
    }

    /**
     * Nabizi editaci pouze jako neautoritativni presentation akci
     *
     * @return list<DataGridRowActionDefinition>
     */
    private function rowActions(int $articleId, bool $deleted, string $contentLocale): array
    {
        $actions = [];
        if (!$deleted && $this->authorization->hasPermission('cms.news.edit')) {
            $actions[] = (new DataGridRowActionDefinition(
                key: 'edit',
                label: $this->translator->get('admin.common.edit'),
                url: $this->editUrl($articleId, $contentLocale),
                method: 'GET',
            ))->withIcon(AdminIcon::PencilSquare);
        }
        $action = $this->actionPresentation->rowAction(
            moduleCode: 'cms.news',
            action: $deleted ? 'restore' : 'delete',
            entityId: $articleId,
        );
        if ($action !== null) {
            $actions[] = $action;
        }

        return $actions;
    }

    /**
     * Sklada canonical URL editoru s explicitni content locale mutace
     */
    private function editUrl(int $articleId, ?string $contentLocale = null): string
    {
        $url = $this->urls->route('admin.module.edit', ['module' => 'news', 'id' => $articleId]);

        return $contentLocale === null ? $url : $url . '?locale=' . rawurlencode($contentLocale);
    }
}
