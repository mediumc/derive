<?php

declare(strict_types=1);

namespace Derive\Utils;

use BitWasp\Bitcoin\Network\Network;
use BitWasp\Bitcoin\Script\ScriptType;
use CoinParams\CoinParams;

class FlexNetwork extends Network
{
    protected $base58PrefixMap;
    protected $bip32PrefixMap;
    protected $bip32ScriptTypeMap;
    protected $signedMessagePrefix;
    protected $bech32PrefixMap;
    protected $p2pMagic;

    public function __construct(string $coin)
    {
        $network = 'main';
        if (str_contains($coin, '-')) {
            [$coin, $network] = explode('-', $coin);
        }

        $params = CoinParams::get_coin_network($coin, $network);
        $prefixes = $params['prefixes'] ?? [];

        // Prefer scripthash2 for coins like LTC that changed p2sh prefix after-launch
        $scripthash = $prefixes['scripthash2'] ?? $prefixes['scripthash'] ?? 0;

        $this->base58PrefixMap = [
            self::BASE58_ADDRESS_P2PKH => self::decToHex((int)($prefixes['public'] ?? 0)),
            self::BASE58_ADDRESS_P2SH => self::decToHex((int)$scripthash),
            self::BASE58_WIF => self::decToHex((int)($prefixes['private'] ?? 0)),
        ];

        $this->bech32PrefixMap = [];
        if (!empty($prefixes['bech32'])) {
            $this->bech32PrefixMap[self::BECH32_PREFIX_SEGWIT] = $prefixes['bech32'];
        }

        $this->bip32PrefixMap = [
            self::BIP32_PREFIX_XPUB => self::trimHexPrefix($prefixes['extended']['xpub']['public'] ?? ''),
            self::BIP32_PREFIX_XPRV => self::trimHexPrefix($prefixes['extended']['xpub']['private'] ?? ''),
        ];

        $this->bip32ScriptTypeMap = [
            self::BIP32_PREFIX_XPUB => ScriptType::P2PKH,
            self::BIP32_PREFIX_XPRV => ScriptType::P2PKH,
        ];

        $this->signedMessagePrefix = $params['message_magic'];
        $this->p2pMagic = self::trimHexPrefix($params['protocol']['magic'] ?? '');
    }

    /**
     * Drops the 0x prefix and pads to even length.
     */
    private static function trimHexPrefix(string $hex): string
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            $hex = substr($hex, 2);
        }
        if (strlen($hex) % 2 !== 0) {
            $hex = '0' . $hex;
        }
        return $hex;
    }

    private static function decToHex(int $dec): string
    {
        $hex = dechex($dec);
        if (strlen($hex) % 2 !== 0) {
            $hex = '0' . $hex;
        }
        return $hex;
    }
}
