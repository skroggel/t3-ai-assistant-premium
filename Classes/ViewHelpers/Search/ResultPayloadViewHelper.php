<?php
declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, version 3.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Madj2k\AiAssistantPremium\ViewHelpers\Search;

use Madj2k\AiAssistantPremium\Search\Result\SearchResultPayloadBuilder;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Class ResultPayloadViewHelper
 *
 * Emits an engine-neutral JSON payload from a search result template.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final class ResultPayloadViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function __construct(
        private readonly SearchResultPayloadBuilder $payloadBuilder,
    ) {
    }

    /**
     * Registers result rows, pagination data and the engine field mapping.
     */
    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('results', 'array', 'Search result rows.', true);
        $this->registerArgument('total', 'int', 'Total number of matches.', false, 0);
        $this->registerArgument('page', 'int', 'One-based result page.', false, 1);
        $this->registerArgument('idField', 'string', 'Source identifier field.', true);
        $this->registerArgument('titleField', 'string', 'Plain title field.', true);
        $this->registerArgument('textField', 'string', 'Full result text field.', true);
        $this->registerArgument('urlField', 'string', 'Result URL field.', false, '');
        $this->registerArgument('typeField', 'string', 'Source type field.', false, '');
        $this->registerArgument('pageIdField', 'string', 'Source page ID field.', false, '');
        $this->registerArgument('changedAtField', 'string', 'Source timestamp field.', false, '');
        $this->registerArgument('scoreField', 'string', 'Optional relevance score field.', false, '');
    }

    /**
     * Builds the JSON element consumed by the generic summary frontend.
     */
    public function render(): string
    {
        $request = $this->renderingContext->getRequest();
        if (!$request instanceof ServerRequestInterface) {
            return '';
        }

        $payload = $this->payloadBuilder->build(
            rows: is_array($this->arguments['results'] ?? null) ? $this->arguments['results'] : [],
            fields: [
                'id' => (string)$this->arguments['idField'],
                'title' => (string)$this->arguments['titleField'],
                'text' => (string)$this->arguments['textField'],
                'url' => (string)$this->arguments['urlField'],
                'type' => (string)$this->arguments['typeField'],
                'pageId' => (string)$this->arguments['pageIdField'],
                'changedAt' => (string)$this->arguments['changedAtField'],
                'score' => (string)$this->arguments['scoreField'],
            ],
            total: (int)$this->arguments['total'],
            page: (int)$this->arguments['page'],
            request: $request,
        );

        return $this->payloadBuilder->renderElement($payload);
    }
}
