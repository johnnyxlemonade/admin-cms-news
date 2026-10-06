<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Audit;

use Lemonade\Admin\Audit\AuditEventPresentation;
use Lemonade\Admin\Audit\AuditEventPresentationRegistry;
use Lemonade\Admin\Audit\AuditModulePresentation;
use Lemonade\Admin\Icon\AdminIcon;

/**
 * Registruje citelne auditni udalosti a identitu modulu Aktuality
 */
final class NewsAuditPresentationRegistrar
{
    /**
     * Nastavuje registry auditni prezentace modulu
     */
    public function __construct(private readonly AuditEventPresentationRegistry $presentations) {}

    /**
     * Priradi vsem emitovanym udalostem lokalizovane popisky a ikony
     */
    public function register(): void
    {
        $this->presentations->registerModule(new AuditModulePresentation(
            moduleCode: 'cms.news',
            translationGroup: 'cms.news',
            nameKey: 'news.module.name',
            icon: AdminIcon::JournalText,
        ));
        foreach ([
            'cms.news.created' => AdminIcon::PlusLg,
            'cms.news.updated' => AdminIcon::PencilSquare,
            'cms.news.translation_created' => AdminIcon::PlusLg,
            'cms.news.translation_updated' => AdminIcon::PencilSquare,
            'cms.news.published' => AdminIcon::CheckCircle,
            'cms.news.unpublished' => AdminIcon::PauseCircle,
            'cms.news.deleted' => AdminIcon::Trash3,
            'cms.news.restored' => AdminIcon::ArrowCounterclockwise,
        ] as $eventCode => $icon) {
            $this->presentations->register(new AuditEventPresentation(
                eventCode: $eventCode,
                translationKey: 'news.audit.events.' . substr($eventCode, strlen('cms.news.')),
                icon: $icon,
            ));
        }
    }
}
