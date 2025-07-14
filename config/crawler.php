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
    ],

    /*
    |--------------------------------------------------------------------------
    | Server Mapping Configuration
    |--------------------------------------------------------------------------
    |
    | 한글 서버명을 API 서버 ID로 매핑
    |
    */
    'server_mapping' => [
        '안톤' => 'anton',
        '바칼' => 'bakal',
        '카인' => 'cain',
        '카시야스' => 'casillas',
        '디레지에' => 'diregie',
        '힐더' => 'hilder',
        '프레이' => 'prey',
        '시로코' => 'siroco'
    ],

    /*
    |--------------------------------------------------------------------------
    | Neople API Configuration
    |--------------------------------------------------------------------------
    |
    | 네오플 던전앤파이터 API 설정
    |
    */
    'neople_api' => [
        'base_url' => env('DFO_API_BASE_URL', 'https://api.neople.co.kr'),
        'api_key' => env('DFO_API_KEY'),
        'version' => env('DFO_API_VERSION', 'df'),
        'endpoints' => [
            'character_search' => '/df/servers/{serverId}/characters',
            'character_timeline' => '/df/servers/{serverId}/characters/{characterId}/timeline'
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Character Configuration
    |--------------------------------------------------------------------------
    |
    | 캐릭터 관련 설정
    |
    */
    'character' => [
        'server_cell' => 'B3',
        'name_start_row' => 7,
        'name_column' => 'B'
    ]
];
