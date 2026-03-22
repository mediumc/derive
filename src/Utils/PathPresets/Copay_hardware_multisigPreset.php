<?php

declare(strict_types=1);

namespace Derive\Utils\PathPresets;

use Derive\Utils\AbstractPathPreset;

class Copay_hardware_multisigPreset extends AbstractPathPreset
{
    public function getPath(): string
    {
        return "m/48'/c'/a'/v/x";
    }

    public function getWalletSoftwareName(): string
    {
        return 'Copay';
    }

    public function getWalletSoftwareVersionInfo(): string
    {
        return '>= 1.5';
    }

    public function getNote(): string
    {
        return 'Hardware multisig wallets';
    }
}
