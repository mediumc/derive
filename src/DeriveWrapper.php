<?php

declare(strict_types=1);

namespace Derive;

use BitWasp\Bitcoin\Base58;
use BitWasp\Buffertools\Buffer;
use CoinParams\CoinParams;
use Derive\Utils\PathPresets;

class DeriveWrapper
{
    private const ALLOWED_ADDR_TYPES = ['legacy', 'p2sh-segwit', 'bech32', 'auto'];
    private const ALLOWED_KEY_TYPES = ['x', 'y', 'z'];
    private const ALLOWED_FORMATS = ['array', 'json', 'jsonpretty'];
    private const ALLOWED_GEN_WORDS = [12, 15, 18, 21, 24, 27, 30, 33, 36, 39, 42, 45, 48];

    public static function derive(
        ?string $key = null,
        ?string $mnemonic = null,
        ?string $mnemonicPw = null,
        string  $coin = 'btc',
        string  $keyType = 'x',
        string  $addrType = 'auto',
        ?string $path = null,
        ?string $preset = null,
        int     $numderive = 10,
        int     $startindex = 0,
        string  $cols = 'all',
        string  $format = 'array',
        string  $bchFormat = 'cash',
        ?string $altExtended = null,
        bool    $includeroot = false,
        bool    $pathChange = false,
        int     $pathAccount = 0,
        bool    $genKey = false,
        int     $genWords = 24,
    ): array|string {
        try {
            if (!in_array($format, self::ALLOWED_FORMATS, true)) {
                throw new \InvalidArgumentException(
                    sprintf('format must be one of: [%s]', implode('|', self::ALLOWED_FORMATS))
                );
            }

            $params = self::validateParams(
                key: $key,
                mnemonic: $mnemonic,
                mnemonicPw: $mnemonicPw,
                coin: $coin,
                keyType: $keyType,
                addrType: $addrType,
                path: $path,
                preset: $preset,
                numderive: $numderive,
                startindex: $startindex,
                cols: $cols,
                bchFormat: $bchFormat,
                altExtended: $altExtended,
                includeroot: $includeroot,
                pathChange: $pathChange,
                pathAccount: $pathAccount,
                genKey: $genKey,
                genWords: $genWords,
            );

            $walletDerive = new WalletDerive($params);

            $derivationKey = $params['key']
                ?? $walletDerive->mnemonicToKey($params['coin'], $params['mnemonic'], $params['keyType'], $params['mnemonicPw']);

            $data = $walletDerive->deriveKeys($derivationKey);
            $data = self::filterColumns($data, $cols);
            $result = ['ok' => true, 'data' => $data];
        } catch (\Exception $e) {
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }

        return self::formatResult($result, $format);
    }

    /**
     * Converts an extended key between types (x/y/z) by replacing the version prefix bytes.
     * E.g. xpub -> zpub, xprv -> zprv, zpub -> ypub, etc.
     *
     * Note: this only changes the serialization format. The underlying key data stays the same.
     * The resulting key will only produce correct addresses if the original key was derived
     * from the appropriate BIP path (BIP44 for x, BIP49 for y, BIP84 for z).
     */
    public static function convertKey(
        string  $key,
        string  $toType,
        string  $coin = 'btc',
        ?string $altExtended = null,
    ): string {
        if (!in_array($toType, self::ALLOWED_KEY_TYPES, true)) {
            throw new \InvalidArgumentException(
                sprintf('toType must be one of: [%s]', implode(',', self::ALLOWED_KEY_TYPES))
            );
        }

        $prefixes = self::getExtendedPrefixesStatic($coin, $altExtended);
        $decoded = Base58::decodeCheck($key);
        $hex = $decoded->getHex();
        $sourcePrefix = '0x' . substr($hex, 0, 8);

        $isPublic = null;
        foreach ($prefixes as $kt => $info) {
            if (!is_array($info)) {
                continue;
            }
            if (strtolower($sourcePrefix) === strtolower($info['public'] ?? '')) {
                $isPublic = true;
                break;
            }
            if (strtolower($sourcePrefix) === strtolower($info['private'] ?? '')) {
                $isPublic = false;
                break;
            }
        }

        if ($isPublic === null) {
            throw new \InvalidArgumentException("Cannot determine key type for the given key and coin '$coin'");
        }

        $targetPrefixKey = $toType . 'pub';
        $targetInfo = $prefixes[$targetPrefixKey] ?? null;

        if (!$targetInfo || empty($targetInfo['public']) || empty($targetInfo['private'])) {
            throw new \InvalidArgumentException("Key type '$toType' is not supported for coin '$coin'");
        }

        $targetPrefix = $isPublic ? $targetInfo['public'] : $targetInfo['private'];
        $targetPrefixHex = str_replace('0x', '', strtolower($targetPrefix));

        $newHex = $targetPrefixHex . substr($hex, 8);

        return Base58::encodeCheck(Buffer::hex($newHex));
    }

