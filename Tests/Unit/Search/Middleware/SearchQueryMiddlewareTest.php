<?php
declare(strict_types=1);

namespace Madj2k\AiAssistantPremium\Tests\Unit\Search\Middleware;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Madj2k\AiAssistantPremium\Search\Configuration\SearchIntegrationRegistry;
use Madj2k\AiAssistantPremium\Search\Middleware\SearchQueryMiddleware;
use Madj2k\AiAssistantPremium\Search\Request\NestedParameterAccessor;
use Madj2k\AiAssistantPremium\Search\Service\QueryOptimizer;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SearchQueryMiddlewareTest extends TestCase
{
    public function testNativeQueryIsForwardedWithoutOptimizerProfile(): void
    {
        $queryParameters = [
            'tx_kesearch_pi1' => [
                'sword' => 'Was macht Döhler?',
                'page' => 3,
                'filter' => ['region' => 'australia'],
            ],
            SearchQueryMiddleware::CONTROL_PARAMETER => [
                'integration' => 'ke_search',
                'optimizerProfile' => 0,
                'chatIdentifier' => 'search-1',
            ],
        ];
        $request = (new ServerRequest(
            'GET',
            'https://example.test/search?' . http_build_query($queryParameters),
        ))->withQueryParams($queryParameters);

        $queryOptimizer = (new \ReflectionClass(QueryOptimizer::class))->newInstanceWithoutConstructor();
        $subject = new SearchQueryMiddleware(
            new SearchIntegrationRegistry(),
            new NestedParameterAccessor(),
            $queryOptimizer,
        );
        $response = $subject->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(204);
            }
        });

        self::assertSame(303, $response->getStatusCode());
        parse_str((string)parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $redirectParameters);

        self::assertSame('Was macht Döhler?', $redirectParameters['tx_kesearch_pi1']['sword']);
        self::assertArrayNotHasKey('page', $redirectParameters['tx_kesearch_pi1']);
        self::assertSame('australia', $redirectParameters['tx_kesearch_pi1']['filter']['region']);
        self::assertSame('Was macht Döhler?', $redirectParameters[SearchQueryMiddleware::CONTROL_PARAMETER]['originalQuery']);
        self::assertSame('Was macht Döhler?', $redirectParameters[SearchQueryMiddleware::CONTROL_PARAMETER]['effectiveQuery']);
        self::assertSame('1', $redirectParameters[SearchQueryMiddleware::CONTROL_PARAMETER]['processed']);
    }
}
