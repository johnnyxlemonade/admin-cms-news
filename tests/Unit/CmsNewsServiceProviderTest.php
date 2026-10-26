<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit;

use Lemonade\Cms\News\Actions\NewsActionRegistrar;
use Lemonade\Cms\News\CmsNewsServiceProvider;
use Lemonade\Framework\Container\Container;
use Lemonade\Framework\Routing\RouteRegistrarInterface;
use PHPUnit\Framework\TestCase;

/**
 * Overuje oddeleni administracniho News modulu od verejneho routingu hosta
 */
final class CmsNewsServiceProviderTest extends TestCase
{
    /**
     * Registruje pouze administracni sluzby bez verejnych rout
     */
    public function testItDoesNotActivatePublicNewsRouting(): void
    {
        $container = new Container();

        (new CmsNewsServiceProvider())->register($container);

        self::assertTrue($container->isBound(NewsActionRegistrar::CREATE_HANDLER));
        self::assertSame([], iterator_to_array($container->tagged(RouteRegistrarInterface::class)));
    }
}
