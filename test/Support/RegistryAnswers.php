<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Support;

/**
 * Shared by the anchor tests: the readable Base Sepolia registry, a bundle re-pointed at it, and the eth_call
 * answers of registry(bytes32) and rootOf(bytes32).
 */
final class RegistryAnswers
{
    /** The AgreelyRegistry the SDK reads by default on Base Sepolia (84532), EIP-55 checksummed. */
    public const SEPOLIA_REGISTRY = '0xf25D3F8E73648C797Be2CCCD428061f357567b9A';

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

    private static function word(int $n): string
    {
        return str_pad(dechex($n), 64, '0', STR_PAD_LEFT);
    }
}
