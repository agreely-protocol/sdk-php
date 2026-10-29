<?php

declare(strict_types=1);

namespace Agreely\Sdk\Verify;

/**
 * How many refs a consent's own anchor wrote (public/assets/verify/chain.js readAnchoredRefCount): the refCount of the
 * ONE AnchorBatch(bytes32 indexed merkleRoot, uint256 refCount, uint64 timestamp) log of the trusted root, at the ONE
 * block every matching ref sits in, read with a single-block eth_getLogs. The bundle's ref list and its items are
 * signed by no one; this log is. Vector: vectors/anchor-batch.json.
 * TRAP: never sum across blocks. One consent is one transaction; refs at two blocks mean a second batch.
 */
final class AnchorBatch
{
    /** keccak256("AnchorBatch(bytes32,uint256,uint64)"). */
    public const TOPIC = '0x5254c3f989f0bd166934c5b72923f9347ff20239737b8787c1821858809de984';

    private const MAX_SAFE_INTEGER = 9007199254740991;

    /**
     * The one block every matching ref sits in, as the eth_getLogs block tag; null when they sit at more than one.
     *
     * @param list<int> $blocks
     */
    public static function blockTag(array $blocks): ?string
    {
        $distinct = array_values(array_unique($blocks));
        if (count($distinct) !== 1 || $distinct[0] < 0 || $distinct[0] > self::MAX_SAFE_INTEGER) {
            return null;
        }
        return '0x' . dechex($distinct[0]);
    }

    /**
     * The eth_getLogs filter for the root's AnchorBatch log at that one block.
     *
     * @return array{address:string, topics:list<string>, fromBlock:string, toBlock:string}
     */
    public static function filter(string $address, string $root, string $blockTag): array
    {
        return ['address' => $address, 'topics' => [self::TOPIC, strtolower($root)], 'fromBlock' => $blockTag, 'toBlock' => $blockTag];
    }

    /**
     * The refCount of the one log of `$root` from `$address` at `$block`, or null (unknown): a result that is not a
     * list, anything but exactly one such log (a removed one never counts), a data field that is not two words, or a
     * count that is zero or not a safe integer.
     */
    public static function refCount(mixed $result, string $address, string $root, int $block): ?int
    {
        if (!is_array($result) || !array_is_list($result)) {
            return null;
        }
        $topic = strtolower($root);
        $want = strtolower($address);
        $ours = [];
        foreach ($result as $entry) {
            $log = Json::obj($entry);
            $topics = Json::arr($log['topics'] ?? null);
            $blockNumber = $log['blockNumber'] ?? null;
            if (
                ($log['removed'] ?? null) !== true
                && strtolower(Json::str($log['address'] ?? null)) === $want
                && strtolower(Json::str($topics[0] ?? null)) === self::TOPIC
                && strtolower(Json::str($topics[1] ?? null)) === $topic
                && is_string($blockNumber)
                && preg_match('/^0x[0-9a-fA-F]+$/D', $blockNumber) === 1
                && self::hexEquals(substr($blockNumber, 2), $block)
            ) {
                $ours[] = $log;
            }
        }
        if (count($ours) !== 1) {
            return null;
        }
        $raw = Json::str($ours[0]['data'] ?? null);
        $data = strtolower(str_starts_with($raw, '0x') || str_starts_with($raw, '0X') ? substr($raw, 2) : $raw);
        if (preg_match('/^[0-9a-f]{128}$/D', $data) !== 1) {
            return null;
        }
        $count = ltrim(substr($data, 0, 64), '0');
        if ($count === '' || strlen($count) > 14) {
            return null;
        }
        $n = (int) hexdec($count);
        return $n <= self::MAX_SAFE_INTEGER ? $n : null;
    }

    /** Hex digits (any length, any case) equal to a non-negative int, compared without overflow. */
    private static function hexEquals(string $hex, int $n): bool
    {
        $digits = ltrim(strtolower($hex), '0');
        return $digits === dechex($n) || ($digits === '' && $n === 0);
    }
}
