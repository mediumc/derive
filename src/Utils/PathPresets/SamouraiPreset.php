<?php

declare(strict_types=1);

namespace Derive\Utils\PathPresets;

use Derive\Utils\AbstractPathPreset;

class SamouraiPreset extends AbstractPathPreset
{
    public function getPath(): string
    {
        return "m/44'/c'/a'/v/x";
    }

    public function getWalletSoftwareName(): string
    {
        return 'Samourai (p2pkh)';
    }

    public function getWalletSoftwareVersionInfo(): string
    {
        return '?';
    }

    public function getNote(): string
    {
        return 'Bip44';
    }
}
