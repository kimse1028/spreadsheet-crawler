<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Crawler Configuration
    |--------------------------------------------------------------------------
    |
    | 웹 크롤링 관련 설정값들
    |
    */

    'retries' => env('CRAWLER_RETRIES', 3),
    'timeout' => env('CRAWLER_TIMEOUT', 60000),
    'delay' => env('CRAWLER_DELAY', 1000),
    'min_html_size' => env('CRAWLER_MIN_HTML_SIZE', 760000),

    'sheets' => [
        'target_range' => [
            'start_row' => 7,
            'end_row' => 30,
            'url_column' => 'J',
            'role_column' => 'F',
            'damage_column' => 'D',
            'unit_column' => 'E'
        ]
    ],

    'sheet_names' => [
        '이승빈',
        '김세훈',
        '남용준',
        '신희도',
        '이윤찬',
        '김도연'
        // 필요한 시트 이름들 추가
    ]
];
