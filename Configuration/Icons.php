<?php
declare(strict_types=1);
use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

$iconList = [];
foreach (
[
    'aiassistantpremium-plugin-kesearch' => 'Extension.svg',
    'aiassistantpremium-plugin-kesearchsummary' => 'Extension.svg',
] as $identifier => $path) {
    $iconList[$identifier] = [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:ai_assistant_premium/Resources/Public/Icons/' . $path,
    ];
}

return $iconList;
