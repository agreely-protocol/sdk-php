<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Test\Support\RegistryAnswers;
use Agreely\Sdk\Test\Support\VectorCase;
use Agreely\Sdk\Verify\AnchorBatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * vectors/anchor-batch.json: how many refs one consent's anchor wrote, from the ONE AnchorBatch log of its trusted
 * root at the ONE block its refs sit in. An unknown count never reads in force.
 */
final class AnchorBatchTest extends TestCase
{
    public function testTheTopicIsKeccak256OfTheEventSignature(): void
    {
        $this->assertSame(VectorCase::read('anchor-batch.json')['topic0'], AnchorBatch::TOPIC);
        $this->assertSame(RegistryAnswers::ANCHOR_BATCH_TOPIC, AnchorBatch::TOPIC);
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function cases(): array
    {
        $out = [];
        foreach ((array) VectorCase::read('anchor-batch.json')['cases'] as $case) {
            /** @var array<string,mixed> $case */
            $out[VectorCase::str($case['name'])] = [$case];
        }
        return $out;
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('cases')]
    public function testAnchorBatch(array $c): void
    {
        $file = VectorCase::read('anchor-batch.json');
        $docs = VectorCase::read('receipts/did-documents.json');
        /** @var array<string,mixed> $answers */
        $answers = $c['answers'];
        $chain = RegistryAnswers::vectorChain($answers);
        $now = VectorCase::int($file['now']);
        $result = Agreely::verifyReceipt(VectorCase::read(VectorCase::str($file['bundle'])), [
            'resolver' => static fn (string $did): ?array => is_array($docs[$did] ?? null) ? $docs[$did] : null,
            'rpcUrl' => 'https://rpc.test',
            'chainId' => 84532,
            'httpPost' => $chain->post(...),
            'now' => static fn (): int => $now,
        ]);
        /** @var array<string,mixed> $want */
        $want = $c['expect'];
        VectorCase::expectFields($result, $want);
        $getLogs = $chain->callsOf('eth_getLogs');
        if (array_key_exists('getLogsCalls', $want)) {
            $this->assertCount(VectorCase::int($want['getLogsCalls']), $getLogs);
        }
        $at = '0x' . dechex(VectorCase::int($file['block']));
        foreach ($getLogs as $call) {
            /** @var array{address:string, fromBlock:string, toBlock:string, topics:list<string>} $filter */
            $filter = $call['params'][0];
            $this->assertSame(RegistryAnswers::SEPOLIA_REGISTRY, $filter['address']);
            $this->assertSame($at, $filter['fromBlock']);
            $this->assertSame($at, $filter['toBlock']);
            $this->assertCount(2, $filter['topics']);
            $this->assertSame($file['topic0'], $filter['topics'][0]);
        }
    }
}
