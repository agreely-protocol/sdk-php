<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Test\Support\RegistryAnswers;
use Agreely\Sdk\Verify\ReceiptVerification;
use PHPUnit\Framework\TestCase;

/** Whether the consent is still in force: the twin of the TS verifyWithdrawal tests. */
final class WithdrawalTest extends TestCase
{
    private const SEPOLIA = ['rpcUrl' => 'https://rpc.test', 'chainId' => 84532];

    /** @return array<string,mixed> */
    private static function artefact(string $name): array
    {
        /** @var array<string,mixed> $value */
        $value = json_decode((string) file_get_contents(dirname(__DIR__, 2) . "/vectors/receipts/{$name}.json"), true);
        return $value;
    }

    /** @param array<string,mixed> $opts */
    private static function verify(mixed $input, array $opts = []): ReceiptVerification
    {
        $docs = self::artefact('did-documents');
        return Agreely::verifyReceipt($input, ['resolver' => static fn (string $did): ?array => is_array($docs[$did] ?? null) ? $docs[$did] : null] + $opts);
    }

    /** @return array<string,mixed> */
    private static function bundle(): array
    {
        return RegistryAnswers::onReadableRegistry(self::artefact('a1v1.bundle'));
    }

    private static function root(): string
    {
        $root = self::bundle()['merkleRoot'];
        return is_string($root) ? $root : '';
    }

    /** @return list<string> */
    private static function refs(): array
    {
        /** @var list<string> $refs */
        $refs = self::bundle()['consentRefs'];
        return $refs;
    }

    /**
     * Answers each eth_call registry(consentRef) with `$byRef(ref)`; null is a transport failure. `$asked` records the refs.
     *
     * @param callable(string): ?string $byRef
     * @param list<string> $asked
     * @return array<string,mixed>
     */
    private static function registry(callable $byRef, array &$asked): array
    {
        return self::SEPOLIA + ['httpPost' => static function (string $url, string $body) use ($byRef, &$asked): ?string {
            /** @var array{params:list<array{data:string}>} $req */
            $req = json_decode($body, true);
            $ref = '0x' . substr($req['params'][0]['data'], 10);
            $asked[] = $ref;
            $out = $byRef($ref);
            return $out === null ? null : (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $out]);
        }];
    }

    public function testOfflineAGenuineBundleVerifiesAndItsWithdrawalReadsNotCheckedNeverAbsent(): void
    {
        $result = self::verify(self::bundle());
        $this->assertSame('verified', $result->overall);
        $this->assertSame('not_checked', $result->withdrawal);
        $this->assertArrayNotHasKey('withdrawnAt', $result->toArray());
        $this->assertSame('not_checked', $result->toArray()['withdrawal']);
    }

    public function testEveryCellReadInForceUnderTheBoundRootReadsActiveWithNoDate(): void
    {
        $asked = [];
        $result = self::verify(self::bundle(), self::registry(static fn (): string => RegistryAnswers::anchorWord(self::root(), 1792862400), $asked));
        $this->assertSame('pass', $result->consentAnchor);
        $this->assertSame('active', $result->withdrawal);
        $this->assertNull($result->withdrawnAt);
        $this->assertSame(['cells' => 3, 'active' => 3, 'withdrawn' => 0, 'pending' => 0, 'unreadable' => 0], $result->consentAnchorCounts);
        $this->assertCount(3, $asked);
    }

    public function testAWithdrawnCellReadsWithdrawnDatedAtTheLatestWithdrawal(): void
    {
        [$first, $second] = self::refs();
        $asked = [];
        $answer = static fn (string $ref): string => match ($ref) {
            $first => RegistryAnswers::anchorWord(self::root(), 1792862400, 1793000000, 2),
            $second => RegistryAnswers::anchorWord(self::root(), 1792862400, 1793100000, 2),
            default => RegistryAnswers::anchorWord(self::root(), 1792862400),
        };
        $result = self::verify(self::bundle(), self::registry($answer, $asked));
        $this->assertSame('withdrawn', $result->overall);
        $this->assertSame('withdrawn', $result->withdrawal);
        $this->assertSame(1793100000, $result->withdrawnAt);
        $this->assertSame(1793100000, $result->toArray()['withdrawnAt'] ?? null);
    }

    public function testAWithdrawnRecordWithoutItsInstantReadsWithdrawnWithNoDate(): void
    {
        $asked = [];
        $result = self::verify(self::bundle(), self::registry(static fn (): string => RegistryAnswers::anchorWord(self::root(), 1792862400, 0, 2), $asked));
        $this->assertSame('withdrawn', $result->withdrawal);
        $this->assertArrayNotHasKey('withdrawnAt', $result->toArray());
    }

    public function testAnRpcThatDoesNotAnswerLeavesTheWithdrawalNotChecked(): void
    {
        $asked = [];
        $result = self::verify(self::bundle(), self::registry(static fn (): ?string => null, $asked));
        $this->assertSame('unavailable', $result->consentAnchor);
        $this->assertSame('not_checked', $result->withdrawal);
    }

    public function testARecordUnderAnotherRootIsAContradictionNeverActive(): void
    {
        $asked = [];
        $result = self::verify(self::bundle(), self::registry(static fn (): string => RegistryAnswers::anchorWord('0x' . str_repeat('99', 32), 1792862400), $asked));
        $this->assertSame('failed', $result->overall);
        $this->assertSame('not_checked', $result->withdrawal);
    }

    public function testAPaperReceiptReadWithAnRpcUrlLeavesTheWithdrawalNotCheckedAndReadsNoConsentRecord(): void
    {
        $asked = [];
        $result = self::verify(self::artefact('a3v3.receipt'), self::registry(static fn (): string => RegistryAnswers::anchorWord(self::root(), 1792862400, 1793100000, 2), $asked));
        $this->assertSame('verified', $result->overall);
        $this->assertSame('not_checked', $result->withdrawal);
        $this->assertSame([], $asked);
        $this->assertStringContainsString('the withdrawal of a paper or telephone consent is not checked', implode("\n", $result->notes));
    }

    public function testABarePasskeyReceiptAndAMalformedFileLeaveTheWithdrawalNotChecked(): void
    {
        foreach ([self::artefact('a1v1.receipt'), ['version' => '99'], 42] as $input) {
            $asked = [];
            $this->assertSame('not_checked', self::verify($input, self::registry(static fn (): ?string => null, $asked))->withdrawal);
        }
    }

    public function testABundleThatDropsAWithdrawnCellsRefIsNeverReadActive(): void
    {
        [$kept1, $kept2, $dropped] = self::refs();
        $doctored = self::bundle();
        $doctored['consentRefs'] = [$kept1, $kept2];
        $asked = [];
        $answer = static fn (string $ref): string => $ref === $dropped
            ? RegistryAnswers::anchorWord(self::root(), 1792862400, 1793100000, 2)
            : RegistryAnswers::anchorWord(self::root(), 1792862400);
        $result = self::verify($doctored, self::registry($answer, $asked));
        $this->assertNotContains($dropped, $asked);
        $this->assertSame('unavailable', $result->consentAnchor);
        $this->assertSame(['cells' => 3, 'active' => 2, 'withdrawn' => 0, 'pending' => 0, 'unreadable' => 1], $result->consentAnchorCounts);
        $this->assertSame('not_checked', $result->withdrawal);
        $this->assertStringContainsString("names a consentRef for 2 of the receipt's 3 accepted cells", implode("\n", $result->notes));
    }
}
