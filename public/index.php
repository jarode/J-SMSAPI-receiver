<?php

/**
 * This file is part of the bitrix24-php-sdk package.
 *
 * © Maksim Mesilov <mesilov.maxim@gmail.com>
 *
 * For the full copyright and license information, please view the MIT-LICENSE.txt
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App;

use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/../vendor/autoload.php';

$incomingRequest = Request::createFromGlobals();
Application::getLog()->debug('index.init', ['request' => $incomingRequest->request->all(), 'query' => $incomingRequest->query->all()]);

// Ścieżka do pliku konfiguracyjnego
$configFile = __DIR__ . '/../config/config.json.local';

// Pobierz domenę z requesta (jeśli jest)
$domain = $_REQUEST['DOMAIN'] ?? ($_REQUEST['domain'] ?? null);

// Obsługa formularza
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['numbers']) && $domain) {
    $numbersRaw = $_POST['numbers'];
    $numbers = preg_split('/[\s,]+/', $numbersRaw, -1, PREG_SPLIT_NO_EMPTY);
    \App\Application::bindNumbersToDomain($numbers, $domain);
    $msg = 'Numery zostały zapisane.';
}

// Wczytaj aktualną konfigurację
$config = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Powiązanie numerów SMSAPI z Bitrix24</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2em; }
        input, textarea { width: 100%; padding: 0.5em; margin-bottom: 1em; }
        .msg { color: green; }
        table { border-collapse: collapse; margin-top: 2em; }
        th, td { border: 1px solid #ccc; padding: 0.5em 1em; }
    </style>
</head>
<body>
    <h2>Powiąż swoje numery SMSAPI z domeną Bitrix24</h2>
    <?php if (!empty($msg)): ?>
        <div class="msg"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <form method="post">
        <label for="numbers">Wpisz jeden lub więcej numerów (oddziel przecinkiem, spacją lub enterem):</label><br>
        <textarea name="numbers" id="numbers" rows="3" placeholder="np. +48500100200, +48500900900"></textarea><br>
        <input type="hidden" name="domain" value="<?= htmlspecialchars($domain ?? '') ?>">
        <button type="submit">Zapisz</button>
    </form>

    <h3>Aktualne powiązania numerów z domenami:</h3>
    <?php if (!empty($config)): ?>
        <table>
            <tr><th>Numer SMSAPI</th><th>Domena Bitrix24</th></tr>
            <?php foreach ($config as $num => $dom): ?>
                <tr><td><?= htmlspecialchars($num) ?></td><td><?= htmlspecialchars($dom) ?></td></tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>Brak powiązanych numerów.</p>
    <?php endif; ?>
</body>
</html>

//  try work with app

