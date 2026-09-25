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


namespace Madj2k\AiAssistantPremium\Search\Configuration;

/**
 * Class SearchIntegration
 *
 * Describes how one search integration stores its request parameters.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class SearchIntegration
{
    /**
     * @param string $identifier Stable integration identifier.
     * @param array<int,string> $queryParameterPath Nested path of the engine query parameter.
     * @param array<int,string> $pageParameterPath Nested path of the engine page parameter.
     */
    public function __construct(
        public string $identifier,
        public array $queryParameterPath,
        public array $pageParameterPath = [],
    ) {
    }

    /**
     * Returns the root request namespace owned by the search engine.
     */
    public function getRootParameter(): string
    {
        return (string)($this->queryParameterPath[0] ?? '');
    }
}
