<?php

declare(strict_types=1);

namespace Derive\Utils\PathPresets;

use Derive\Utils\AbstractPathPreset;

class BitcoincorePreset extends AbstractPathPreset
{
    public function getPath(): string
    {
        return "m/a'/v'/x'";
    }

    public function getWalletSoftwareName(): string
    {
        return 'Bitcoin Core';
    }

    public function getWalletSoftwareVersionInfo(): string
    {
        return 'v0.13 and above.';
    }

    public function getNote(): string
    {
        return 'Bip32 fully hardened';
    }
}
