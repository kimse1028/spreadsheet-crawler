<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CharacterRaidService;
use Exception;

class CheckRaidCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'raid:check {--sheet= : 특정 시트만 체크}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '캐릭터 나벨 레이드 클리어 상태 체크';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🏴‍☠️ 나벨 레이드 클리어 체크를 시작합니다...');
        $this->newLine();

        try {
            // CharacterRaidService 인스턴스 생성
            $raidService = app(CharacterRaidService::class);

            // Command 인스턴스를 서비스에 전달
            $raidService->setConsole($this);

            // 특정 시트 옵션 확인
            $targetSheet = $this->option('sheet');

            if ($targetSheet) {
                // 특정 시트만 체크
                $this->info("📄 시트 '{$targetSheet}' 레이드 체크를 시작합니다...");
                $result = $raidService->checkSingleSheetRaidStatus($targetSheet);
                $results = [$targetSheet => $result];
            } else {
                // 모든 시트 체크
                $results = $raidService->checkAllSheetsRaidStatus();
            }

            // 최종 결과 출력
            $this->displayFinalResults($results);

            $this->newLine();
            $this->info('✅ 레이드 체크가 완료되었습니다!');

        } catch (Exception $e) {
            $this->newLine();
            $this->error('❌ 레이드 체크 중 오류 발생: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function displayFinalResults(array $results)
    {
        $this->newLine();
        $this->info('📊 최종 결과 요약');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $totalChecked = 0;
        $totalCleared = 0;
        $totalFailed = 0;

        foreach ($results as $sheetName => $result) {
            if ($result['success']) {
                $checked = $result['checked'] ?? 0;
                $cleared = $result['cleared'] ?? 0;
                $failed = $result['failed'] ?? 0;

                $totalChecked += $checked;
                $totalCleared += $cleared;
                $totalFailed += $failed;

                $this->line("🏴‍☠️ {$sheetName}: 체크 {$checked}명 | 클리어 {$cleared}명 | 실패 {$failed}명");
                
                // 클리어율 계산
                if ($checked > 0) {
                    $clearRate = round(($cleared / $checked) * 100, 1);
                    $this->line("   └─ 클리어율: {$clearRate}%");
                }
            } else {
                $this->error("🏴‍☠️ {$sheetName}: ❌ {$result['error']}");
            }
        }

        if (count($results) > 1) {
            $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->info("🎯 전체 통계: 체크 {$totalChecked}명 | 클리어 {$totalCleared}명 | 실패 {$totalFailed}명");
            
            if ($totalChecked > 0) {
                $overallClearRate = round(($totalCleared / $totalChecked) * 100, 1);
                $this->info("📈 전체 클리어율: {$overallClearRate}%");
            }
        }
    }
}
