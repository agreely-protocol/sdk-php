<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Support;

/**
 * Shared by the anchor and key tests: the readable Base Sepolia and Base mainnet registries, a bundle re-pointed at
 * Sepolia, the eth_call answers of the registry reads, and a JSON-RPC endpoint answering each read by its selector.
 */
final class RegistryAnswers
{
    /** Consent anchor times a little after each bundle's grantedAt: an earlier anchor would read the consent as granted after it. */
    public const A1V1_ANCHORED_AT = 1792862400;
    public const CIDV1_ANCHORED_AT = 1792545900;

    /** keccak256("AnchorBatch(bytes32,uint256,uint64)"). */
    public const ANCHOR_BATCH_TOPIC = '0x5254c3f989f0bd166934c5b72923f9347ff20239737b8787c1821858809de984';

    /** The AgreelyRegistry the SDK reads by default on Base Sepolia (84532), EIP-55 checksummed. */
    public const SEPOLIA_REGISTRY = '0xf25D3F8E73648C797Be2CCCD428061f357567b9A';

    /** The AgreelyRegistry the SDK reads by default on Base mainnet (8453), EIP-55 checksummed. */
    public const MAINNET_REGISTRY = '0x6F2cF94045cF0cbDD94338882c34D183575023E9';

    /**
     * The bundle with its issuer delegation naming the readable Sepolia registry instead of the emit-only one the
     * vectors were issued on. The SDK does not verify that delegation, so no signature it checks moves; regenerated
     * bundles will make this unnecessary.
     *
     * @param array<string,mixed> $bundle
     * @return array<string,mixed>
     */
    public static function onReadableRegistry(array $bundle): array
    {
        /** @var array{didDocuments: array{issuer: array{delegation: array{body: array<string,mixed>}}}} $bundle */
        $bundle['didDocuments']['issuer']['delegation']['body']['registry'] = strtolower(self::SEPOLIA_REGISTRY);
        $bundle['didDocuments']['issuer']['delegation']['body']['chainId'] = '84532';
        return $bundle;
    }

    /**
     * The same bundle with its issuer delegation naming the Base mainnet registry on chainId 8453.
     *
     * @param array<string,mixed> $bundle
     * @return array<string,mixed>
     */
    public static function onMainnetRegistry(array $bundle): array
    {
        /** @var array{didDocuments: array{issuer: array{delegation: array{body: array<string,mixed>}}}} $bundle */
        $bundle['didDocuments']['issuer']['delegation']['body']['registry'] = strtolower(self::MAINNET_REGISTRY);
        $bundle['didDocuments']['issuer']['delegation']['body']['chainId'] = '8453';
        return $bundle;
    }

    /** registry(bytes32): merkleRoot, anchoredAt, revokedAt, state, anchoredBlock. */
    public static function anchorWord(string $root, int $anchoredAt, int $revokedAt = 0, int $state = 1, ?int $anchoredBlock = null): string
    {
        $block = $anchoredBlock ?? ($anchoredAt === 0 ? 0 : 53000000);
        return '0x' . (string) preg_replace('/^0x/', '', $root) . self::word($anchoredAt) . self::word($revokedAt)
            . self::word($state) . self::word($block);
    }

    /** rootOf(bytes32): tag, anchoredAt, anchoredBlock, lane. */
    public static function rootWord(int $lane, int $anchoredAt = 1790816400, int $anchoredBlock = 53000000, int $tag = 0): string
    {
        return '0x' . self::word($tag) . self::word($lane === 0 ? 0 : $anchoredAt) . self::word($lane === 0 ? 0 : $anchoredBlock)
            . self::word($lane);
    }

    /** keyOf(bytes32) and companyKeyOf(bytes32,bytes32): validFrom, anchoredAt, retiredAt, voidedAt. */
    public static function keyWord(int $validFrom, int $anchoredAt, int $retiredAt = 0, int $voidedAt = 0): string
    {
        return '0x' . self::word($validFrom) . self::word($anchoredAt) . self::word($retiredAt) . self::word($voidedAt);
    }

    /** A key window, valid and recorded from 2026-10-20, in force for every receipt in vectors/receipts. */
    public static function keyInForce(): string
    {
        return self::keyWord(1792500000, 1792500060);
    }

    /**
     * A citizenKeySalt, a top-level member no signature covers, so a vector bundle can carry one.
     *
     * @param array<string,mixed> $bundle
     * @return array<string,mixed>
     */
    public static function salted(array $bundle): array
    {
        $bundle['citizenKeySalt'] = '0x' . str_repeat('5a', 32);
        return $bundle;
    }

    /**
     * The AnchorBatch(merkleRoot, refCount, timestamp) log the anchoring transaction emitted.
     *
     * @return array{address:string, topics:list<string>, data:string, blockNumber:string, removed:bool}
     */
    public static function anchorBatchLog(string $root, int $refCount, int $block = 53000000, ?string $address = null): array
    {
        return [
            'address' => $address ?? strtolower(self::SEPOLIA_REGISTRY),
            'topics' => [self::ANCHOR_BATCH_TOPIC, '0x' . strtolower((string) preg_replace('/^0x/', '', $root))],
            'data' => '0x' . self::word($refCount) . self::word(0),
            'blockNumber' => '0x' . dechex($block),
            'removed' => false,
        ];
    }

    /**
     * A JSON-RPC endpoint answering each eth_call by its selector and eth_getLogs with `logs` (see ChainStub).
     *
     * @param array<string,callable> $answers registry, rootOf, keyOf, companyKeyOf, getCompanyDid, logs
     */
    public static function chain(array $answers): ChainStub
    {
        return new ChainStub($answers);
    }

    /**
     * A chain answering by selector from a vector case's answers (citizen-key-chain.json, recorded-v4.json,
     * anchor-batch.json); an answer absent is a JSON-RPC error.
     *
     * @param array<string,mixed> $answers
     */
    public static function vectorChain(array $answers): ChainStub
    {
        $out = [];
        $registry = $answers['registry'] ?? null;
        if (is_array($registry)) {
            $out['registry'] = static fn (string $ref): mixed => $registry[$ref] ?? null;
        }
        foreach (['keyOf', 'companyKeyOf', 'getCompanyDid'] as $read) {
            if (array_key_exists($read, $answers)) {
                $answer = $answers[$read];
                $out[$read] = static fn (): mixed => $answer;
            }
        }
        if (array_key_exists('anchorBatchLogs', $answers)) {
            $logs = $answers['anchorBatchLogs'];
            $out['logs'] = static fn (): mixed => $logs;
        }
        return new ChainStub($out);
    }

    private static function word(int $n): string
    {
        return str_pad(dechex($n), 64, '0', STR_PAD_LEFT);
    }
}
