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
use Madj2k\AiAssistantPremium\License\LicenseService;
use Madj2k\AiAssistantPremium\License\LicenseCheckInterface;
use Madj2k\AiAssistantPremium\Search\Service\SearchStateService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SearchQueryMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 64);
    }

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
        $licenseService = $this->createStub(LicenseCheckInterface::class);
        $licenseService->method('isValid')->willReturn(true);
        $stateService = new SearchStateService();
        $subject = new SearchQueryMiddleware(
            new SearchIntegrationRegistry(),
            new NestedParameterAccessor(),
            $queryOptimizer,
            $stateService,
            $licenseService,
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
        $state = $stateService->decode((string)$redirectParameters[SearchQueryMiddleware::STATE_PARAMETER]);
        self::assertSame('Was macht Döhler?', $state['originalQuery']);
        self::assertSame('Was macht Döhler?', $state['effectiveQuery']);
        self::assertSame(1, $state['processed']);
    }

    /**
     * Tests the JSON response mode used by non-native search clients such as Vue.
     *
     * @return void
     */
    public function testJsonResponseReturnsEffectiveQueryAndState(): void
    {
        $queryParameters = [
            'tx_kesearch_pi1' => ['sword' => 'Original query'],
            SearchQueryMiddleware::CONTROL_PARAMETER => [
                'integration' => 'ke_search',
                'optimizerProfile' => 0,
                'chatIdentifier' => 'search-json',
                'response' => 'json',
            ],
        ];
        $request = (new ServerRequest('GET', 'https://example.test/search?' . http_build_query($queryParameters)))
            ->withQueryParams($queryParameters);

        $queryOptimizer = (new \ReflectionClass(QueryOptimizer::class))->newInstanceWithoutConstructor();
        $licenseService = $this->createStub(LicenseCheckInterface::class);
        $licenseService->method('isValid')->willReturn(true);
        $middleware = new SearchQueryMiddleware(
            new SearchIntegrationRegistry(),
            new NestedParameterAccessor(),
            $queryOptimizer,
            new SearchStateService(),
            $licenseService,
        );

        $response = $middleware->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(204);
            }
        });
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Original query', $payload['originalQuery']);
        self::assertSame('Original query', $payload['effectiveQuery']);
        self::assertFalse($payload['optimized']);
        self::assertNotEmpty($payload['state']);
    }
}
