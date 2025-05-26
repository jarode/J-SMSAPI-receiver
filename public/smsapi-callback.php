<?php
// smsapi-callback.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Bitrix24\SDK\Core\Credentials\OAuth2\OAuth2Token;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Application;

function loadTokens() {
    $file = __DIR__ . '/../config/auth.json.local';
    if (!file_exists($file)) {
        throw new Exception('Brak pliku z tokenami!');
    }
    $data = json_decode(file_get_contents($file), true);
    if (!$data || !isset($data['auth_token'])) {
        throw new Exception('Nieprawidłowy format pliku z tokenami!');
    }
    return $data['auth_token'];
}

function saveTokens($tokens) {
    $file = __DIR__ . '/../config/auth.json.local';
    $data = json_decode(file_get_contents($file), true);
    $data['auth_token'] = $tokens;
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

// Dane aplikacji z Bitrix24
$appProfile = ApplicationProfile::initFromArray([
    'BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID' => 'app.68333281411f82.84628684',
    'BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET' => 'lp7XcJ8aI5AdeOuQzeXvRYyS91cq5MUeGlb5r1mNEo4Nw9LPVE',
    'BITRIX24_PHP_SDK_APPLICATION_SCOPE' => 'crm,telephony',
]);

function normalizePhone($phone) {
    return preg_replace('/\\D+/', '', $phone);
}

$request = Request::createFromGlobals();
$data = $request->request->all();

// Loguj przyjęcie callbacku
Application::getLog()->info('smsapi.callback.received', ['data' => $data]);

$from = $data['sms_from'] ?? $data['from'] ?? null;
$to = $data['sms_to'] ?? $data['to'] ?? null;
$message = $data['sms_text'] ?? $data['message'] ?? null;

if (!$from || !$to || !$message) {
    Application::getLog()->error('smsapi.callback.missing_fields', ['from' => $from, 'to' => $to, 'message' => $message]);
    http_response_code(400);
    echo 'Missing required fields';
    exit;
}

// 1. Znajdź domenę Bitrix24 na podstawie numeru docelowego
$configFile = __DIR__ . '/../config/config.json.local';
$config = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];
$domain = $config[$to] ?? null;

if (!$domain) {
    Application::getLog()->error('smsapi.callback.domain_not_found', ['to' => $to]);
    http_response_code(404);
    echo 'Domain not found for this number';
    exit;
}

// 2. Załaduj tokeny dla tej domeny (na razie zakładamy jeden plik auth.json.local)
$authFile = __DIR__ . '/../config/auth.json.local';
if (!file_exists($authFile)) {
    Application::getLog()->error('smsapi.callback.auth_file_not_found', ['authFile' => $authFile]);
    http_response_code(500);
    echo 'Auth file not found';
    exit;
}
$auth = json_decode(file_get_contents($authFile), true);

