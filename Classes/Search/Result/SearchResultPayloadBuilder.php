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


namespace Madj2k\AiAssistantPremium\Search\Result;

use Madj2k\AiAssistantPremium\Search\Middleware\SearchQueryMiddleware;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Class SearchResultPayloadBuilder
 *
 * Maps presentation-ready search rows to the engine-neutral browser payload.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final class SearchResultPayloadBuilder
{
    private const MAX_TITLE_LENGTH = 500;
    private const MAX_TEXT_LENGTH = 6000;
    private const MAX_URL_LENGTH = 2048;

    /**
     * @param array<int,mixed> $rows
     * @param array<string,string> $fields
     * @return array<string,mixed>
     */
    public function build(
        array $rows,
        array $fields,
        int $total,
        int $page,
        ServerRequestInterface $request,
    ): array {
        $control = $request->getQueryParams()[SearchQueryMiddleware::CONTROL_PARAMETER] ?? [];
        if (!is_array($control) || !(bool)($control['processed'] ?? false)) {
            return [];
        }

        $results = [];
        foreach ($rows as $position => $row) {
            if (!is_array($row)) {
                continue;
            }

            $sourceType = $this->normalizeText($this->read($row, $fields['type'] ?? ''), 100);
            $sourceUid = $this->normalizeText($this->read($row, $fields['id'] ?? ''), 255);
            $identifier = $sourceUid !== ''
                ? trim((string)($control['integration'] ?? 'search')) . ':' . ($sourceType !== '' ? $sourceType . ':' : '') . $sourceUid
                : trim((string)($control['integration'] ?? 'search')) . ':result:' . ($position + 1);
            $scoreValue = $this->read($row, $fields['score'] ?? '');
            $score = is_numeric($scoreValue)
                ? (float)$scoreValue
                : max(0.0, 1.0 - (((int)$position) * 0.01));

            $results[] = [
                'id' => $identifier,
                'score' => $score,
                'text' => $this->normalizeText($this->read($row, $fields['text'] ?? ''), self::MAX_TEXT_LENGTH),
                'source_type' => $sourceType,
                'source_identifier' => $identifier,
                'source_uid' => $sourceUid,
                'title' => $this->normalizeText($this->read($row, $fields['title'] ?? ''), self::MAX_TITLE_LENGTH),
                'url' => $this->normalizeText($this->read($row, $fields['url'] ?? ''), self::MAX_URL_LENGTH),
                'page_id' => (int)$this->read($row, $fields['pageId'] ?? ''),
                'changed_at' => (int)$this->read($row, $fields['changedAt'] ?? ''),
                'position' => (int)$position + 1,
            ];
        }

        return [
            'version' => 1,
            'integration' => trim((string)($control['integration'] ?? '')),
            'chatIdentifier' => trim((string)($control['chatIdentifier'] ?? '')),
            'originalQuery' => trim((string)($control['originalQuery'] ?? '')),
            'effectiveQuery' => trim((string)($control['effectiveQuery'] ?? '')),
            'page' => max(1, $page),
            'total' => max(0, $total),
            'results' => $results,
        ];
    }

    /**
     * Encodes a payload as a safe application/json script element.
     *
     * @param array<string,mixed> $payload
     */
    public function renderElement(array $payload): string
    {
        if ($payload === []) {
            return '';
        }

        try {
            $json = json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                | JSON_HEX_APOS
                | JSON_HEX_AMP
                | JSON_HEX_QUOT
                | JSON_HEX_TAG
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException) {
            return '';
        }

        return sprintf(
            '<script type="application/json" class="js-aiassistant-search-results" data-chat-identifier="%s">%s</script>',
            htmlspecialchars((string)($payload['chatIdentifier'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $json,
        );
    }

    /**
     * Reads one scalar field from a result row.
     *
     * @param array<string,mixed> $row
     */
    private function read(array $row, string $field): string|int|float|bool|null
    {
        if ($field === '' || !array_key_exists($field, $row) || !is_scalar($row[$field])) {
            return null;
        }

        return $row[$field];
    }

    /**
     * Converts HTML fragments and encoded entities to bounded plain text.
     */
    private function normalizeText(string|int|float|bool|null $value, int $maximumLength): string
    {
        $value = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim((string)preg_replace('/\s+/u', ' ', $value));

        return mb_substr($value, 0, $maximumLength);
    }
}
