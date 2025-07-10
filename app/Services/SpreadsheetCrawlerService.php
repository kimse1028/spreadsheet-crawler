<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class SpreadsheetCrawlerService
{
    private $sheetsService;
    private $crawlerService;

    public function __construct(
        GoogleSheetsService $sheetsService,
        PlaywrightCrawlerService $crawlerService
    ) {
        $this->sheetsService = $sheetsService;
        $this->crawlerService = $crawlerService;
    }

    /**
     * 전체 크롤링 프로세스 실행
     */
    public function crawlAllSheets(): array
    {
        $results = [];
        $sheetNames = config('crawler.sheet_names', []);

        Log::info("다중 시트 크롤링 시작", ['sheets' => $sheetNames]);

        foreach ($sheetNames as $index => $sheetName) {
            Log::info("시트 처리 시작", [
                'sheet' => $sheetName,
                'index' => $index + 1,
                'total' => count($sheetNames)
            ]);

            try {
                $sheetResult = $this->crawlSingleSheet($sheetName);
                $results[$sheetName] = $sheetResult;
                
                Log::info("시트 크롤링 완료", ['sheet' => $sheetName]);

                // 시트 간 대기
                if ($index < count($sheetNames) - 1) {
                    sleep(3);
                }
                
            } catch (Exception $e) {
                Log::error("시트 크롤링 실패", [
                    'sheet' => $sheetName,
                    'error' => $e->getMessage()
                ]);
                
                $results[$sheetName] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        Log::info("모든 시트 크롤링 완료", ['results' => $results]);
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

        for ($row = $config['start_row']; $row <= $config['end_row']; $row++) {
            Log::info("행 처리 시작", ['sheet' => $sheetName, 'row' => $row]);

            try {
                $rowResult = $this->processRow($sheetName, $row, $config);
                $results['details'][$row] = $rowResult;
                $results['processed']++;

                if ($rowResult['updated']) {
                    $results['updated']++;
                } else {
                    $results['failed']++;
                }

                // 행 간 대기
                sleep(1);

            } catch (Exception $e) {
                Log::error("행 처리 실패", [
                    'sheet' => $sheetName,
                    'row' => $row,
                    'error' => $e->getMessage()
                ]);

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

        Log::info("행 데이터 읽기 완료", [
            'sheet' => $sheetName,
            'row' => $row,
            'url' => $url,
            'role' => $role
        ]);

        // URL이 비어있으면 건너뛰기
        if (empty($url)) {
            Log::info("URL 없음 - 건너뛰기", ['sheet' => $sheetName, 'row' => $row]);
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

        Log::info("시트 업데이트 완료", [
            'sheet' => $sheetName,
            'row' => $row,
            'updates' => $updates
        ]);
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
            
            Log::info("수집실패 기록", [
                'sheet' => $sheetName,
                'row' => $row,
                'cell' => $damageCell
            ]);
        } else {
            Log::info("기존 데이터 유지", [
                'sheet' => $sheetName,
                'row' => $row,
                'existing_value' => $existingValue
            ]);
        }
    }
}
