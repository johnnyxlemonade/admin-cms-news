<?php

declare(strict_types=1);

namespace Lemonade\Cms\News;

use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\DataGrid\DataGridIndexViewModel;
use Lemonade\Admin\DataGrid\DataGridPrimaryAction;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorDefinition;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorHeaderContextDefinition;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorHeaderContextItem;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorRenderContext;
use Lemonade\Admin\Editor\EditorLoaded;
use Lemonade\Admin\Editor\Exception\EditorEntityNotFoundException;
use Lemonade\Admin\Icon\AdminIcon;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Page\Contract\ModuleEditorPageProviderInterface;
use Lemonade\Admin\Page\Contract\ModuleIndexPageProviderInterface;
use Lemonade\Admin\Page\Contract\ModuleModalEditorPageProviderInterface;
use Lemonade\Admin\Page\ModulePage;
use Lemonade\Admin\Presentation\AdminFileUploadComponent;
use Lemonade\Cms\News\DataGrid\NewsDataGrid;
use Lemonade\Cms\News\Editor\NewsAdminEditorDefinitionFactory;
use Lemonade\Cms\News\Models\NewsModel;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\UrlGenerator;

/**
 * Sklada indexovou stranku News managementu nad shared DataGrid transportem
 */
final class NewsModulePageProvider implements ModuleIndexPageProviderInterface, ModuleEditorPageProviderInterface, ModuleModalEditorPageProviderInterface
{
    /**
     * Nastavuje grid, authorization, preklady a URL pro News index
     */
    public function __construct(
        private readonly NewsDataGrid $grid,
        private readonly AuthorizationService $authorization,
        private readonly TranslatorInterface $translator,
        private readonly UrlGenerator $urls,
        private readonly AdminModuleRouteResolver $routes,
        private readonly NewsAdminEditorDefinitionFactory $adminEditorDefinitions,
        private readonly AdminFileUploadComponent $files,
        private readonly NewsModel $news,
    ) {}

    /**
     * Vrati pravo potrebne pro otevreni News indexu
     */
    public function indexPermission(): string
    {
        return 'cms.news.view';
    }

    /**
     * Vytvori standardni News index s autorizovanou create akci
     */
    public function index(string $locale): ModulePage
    {
        $primaryAction = $this->authorization->hasPermission('cms.news.create')
            ? new DataGridPrimaryAction(
                translationKey: 'news.actions.create',
                icon: AdminIcon::PlusLg,
                href: '#',
                modalUrl: $this->urls->route(
                    name: 'admin.api.modal.create',
                    params: ['module' => 'news'],
                ),
                modalSize: 'medium',
            )
            : null;
        $viewModel = new DataGridIndexViewModel(
            moduleCode: 'news',
            title: $this->translator->get('news.list.title'),
            description: $this->translator->get('news.list.description'),
            endpoint: $this->urls->route(
                name: 'admin.api.datagrid.index',
                params: ['module' => 'news'],
            ),
            definition: $this->grid->dataGridDefinition(),
            primaryAction: $primaryAction,
            loadingText: $this->translator->get('news.list.loading'),
            emptyText: $this->translator->get('news.list.empty'),
            errorText: $this->translator->get('admin.datagrid.errorDescription'),
        );

        return new ModulePage(
            view: 'admin::datagrid.index',
            title: $viewModel->title(),
            data: ['dataGrid' => $viewModel, 'locale' => $locale],
        );
    }

    /**
     * News nema full-page create presentation; draft se zaklada jen modalem
     *
     * @param array<string,string> $errors
     * @param array<string,mixed> $input
     * @param array<string,mixed> $query
     */
    public function create(EditorLoaded $editor, array $errors, array $input, string $locale, array $query = []): ModulePage
    {
        throw new EditorEntityNotFoundException('News does not provide a full-page create editor.');
    }

    /**
     * Sklada minimalni modal pro zalozeni pojmenovaneho News draftu
     */
    public function modalCreate(EditorLoaded $editor, string $locale): ModulePage
    {
        $editorLocales = $editor->data()['locales'] ?? [];
        if (!is_array($editorLocales)) {
            throw new \LogicException('News create editor did not return enabled locales.');
        }
        /** @var list<array<string,mixed>> $locales */
        $locales = array_values($editorLocales);

        return $this->modalPage(
            $this->adminEditorDefinitions->modalCreate($locales),
            $locale,
        );
    }

    /**
     * News neotevira existujici zaznamy v modalu
     */
    public function modalEdit(EditorLoaded $editor, string $locale): ModulePage
    {
        throw new \LogicException('News does not provide a modal edit presentation.');
    }

    /**
     * Vytvori full-page editor existujici News entity
     *
     * @param array<string,string> $errors
     * @param array<string,mixed> $input
     * @param array<string,mixed> $query
     */
    public function editor(EditorLoaded $editor, array $errors, array $input, string $locale, array $query = []): ModulePage
    {
        return $this->editorPage(
            editor: $editor,
            errors: $errors,
            input: $input,
            locale: $locale,
            mode: 'edit',
            query: $query,
        );
    }

