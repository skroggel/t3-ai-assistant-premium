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

use Madj2k\AiAssistantPremium\Search\Middleware\SearchQueryMiddleware;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Class OriginalQueryViewHelper
 *
 * Returns the user-entered query retained by the search middleware.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
final class OriginalQueryViewHelper extends AbstractViewHelper
{
    /**
     * Registers the value used for requests without AI search metadata.
     */
    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('fallback', 'string', 'Query supplied by the native search engine.', false, '');
    }

    /**
     * Resolves the original query from generic metadata or returns the fallback.
     */
    public function render(): string
    {
        $metadata = $this->renderingContext->getRequest()
            ->getQueryParams()[SearchQueryMiddleware::CONTROL_PARAMETER] ?? [];

        $originalQuery = is_array($metadata)
            ? trim((string)($metadata['originalQuery'] ?? ''))
            : '';

        return $originalQuery !== ''
            ? $originalQuery
            : trim((string)($this->arguments['fallback'] ?? ''));
    }
}
