<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Actions;

use Lemonade\Admin\Action\ModuleActionDefinition;
use Lemonade\Admin\Action\ModuleActionRegistry;
use Lemonade\Admin\Action\Presentation\ConfirmationDefinition;
use Lemonade\Cms\News\ModuleManifest;

/**
 * Pripojuje shared editor transport k create a save mutacim Aktualit
 */
final readonly class NewsActionRegistrar
{
    public const string CREATE_HANDLER = 'admin.cms.news.action.create';
    public const string SAVE_HANDLER = 'admin.cms.news.action.save';

    /**
     * Nastavuje registry definic a handler service ID
     */
    public function __construct(
        private ModuleActionRegistry $actions,
    ) {}

    /**
     * Registruje autorizovane create a save action pro full-page editor
     */
    public function register(): void
    {
        $moduleCode = ModuleManifest::CODE;
        $this->actions->register(
            moduleCode: $moduleCode,
            definition: new ModuleActionDefinition(
                key: 'create',
                permission: 'cms.news.create',
                labelKey: 'news.actions.create',
                refreshGrid: true,
            ),
            handlerServiceId: self::CREATE_HANDLER,
        );
        $this->actions->register(
            moduleCode: $moduleCode,
            definition: new ModuleActionDefinition(
                key: 'save',
                permission: 'cms.news.edit',
                labelKey: 'admin.common.save',
            ),
            handlerServiceId: self::SAVE_HANDLER,
        );
        $this->actions->register(
            moduleCode: $moduleCode,
            definition: new ModuleActionDefinition(
                key: 'change-url',
                permission: 'cms.news.edit',
                labelKey: 'admin.url_segment.save',
            ),
            handlerServiceId: NewsChangeUrlAction::class,
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
            handlerServiceId: NewsDeleteAction::class,
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
            handlerServiceId: NewsRestoreAction::class,
        );
    }
}
