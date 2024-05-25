<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Utils\PathPresets;
use App\WalletDerive;

class DeriveWrapper
{
    public static function derive(array $params): array
    {
        ini_set('memory_limit', -1);

        try {
            $params = static::collectParams($params);
            $walletDerive = new WalletDerive($params);

            $key = $params['key'] ?? $walletDerive->mnemonicToKey($params['coin'], $params['mnemonic'], $params['key-type'], $params['mnemonic-pw']);
            return ['ok' => true, 'data' => $walletDerive->derive_keys($key)];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private static function collectParams(array $params): array
    {
        $params['cols'] = $arr['cols'] ?? 'all';
        $params['coin'] = $params['coin'] ?? 'btc';

        $params['gen-key'] = isset($params['gen-key']) || isset($params['gen-words']);
        $params['gen-key-all'] = isset($params['gen-key-all']);  // hidden param, for the truly worthy who read the code.
        $key = $params['key'] ?? null;
        $mnemonic = $params['mnemonic'] ?? null;

        if( !$key && !$mnemonic && !$params['gen-key']) {
            throw new Exception( "--key or --mnemonic or --gen-key must be specified." );
        }

        $params['mnemonic-pw'] = $params['mnemonic-pw'] ?? null;

        $params['addr-type'] = $params['addr-type'] ?? 'auto';
        $allowed_addr_type = ['legacy', 'p2sh-segwit', 'bech32', 'auto'];

        if(!in_array($params['addr-type'], $allowed_addr_type)) {
            throw new Exception(sprintf("--addr-type must be one of: [%s]", implode('|', $allowed_addr_type)));
        }

        $type = $params['key-type'] ?? 'x';
        if(!in_array($type, ['x', 'y', 'z'] ) ) {
            throw new Exception( "--key-type must be one of: " . implode(',', ['x', 'y', 'z']));
        }

        $params['key-type'] = $type;

        if(isset($params['path']) && isset($params['preset'])) {
            throw new Exception ("--path and --preset are mutually exclusive");
        }

        if(isset($params['preset'])) {
            $preset = PathPresets::getPreset($params['preset']);
            $params['path'] = $preset->getPath();
        }

        if(isset($params['path'])) {
            if(!preg_match('/[m\d]/', $params['path'][0]) ) {
                throw new Exception( "path parameter is invalid.  It should begin with m or an integer number.");
            }
            if(!preg_match("#^[/\dxcva']*$#", @substr($params['path'], 1) ) ) {
                throw new Exception( "path parameter is invalid.  It should begin with m or an integer and contain only [0-9'/xcva]");
            }
            if(preg_match('#//#', $params['path']) ) {
                throw new Exception( "path parameter is invalid.  It must not contain '//'");
            }
            if(preg_match("#/.*x.*x#", $params['path']) ) {
                throw new Exception( "path parameter is invalid. x may only be used once");
            }
            if(preg_match("#/.*y.*y#", $params['path']) ) {
                throw new Exception( "path parameter is invalid. y may only be used once");
            }
            if(preg_match("#/'#", $params['path']) ) {
                throw new Exception( "path parameter is invalid. single-quote must follow an integer");
            }
            if(preg_match("#''#", $params['path']) ) {
                throw new Exception( "path parameter is invalid. It must not contain \"''\"");
            }
            $params['path'] = rtrim($params['path'], '/');  // trim any trailing path separator.
        } else {
            $params['path'] = 'm';
        }

        $params['bch-format'] = $params['bch-format'] ?? 'cash';
        $params['numderive'] = $params['numderive'] ?? 10;
        $params['alt-extended'] = $params['alt-extended'] ?? null;
        $params['startindex'] = $params['startindex'] ?? 0;
        $params['includeroot'] = isset($params['includeroot'] );
        $params['path-change'] = isset($params['path-change']) ? 1 : 0;
        $params['path-account'] = $params['path-account'] ?? 0;

        $gen_words = (int)($params['gen-words'] ?? 24);
        $allowed = [12, 15, 18, 21, 24, 27, 30, 33, 36, 39, 42, 45, 48];

        if(!in_array($gen_words, $allowed)) {
            throw new Exception("--gen-words must be one of " . implode(', ', $allowed));
        }
        $params['gen-words'] = $gen_words;

        return $params;
    }
}