<?php

declare(strict_types=1);

/**
 * EcoFlow-Gerät über die offizielle EcoFlow Open API (Cloud).
 *
 * - Liest zyklisch alle Datenpunkte ("Quotas") eines Geräts und legt dafür Variablen an.
 * - Optional: Berechnung "Stromüberschuss" aus einem frei wählbaren Leistungswert (z. B. Netzleistung).
 * - Schreiben von Parametern über ECO_SetQuota().
 *
 * API-Doku: https://developer-eu.ecoflow.com/us/document/introduction
 */
class EcoFlowDevice extends IPSModule
{
    private $lastDiagnosis = '';
    private $lastError = '';

    private const HOSTS = [
        0 => 'https://api-e.ecoflow.com', // Europa
        1 => 'https://api-a.ecoflow.com', // Amerika
        2 => 'https://api.ecoflow.com'    // Global
    ];

    public function Create()
    {
        parent::Create();

        // Zugang
        $this->RegisterPropertyString('AccessKey', '');
        $this->RegisterPropertyString('SecretKey', '');
        $this->RegisterPropertyInteger('Region', 0);
        $this->RegisterPropertyString('SerialNumber', '');

        // Abfrage
        $this->RegisterPropertyInteger('UpdateInterval', 60);
        $this->RegisterPropertyString('QuotaFilter', '');

        // Stromüberschuss (optional)
        $this->RegisterPropertyString('SurplusKey', '');
        $this->RegisterPropertyBoolean('SurplusFeedInNegative', true);
        $this->RegisterPropertyInteger('SurplusOnWatt', 800);
        $this->RegisterPropertyInteger('SurplusOffWatt', 200);

        $this->RegisterTimer('Update', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Update\', \'\');');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterVariableInteger('LastUpdate', 'Letzte Aktualisierung', '~UnixTimestamp', 0);

        $surplusActive = trim($this->ReadPropertyString('SurplusKey')) !== '';
        $this->MaintainVariable('FeedInPower', 'Einspeiseleistung', VARIABLETYPE_FLOAT, '~Watt.3680', 1, $surplusActive);
        $this->MaintainVariable('Surplus', 'Stromüberschuss', VARIABLETYPE_BOOLEAN, '~Switch', 2, $surplusActive);

        if (trim($this->ReadPropertyString('AccessKey')) === '' || trim($this->ReadPropertyString('SecretKey')) === ''
            || $this->ReadPropertyString('SerialNumber') === '') {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus(201);
            return;
        }

        $interval = max(10, $this->ReadPropertyInteger('UpdateInterval'));
        $this->SetTimerInterval('Update', $interval * 1000);
        $this->SetStatus(102);
    }

