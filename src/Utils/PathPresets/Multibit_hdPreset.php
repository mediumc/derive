<?php

declare(strict_types=1);

namespace Derive\Utils\PathPresets;

use Derive\Utils\AbstractPathPreset;

class Multibit_hdPreset extends AbstractPathPreset
{
    public function getPath(): string
    {
        return "m/a'/v/x";
    }

    public function getWalletSoftwareName(): string
    {
        return 'Multibit HD';
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
