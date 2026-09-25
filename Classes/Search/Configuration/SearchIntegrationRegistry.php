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
 * Class SearchIntegrationRegistry
 *
 * Resolves declarative request mappings without loading a search extension.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class SearchIntegrationRegistry
{
    /**
     * @var array<string,SearchIntegration>
     */
    private array $integrations;

    public function __construct()
    {
        $this->integrations = [
            'ke_search' => new SearchIntegration(
                identifier: 'ke_search',
                queryParameterPath: ['tx_kesearch_pi1', 'sword'],
                pageParameterPath: ['tx_kesearch_pi1', 'page'],
            ),
            'solr' => new SearchIntegration(
                identifier: 'solr',
                queryParameterPath: ['tx_solr', 'q'],
                pageParameterPath: ['tx_solr', 'page'],
            ),
        ];
    }

    /**
     * Returns an integration or null for an unsupported identifier.
     */
    public function get(string $identifier): ?SearchIntegration
    {
        return $this->integrations[trim($identifier)] ?? null;
    }
}
