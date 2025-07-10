<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 매일 자정에 크롤링 실행
Schedule::command('crawl:sheets')
    ->daily()                    // 매일 실행
    ->at('00:00')               // 자정에 실행
    ->timezone('Asia/Seoul')  // 한국 시간대 명시
    ->runInBackground()         // 백그라운드에서 실행
    ->withoutOverlapping()      // 중복 실행 방지
    ->onOneServer()             // 여러 서버 있어도 한 곳에서만 실행
    ->emailOutputOnFailure('zzedai@gmail.com'); // 실패시 이메일 (선택사항)