    private static function getExtendedPrefixesStatic(string $coin, ?string $altExtended): array
    {
        $chain = str_contains($coin, '-') ? $coin : "$coin-main";
        [$symbol, $net] = explode('-', $chain);
        $nparams = CoinParams::get_coin_network(strtoupper($symbol), strtolower($net));

        if ($altExtended) {
            $val = $nparams['prefixes']['extended']['alternates'][$altExtended] ?? null;
            if (!$val) {
                throw new \InvalidArgumentException("Invalid value for altExtended. Check coin type");
            }
        } else {
            $val = $nparams['prefixes']['extended'] ?? [];
            unset($val['alternates']);
        }

        $val = $val ?: [];
        foreach ($val as $k => $v) {
            if (!is_array($v)) {
                continue;
            }
            if (empty($v['public']) || empty($v['private'])) {
                unset($val[$k]);
            }
        }
        return $val;
    }

    private static function filterColumns(array $rows, string $cols): array
    {
        if ($cols === 'all') {
            return $rows;
        }

        $allowed = array_map('trim', explode(',', $cols));

        return array_map(
            static fn(array $row): array => array_intersect_key($row, array_flip($allowed)),
            $rows,
        );
    }

    private static function formatResult(array $result, string $format): array|string
    {
        return match ($format) {
            'json' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'jsonpretty' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            default => $result,
        };
    }

    private static function validateParams(
        ?string $key,
        ?string $mnemonic,
        ?string $mnemonicPw,
        string  $coin,
        string  $keyType,
        string  $addrType,
        ?string $path,
        ?string $preset,
        int     $numderive,
        int     $startindex,
        string  $cols,
        string  $bchFormat,
        ?string $altExtended,
        bool    $includeroot,
        bool    $pathChange,
        int     $pathAccount,
        bool    $genKey,
        int     $genWords,
    ): array {
        if (!$key && !$mnemonic && !$genKey) {
            throw new \InvalidArgumentException('key, mnemonic, or genKey must be specified.');
        }

        if (!in_array($addrType, self::ALLOWED_ADDR_TYPES, true)) {
            throw new \InvalidArgumentException(
                sprintf('addrType must be one of: [%s]', implode('|', self::ALLOWED_ADDR_TYPES))
            );
        }

        if (!in_array($keyType, self::ALLOWED_KEY_TYPES, true)) {
            throw new \InvalidArgumentException(
                sprintf('keyType must be one of: [%s]', implode(',', self::ALLOWED_KEY_TYPES))
            );
        }

        if ($path !== null && $preset !== null) {
            throw new \InvalidArgumentException('path and preset are mutually exclusive');
        }

        $resolvedPath = $path;
        if ($preset !== null) {
            $presetObj = PathPresets::getPreset($preset);
            $resolvedPath = $presetObj->getPath();
        }

        if ($resolvedPath !== null) {
            self::validatePath($resolvedPath);
            $resolvedPath = rtrim($resolvedPath, '/');
        } else {
            $resolvedPath = 'm';
        }

        if (!in_array($genWords, self::ALLOWED_GEN_WORDS, true)) {
            throw new \InvalidArgumentException(
                'genWords must be one of: ' . implode(', ', self::ALLOWED_GEN_WORDS)
            );
        }

        return [
            'key' => $key,
            'mnemonic' => $mnemonic,
            'mnemonicPw' => $mnemonicPw,
            'coin' => $coin,
            'keyType' => $keyType,
            'addrType' => $addrType,
            'path' => $resolvedPath,
            'numderive' => $numderive,
            'startindex' => $startindex,
            'cols' => $cols,
            'bchFormat' => $bchFormat,
            'altExtended' => $altExtended,
            'includeroot' => $includeroot,
            'pathChange' => $pathChange ? 1 : 0,
            'pathAccount' => $pathAccount,
            'genKey' => $genKey,
            'genWords' => $genWords,
        ];
    }

    private static function validatePath(string $path): void
    {
        if (!preg_match('/^[m\d]/', $path)) {
            throw new \InvalidArgumentException('path is invalid. It should begin with m or an integer number.');
        }
        if (!preg_match("#^[/\dxcva']*$#", substr($path, 1))) {
            throw new \InvalidArgumentException("path is invalid. It should contain only [0-9'/xcva]");
        }
        if (str_contains($path, '//')) {
            throw new \InvalidArgumentException("path is invalid. It must not contain '//'");
        }
        if (preg_match("#/.*x.*x#", $path)) {
            throw new \InvalidArgumentException('path is invalid. x may only be used once');
        }
        if (preg_match("#/.*y.*y#", $path)) {
            throw new \InvalidArgumentException('path is invalid. y may only be used once');
        }
        if (preg_match("#/'#", $path)) {
            throw new \InvalidArgumentException('path is invalid. single-quote must follow an integer');
        }
        if (str_contains($path, "''")) {
            throw new \InvalidArgumentException("path is invalid. It must not contain \"''\"");
        }
    }
}
