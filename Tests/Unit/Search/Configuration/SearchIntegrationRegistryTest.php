<?php
declare(strict_types=1);

namespace Madj2k\AiAssistantPremium\Tests\Unit\Search\Configuration;

use Madj2k\AiAssistantPremium\Search\Configuration\SearchIntegrationRegistry;
use PHPUnit\Framework\TestCase;

final class SearchIntegrationRegistryTest extends TestCase
{
    public function testResolvesEngineParameterMappingsWithoutExtensionClasses(): void
    {
        $subject = new SearchIntegrationRegistry();

        self::assertSame(['tx_kesearch_pi1', 'sword'], $subject->get('ke_search')?->queryParameterPath);
        self::assertSame(['tx_solr', 'q'], $subject->get('solr')?->queryParameterPath);
        self::assertNull($subject->get('unknown'));
    }
}