// 3. Stwórz klienta SDK
try {
    $authObj = Application::getAuthByPhone($to);
    if ($authObj === null) {
        Application::getLog()->error('smsapi.callback.auth_not_found', ['to' => $to, 'domain' => $domain]);
        http_response_code(500);
        echo 'Auth not found for this number/domain';
        exit;
    }
    $b24Service = (new ServiceBuilderFactory(
        Application::getEventDispatcher(),
        Application::getLog()
    ))->init(
        $appProfile,
        $authObj->getAuthToken(),
        $authObj->getDomainUrl()
    );
} catch (\Throwable $e) {
    Application::getLog()->error('smsapi.callback.b24service_error', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo 'Bitrix24 service error';
    exit;
}

// Przygotuj warianty numeru
$variants = [];
$normalized = preg_replace('/\D+/', '', $from);
if (strlen($normalized) > 9) {
    $last9 = substr($normalized, -9);
} else {
    $last9 = $normalized;
}
$variants[] = $from; // oryginał
$variants[] = '+' . $normalized; // +48506502706
$variants[] = $normalized; // 48506502706
$variants[] = $last9; // 506502706
if (strpos($from, '+') === 0) {
    $variants[] = preg_replace('/\s+/', '', $from); // +48506502706 bez spacji
}

$contactsData = [];
foreach ($variants as $variant) {
    try {
        Application::getLog()->info('smsapi.callback.contact_search_attempt', ['variant' => $variant]);
        $contacts = $b24Service->getCrmScope()->contact()->list(
            [],
            ['PHONE' => $variant],
            ['ID', 'NAME', 'LAST_NAME', 'PHONE'],
            0
        );
        $contactsData = $contacts->getContacts();
        if (!empty($contactsData)) {
            Application::getLog()->info('smsapi.callback.contacts_found', ['from' => $from, 'variant' => $variant, 'count' => count($contactsData)]);
            break;
        }
    } catch (\Throwable $e) {
        Application::getLog()->error('smsapi.callback.contact_search_error', ['error' => $e->getMessage(), 'variant' => $variant]);
    }
}
if (empty($contactsData)) {
    Application::getLog()->warning('smsapi.callback.contact_not_found', ['from' => $from, 'variants' => $variants]);
    http_response_code(404);
    echo 'Contact not found';
    exit;
}

// Pobierz datę SMS (jeśli jest)
$smsDateRaw = $data['sms_date'] ?? null;
$smsDateStr = '';
if ($smsDateRaw && is_numeric($smsDateRaw)) {
    $smsDateStr = date('Y-m-d H:i:s', (int)$smsDateRaw);
}

// Przygotuj treść komentarza w spójnym formacie
$commentText = "📩 [SMSAPI] Odebrano SMS\n";
$commentText .= "Od: $from\n";
if ($smsDateStr) {
    $commentText .= "Data: $smsDateStr\n";
}
$commentText .= "\n$message";

// Przygotuj numer w formacie z plusem
$fromNormalized = $from;
if (strpos($fromNormalized, '+') !== 0) {
    $fromNormalized = '+' . preg_replace('/\D+/', '', $fromNormalized);
}

// Pobierz ASSIGNED_BY_ID z kontaktu (jeśli istnieje), w przeciwnym razie ustaw domyślne ID (np. 1)
$assignedById = null;
if (isset($contactsData[0]->ASSIGNED_BY_ID) && $contactsData[0]->ASSIGNED_BY_ID) {
    $assignedById = $contactsData[0]->ASSIGNED_BY_ID;
} else {
    $assignedById = 1; // <- tutaj możesz wpisać swoje ID użytkownika Bitrix24
}

// 5. Dodaj komentarz do kontaktu (do pierwszego znalezionego)
$contactId = $contactsData[0]->ID;
try {
    Application::getLog()->info('smsapi.callback.comment_add_attempt', ['contactId' => $contactId, 'message' => $commentText]);
    $b24Service->core->call('crm.timeline.comment.add', [
        'fields' => [
            'ENTITY_ID' => $contactId,
            'ENTITY_TYPE' => 'contact',
            'COMMENT' => $commentText
        ]
    ]);
    Application::getLog()->info('smsapi.callback.comment_added', ['contactId' => $contactId, 'message' => $commentText]);
} catch (\Throwable $e) {
    Application::getLog()->error('smsapi.callback.comment_error', ['error' => $e->getMessage(), 'contactId' => $contactId]);
}

// Szukaj aktywnych leadów powiązanych z numerem
try {
    $leads = $b24Service->getCrmScope()->lead()->list(
        ['DATE_CREATE' => 'DESC'],
        [
            'STATUS_ID' => ['NEW', 'IN_PROCESS', 'JUNK', 'CONVERTED'], // możesz doprecyzować statusy aktywne
            'PHONE' => $fromNormalized
        ],
        ['ID', 'TITLE', 'DATE_CREATE', 'STATUS_ID', 'PHONE'],
        0
    );
    $leadsData = $leads->getLeads();
    Application::getLog()->info('smsapi.callback.leads_found', ['count' => count($leadsData)]);
} catch (\Throwable $e) {
    $leadsData = [];
    Application::getLog()->error('smsapi.callback.lead_search_error', ['error' => $e->getMessage()]);
}

// Szukaj aktywnych dealów powiązanych z numerem
try {
    $deals = $b24Service->getCrmScope()->deal()->list(
        ['DATE_CREATE' => 'DESC'],
        [
            'STAGE_SEMANTIC_ID' => 'P', // P = Process (w toku)
            'PHONE' => $fromNormalized
        ],
        ['ID', 'TITLE', 'DATE_CREATE', 'STAGE_ID', 'STAGE_SEMANTIC_ID', 'PHONE'],
        0
    );
    $dealsData = $deals->getDeals();
    Application::getLog()->info('smsapi.callback.deals_found', ['count' => count($dealsData)]);
} catch (\Throwable $e) {
    $dealsData = [];
    Application::getLog()->error('smsapi.callback.deal_search_error', ['error' => $e->getMessage()]);
}

// Wybierz najnowszy lead lub deal
$entityType = null;
$entityId = null;
$latestDate = null;
if (!empty($leadsData)) {
    $lead = $leadsData[0];
    $entityType = 'lead';
    $entityId = $lead->ID;
    $latestDate = $lead->DATE_CREATE;
}
if (!empty($dealsData)) {
    $deal = $dealsData[0];
    if ($latestDate === null || strtotime($deal->DATE_CREATE) > strtotime($latestDate)) {
        $entityType = 'deal';
        $entityId = $deal->ID;
        $latestDate = $deal->DATE_CREATE;
    }
}
// Dodaj komentarz do najnowszego leada/deala (jeśli znaleziono)
if ($entityType && $entityId) {
    try {
        Application::getLog()->info('smsapi.callback.comment_add_attempt', ['entityType' => $entityType, 'entityId' => $entityId, 'message' => $commentText]);
        $b24Service->core->call('crm.timeline.comment.add', [
            'fields' => [
                'ENTITY_ID' => $entityId,
                'ENTITY_TYPE' => $entityType,
                'COMMENT' => $commentText
            ]
        ]);
        Application::getLog()->info('smsapi.callback.comment_added', ['entityType' => $entityType, 'entityId' => $entityId, 'message' => $commentText]);
    } catch (\Throwable $e) {
        Application::getLog()->error('smsapi.callback.comment_error', ['error' => $e->getMessage(), 'entityType' => $entityType, 'entityId' => $entityId]);
    }
}

http_response_code(200);
echo 'OK'; 