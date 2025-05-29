<?php
// smsapi-callback.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// Załaduj zmienne środowiskowe z plików .env i .env.local
(new Dotenv())->load(
    __DIR__ . '/../config/.env',
    __DIR__ . '/../config/.env.local'
);

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
use Bitrix24\SDK\Core\Credentials\ApplicationProfile as Bitrix24ApplicationProfile;
$appProfile = Bitrix24ApplicationProfile::initFromArray($_ENV);

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

// Wybierz najnowszy lead lub deal, w którym już był SMS od SMSAPI
$entityType = null;
$entityId = null;
$latestDate = null;

function hasSmsapiComment($b24Service, $entityType, $entityId) {
    try {
        $timeline = $b24Service->core->call('crm.timeline.comment.list', [
            'filter' => [
                'ENTITY_ID' => $entityId,
                'ENTITY_TYPE' => $entityType
            ]
        ]);
        $timelineArr = $timeline->getResponseData()->getResult();
        if (is_array($timelineArr)) {
            foreach ($timelineArr as $comment) {
                if (isset($comment['COMMENT']) && strpos($comment['COMMENT'], '[SMSAPI]') !== false) {
                    return true;
                }
            }
        }
    } catch (\Throwable $e) {
        Application::getLog()->error('smsapi.callback.timeline_error', ['error' => $e->getMessage(), 'entityType' => $entityType, 'entityId' => $entityId]);
    }
    return false;
}

// Sprawdź deale
foreach ($dealsData as $deal) {
    if (hasSmsapiComment($b24Service, 'deal', $deal->ID)) {
        if ($latestDate === null || strtotime($deal->DATE_CREATE) > strtotime($latestDate)) {
            $entityType = 'deal';
            $entityId = $deal->ID;
            $latestDate = $deal->DATE_CREATE;
        }
    }
}
// Sprawdź leady
foreach ($leadsData as $lead) {
    if (hasSmsapiComment($b24Service, 'lead', $lead->ID)) {
        if ($latestDate === null || strtotime($lead->DATE_CREATE) > strtotime($latestDate)) {
            $entityType = 'lead';
            $entityId = $lead->ID;
            $latestDate = $lead->DATE_CREATE;
        }
    }
}

// Przygotuj link i opis do encji
$link = '';
$linkLabel = '';
if ($entityType && $entityId) {
    if ($entityType === 'lead') {
        $link = "https://{$domain}/crm/lead/details/{$entityId}/";
        $linkLabel = 'Lead';
    } elseif ($entityType === 'deal') {
        $link = "https://{$domain}/crm/deal/details/{$entityId}/";
        $linkLabel = 'Deal';
    }
} else {
    // Jeśli nie ma leada/deala, dodaj link do kontaktu
    $link = "https://{$domain}/crm/contact/details/{$contactId}/";
    $linkLabel = 'Kontakt';
}

// Przygotuj treść powiadomienia
$notifyMessage = "📩 [SMSAPI] Odebrano SMS\n";
$notifyMessage .= "Od: $from\n";
if ($smsDateStr) {
    $notifyMessage .= "Data: $smsDateStr\n";
}
$notifyMessage .= "\n$message\n\n";
$notifyMessage .= "$linkLabel: $link";

// Wysyłka powiadomienia
try {
    $notifyResult = $b24Service->core->call('im.notify', [
        'to' => $recipientId,
        'message' => $notifyMessage,
        'type' => 'SYSTEM'
    ]);
    Application::getLog()->info('smsapi.callback.im_notify_result', [
        'to' => $recipientId,
        'result' => $notifyResult->getResponseData()->getResult(),
        'error' => $notifyResult->getResponseData()->getErrorDescription()
    ]);
} catch (\Throwable $e) {
    Application::getLog()->error('smsapi.callback.im_notify_error', ['error' => $e->getMessage(), 'to' => $recipientId]);
}

// --- DODAJ WYSYŁKĘ WIADOMOŚCI I POWIADOMIENIA DO BITRIX24 CHAT ---
$imMessage = $commentText;
if ($link) {
    $imMessage .= "\n$linkLabel: $link";
}

// Dodaj logowanie treści wiadomości przed wysyłką
Application::getLog()->debug('smsapi.callback.im_message_content', ['message' => $imMessage]);

// Walidacja: nie wysyłaj pustej wiadomości
if (empty(trim($imMessage))) {
    Application::getLog()->error('smsapi.callback.im_message_empty', ['recipientId' => $recipientId]);
} else {
    // Wysyłka do osoby odpowiedzialnej za lead/deal lub kontakt
    $recipientId = $assignedById;
    try {
        $notifyResult = $b24Service->core->call('im.notify', [
            'to' => $recipientId,
            'message' => $imMessage,
            'type' => 'SYSTEM'
        ]);
        Application::getLog()->info('smsapi.callback.im_notify_result', [
            'to' => $recipientId,
            'result' => $notifyResult->getResponseData()->getResult(),
            'error' => $notifyResult->getResponseData()->getErrorDescription()
        ]);
    } catch (\Throwable $e) {
        Application::getLog()->error('smsapi.callback.im_notify_error', ['error' => $e->getMessage(), 'to' => $recipientId]);
    }
}