    /** Buttons im Konfigurationsformular und Timer laufen über RequestAction. */
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Update':
                $ok = $this->Update();
                if ($Value === 'button') {
                    echo $ok ? 'Aktualisiert.' : 'Fehler, siehe Meldungen.';
                }
                break;
            case 'ShowQuotas':
                $this->ShowQuotas();
                break;
            case 'ListDevices':
                $this->ListDevices();
                break;
            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
    }

    // ------------------------------------------------------------------
    // Öffentliche Funktionen (ECO_...)
    // ------------------------------------------------------------------

    /** Alle Datenpunkte abrufen und Variablen aktualisieren. */
    public function Update(): bool
    {
        $sn = $this->GetSerial();
        $data = $this->Request('GET', '/iot-open/sign/device/quota/all', ['sn' => $sn]);
        if ($data === null) {
            return false;
        }

        $filter = $this->GetFilter();
        $position = 10;
        ksort($data, SORT_STRING);

        foreach ($data as $key => $value) {
            $key = (string) $key;
            $listed = $this->IsListed($key, $filter);
            if ($filter !== [] && !$listed) {
                continue;
            }
            if (is_array($value)) {
                // Arrays nur speichern, wenn ausdrücklich im Filter angegeben
                if (!$listed || $filter === []) {
                    continue;
                }
                $value = json_encode($value);
            }
            $this->WriteQuotaVariable($key, $value, $position++);
        }

        $this->UpdateSurplus($data);

        $this->SetValue('LastUpdate', time());
        if ($this->GetStatus() !== 102) {
            $this->SetStatus(102);
        }
        return true;
    }

    /** Gibt alle verfügbaren Datenpunkte mit aktuellem Wert aus (für die Auswahl im Filter). */
    public function ShowQuotas(): string
    {
        $sn = $this->GetSerial();
        $data = $this->Request('GET', '/iot-open/sign/device/quota/all', ['sn' => $sn]);
        if ($data === null) {
            echo 'Abfrage fehlgeschlagen: ' . $this->lastError . PHP_EOL . $this->lastDiagnosis;
            return '';
        }
        ksort($data, SORT_STRING);
        $lines = [];
        foreach ($data as $key => $value) {
            $lines[] = $key . ' = ' . (is_array($value) ? json_encode($value) : var_export($value, true));
        }
        $text = implode(PHP_EOL, $lines);
        echo count($data) . ' Datenpunkte:' . PHP_EOL . $text;
        return $text;
    }

    /** Listet alle Geräte des EcoFlow-Kontos (Seriennummer, Name, Online-Status). */
    public function ListDevices(): string
    {
        $data = $this->Request('GET', '/iot-open/sign/device/list', []);
        if ($data === null) {
            echo 'Abfrage fehlgeschlagen: ' . $this->lastError . PHP_EOL . $this->lastDiagnosis;
            return '';
        }
        $lines = [];
        foreach ($data as $device) {
            $lines[] = sprintf('%s | %s | %s | %s',
                $device['sn'] ?? '?',
                $device['deviceName'] ?? '',
                $device['productName'] ?? '',
                !empty($device['online']) ? 'online' : 'offline');
        }
        $text = implode(PHP_EOL, $lines);
        echo $text;
        return $text;
    }

    /**
     * Einzelne Datenpunkte abfragen.
     * Beispiel: ECO_GetQuota(12345, '["20_1.pv1InputWatts","20_1.batSoc"]');
     */
    public function GetQuota(string $QuotasJson): string
    {
        $quotas = json_decode($QuotasJson, true);
        if (!is_array($quotas)) {
            trigger_error('QuotasJson muss ein JSON-Array sein', E_USER_WARNING);
            return '';
        }
        $body = ['sn' => $this->GetSerial(), 'params' => ['quotas' => array_values($quotas)]];
        $data = $this->Request('POST', '/iot-open/sign/device/quota', [], $body);
        return $data === null ? '' : (string) json_encode($data);
    }

    /**
     * Parameter setzen. Der Inhalt hängt vom Gerät ab (siehe EcoFlow-Doku), die Seriennummer wird ergänzt.
     * Beispiel PowerStream: ECO_SetQuota(12345, '{"cmdCode":"WN511_SET_PERMANENT_WATTS_PACK","params":{"permanentWatts":2000}}');
     */
    public function SetQuota(string $BodyJson): bool
    {
        $body = json_decode($BodyJson, true);
        if (!is_array($body)) {
            trigger_error('BodyJson muss ein JSON-Objekt sein', E_USER_WARNING);
            return false;
        }
        $body = array_merge(['sn' => $this->GetSerial()], $body);
        $result = $this->Request('PUT', '/iot-open/sign/device/quota', [], $body, true);
        return $result !== null;
    }

    // ------------------------------------------------------------------
    // Interne Funktionen
    // ------------------------------------------------------------------

    private function WriteQuotaVariable(string $key, $value, int $position): void
    {
        $ident = 'Q_' . substr(preg_replace('/[^A-Za-z0-9_]/', '_', $key), 0, 120);
        $existingId = @$this->GetIDForIdent($ident);

        if (is_bool($value)) {
            $type = VARIABLETYPE_BOOLEAN;
        } elseif (is_int($value)) {
            $type = VARIABLETYPE_INTEGER;
        } elseif (is_float($value)) {
            $type = VARIABLETYPE_FLOAT;
        } else {
            $type = VARIABLETYPE_STRING;
            $value = (string) $value;
        }

        // Zahlen: vorhandenen Variablentyp beibehalten, damit Archivdaten nicht verloren gehen
        if ($existingId !== false && is_numeric($value) && !is_string($value)) {
            $existingType = IPS_GetVariable($existingId)['VariableType'];
            if ($existingType === VARIABLETYPE_FLOAT) {
                $type = VARIABLETYPE_FLOAT;
            } elseif ($existingType === VARIABLETYPE_INTEGER && is_float($value) && floor($value) == $value) {
                $type = VARIABLETYPE_INTEGER;
            }
        }

        switch ($type) {
            case VARIABLETYPE_INTEGER:
                $value = (int) $value;
                break;
            case VARIABLETYPE_FLOAT:
                $value = (float) $value;
                break;
        }

        if ($existingId === false || IPS_GetVariable($existingId)['VariableType'] !== $type) {
            $this->MaintainVariable($ident, $key, $type, '', $position, true);
        }
        $this->SetValue($ident, $value);
    }

    private function UpdateSurplus(array $data): void
    {
        $key = trim($this->ReadPropertyString('SurplusKey'));
        if ($key === '') {
            return;
        }
        if (!array_key_exists($key, $data) || !is_numeric($data[$key])) {
            $this->SendDebug('Surplus', "Datenpunkt '$key' nicht gefunden oder nicht numerisch", 0);
            return;
        }

        $power = (float) $data[$key];
        // Einspeisung als positiven Wert darstellen
        $feedIn = $this->ReadPropertyBoolean('SurplusFeedInNegative') ? -$power : $power;
        $this->SetValue('FeedInPower', $feedIn);

        // Hysterese: ein ab SurplusOnWatt, aus unter SurplusOffWatt
        $current = $this->GetValue('Surplus');
        if (!$current && $feedIn >= $this->ReadPropertyInteger('SurplusOnWatt')) {
            $this->SetValue('Surplus', true);
        } elseif ($current && $feedIn < $this->ReadPropertyInteger('SurplusOffWatt')) {
            $this->SetValue('Surplus', false);
        }
    }

    /** Seriennummer ohne Leer- oder unsichtbare Zeichen. */
    private function GetSerial(): string
    {
        return (string) preg_replace('/[^A-Za-z0-9]/', '', $this->ReadPropertyString('SerialNumber'));
    }

    private function GetFilter(): array
    {
        $raw = $this->ReadPropertyString('QuotaFilter');
        $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        return $parts === false ? [] : $parts;
    }

    /** Filtereintrag passt exakt oder als Präfix mit * (z. B. "20_1.*"). */
    private function IsListed(string $key, array $filter): bool
    {
        foreach ($filter as $entry) {
            if (substr($entry, -1) === '*') {
                if (strpos($key, substr($entry, 0, -1)) === 0) {
                    return true;
                }
            } elseif ($entry === $key) {
                return true;
            }
        }
        return false;
    }

    /**
     * Signierte Anfrage an die EcoFlow Open API.
     * Signatur: alle Parameter (Query + Body, verschachtelt mit "." bzw. "[i]") nach ASCII sortiert,
     * als key=value&... verbunden, dann accessKey, nonce und timestamp angehängt, HMAC-SHA256 (hex).
     *
     * @return array|null data-Feld der Antwort oder null bei Fehler
     */
    private function Request(string $method, string $path, array $query, ?array $body = null, bool $allowEmptyData = false): ?array
    {
        $accessKey = trim($this->ReadPropertyString('AccessKey'));
        $secretKey = trim($this->ReadPropertyString('SecretKey'));
        if ($accessKey === '' || $secretKey === '') {
            $this->SetStatus(201);
            return null;
        }

        $nonce = (string) random_int(100000, 999999);
        $timestamp = sprintf('%.0f', floor(microtime(true) * 1000));

        $signParams = $this->Flatten(array_merge($query, $body ?? []));
        ksort($signParams, SORT_STRING);
        $pairs = [];
        foreach ($signParams as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $pairs[] = 'accessKey=' . $accessKey;
        $pairs[] = 'nonce=' . $nonce;
        $pairs[] = 'timestamp=' . $timestamp;
        $signBase = implode('&', $pairs);
        $sign = hash_hmac('sha256', $signBase, $secretKey);
        $this->lastDiagnosis = 'URL: ' . $path . ($query !== [] ? '?' . http_build_query($query) : '')
            . PHP_EOL . 'Signiert: ' . preg_replace('/accessKey=[^&]+/', 'accessKey=***', $signBase);

        $url = (self::HOSTS[$this->ReadPropertyInteger('Region')] ?? self::HOSTS[0]) . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'accessKey: ' . $accessKey,
            'nonce: ' . $nonce,
            'timestamp: ' . $timestamp,
            'sign: ' . $sign
        ];

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curl, CURLOPT_TIMEOUT, 15);
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json;charset=UTF-8';
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $raw = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        $this->SendDebug('Request', "$method $url", 0);
        $this->SendDebug('Response', (string) $raw, 0);

        if ($raw === false || $httpCode !== 200) {
            $this->Fail("HTTP-Fehler $httpCode $curlError");
            return null;
        }

        $response = json_decode((string) $raw, true);
        if (!is_array($response) || (string) ($response['code'] ?? '') !== '0') {
            $this->Fail('API-Fehler: ' . ($response['message'] ?? (string) $raw));
            return null;
        }

        if (!isset($response['data']) || !is_array($response['data'])) {
            return $allowEmptyData ? [] : null;
        }
        return $response['data'];
    }

    private function Flatten(array $data, string $prefix = ''): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if ($prefix === '') {
                $newKey = (string) $key;
            } elseif (is_int($key)) {
                $newKey = $prefix . '[' . $key . ']';
            } else {
                $newKey = $prefix . '.' . $key;
            }
            if (is_array($value)) {
                $result = array_merge($result, $this->Flatten($value, $newKey));
            } else {
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }
                $result[$newKey] = (string) $value;
            }
        }
        return $result;
    }

    private function Fail(string $message): void
    {
        $this->lastError = $message;
        $this->LogMessage($message, KL_ERROR);
        $this->SendDebug('Fehler', $message, 0);
        $this->SetStatus(202);
    }
}
