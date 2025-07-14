<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class CharacterRaidService
{
    private $neopleApi;
    private $sheetsService;
    private $console;

    public function __construct(
        NeopleApiService $neopleApi,
        GoogleSheetsService $sheetsService
    ) {
        $this->neopleApi = $neopleApi;
        $this->sheetsService = $sheetsService;
    }

    public function setConsole($console): void
    {
        $this->console = $console;
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
     * 모든 시트의 레이드 상태 체크
     */
    public function checkAllSheetsRaidStatus(): array
    {
        $results = [];
        $sheetNames = config('crawler.sheet_names', []);

        $this->output("🏴‍☠️ 나벨 레이드 클리어 체크 시작 - 시트: " . implode(', ', $sheetNames));

        foreach ($sheetNames as $index => $sheetName) {
            $this->output("📄 시트 '{$sheetName}' 레이드 체크 시작 (" . ($index + 1) . "/" . count($sheetNames) . ")");

            try {
                $sheetResult = $this->checkSingleSheetRaidStatus($sheetName);
                $results[$sheetName] = $sheetResult;

                $checkedCount = $sheetResult['checked'] ?? 0;
                $clearedCount = $sheetResult['cleared'] ?? 0;

                $this->output("✅ 시트 '{$sheetName}' 완료 - 체크: {$checkedCount}명, 클리어: {$clearedCount}명", 'comment');

                // 시트 간 대기
                if ($index < count($sheetNames) - 1) {
                    $this->output("⏳ 다음 시트까지 2초 대기...", 'comment');
                    sleep(2);
                }

            } catch (Exception $e) {
                $this->output("❌ 시트 '{$sheetName}' 레이드 체크 실패: " . $e->getMessage(), 'error');

                $results[$sheetName] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        $this->output("🎉 모든 시트 레이드 체크 완료!");
        return $results;
    }

    /**
     * 단일 시트의 레이드 상태 체크
     */
    public function checkSingleSheetRaidStatus(string $sheetName): array
    {
        $results = [
            'success' => true,
            'checked' => 0,
            'cleared' => 0,
            'failed' => 0,
            'details' => []
        ];

        try {
            // 1. 서버명 가져오기
            $serverId = $this->getServerFromSheet($sheetName);
            if (!$serverId) {
                throw new Exception("서버명을 찾을 수 없습니다");
            }

            $this->output("   🖥️  서버: {$serverId}");

            // 2. 캐릭터 목록 가져오기
            $characters = $this->getCharactersFromSheet($sheetName);
            if (empty($characters)) {
                $this->output("   ⚠️  캐릭터를 찾을 수 없습니다", 'warn');
                return $results;
            }

            $this->output("   👥 캐릭터 " . count($characters) . "명 발견");

            // 3. 각 캐릭터 레이드 상태 체크
            foreach ($characters as $rowIndex => $characterName) {
                if (empty(trim($characterName))) {
                    continue;
                }

                $this->output("      🔍 {$characterName} 체크 중...");

                try {
                    $raidStatus = $this->neopleApi->checkAllRaids($serverId, $characterName);

                    $results['details'][$rowIndex] = [
                        'character' => $characterName,
                        'raidStatus' => $raidStatus,
                        'success' => true
                    ];

                    $results['checked']++;

                    // 클리어 상태 출력 및 카운트
                    $clearMessages = [];
                    if ($raidStatus['venus']) {
                        $clearMessages[] = '베누스';
                    }
                    if ($raidStatus['nabal']) {
                        $clearMessages[] = '나벨';
                    }

                    if (!empty($clearMessages)) {
                        $results['cleared']++;
                        $this->output("      ✅ {$characterName} - " . implode(', ', $clearMessages) . " 클리어!");
                    } else {
                        $this->output("      ❌ {$characterName} - 미클리어");
                    }

                    // 항상 컨텐츠 현황 시트 업데이트
                    $this->updateAllRaidStatus($characterName, $raidStatus);

                    // 캐릭터 간 대기 (API 제한 고려)
                    sleep(1);

                } catch (Exception $e) {
                    $this->output("      ⚠️  {$characterName} 체크 실패: " . $e->getMessage(), 'warn');

                    $results['details'][$rowIndex] = [
                        'character' => $characterName,
                        'cleared' => false,
                        'success' => false,
                        'error' => $e->getMessage()
                    ];

                    $results['failed']++;
                }
            }

        } catch (Exception $e) {
            $results['success'] = false;
            $results['error'] = $e->getMessage();
            throw $e;
        }

        return $results;
    }

    /**
     * B3에서 서버명 읽고 영어로 변환
     */
    private function getServerFromSheet(string $sheetName): ?string
    {
        try {
            $serverCell = config('crawler.character.server_cell', 'B3');
            $serverData = $this->sheetsService->readRange($sheetName, $serverCell);

            $serverNameKor = isset($serverData[0][0]) ? trim($serverData[0][0]) : '';

            if (empty($serverNameKor)) {
                return null;
            }

            // 한글 서버명을 영어로 변환
            $serverMapping = config('crawler.server_mapping', []);
            return $serverMapping[$serverNameKor] ?? null;

        } catch (Exception $e) {
            Log::error('서버명 읽기 실패', [
                'sheet' => $sheetName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * B7부터 캐릭터명들 읽기
     */
    private function getCharactersFromSheet(string $sheetName): array
    {
        try {
            $startRow = config('crawler.character.name_start_row', 7);
            $endRow = config('crawler.sheets.target_range.end_row', 30);
            $nameColumn = config('crawler.character.name_column', 'B');

            $range = "{$nameColumn}{$startRow}:{$nameColumn}{$endRow}";
            $charactersData = $this->sheetsService->readRange($sheetName, $range);

            $characters = [];
            foreach ($charactersData as $rowIndex => $row) {
                $characterName = isset($row[0]) ? trim($row[0]) : '';
                if (!empty($characterName)) {
                    $characters[$startRow + $rowIndex] = $characterName;
                }
            }

            return $characters;

        } catch (Exception $e) {
            Log::error('캐릭터명 읽기 실패', [
                'sheet' => $sheetName,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * 모든 레이드 상태를 컨텐츠 현황 시트에 업데이트
     * 클리어 기록이 있으면 체크, 없으면 아무것도 체크 안함
     */
    private function updateAllRaidStatus(string $characterName, array $raidStatus): void
    {
        try {
            $contentSheetName = '컨텐츠 현황';

            // 1. 전체 시트에서 캐릭터명 찾기 (A~Z 범위)
            $allData = $this->sheetsService->readRange($contentSheetName, 'A1:Z100');

            $targetRow = null;
            $characterColumn = null;

            foreach ($allData as $rowIndex => $row) {
                foreach ($row as $colIndex => $cellValue) {
                    if (isset($cellValue) && trim($cellValue) === $characterName) {
                        $targetRow = $rowIndex + 1; // 1-based index
                        $characterColumn = $colIndex; // 0-based index
                        break 2; // 두 중첩 루프 모두 탈출
                    }
                }
            }

            if (!$targetRow || $characterColumn === null) {
                Log::warning('컨텐츠 현황 시트에서 캐릭터를 찾을 수 없음', [
                    'character' => $characterName
                ]);
                return;
            }

            // 2. 해당 행에서 체크박스들 찾기 (캐릭터명 오른쪽부터)
            $rowData = $allData[$targetRow - 1];
            $checkboxCount = 0;
            $checkboxPositions = [];

            // 캐릭터명 다음 칸부터 스캔
            for ($col = $characterColumn + 1; $col < count($rowData); $col++) {
                $cellValue = $rowData[$col] ?? '';

                // 체크박스 판별 (TRUE/FALSE/빈값이면 체크박스로 간주)
                if ($cellValue === 'TRUE' || $cellValue === 'FALSE' || $cellValue === '') {
                    $checkboxCount++;
                    $checkboxPositions[$checkboxCount] = $col;

                    // 1번째, 2번째, 3번째 체크박스만 필요
                    if ($checkboxCount >= 3) {
                        break;
                    }
                }
            }

            // 3. 클리어 기록이 있는 레이드만 체크박스 업데이트
            $updates = [];

            // 상급던전 (1번째 체크박스) - 제거됨, 업데이트 안함

            // 베누스 (2번째 체크박스) - 클리어했을 때만 TRUE 설정
            if (isset($checkboxPositions[2]) && $raidStatus['venus']) {
                $venusColumn = $this->numberToColumnLetter($checkboxPositions[2] + 1);
                $venusCell = $venusColumn . $targetRow;
                $updates[$venusCell] = true;
            }

            // 나벨 (3번째 체크박스) - 클리어했을 때만 TRUE 설정
            if (isset($checkboxPositions[3]) && $raidStatus['nabal']) {
                $nabalColumn = $this->numberToColumnLetter($checkboxPositions[3] + 1);
                $nabalCell = $nabalColumn . $targetRow;
                $updates[$nabalCell] = true;
            }

            // 한 번에 모든 셀 업데이트 (클리어된 것만 업데이트됨)
            if (!empty($updates)) {
                foreach ($updates as $cell => $value) {
                    $this->sheetsService->writeCell($contentSheetName, $cell, $value);
                }

                Log::info('레이드 체크박스 업데이트 (클리어된 항목만)', [
                    'character' => $characterName,
                    'updates' => $updates,
                    'raidStatus' => $raidStatus
                ]);
            } else {
                Log::info('레이드 체크박스 업데이트 없음 (클리어 기록 없음)', [
                    'character' => $characterName,
                    'raidStatus' => $raidStatus
                ]);
            }

        } catch (Exception $e) {
            Log::error('컨텐츠 현황 시트 업데이트 실패', [
                'character' => $characterName,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 컨텐츠 현황 시트 업데이트 (기존 호환성 유지)
     */
    private function updateContentSheet(string $characterName, bool $cleared): void
    {
        $raidStatus = [
            'venus' => false,
            'nabal' => $cleared
        ];
        $this->updateAllRaidStatus($characterName, $raidStatus);
    }

    private function numberToColumnLetter(int $num): string
    {
        $letter = '';
        while ($num > 0) {
            $num--;
            $letter = chr(65 + ($num % 26)) . $letter;
            $num = intval($num / 26);
        }
        return $letter;
    }
}