    /**
     * Pripravi package-owned editor view pro jeden content locale context
     *
     * @param array<string,string> $errors
     * @param array<string,mixed> $input
     * @param array<string,mixed> $query
     */
    private function editorPage(EditorLoaded $editor, array $errors, array $input, string $locale, string $mode, array $query = []): ModulePage
    {
        $locales = $editor->data()['locales'];
        $selected = $this->resolveContentLocale($locales, $query);
        $article = $editor->data()['article'];
        $article['translations'] = array_values(array_filter(
            $article['translations'],
            static fn(array $translation): bool => $translation['locale'] === $selected['code'],
        ));
        $context = $this->localeContext(
            locales: $locales,
            selectedLocale: $selected['code'],
            mode: $mode,
            articleId: $article['id'],
        );

        return new ModulePage(
            view: 'cms-news::editor',
            title: $this->translator->get($mode === 'create' ? 'news.editor.create_title' : 'news.editor.title'),
            data: [
                'adminEditor' => $this->adminEditorDefinitions->create(
                    article: $article,
                    contentLocale: $selected['code'],
                    mode: $mode,
                    canPublish: $this->authorization->hasPermission('cms.news.publish'),
                    context: $context,
                    featuredImageUpload: $mode === 'edit'
                        ? $this->files->collection(
                            module: 'cms.news',
                            entityId: (int) $article['id'],
                            usage: 'thumbnail',
                            labelKey: 'news.fields.thumbnail',
                            helpKey: 'news.editor.thumbnail.help',
                            alt: (string) ($article['translations'][0]['title'] ?? ''),
                        )
                        : null,
                    galleryUpload: $mode === 'edit'
                        ? $this->files->collection('cms.news', (int) $article['id'], 'gallery', 'admin.file_upload.gallery', 'admin.file_upload.gallery_help')
                        : null,
                    attachmentUpload: $mode === 'edit'
                        ? $this->files->collection('cms.news', (int) $article['id'], 'attachment', 'admin.file_upload.attachments', 'admin.file_upload.attachments_help')
                        : null,
                    tagOptions: $this->news->tagNamesForLocale($selected['code']),
                ),
                'adminEditorContext' => new AdminEditorRenderContext(
                    values: [
                        'state' => $article['state'],
                        'published_at' => $article['published_at'],
                    ],
                    oldInput: $input,
                    errors: $errors,
                    mode: $mode,
                ),
                'locale' => $locale,
            ],
        );
    }

    /**
     * Vytvori shared modal presentation s minimalnim create formularem
     */
    private function modalPage(AdminEditorDefinition $adminEditor, string $locale): ModulePage
    {
        return new ModulePage(
            view: 'cms-news::modal-create',
            title: $this->translator->get('news.editor.create_title'),
            data: [
                'title' => $this->translator->get('news.editor.create_title'),
                'adminEditor' => $adminEditor,
                'adminEditorContext' => new AdminEditorRenderContext(
                    values: [],
                    oldInput: [],
                    errors: [],
                    mode: 'create',
                ),
                'locale' => $locale,
            ],
        );
    }

    /**
     * Vybere povoleny content locale podle explicitniho query contextu
     *
     * @param list<array<string,mixed>> $locales
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function resolveContentLocale(array $locales, array $query): array
    {
        $requested = strtolower(trim((string) ($query['locale'] ?? '')));
        if ($requested !== '') {
            foreach ($locales as $locale) {
                if ($locale['code'] === $requested) {
                    return $locale;
                }
            }

            throw new EditorEntityNotFoundException('News content locale is not available.');
        }

        foreach ($locales as $locale) {
            if ((int) ($locale['is_default'] ?? 0) === 1) {
                return $locale;
            }
        }

        return $locales[0] ?? throw new EditorEntityNotFoundException('News content locale is not available.');
    }

    /**
     * Vytvori linkove prepinani dostupnych content locales editoru
     *
     * @param list<array<string,mixed>> $locales
     */
    private function localeContext(array $locales, string $selectedLocale, string $mode, ?int $articleId): AdminEditorHeaderContextDefinition
    {
        $items = [];
        foreach ($locales as $locale) {
            $code = (string) $locale['code'];
            $enabled = (int) ($locale['enabled'] ?? 1) === 1;
            $hasTranslation = (int) ($locale['has_translation'] ?? 0) === 1;
            $items[] = new AdminEditorHeaderContextItem(
                label: (string) $locale['name'],
                href: $this->urls->route(
                    name: $this->routes->managementRouteName('news', $mode === 'create' ? 'create' : 'edit'),
                    params: $mode === 'create'
                        ? ['module' => 'news', 'locale' => $code]
                        : ['module' => 'news', 'id' => $articleId, 'locale' => $code],
                ),
                active: $code === $selectedLocale,
                secondary: !$enabled
                    ? $this->translator->get('news.editor.locale.disabled')
                    : (!$hasTranslation && $mode !== 'create' ? $this->translator->get('news.editor.locale.new_translation') : null),
            );
        }

        return new AdminEditorHeaderContextDefinition(
            label: $this->translator->get('news.editor.locale.label'),
            items: $items,
        );
    }
}
