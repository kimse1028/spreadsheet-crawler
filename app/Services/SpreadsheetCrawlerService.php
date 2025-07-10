<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class SpreadsheetCrawlerService
{
    private $sheetsService;
    private $crawlerService;
    private $console;

    public function __construct(
        GoogleSheetsService $sheetsService,
        PlaywrightCrawlerService $crawlerService
    ) {
        $this->sheetsService = $sheetsService;
        $this->crawlerService = $crawlerService;
    }

    public function setConsole($console): void
    {
        $this->console = $console;
        // 크롤러 서비스에도 전달
        $this->crawlerService->setConsole($console);
    }

    // 출력용 헬퍼 메서드
    private function output(string $message, string $type = 'info'): void
    {
        if ($this->console) {
            match($type) {
                'error' => $this->console->error($message),
                'warn' => $this->console->warn($message),
                'comment' => $this->console->comment($message),
                default => $this->console->line($message)
            };
        }
    }

    /**
     * 전체 크롤링 프로세스 실행
     */
    public function crawlAllSheets(): array
    {
        $results = [];
        $sheetNames = config('crawler.sheet_names', []);

        $this->output("📚 다중 시트 크롤링 시작 - 시트: " . implode(', ', $sheetNames));

        foreach ($sheetNames as $index => $sheetName) {
            $this->output("📄 시트 '{$sheetName}' 처리 시작 (" . ($index + 1) . "/" . count($sheetNames) . ")");

            try {
                $sheetResult = $this->crawlSingleSheet($sheetName);
                $results[$sheetName] = $sheetResult;

                $this->output("✅ 시트 '{$sheetName}' 크롤링 완료!", 'comment');

                // 시트 간 대기
                if ($index < count($sheetNames) - 1) {
                    $this->output("⏳ 다음 시트까지 3초 대기...", 'comment');
                    sleep(3);
                }

            } catch (Exception $e) {
                $this->output("❌ 시트 '{$sheetName}' 크롤링 실패: " . $e->getMessage(), 'error');

                $results[$sheetName] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        $this->output("🎉 모든 시트 크롤링 완료!");
        return $results;
    }

    /**
     * 단일 시트 크롤링
     */
    public function crawlSingleSheet(string $sheetName): array
    {
        $config = config('crawler.sheets.target_range');
        $results = [
            'success' => true,
            'processed' => 0,
            'updated' => 0,
            'failed' => 0,
            'details' => []
        ];

        $this->output("   🔍 행 {$config['start_row']}~{$config['end_row']} 처리 시작");

        for ($row = $config['start_row']; $row <= $config['end_row']; $row++) {
            $this->output("      ⚙️  행 {$row} 처리 중...");

            try {
                $rowResult = $this->processRow($sheetName, $row, $config);
                $results['details'][$row] = $rowResult;
                $results['processed']++;

                if ($rowResult['updated']) {
                    $results['updated']++;
                    if (isset($rowResult['damage'])) {
                        $unit = $rowResult['unit'] ?? '';
                        $this->output("      ✅ 행 {$row} 완료 - 딜량: {$rowResult['damage']}{$unit}");
                    } else {
                        $this->output("      ⚠️  행 {$row} 실패 - " . ($rowResult['reason'] ?? '알 수 없는 오류'));
                    }
                } else {
                    $this->output("      ⏭️  행 {$row} 건너뛰기 - " . ($rowResult['reason'] ?? 'URL 없음'));
                }

                // 행 간 대기
                sleep(1);

            } catch (Exception $e) {
                $this->output("      ❌ 행 {$row} 처리 실패: " . $e->getMessage(), 'error');

                $results['details'][$row] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'updated' => false
                ];
                $results['processed']++;
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * 단일 행 처리
     */
    private function processRow(string $sheetName, int $row, array $config): array
    {
        // URL과 역할 정보 읽기
        $urlCell = $config['url_column'] . $row;
        $roleCell = $config['role_column'] . $row;

        $urlData = $this->sheetsService->readRange($sheetName, $urlCell);
        $roleData = $this->sheetsService->readRange($sheetName, $roleCell);

        $url = isset($urlData[0][0]) ? trim($urlData[0][0]) : '';
        $role = isset($roleData[0][0]) ? trim($roleData[0][0]) : '';

        // URL이 비어있으면 건너뛰기
        if (empty($url)) {
            return [
                'success' => true,
                'skipped' => true,
                'reason' => 'URL 없음',
                'updated' => false
            ];
        }

        try {
            // 웹 크롤링
            $html = $this->crawlerService->crawlUrl($url);

            // 딜량 데이터 추출
            $damageValues = $this->crawlerService->extractDamageData($html);

            if (empty($damageValues)) {
                // 딜량 데이터 없음 - 수집실패 처리
                $this->handleFailedRow($sheetName, $row, $config);
                return [
                    'success' => false,
                    'reason' => '딜량 데이터 없음',
                    'updated' => true
                ];
            }

            // 역할에 따른 딜량 선택
            $result = $this->crawlerService->selectDamageByRole($damageValues, $role);

            // 스프레드시트에 결과 쓰기
            $this->updateSheetWithResult($sheetName, $row, $config, $result);

            return [
                'success' => true,
                'damage' => $result['damage'],
                'unit' => $result['unit'],
                'updated' => true
            ];

        } catch (Exception $e) {
            // 크롤링 실패 - 수집실패 처리
            $this->handleFailedRow($sheetName, $row, $config);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'updated' => true
            ];
        }
    }

    /**
     * 성공한 결과를 시트에 업데이트
     */
    private function updateSheetWithResult(string $sheetName, int $row, array $config, array $result): void
    {
        $updates = [
            $config['damage_column'] . $row => $result['damage']
        ];

        // 단위가 있으면 단위 컬럼에도 기록
        if (!empty($result['unit'])) {
            $updates[$config['unit_column'] . $row] = $result['unit'];
        }

        $this->sheetsService->writeCells($sheetName, $updates);
    }

    /**
     * 실패한 행 처리
     */
    private function handleFailedRow(string $sheetName, int $row, array $config): void
    {
        // 기존 데이터 확인
        $damageCell = $config['damage_column'] . $row;
        $existingData = $this->sheetsService->readRange($sheetName, $damageCell);
        $existingValue = isset($existingData[0][0]) ? $existingData[0][0] : '';

        // 기존에 숫자 데이터가 없으면 '수집실패' 기록
        if (empty($existingValue) || !is_numeric($existingValue)) {
            $this->sheetsService->writeCell($sheetName, $damageCell, '수집실패');
        }
    }
}
