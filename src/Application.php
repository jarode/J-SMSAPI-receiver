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

use Bitrix24\SDK\Application\Local\Entity\LocalAppAuth;
use Bitrix24\SDK\Application\Local\Infrastructure\Filesystem\AppAuthFileStorage;
use Bitrix24\SDK\Application\Local\Repository\LocalAppAuthRepositoryInterface;
use Bitrix24\SDK\Application\Requests\Events\OnApplicationInstall\OnApplicationInstall;
use Bitrix24\SDK\Application\Requests\Events\OnApplicationUninstall\OnApplicationUninstall;
use Bitrix24\SDK\Application\Requests\Placement\PlacementRequest;
use Bitrix24\SDK\Core\Contracts\Events\EventInterface;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Exceptions\BaseException;
use Bitrix24\SDK\Core\Exceptions\InvalidArgumentException;
use Bitrix24\SDK\Core\Exceptions\TransportException;
use Bitrix24\SDK\Core\Exceptions\UnknownScopeCodeException;
use Bitrix24\SDK\Core\Exceptions\WrongConfigurationException;
use Bitrix24\SDK\Events\AuthTokenRenewedEvent;
use Bitrix24\SDK\Services\Main\Common\EventHandlerMetadata;
use Bitrix24\SDK\Services\RemoteEventsFabric;
use Bitrix24\SDK\Services\ServiceBuilder;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Monolog\Processor\MemoryUsageProcessor;
use Monolog\Processor\UidProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class Application
{
    private const CONFIG_FILE_NAME = '/config/.env';

    private const LOG_FILE_NAME = '/var/log/application.log';

    public static function processRequest(Request $incomingRequest): Response
    {
        self::getLog()->debug('processRequest.start', [
            'request' => $incomingRequest->request->all(),
            'baseUrl' => $incomingRequest->getBaseUrl(),
        ]);
        // it can be
        // - incoming request when placement or custom type in userfield loaded
        // - incoming event request on install.php when lical app without UI
        if (PlacementRequest::isCanProcess($incomingRequest)) {
            self::getLog()->debug('processRequest.placementRequest', [
                'request' => $incomingRequest->request->all()
            ]);
            $placementRequest = new PlacementRequest($incomingRequest);

            // is install url?
            if ($placementRequest->getRequest()->getBaseUrl() === '/install.php') {
                self::processOnInstallPlacementRequest($placementRequest);
            }

            // todo process other placement request

        } elseif (RemoteEventsFabric::isCanProcess($incomingRequest)) {
            self::getLog()->debug('processRequest.b24EventRequest');

            // get application_token for check event security signature
            // see https://apidocs.bitrix24.com/api-reference/events/safe-event-handlers.html
            // on first lifecycle event OnApplicationInstall application token is null and file with auth data doesn't exists
            // we save application_token and all next events will be validated security signature
            $applicationToken = self::getAuthRepository()->getApplicationToken();
            $event = RemoteEventsFabric::init(self::getLog())->createEvent($incomingRequest, $applicationToken);
            self::getLog()->debug('processRequest.eventRequest', [
                'eventClassName' => $event::class,
                'eventCode' => $event->getEventCode(),
                'eventPayload' => $event->getEventPayload(),
            ]);
            self::processRemoteEvents($event);
        }

        self::getLog()->debug('processRequest.finish');

        return new Response('OK', 200);
    }

    /**
     * Process remote bitrix24 events
     *
     * @throws InvalidArgumentException
     * @throws WrongConfigurationException
     */
    protected static function processRemoteEvents(EventInterface $b24Event): void
    {
        self::getLog()->debug('processRemoteEvents.start', [
            'event_code' => $b24Event->getEventCode(),
            'event_classname' => $b24Event::class,
            'event_payload' => $b24Event->getEventPayload()
        ]);

        switch ($b24Event->getEventCode()) {
            case OnApplicationInstall::CODE:
                self::getLog()->debug('processRemoteEvents.onApplicationInstall');
                // usuń https:// z domeny
                $domain = preg_replace('#^https?://#', '', $b24Event->getAuth()->domain);
                $key = sprintf('%s_%s', $domain, $b24Event->getAuth()->member_id);
                $authData = [
                    $key => [
                        'auth_token' => [
                            'access_token' => $b24Event->getAuth()->authToken->accessToken,
                            'refresh_token' => $b24Event->getAuth()->authToken->refreshToken,
                            'expires' => $b24Event->getAuth()->authToken->expires
                        ],
                        'domain_url' => $domain,
                        'application_token' => $b24Event->getAuth()->application_token
                    ]
                ];
                // read existing auth data if file exists
                $authFileName = __DIR__ . '/../config/auth.json.local';
                if (file_exists($authFileName)) {
                    $existingData = json_decode(file_get_contents($authFileName), true);
                    if (is_array($existingData)) {
                        $authData = array_merge($existingData, $authData);
                    }
                }
                // write updated auth data
                file_put_contents($authFileName, json_encode($authData, JSON_PRETTY_PRINT));
                break;
            case 'OTHER_EVENT_CODE':
                // add your event handler code
                break;
            default:
                self::getLog()->warning('processRemoteEvents.unknownEvent', [
                    'event_code' => $b24Event->getEventCode(),
                    'event_classname' => $b24Event::class,
                    'event_payload' => $b24Event->getEventPayload()
                ]);
                break;
        }

        self::getLog()->debug('processRemoteEvents.finish');
    }

    /**
     * Process first request (installation) on default placement
     *
     * @throws InvalidArgumentException
     * @throws UnknownScopeCodeException
     * @throws WrongConfigurationException
     * @throws BaseException
     * @throws TransportException
     */
    protected static function processOnInstallPlacementRequest(PlacementRequest $placementRequest): void
    {
        self::getLog()->debug('processRequest.processOnInstallPlacementRequest.start');

        $currentB24UserId = self::getB24Service($placementRequest->getRequest())
            ->getMainScope()
            ->main()
            ->getCurrentUserProfile()
            ->getUserProfile()
            ->ID;

        $eventHandlerUrl = sprintf('https://%s/event-handler.php', $placementRequest->getRequest()->server->get('HTTP_HOST'));
        self::getLog()->debug('processRequest.processOnInstallPlacementRequest.startBindEventHandlers', [
            'eventHandlerUrl' => $eventHandlerUrl
        ]);

        // register application lifecycle event handlers
        self::getB24Service($placementRequest->getRequest())->getMainScope()->eventManager()->bindEventHandlers(
            [
                // register event handlers for implemented in SDK events
                new EventHandlerMetadata(
                    OnApplicationInstall::CODE,
                    $eventHandlerUrl,
                    $currentB24UserId
                ),
                new EventHandlerMetadata(
                    OnApplicationUninstall::CODE,
                    $eventHandlerUrl,
                    $currentB24UserId,
                ),

                // register not implemented in SDK event
                new EventHandlerMetadata(
                    'ONCRMCONTACTADD',
                    $eventHandlerUrl,
                    $currentB24UserId,
                ),
            ]
        );
        self::getLog()->debug('processRequest.processOnInstallPlacementRequest.finishBindEventHandlers');

        // save admin auth token without application_token key
        // they will arrive at OnApplicationInstall event
        $domain = preg_replace('#^https?://#', '', $placementRequest->getDomainUrl());
        $key = sprintf('%s_%s', $domain, $placementRequest->getRequest()->get('member_id'));
        $authData = [
            $key => [
                'auth_token' => [
                    'access_token' => $placementRequest->getAccessToken()->accessToken,
                    'refresh_token' => $placementRequest->getAccessToken()->refreshToken,
                    'expires' => $placementRequest->getAccessToken()->expires
                ],
                'domain_url' => $domain,
                'application_token' => null
            ]
        ];
        // read existing auth data if file exists
        $authFileName = __DIR__ . '/../config/auth.json.local';
        if (file_exists($authFileName)) {
            $existingData = json_decode(file_get_contents($authFileName), true);
            if (is_array($existingData)) {
                $authData = array_merge($existingData, $authData);
            }
        }
        // write updated auth data
        file_put_contents($authFileName, json_encode($authData, JSON_PRETTY_PRINT));
        self::getLog()->debug('processRequest.processOnInstallPlacementRequest.finish');
    }

    /**
     * @throws WrongConfigurationException
     * @throws InvalidArgumentException
     */
    public static function getLog(): LoggerInterface
    {
        static $logger;

        if ($logger === null) {
            // load config
            self::loadConfigFromEnvFile();

            // check settings
            if (!array_key_exists('BITRIX24_PHP_SDK_LOG_LEVEL', $_ENV)) {
                throw new InvalidArgumentException('in $_ENV variables not found key BITRIX24_PHP_SDK_LOG_LEVEL');
            }

            // rotating
            $rotatingFileHandler = new RotatingFileHandler(dirname(__DIR__) . self::LOG_FILE_NAME, 0, (int)$_ENV['BITRIX24_PHP_SDK_LOG_LEVEL']);
            $rotatingFileHandler->setFilenameFormat('{filename}-{date}', 'Y-m-d');

            $logger = new Logger('App');
            $logger->pushHandler($rotatingFileHandler);
            $logger->pushProcessor(new MemoryUsageProcessor(true, true));
            $logger->pushProcessor(new UidProcessor());
        }

        return $logger;
    }

    /**
     * Retrieves an instance of the event dispatcher.
     *
     * @return EventDispatcherInterface The event dispatcher instance.
     */
    public static function getEventDispatcher(): EventDispatcherInterface
    {
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addListener(AuthTokenRenewedEvent::class, function (AuthTokenRenewedEvent $authTokenRenewedEvent): void {
            self::onAuthTokenRenewedEventListener($authTokenRenewedEvent);
        });
        return $eventDispatcher;
    }

    /**
     * Event listener for when the authentication token is renewed.
     *
     * @param AuthTokenRenewedEvent $authTokenRenewedEvent The event object containing the renewed authentication token.
     */
    protected static function onAuthTokenRenewedEventListener(AuthTokenRenewedEvent $authTokenRenewedEvent): void
    {
        self::getLog()->debug('onAuthTokenRenewedEventListener.start', [
            'expires' => $authTokenRenewedEvent->getRenewedToken()->authToken->expires
        ]);

        // update renewed auth token in auth.json.local under key {domain}_{member_id}
        $domain = preg_replace('#^https?://#', '', $authTokenRenewedEvent->getRenewedToken()->domain);
        $key = sprintf('%s_%s', $domain, $authTokenRenewedEvent->getRenewedToken()->memberId);
        $authFileName = __DIR__ . '/../config/auth.json.local';
        if (file_exists($authFileName)) {
            $authData = json_decode(file_get_contents($authFileName), true);
            if (is_array($authData) && isset($authData[$key])) {
                $authData[$key]['auth_token'] = [
                    'access_token' => $authTokenRenewedEvent->getRenewedToken()->authToken->accessToken,
                    'refresh_token' => $authTokenRenewedEvent->getRenewedToken()->authToken->refreshToken,
                    'expires' => $authTokenRenewedEvent->getRenewedToken()->authToken->expires
                ];
                file_put_contents($authFileName, json_encode($authData, JSON_PRETTY_PRINT));
            }
        }

        self::getLog()->debug('onAuthTokenRenewedEventListener.finish');
    }

    /**
     * @throws InvalidArgumentException
     * @throws UnknownScopeCodeException
     * @throws WrongConfigurationException
     */
    public static function getB24Service(?Request $request = null): ServiceBuilder
    {
        // init bitrix24 service builder auth data from request
        if ($request instanceof Request) {
            self::getLog()->debug('getB24Service.authFromRequest');
            return ServiceBuilderFactory::createServiceBuilderFromPlacementRequest(
                $request,
                self::getApplicationProfile(),
                self::getEventDispatcher(),
                self::getLog(),
            );
        }

        // init bitrix24 service builder auth data from saved auth token
        self::getLog()->debug('getB24Service.authFromAuthRepositoryStorage');
        return (new ServiceBuilderFactory(
            self::getEventDispatcher(),
            self::getLog()
        ))->init(
        // load app profile from /config/.env.local to $_ENV and create ApplicationProfile object
            self::getApplicationProfile(),
            // load oauth tokens and portal URL stored in /config/auth.json.local to LocalAppAuth object
            self::getAuthRepository()->getAuth()->getAuthToken(),
            self::getAuthRepository()->getAuth()->getDomainUrl()
        );
    }

    /**
     * Retrieves the authentication repository.
     *
     * @return LocalAppAuthRepositoryInterface The authentication repository used for B24Service.
     */
    protected static function getAuthRepository(): LocalAppAuthRepositoryInterface
    {
        return new AppAuthFileStorage(
            dirname(__DIR__) . '/config/auth.json.local',
            new Filesystem(),
            self::getLog()
        );
    }

    /**
     * Get Application profile from environment variables
     *
     * By default behavioral
     *
     * @throws WrongConfigurationException
     * @throws UnknownScopeCodeException
     * @throws InvalidArgumentException
     */
    protected static function getApplicationProfile(): ApplicationProfile
    {
        self::getLog()->debug('getApplicationProfile.start');
        // you can find list of local apps by this URL
        // https://YOUR-PORTAL-URL.bitrix24.com/devops/list/
        // or see in left menu Developer resources → Integrations → select target local applicatoin

        // load config: application secrets, logging
        self::loadConfigFromEnvFile();

        try {
            $profile = ApplicationProfile::initFromArray($_ENV);
            self::getLog()->debug('getApplicationProfile.finish');
            return $profile;
        } catch (InvalidArgumentException $invalidArgumentException) {
            self::getLog()->error('getApplicationProfile.error',
                [
                    'message' => sprintf('cannot read config from $_ENV: %s', $invalidArgumentException->getMessage()),
                    'trace' => $invalidArgumentException->getTraceAsString()
                ]);
            throw $invalidArgumentException;
        }
    }

    /**
     * Loads configuration from the environment file.
     *
     * @throws WrongConfigurationException if "symfony/dotenv" is not added as a Composer dependency.
     */
    private static function loadConfigFromEnvFile(): void
    {
        static $isConfigLoaded = null;
        if ($isConfigLoaded === null) {
            if (!class_exists(Dotenv::class)) {
                throw new WrongConfigurationException('You need to add "symfony/dotenv" as Composer dependencies.');
            }

            $argvInput = new ArgvInput();
            if (null !== $env = $argvInput->getParameterOption(['--env', '-e'], null, true)) {
                putenv('APP_ENV=' . $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $env);
            }

            if ($argvInput->hasParameterOption('--no-debug', true)) {
                putenv('APP_DEBUG=' . $_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = '0');
            }

            (new Dotenv())->loadEnv(dirname(__DIR__) . self::CONFIG_FILE_NAME);

            $isConfigLoaded = true;
        }
    }

    /**
     * Powiąż numery SMSAPI z domeną Bitrix24 i zapisz do config.json.local
     */
    public static function bindNumbersToDomain(array $numbers, string $domain): void
    {
        $domain = preg_replace('#^https?://#', '', $domain);
        $configFile = dirname(__DIR__) . '/config/config.json.local';
        $config = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];

        foreach ($numbers as $number) {
            $number = trim($number);
            if ($number !== '') {
                $config[$number] = $domain;
                self::getLog()->info('number.bind', ['number' => $number, 'domain' => $domain]);
            }
        }
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Pobiera dane autoryzacyjne na podstawie numeru telefonu.
     * 
     * @param string $phone Numer telefonu
     * @return LocalAppAuth|null Obiekt LocalAppAuth lub null, jeśli nie znaleziono wpisu
     */
    public static function getAuthByPhone(string $phone): ?LocalAppAuth
    {
        $configFile = dirname(__DIR__) . '/config/config.json.local';
        if (!file_exists($configFile)) {
            self::getLog()->warning('getAuthByPhone.configFileNotFound', ['phone' => $phone]);
            return null;
        }
        $config = json_decode(file_get_contents($configFile), true);
        if (!isset($config[$phone])) {
            self::getLog()->warning('getAuthByPhone.domainNotFound', ['phone' => $phone]);
            return null;
        }
        $domain = $config[$phone];
        $authFile = dirname(__DIR__) . '/config/auth.json.local';
        if (!file_exists($authFile)) {
            self::getLog()->warning('getAuthByPhone.authFileNotFound', ['phone' => $phone, 'domain' => $domain]);
            return null;
        }
        $authData = json_decode(file_get_contents($authFile), true);
        if (!is_array($authData)) {
            self::getLog()->error('getAuthByPhone.authDataNotArray', ['authData' => $authData]);
            return null;
        }
        // Szukamy wpisu, gdzie domain_url pasuje do domeny z config.json.local
        foreach ($authData as $key => $data) {
            if (!is_array($data) || !isset($data['domain_url'])) {
                continue; // pomiń jeśli nie jest tablicą lub nie ma klucza domain_url
            }
            if ($data['domain_url'] === $domain) {
                return new LocalAppAuth(
                    new \Bitrix24\SDK\Core\Credentials\AuthToken(
                        $data['auth_token']['access_token'],
                        $data['auth_token']['refresh_token'],
                        $data['auth_token']['expires']
                    ),
                    $data['domain_url'],
                    $data['application_token']
                );
            }
        }
        self::getLog()->warning('getAuthByPhone.authNotFound', ['phone' => $phone, 'domain' => $domain]);
        return null;
    }
}