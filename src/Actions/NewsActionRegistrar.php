<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Actions;

use Lemonade\Admin\Action\ModuleActionDefinition;
use Lemonade\Admin\Action\ModuleActionRegistry;
use Lemonade\Admin\Action\Presentation\ConfirmationDefinition;
use Lemonade\Admin\Action\StandardEditorAction;
use Lemonade\Admin\Editor\EditorDispatcher;

/**
 * Pripojuje shared editor transport k create a save mutacim Aktualit
 */
final class NewsActionRegistrar
{
    /**
     * Nastavuje action registry a shared dispatcher editoru
     */
    public function __construct(
        private readonly ModuleActionRegistry $actions,
        private readonly EditorDispatcher $editors,
        private readonly NewsDeleteAction $deleteAction,
        private readonly NewsRestoreAction $restoreAction,
    ) {}

    /**
     * Registruje autorizovane create a save action pro full-page editor
     */
    public function register(): void
    {
        $moduleCode = 'cms.news';
        $this->actions->register(
            moduleCode: $moduleCode,
            definition: new ModuleActionDefinition(
                key: 'create',
                permission: 'cms.news.create',
                labelKey: 'news.actions.create',
                refreshGrid: true,
            ),
            handler: StandardEditorAction::create($this->editors, $moduleCode),
        );
        $this->actions->register(
            moduleCode: $moduleCode,
            definition: new ModuleActionDefinition(
                key: 'save',
                permission: 'cms.news.edit',
                labelKey: 'admin.common.save',
            ),
            handler: StandardEditorAction::save($this->editors, $moduleCode),
        );
        $this->actions->register(
            moduleCode: $moduleCode,
            definition: new ModuleActionDefinition(
                key: 'delete',
                permission: 'cms.news.delete',
                labelKey: 'news.actions.delete',
                confirmation: new ConfirmationDefinition('news.confirm.delete'),
                refreshGrid: true,
            ),
            handler: $this->deleteAction,
        );
        $this->actions->register(
            moduleCode: $moduleCode,
            definition: new ModuleActionDefinition(
                key: 'restore',
                permission: 'cms.news.restore',
                labelKey: 'news.actions.restore',
                confirmation: new ConfirmationDefinition('news.confirm.restore'),
                refreshGrid: true,
            ),
            handler: $this->restoreAction,
        );
    }
}
