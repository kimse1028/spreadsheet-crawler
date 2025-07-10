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
        $this->info('🚀 스프레드시트 크롤링을 시작합니다...');
        $this->newLine();

        try {
            // SpreadsheetCrawlerService 인스턴스 생성
            $crawlerService = app(SpreadsheetCrawlerService::class);

            // Command 인스턴스를 서비스에 전달
            $crawlerService->setConsole($this);

            // 크롤링 실행
            $results = $crawlerService->crawlAllSheets();

            // 최종 결과 출력
            $this->displayFinalResults($results);

            $this->newLine();
            $this->info('✅ 크롤링이 완료되었습니다!');

        } catch (Exception $e) {
            $this->newLine();
            $this->error('❌ 크롤링 중 오류 발생: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function displayFinalResults(array $results)
    {
        $this->newLine();
        $this->info('📊 최종 결과 요약');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        foreach ($results as $sheetName => $result) {
            if ($result['success']) {
                $this->line("📄 {$sheetName}: 처리 {$result['processed']}개 | 성공 {$result['updated']}개 | 실패 {$result['failed']}개");
            } else {
                $this->error("📄 {$sheetName}: ❌ {$result['error']}");
            }
        }
    }
}
