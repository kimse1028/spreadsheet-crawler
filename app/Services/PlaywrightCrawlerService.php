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

    public function __construct()
    {
        $this->retries = config('crawler.retries', 3);
        $this->timeout = config('crawler.timeout', 60000);
        $this->delay = config('crawler.delay', 1000);
        $this->minHtmlSize = config('crawler.min_html_size', 760000);
    }

    /**
     * URL에서 HTML 크롤링
     */
    public function crawlUrl(string $url): string
    {
        Log::info("크롤링 시작", ['url' => $url]);

        for ($attempt = 1; $attempt <= $this->retries; $attempt++) {
            try {
                Log::info("크롤링 시도 {$attempt}/{$this->retries}", ['url' => $url]);

                $html = $this->fetchHtmlWithPlaywright($url);
                
                if (strlen($html) >= $this->minHtmlSize) {
                    Log::info("HTML 크기 충분", [
                        'url' => $url,
                        'size' => strlen($html),
                        'attempt' => $attempt
                    ]);
                    return $html;
                }

                Log::warning("HTML 크기 부족", [
                    'url' => $url,
                    'size' => strlen($html),
                    'min_size' => $this->minHtmlSize,
                    'attempt' => $attempt
                ]);

                if ($attempt < $this->retries) {
                    $waitTime = $this->delay * pow(1.5, $attempt - 1);
                    Log::info("재시도 대기", ['wait_ms' => $waitTime]);
                    usleep($waitTime * 1000);
                }

            } catch (Exception $e) {
                Log::error("크롤링 오류", [
                    'url' => $url,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);

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
        Log::info("딜량 데이터 추출 시작", ['html_size' => strlen($html)]);

        // 7자리 이상 숫자(콤마 포함) 패턴 매칭
        preg_match_all('/>([0-9,]{7,})</', $html, $matches);

        if (empty($matches[1])) {
            Log::warning("딜량 패턴 매치 없음");
            return [];
        }

        $damageValues = [];
        foreach ($matches[1] as $match) {
            $cleanNumber = str_replace(',', '', $match);
            if (is_numeric($cleanNumber)) {
                $damageValues[] = (int)$cleanNumber;
            }
        }

        Log::info("딜량 데이터 추출 완료", [
            'matches_count' => count($matches[1]),
            'valid_numbers' => count($damageValues),
            'values' => array_slice($damageValues, 0, 3) // 처음 3개만 로그
        ]);

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
        Log::info("역할별 딜량 선택", [
            'role' => $role,
            'damage_count' => count($damageValues),
            'values' => $damageValues
        ]);

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

        Log::info("딜량 선택 완료", [
            'role' => $role,
            'original_damage' => $selectedDamage,
            'final_damage' => $finalDamage,
            'unit' => $unit
        ]);

        return [
            'damage' => $finalDamage,
            'unit' => $unit
        ];
    }
}
