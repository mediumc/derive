<?php

declare(strict_types=1);

namespace Derive\Utils;

use Derive\Utils\PathPresets\Bip32Preset;
use Derive\Utils\PathPresets\Bip44Preset;
use Derive\Utils\PathPresets\Bip49Preset;
use Derive\Utils\PathPresets\Bip84Preset;
use Derive\Utils\PathPresets\BitcoincorePreset;
use Derive\Utils\PathPresets\BitherPreset;
use Derive\Utils\PathPresets\BreadwalletPreset;
use Derive\Utils\PathPresets\Coinomi_bech32Preset;
use Derive\Utils\PathPresets\Coinomi_p2shPreset;
use Derive\Utils\PathPresets\CoinomiPreset;
use Derive\Utils\PathPresets\Copay_hardware_multisigPreset;
use Derive\Utils\PathPresets\Copay_legacyPreset;
use Derive\Utils\PathPresets\CopayPreset;
use Derive\Utils\PathPresets\Electrum_multiPreset;
use Derive\Utils\PathPresets\ElectrumPreset;
use Derive\Utils\PathPresets\HivePreset;
use Derive\Utils\PathPresets\JaxxPreset;
use Derive\Utils\PathPresets\LedgerlivePreset;
use Derive\Utils\PathPresets\Multibit_hd_44Preset;
use Derive\Utils\PathPresets\Multibit_hdPreset;
use Derive\Utils\PathPresets\MyceliumPreset;
use Derive\Utils\PathPresets\Samourai_bech32Preset;
use Derive\Utils\PathPresets\Samourai_p2shPreset;
use Derive\Utils\PathPresets\SamouraiPreset;
use Derive\Utils\PathPresets\TrezorPreset;
use Derive\Utils\PathPresets\WasabiPreset;

class PathPresets
{
    private const REGISTRY = [
        'bip32' => Bip32Preset::class,
        'bip44' => Bip44Preset::class,
        'bip49' => Bip49Preset::class,
        'bip84' => Bip84Preset::class,
        'bitcoincore' => BitcoincorePreset::class,
        'bither' => BitherPreset::class,
        'breadwallet' => BreadwalletPreset::class,
        'coinomi' => CoinomiPreset::class,
        'coinomi_p2sh' => Coinomi_p2shPreset::class,
        'coinomi_bech32' => Coinomi_bech32Preset::class,
        'copay' => CopayPreset::class,
        'copay_legacy' => Copay_legacyPreset::class,
        'copay_hardware_multisig' => Copay_hardware_multisigPreset::class,
        'electrum' => ElectrumPreset::class,
        'electrum_multi' => Electrum_multiPreset::class,
        'hive' => HivePreset::class,
        'jaxx' => JaxxPreset::class,
        'ledgerlive' => LedgerlivePreset::class,
        'multibit_hd' => Multibit_hdPreset::class,
        'multibit_hd_44' => Multibit_hd_44Preset::class,
        'mycelium' => MyceliumPreset::class,
        'samourai' => SamouraiPreset::class,
        'samourai_p2sh' => Samourai_p2shPreset::class,
        'samourai_bech32' => Samourai_bech32Preset::class,
        'trezor' => TrezorPreset::class,
        'wasabi' => WasabiPreset::class,
    ];

    public static function getPreset(string $presetId): AbstractPathPreset
    {
        if (!isset(self::REGISTRY[$presetId])) {
            throw new \InvalidArgumentException("Invalid preset identifier: $presetId");
        }

        $class = self::REGISTRY[$presetId];
        return new $class();
    }

    /**
     * @return string[]
     */
    public static function getAllPresetIds(): array
    {
        return array_keys(self::REGISTRY);
    }

    /**
     * @return AbstractPathPreset[]
     */
    public static function getAllPresets(): array
    {
        $presets = [];
        foreach (self::REGISTRY as $id => $class) {
            $presets[] = new $class();
        }
        return $presets;
    }
}
