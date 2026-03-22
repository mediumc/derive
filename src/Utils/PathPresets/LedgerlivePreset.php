<?php

declare(strict_types=1);

namespace Derive\Utils\PathPresets;

use Derive\Utils\AbstractPathPreset;

class LedgerlivePreset extends AbstractPathPreset
{
    public function getPath(): string
    {
        return "m/44'/c'/x'/v/0";
    }

    public function getWalletSoftwareName(): string
    {
        return 'Ledger Live';
    }

    public function getWalletSoftwareVersionInfo(): string
    {
        return 'All versions';
    }

    public function getNote(): string
    {
        return 'Non-standard Bip44';
    }
}
