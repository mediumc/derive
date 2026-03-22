<?php

declare(strict_types=1);

namespace Derive\Utils\PathPresets;

use Derive\Utils\AbstractPathPreset;

class BreadwalletPreset extends AbstractPathPreset
{
    public function getPath(): string
    {
        return "m/a'/v/x";
    }

    public function getWalletSoftwareName(): string
    {
        return 'BreadWallet';
    }

    public function getWalletSoftwareVersionInfo(): string
    {
        return '?';
    }

    public function getNote(): string
    {
        return 'Bip32';
    }
}
