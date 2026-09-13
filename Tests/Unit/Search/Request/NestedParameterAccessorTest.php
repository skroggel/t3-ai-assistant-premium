<?php
declare(strict_types=1);

namespace Madj2k\AiAssistantPremium\Tests\Unit\Search\Request;

use Madj2k\AiAssistantPremium\Search\Request\NestedParameterAccessor;
use PHPUnit\Framework\TestCase;

final class NestedParameterAccessorTest extends TestCase
{
    public function testGetReadsNestedEngineParameter(): void
    {
        $subject = new NestedParameterAccessor();

        self::assertSame('natural colours', $subject->get(
            ['tx_solr' => ['q' => 'natural colours']],
            ['tx_solr', 'q'],
        ));
        self::assertNull($subject->get(
            ['tx_solr' => ['q' => 'natural colours']],
            ['tx_kesearch_pi1', 'sword'],
        ));
    }

    public function testSetAndRemoveNestedEngineParameters(): void
    {
        $subject = new NestedParameterAccessor();
        $parameters = $subject->set([], ['tx_solr', 'q'], 'natural colours');
        $parameters = $subject->set($parameters, ['tx_solr', 'page'], 3);

        self::assertSame('natural colours', $parameters['tx_solr']['q']);
        self::assertSame(3, $parameters['tx_solr']['page']);

        $parameters = $subject->remove($parameters, ['tx_solr', 'page']);
        self::assertSame(['tx_solr' => ['q' => 'natural colours']], $parameters);
    }

    public function testRemovePrunesEmptyParents(): void
    {
        $subject = new NestedParameterAccessor();
        self::assertSame([], $subject->remove(
            ['tx_kesearch_pi1' => ['page' => 2]],
            ['tx_kesearch_pi1', 'page'],
        ));
    }
}
