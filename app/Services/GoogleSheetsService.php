<?php

namespace App\Services;

use Exception;
use Google\Client;
use Google\Service\Sheets;

class GoogleSheetsService
{
    private $client;
    private $service;
    private $spreadsheetId;

    public function __construct()
    {
        $this->initializeClient();
        $this->spreadsheetId = config('services.google.spreadsheet_id');
    }

    /**
     * Google Client 초기화
     */
    private function initializeClient()
    {
        $this->client = new Client();
        $this->client->setApplicationName(config('services.google.application_name'));
        $this->client->setScopes([Sheets::SPREADSHEETS]);
        if (env('GOOGLE_SERVICE_ACCOUNT_JSON')) {
            $this->client->setAuthConfig(json_decode(env('GOOGLE_SERVICE_ACCOUNT_JSON'), true));
        } else {
            $this->client->setAuthConfig(storage_path('app/google/service-account.json'));
        }
        $this->client->setAccessType('offline');

        $this->service = new Sheets($this->client);
    }

    /**
     * 시트에서 범위 데이터 읽기
     */
    public function readRange(string $sheetName, string $range): array
    {
        try {
            $fullRange = $sheetName . '!' . $range;
            $response = $this->service->spreadsheets_values->get($this->spreadsheetId, $fullRange);
            return $response->getValues() ?: [];
        } catch (Exception $e) {
            throw new Exception("시트 읽기 실패: " . $e->getMessage());
        }
    }

    /**
     * 시트에 데이터 쓰기
     */
    public function writeCell(string $sheetName, string $cell, $value): bool
    {
        try {
            $range = $sheetName . '!' . $cell;
            $valueRange = new \Google\Service\Sheets\ValueRange([
                'values' => [[$value]]
            ]);

            $params = [
                'valueInputOption' => 'RAW'
            ];

            $this->service->spreadsheets_values->update(
                $this->spreadsheetId,
                $range,
                $valueRange,
                $params
            );

            return true;
        } catch (Exception $e) {
            throw new Exception("시트 쓰기 실패: " . $e->getMessage());
        }
    }

    /**
     * 여러 셀에 동시에 데이터 쓰기
     */
    public function writeCells(string $sheetName, array $updates): bool
    {
        try {
            $data = [];

            foreach ($updates as $cell => $value) {
                $data[] = new \Google\Service\Sheets\ValueRange([
                    'range' => $sheetName . '!' . $cell,
                    'values' => [[$value]]
                ]);
            }

            $body = new \Google\Service\Sheets\BatchUpdateValuesRequest([
                'valueInputOption' => 'RAW',
                'data' => $data
            ]);

            $this->service->spreadsheets_values->batchUpdate($this->spreadsheetId, $body);
            return true;
        } catch (Exception $e) {
            throw new Exception("배치 쓰기 실패: " . $e->getMessage());
        }
    }

    /**
     * 스프레드시트 ID 설정
     */
    public function setSpreadsheetId(string $spreadsheetId): void
    {
        $this->spreadsheetId = $spreadsheetId;
    }
}
