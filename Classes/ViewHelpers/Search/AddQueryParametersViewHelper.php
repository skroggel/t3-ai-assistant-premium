<?php
declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Madj2k\AiAssistantPremium\ViewHelpers\Search;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Class AddQueryParametersViewHelper
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
final class AddQueryParametersViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        parent::initializeArguments();

        $this->registerArgument(
            'prefix',
            'string',
            'Prefix of query parameters to preserve from the current request',
            false,
            'ai'
        );

        $this->registerArgument(
            'parameters',
            'array',
            'Additional query parameters. These override parameters from the current request.',
            false,
            []
        );
    }

    /**
     * @return string
     */
    public function render(): string
    {
        $content = $this->renderChildren();

        if (!is_string($content) || $content === '') {
            return '';
        }

        $parameters = $this->getParametersFromRequest(
            $this->arguments['prefix']
        );

        $parameters = array_replace_recursive(
            $parameters,
            $this->arguments['parameters']
        );

        if ($parameters === []) {
            return $content;
        }

        return preg_replace_callback(
            '/href=(["\'])(.*?)\1/i',
            function (array $matches) use ($parameters): string {
                $quote = $matches[1];

                $url = html_entity_decode(
                    $matches[2],
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );

                $url = $this->addQueryParameters(
                    $url,
                    $parameters
                );

                return 'href='
                    . $quote
                    . htmlspecialchars(
                        $url,
                        ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                        'UTF-8'
                    )
                    . $quote;
            },
            $content,
            1
        ) ?? $content;
    }

    /**
     * @param string $prefix
     * @return array
     */
    private function getParametersFromRequest(string $prefix): array
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;

        if (!$request instanceof ServerRequestInterface) {
            return [];
        }

        $queryParameters = $request->getQueryParams();
        $matchingParameters = [];

        foreach ($queryParameters as $name => $value) {
            if (
                is_string($name)
                && str_starts_with($name, $prefix)
            ) {
                $matchingParameters[$name] = $value;
            }
        }

        return $matchingParameters;
    }

    /**
     * @param string $url
     * @param array $additionalParameters
     * @return string
     */
    private function addQueryParameters(
        string $url,
        array $additionalParameters
    ): string {
        $fragment = '';

        if (str_contains($url, '#')) {
            [$url, $fragment] = explode('#', $url, 2);
        }

        $queryParameters = [];

        if (str_contains($url, '?')) {
            [$path, $queryString] = explode('?', $url, 2);

            parse_str(
                $queryString,
                $queryParameters
            );
        } else {
            $path = $url;
        }

        $queryParameters = array_replace_recursive(
            $queryParameters,
            $additionalParameters
        );

        $queryString = http_build_query(
            $queryParameters,
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $result = $path;

        if ($queryString !== '') {
            $result .= '?' . $queryString;
        }

        if ($fragment !== '') {
            $result .= '#' . $fragment;
        }

        return $result;
    }
}