// ---
// Statusy leadów i dealów:
// Dokumentacja: https://helpdesk.bitrix24.com/open/18529390/
// Leady: STATUS_ID (np. NEW, IN_PROCESS, JUNK, CONVERTED, ...)
// Deale: STAGE_SEMANTIC_ID (P = Process/w toku, S = Success/wygrany, F = Failure/przegrany)
// Zalecane: pobierać statusy dynamicznie przez API crm.status.list lub ustalić je w konfiguracji
// ---

// Przykład dynamicznego pobierania statusów leadów (jeśli chcesz mieć zawsze aktualne):
// UWAGA: Możesz cache'ować te wartości, by nie robić zapytania przy każdym SMS!
function getActiveLeadStatusIds($b24Service) {
    $activeStatusIds = [];
    try {
        $result = $b24Service->core->call('crm.status.list', [
            'filter' => ['ENTITY_ID' => 'STATUS']
        ]);
        $resultArr = $result->getResponseData()->getResult();
        if (is_array($resultArr)) {
            foreach ($resultArr as $status) {
                if (isset($status['SEMANTICS']) && $status['SEMANTICS'] === 'P') {
                    $activeStatusIds[] = $status['STATUS_ID'];
                }
            }
        }
    } catch (\Throwable $e) {
        Application::getLog()->error('smsapi.callback.status_list_error', ['error' => $e->getMessage()]);
    }
    // Fallback: jeśli nie uda się pobrać, użyj domyślnych
    if (empty($activeStatusIds)) {
        $activeStatusIds = ['NEW', 'IN_PROCESS', 'JUNK', 'CONVERTED'];
    }
    return $activeStatusIds;
}

// Pobierz aktywne statusy leadów
$activeLeadStatusIds = getActiveLeadStatusIds($b24Service);

// Przykład dynamicznego pobierania aktywnych etapów dealów (tylko "w toku")
function getActiveDealStageIds($b24Service) {
    $activeStageIds = [];
    try {
        // Pobierz wszystkie kategorie dealów
        $categories = $b24Service->core->call('crm.dealcategory.list', []);
        $categoriesArr = $categories->getResponseData()->getResult();
        if (is_array($categoriesArr)) {
            foreach ($categoriesArr as $category) {
                $categoryId = $category['ID'];
                // Pobierz etapy dla danej kategorii
                $stages = $b24Service->core->call('crm.dealcategory.stage.list', [
                    'ID' => $categoryId
                ]);
                $stagesArr = $stages->getResponseData()->getResult();
                if (is_array($stagesArr)) {
                    foreach ($stagesArr as $stage) {
                        if (isset($stage['SEMANTICS']) && $stage['SEMANTICS'] === 'P') {
                            $activeStageIds[] = $stage['STATUS_ID'];
                        }
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        Application::getLog()->error('smsapi.callback.deal_stage_list_error', ['error' => $e->getMessage()]);
    }
    // Fallback: jeśli nie uda się pobrać, zwracaj pustą tablicę (wtedy filtrujemy po STAGE_SEMANTIC_ID = 'P')
    return $activeStageIds;
}

// Pobierz aktywne etapy dealów
$activeDealStageIds = getActiveDealStageIds($b24Service);

// Szukaj leadów powiązanych z kontaktem
try {
    $leads = $b24Service->getCrmScope()->lead()->list(
        ['DATE_CREATE' => 'DESC'],
        [
            // Używamy dynamicznie pobranych statusów
            'STATUS_ID' => $activeLeadStatusIds,
            'CONTACT_ID' => $contactId
        ],
        ['ID', 'TITLE', 'DATE_CREATE', 'STATUS_ID', 'CONTACT_ID'],
        0
    );
    $leadsData = $leads->getLeads();
    Application::getLog()->info('smsapi.callback.leads_found', ['count' => count($leadsData)]);
} catch (\Throwable $e) {
    $leadsData = [];
    Application::getLog()->error('smsapi.callback.lead_search_error', ['error' => $e->getMessage()]);
}

// Szukaj dealów powiązanych z kontaktem
try {
    $dealFilter = [
        'CONTACT_ID' => $contactId
    ];
    if (!empty($activeDealStageIds)) {
        $dealFilter['STAGE_ID'] = $activeDealStageIds;
    } else {
        // Fallback: filtruj po SEMANTICS = 'P' (w toku)
        $dealFilter['STAGE_SEMANTIC_ID'] = 'P';
    }
    $deals = $b24Service->getCrmScope()->deal()->list(
        ['DATE_CREATE' => 'DESC'],
        $dealFilter,
        ['ID', 'TITLE', 'DATE_CREATE', 'STAGE_ID', 'STAGE_SEMANTIC_ID', 'CONTACT_ID'],
        0
    );
    $dealsData = $deals->getDeals();
    Application::getLog()->info('smsapi.callback.deals_found', ['count' => count($dealsData)]);
} catch (\Throwable $e) {
    $dealsData = [];
    Application::getLog()->error('smsapi.callback.deal_search_error', ['error' => $e->getMessage()]);
}

http_response_code(200);
echo 'OK'; 