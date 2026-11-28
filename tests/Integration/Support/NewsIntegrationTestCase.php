<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Integration\Support;

use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Provides a fresh technical News environment for every integration scenario
 */
abstract class NewsIntegrationTestCase extends TestCase
{
    private ?NewsIntegrationEnvironment $environment = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->environment = new NewsIntegrationEnvironment();
    }

    protected function tearDown(): void
    {
        if ($this->environment !== null) {
            $this->environment->close();
        }

        parent::tearDown();
    }

    protected function environment(): NewsIntegrationEnvironment
    {
        return $this->environment ?? throw new LogicException('Integration environment is not initialized.');
    }
}
