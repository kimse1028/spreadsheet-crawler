<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class PlaywrightCrawlerService
{
    private $retries;
    private $timeout;
    private $delay;
    private $minHtmlSize;
    private $console;

    public function __construct()
    {
        $this->retries = config('crawler.retries', 3);
        $this->timeout = config('crawler.timeout', 60000);
        $this->delay = config('crawler.delay', 1000);
        $this->minHtmlSize = config('crawler.min_html_size', 760000);
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
     * URL에서 HTML 크롤링
     */
    public function crawlUrl(string $url): string
    {
        $this->output("         🌐 URL 크롤링 시작: " . substr($url, 0, 50) . "...");

        for ($attempt = 1; $attempt <= $this->retries; $attempt++) {
            try {
                $this->output("         🔄 시도 {$attempt}/{$this->retries}");

                $html = $this->fetchHtmlWithPlaywright($url);

                if (strlen($html) >= $this->minHtmlSize) {
                    $this->output("         ✅ HTML 크기 충분: " . number_format(strlen($html)) . "자");
                    return $html;
                }

                $this->output("         ⚠️  HTML 크기 부족: " . number_format(strlen($html)) . "자 (최소: " . number_format($this->minHtmlSize) . "자)", 'warn');

                if ($attempt < $this->retries) {
                    $waitTime = $this->delay * pow(1.5, $attempt - 1);
                    $this->output("         ⏳ {$waitTime}ms 대기 후 재시도...");
                    usleep($waitTime * 1000);
                }

            } catch (Exception $e) {
                $this->output("         ❌ 크롤링 오류 (시도 {$attempt}): " . $e->getMessage(), 'error');

                if ($attempt < $this->retries) {
                    $waitTime = $this->delay * pow(1.5, $attempt - 1);
                    usleep($waitTime * 1000);
                }
            }
        }

        throw new Exception("모든 크롤링 시도 실패: {$url}");
    }

    /**
     * Playwright를 사용한 HTML 가져오기
     */
    private function fetchHtmlWithPlaywright(string $url): string
    {
        $scriptPath = base_path('scripts/crawler.js');

        // Playwright 스크립트 실행
        $process = new Process([
            'node',
            $scriptPath,
            $url,
            $this->timeout
        ]);

        $process->setTimeout($this->timeout / 1000 + 10); // 추가 여유시간
        $process->run();

        if (!$process->isSuccessful()) {
            throw new Exception("Playwright 실행 실패: " . $process->getErrorOutput());
        }

        $output = $process->getOutput();
        if (empty($output)) {
            throw new Exception("빈 HTML 응답");
        }

        return $output;
    }

    /**
     * HTML에서 딜량 데이터 추출
     */
    public function extractDamageData(string $html): array
    {
        $this->output("         🔍 딜량 데이터 추출 시작 (HTML: " . number_format(strlen($html)) . "자)");

        // 7자리 이상 숫자(콤마 포함) 패턴 매칭
        preg_match_all('/>([0-9,]{7,})</', $html, $matches);

        if (empty($matches[1])) {
            $this->output("         ⚠️  딜량 패턴 매치 없음", 'warn');
            return [];
        }

        $damageValues = [];
        foreach ($matches[1] as $match) {
            $cleanNumber = str_replace(',', '', $match);
            if (is_numeric($cleanNumber)) {
                $damageValues[] = (int)$cleanNumber;
            }
        }

        $this->output("         ✅ 딜량 데이터 추출 완료: " . count($damageValues) . "개 발견");

        return $damageValues;
    }

    /**
     * 역할에 따른 딜량 선택
     */
    public function selectDamageByRole(array $damageValues, string $role): array
    {
        if (empty($damageValues)) {
            return ['damage' => null, 'unit' => ''];
        }

        $role = trim($role);
        $this->output("         🎯 역할별 딜량 선택: {$role} (" . count($damageValues) . "개 값)");

        if (count($damageValues) >= 2) {
            // 두 개 이상의 딜량이 있는 경우
            if ($role === '딜러') {
                $selectedDamage = max($damageValues[0], $damageValues[1]);
                $unit = '억';
                $finalDamage = floor($selectedDamage / 100000000);
            } elseif ($role === '버퍼') {
                $selectedDamage = min($damageValues[0], $damageValues[1]);
                $unit = '만';
                $finalDamage = floor($selectedDamage / 10000);
            } else {
                $selectedDamage = $damageValues[0];
                $unit = '';
                $finalDamage = $selectedDamage;
            }
        } else {
            // 딜량이 하나만 있는 경우
            $selectedDamage = $damageValues[0];
            if ($role === '딜러') {
                $unit = '억';
                $finalDamage = floor($selectedDamage / 100000000);
            } elseif ($role === '버퍼') {
                $unit = '만';
                $finalDamage = floor($selectedDamage / 10000);
            } else {
                $unit = '';
                $finalDamage = $selectedDamage;
            }
        }

        $this->output("         💯 딜량 선택 완료: " . number_format($selectedDamage) . " → {$finalDamage}{$unit}");

        return [
            'damage' => $finalDamage,
            'unit' => $unit
        ];
    }
}
