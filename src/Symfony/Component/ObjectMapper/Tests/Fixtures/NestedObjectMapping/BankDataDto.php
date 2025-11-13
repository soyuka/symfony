<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\ObjectMapper\Tests\Fixtures\NestedObjectMapping;

use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\ReadNestedProperty;

#[Map(target: BankDataResource::class)]
class BankDataDto
{
    #[Map(target: 'iban')]
    public string $iban;

    #[Map(target: 'bic', transform: new ReadNestedProperty('bic'))]
    #[Map(target: 'bankCode', transform: new ReadNestedProperty('code'))]
    #[Map(target: 'bankName', transform: new ReadNestedProperty('name'))]
    public BankDto $bank;
}
