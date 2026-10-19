<?php

declare(strict_types=1);

namespace Lemonade\Cms\News;

use Lemonade\Admin\Modules\Definition\ModuleKind;
use Lemonade\Admin\Modules\Manifest\ModuleManifestInterface;
use Lemonade\Admin\Modules\Migration\ModuleMigrationManifestInterface;
use Lemonade\Framework\Database\Migration\MigrationInterface;

/**
 * Deklaruje optional CMS News modul a jeho schema
 */
final readonly class ModuleManifest implements ModuleManifestInterface, ModuleMigrationManifestInterface
{
    /**
     * Vrati stabilni kod News modulu
     */
    public function code(): string
    {
        return 'cms.news';
    }

    /**
     * Vrati optional lifecycle modulu
     */
    public function kind(): ModuleKind
    {
        return ModuleKind::Optional;
    }

    /**
     * Vrati prekladovy klic nazvu modulu
     */
    public function labelKey(): string
    {
        return 'news.module.name';
    }

    /**
     * Vrati provider runtime contributions modulu
     */
    public function runtimeProvider(): string
    {
        return CmsNewsServiceProvider::class;
    }

    /**
     * Vrati migrace aplikovane pri instalaci modulu
     *
     * @return list<class-string<MigrationInterface>>
     */
    public function migrations(): array
    {
        return [Migrations\CreateNewsArticles::class];
    }
}
