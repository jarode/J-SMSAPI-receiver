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

namespace Bitrix24\SDK\Services\UserConsent;

use Bitrix24\SDK\Attributes\ApiServiceBuilderMetadata;
use Bitrix24\SDK\Core\Credentials\Scope;
use Bitrix24\SDK\Services\AbstractServiceBuilder;
use Bitrix24\SDK\Services\UserConsent\Service\UserConsent;
use Bitrix24\SDK\Services\UserConsent\Service\UserConsentAgreement;
#[ApiServiceBuilderMetadata(new Scope(['userconsent']))]
class UserConsentServiceBuilder extends AbstractServiceBuilder
{
    /**
     * get user consent agreement service
     */
    public function UserConsentAgreement(): UserConsentAgreement
    {
        if (!isset($this->serviceCache[__METHOD__])) {
            $this->serviceCache[__METHOD__] = new UserConsentAgreement($this->core, $this->log);
        }

        return $this->serviceCache[__METHOD__];
    }

    /**
     * get user consent service
     */
    public function UserConsent(): UserConsent
    {
        if (!isset($this->serviceCache[__METHOD__])) {
            $this->serviceCache[__METHOD__] = new UserConsent($this->core, $this->log);
        }

        return $this->serviceCache[__METHOD__];
    }
}