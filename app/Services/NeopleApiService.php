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
     * 나벨 레이드 클리어 여부 확인
     */
    public function checkNabalRaidClear(string $serverId, string $characterName): bool
    {
        try {
            // 1. 캐릭터 검색
            $characters = $this->searchCharacter($serverId, $characterName);

            if (empty($characters)) {
                Log::info('캐릭터를 찾을 수 없음', [
                    'server' => $serverId,
                    'character' => $characterName
                ]);
                return false;
            }

            // 2. 첫 번째 매칭 캐릭터 사용
            $character = $characters[0];
            $characterId = $character['characterId'];

            // 3. 타임라인 조회
            $timeline = $this->getCharacterTimeline($serverId, $characterId);

            if (empty($timeline)) {
                return false;
            }

            // 타임라인 데이터 로깅 추가
            if (!empty($timeline)) {
                Log::info('타임라인 데이터', [
                    'character' => $characterName,
                    'timeline_count' => count($timeline),
                    'recent_activities' => array_slice($timeline, 0, 5) // 최근 5개만
                ]);
            }

            // 4. 나벨 레이드 클리어 확인 (최근 7일)
            $weekAgo = now()->subDays(7);

            foreach ($timeline as $activity) {
                $activityDate = \Carbon\Carbon::parse($activity['date']);

                if ($activityDate->lt($weekAgo)) {
                    continue;
                }

                if ($this->isNabalRaidActivity($activity)) {
                    Log::info('나벨 레이드 클리어 확인', [
                        'server' => $serverId,
                        'character' => $characterName,
                        'date' => $activity['date'],
                        'raidName' => $activity['data']['raidName'] ?? ''
                    ]);
                    return true;
                }
            }

            return false;

        } catch (Exception $e) {
            Log::error('나벨 레이드 체크 에러', [
                'server' => $serverId,
                'character' => $characterName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 나벨 레이드 활동인지 확인
     */
    private function isNabalRaidActivity(array $activity): bool
    {
        // 레이드 코드 확인 (201 = 레이드)
        if (($activity['code'] ?? '') !== 201) {
            return false;
        }

        // raidName에서 나벨 키워드 확인
        $raidName = $activity['data']['raidName'] ?? '';
        $raidNameLower = strtolower($raidName);

        return str_contains($raidNameLower, '나벨') ||
            str_contains($raidNameLower, 'nabal');
    }
}
