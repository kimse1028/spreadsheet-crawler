<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class NeopleApiService
{
    private $baseUrl;
    private $apiKey;
    private $version;

    public function __construct()
    {
        $this->baseUrl = config('crawler.neople_api.base_url');
        $this->apiKey = config('crawler.neople_api.api_key');
        $this->version = config('crawler.neople_api.version');
    }

    /**
     * 캐릭터 검색
     */
    public function searchCharacter(string $serverId, string $characterName): ?array
    {
        try {
            $url = "{$this->baseUrl}/{$this->version}/servers/{$serverId}/characters";

            // API 호출 전 로그 추가
            Log::info('캐릭터 검색 시작', [
                'url' => $url,
                'server' => $serverId,
                'character' => $characterName,
                'encoded_character' => urlencode($characterName)
            ]);

            $encodedCharacterName = urlencode($characterName);
            $fullUrl = "{$url}?characterName={$encodedCharacterName}";

            $response = Http::withHeaders([
                'apikey' => $this->apiKey
            ])->get($fullUrl);

            // API 응답 로그 추가
            Log::info('API 응답', [
                'status' => $response->status(),
                'response_body' => $response->body()
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['rows'] ?? null;
            }

            Log::error('캐릭터 검색 실패', [
                'server' => $serverId,
                'character' => $characterName,
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return null;

        } catch (Exception $e) {
            Log::error('캐릭터 검색 에러', [
                'server' => $serverId,
                'character' => $characterName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 캐릭터 타임라인 조회
     */
    public function getCharacterTimeline(string $serverId, string $characterId): ?array
    {
        try {
            $url = "{$this->baseUrl}/{$this->version}/servers/{$serverId}/characters/{$characterId}/timeline";

            $response = Http::withHeaders([
                'apikey' => $this->apiKey
            ])->get($url);

            if ($response->successful()) {
                $data = $response->json();
                return $data['timeline']['rows'] ?? null;
            }

            Log::error('타임라인 조회 실패', [
                'server' => $serverId,
                'characterId' => $characterId,
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return null;

        } catch (Exception $e) {
            Log::error('타임라인 조회 에러', [
                'server' => $serverId,
                'characterId' => $characterId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 모든 레이드 클리어 여부 확인 (보상 기록도 포함)
     */
    public function checkAllRaids(string $serverId, string $characterName): array
    {
        try {
            // 1. 캐릭터 검색
            $characters = $this->searchCharacter($serverId, $characterName);

            if (empty($characters)) {
                Log::info('캐릭터를 찾을 수 없음', [
                    'server' => $serverId,
                    'character' => $characterName
                ]);
                return [
                    'venus' => false,
                    'nabal' => false
                ];
            }

            // 2. 첫 번째 매칭 캐릭터 사용
            $character = $characters[0];
            $characterId = $character['characterId'];

            // 3. 타임라인 조회
            $timeline = $this->getCharacterTimeline($serverId, $characterId);

            if (empty($timeline)) {
                return [
                    'venus' => false,
                    'nabal' => false
                ];
            }

            // 타임라인 데이터 로깅 추가
            Log::info('타임라인 데이터', [
                'character' => $characterName,
                'timeline_count' => count($timeline),
                'recent_activities' => array_slice($timeline, 0, 5) // 최근 5개만
            ]);

            // 4. 레이드 클리어 확인 (최근 7일)
            $weekAgo = now()->subDays(7);
            $raidStatus = [
                'venus' => false,
                'nabal' => false
            ];

            foreach ($timeline as $activity) {
                $activityDate = \Carbon\Carbon::parse($activity['date']);

                if ($activityDate->lt($weekAgo)) {
                    continue;
                }

                // 베누스 레이드 체크 (직접 클리어만)
                if ($this->isVenusRaidActivity($activity)) {
                    $raidStatus['venus'] = true;
                    Log::info('베누스 레이드 클리어 확인', [
                        'server' => $serverId,
                        'character' => $characterName,
                        'date' => $activity['date'],
                        'type' => '직접클리어',
                        'data' => $activity['data'] ?? []
                    ]);
                }

                // 나벨 레이드 체크 (직접 클리어 + 보상)
                if ($this->isNabalRaidActivity($activity)) {
                    $raidStatus['nabal'] = true;
                    Log::info('나벨 레이드 클리어 확인', [
                        'server' => $serverId,
                        'character' => $characterName,
                        'date' => $activity['date'],
                        'type' => $activity['code'] === 201 ? '직접클리어' : '보상인정',
                        'data' => $activity['data'] ?? []
                    ]);
                }
            }

            return $raidStatus;

        } catch (Exception $e) {
            Log::error('레이드 체크 에러', [
                'server' => $serverId,
                'character' => $characterName,
                'error' => $e->getMessage()
            ]);
            return [
                'venus' => false,
                'nabal' => false
            ];
        }
    }

    /**
     * 나벨 레이드 클리어 여부 확인 (기존 호환성 유지)
     */
    public function checkNabalRaidClear(string $serverId, string $characterName): bool
    {
        $raidStatus = $this->checkAllRaids($serverId, $characterName);
        return $raidStatus['nabal'];
    }

    /**
     * 베누스 레이드 활동인지 확인 (직접 클리어만)
     */
    private function isVenusRaidActivity(array $activity): bool
    {
        $code = $activity['code'] ?? '';

        // 레기온 클리어 직접 체크만
        if ($code === 209) {
            $regionName = $activity['data']['regionName'] ?? '';
            $regionNameLower = strtolower($regionName);
            return str_contains($regionNameLower, '베누스') ||
                   str_contains($regionNameLower, 'venus');
        }

        // 보상 인정 제거
        return false;
    }

    /**
     * 나벨 레이드 활동인지 확인 (직접 클리어 + 보상 인정)
     */
    private function isNabalRaidActivity(array $activity): bool
    {
        $code = $activity['code'] ?? '';

        // 1. 레이드 클리어 직접 체크
        if ($code === 201) {
            $raidName = $activity['data']['raidName'] ?? '';
            $raidNameLower = strtolower($raidName);
            return str_contains($raidNameLower, '나벨') ||
                   str_contains($raidNameLower, 'nabal');
        }

        // 2. 레이드 카드 보상으로 나벨 클리어 인정
        if ($code === 507) {
            return $this->isNabalRewardItem($activity);
        }

        return false;
    }

    /**
     * 나벨 보상 아이템인지 확인
     */
    private function isNabalRewardItem(array $activity): bool
    {
        // code: 507은 모두 나벨 레이드 카드 보상
        return true;
    }
}
