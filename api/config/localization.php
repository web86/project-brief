<?php

return [
    'fallback' => 'en',
    'languages' => [
        'ru' => ['intl' => 'ru-RU', 'group' => "\u{00A0}", 'decimal' => ',', 'approval_date' => 'DD.MM.YYYY'],
        'en' => ['intl' => 'en-GB', 'group' => ',', 'decimal' => '.', 'approval_date' => 'D MMM YYYY'],
        'tr' => ['intl' => 'tr-TR', 'group' => '.', 'decimal' => ',', 'approval_date' => 'D MMM YYYY'],
        'zh-CN' => ['intl' => 'zh-CN', 'storage' => 'zh', 'generated_date' => 'YYYY年M月D日', 'browser_aliases' => ['zh-Hans', 'zh-SG'], 'group' => ',', 'decimal' => '.', 'approval_date' => 'YYYY年M月D日'],
    ],
];
