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


namespace Madj2k\AiAssistantPremium\Search\Request;

/**
 * Class NestedParameterAccessor
 *
 * Reads and writes nested PSR-7 request parameter arrays.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final class NestedParameterAccessor
{
    /**
     * Reads a value from the supplied path.
     *
     * @param array<string,mixed> $parameters
     * @param array<int,string> $path
     */
    public function get(array $parameters, array $path): mixed
    {
        if ($path === []) {
            return null;
        }

        $value = $parameters;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Writes a value at the supplied path.
     *
     * @param array<string,mixed> $parameters
     * @param array<int,string> $path
     * @return array<string,mixed>
     */
    public function set(array $parameters, array $path, mixed $value): array
    {
        if ($path === []) {
            return $parameters;
        }

        $cursor =& $parameters;
        foreach ($path as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor =& $cursor[$segment];
        }
        $cursor = $value;
        unset($cursor);

        return $parameters;
    }

    /**
     * Removes a value at the supplied path and prunes empty parent arrays.
     *
     * @param array<string,mixed> $parameters
     * @param array<int,string> $path
     * @return array<string,mixed>
     */
    public function remove(array $parameters, array $path): array
    {
        if ($path === []) {
            return $parameters;
        }

        $this->removeAtPath($parameters, $path);
        return $parameters;
    }

    /**
     * @param array<string,mixed> $parameters
     * @param array<int,string> $path
     */
    private function removeAtPath(array &$parameters, array $path): void
    {
        $segment = array_shift($path);
        if ($segment === null || !array_key_exists($segment, $parameters)) {
            return;
        }

        if ($path === []) {
            unset($parameters[$segment]);
            return;
        }

        if (!is_array($parameters[$segment])) {
            return;
        }

        $this->removeAtPath($parameters[$segment], $path);
        if ($parameters[$segment] === []) {
            unset($parameters[$segment]);
        }
    }
}
