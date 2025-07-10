<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SpreadsheetCrawlerService;
use Exception;

class CrawlSheetsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crawl:sheets';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '스프레드시트 크롤링 실행';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('스프레드시트 크롤링을 시작합니다...');

        try {
            // SpreadsheetCrawlerService 인스턴스 생성
            $crawlerService = app(SpreadsheetCrawlerService::class);

            // 크롤링 실행
            $results = $crawlerService->crawlAllSheets();

            // 결과 출력
            $this->displayResults($results);

            $this->info('크롤링이 완료되었습니다!');

        } catch (Exception $e) {
            $this->error('크롤링 중 오류 발생: ' . $e->getMessage());
            return 1; // 에러 코드 반환
        }

        return 0; // 성공 코드 반환
    }

    private function displayResults(array $results)
    {
        foreach ($results as $sheetName => $result) {
            $this->line(''); // 빈 줄
            $this->info("=== {$sheetName} 시트 결과 ===");

            if ($result['success']) {
                $this->info("처리된 행: {$result['processed']}개");
                $this->info("업데이트: {$result['updated']}개");
                $this->info("실패: {$result['failed']}개");
            } else {
                $this->error("시트 처리 실패: {$result['error']}");
            }
        }
    }
}
