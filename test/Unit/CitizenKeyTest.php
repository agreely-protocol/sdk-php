<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Crypto\Canonicalizer;
use Agreely\Sdk\Types\Wire;
use Agreely\Sdk\Verify\ReceiptVerification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The citizen-key rule of the web verifier (citizen-key.js), the offer window, and a partial consent-anchor read.
 * The crafted citizen documents are QA's (qa-ver1r3): one bundle, re-signed on the citizen side with a passkey of
 * QA's own, read against each document the citizen tier might serve. The golden cases are the TS suite's, byte for byte.
 */
final class CitizenKeyTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function vector(string $rel): array
    {
        /** @var array<string,mixed> $value */
        $value = json_decode((string) file_get_contents(dirname(__DIR__, 2) . "/vectors/{$rel}"), true);
        return $value;
    }

    /** @return array{issuer: array<string,mixed>, citizen: array<string,array<string,mixed>>} */
    private static function docs(): array
    {
        /** @var array{didDocuments:string} $ck */
        $ck = self::vector('vectors.json')['citizenKey'];
        /** @var array{issuer: array<string,mixed>, citizen: array<string,array<string,mixed>>} $docs */
        $docs = self::vector($ck['didDocuments']);
        return $docs;
    }

    /** The documents as json_decode() returns them, so a JSON object keyed "0" or {} stays an object. */
    private static function objectDocs(): \stdClass
    {
        /** @var array{didDocuments:string} $ck */
        $ck = self::vector('vectors.json')['citizenKey'];
        $docs = json_decode((string) file_get_contents(dirname(__DIR__, 2) . "/vectors/{$ck['didDocuments']}"));
        self::assertInstanceOf(\stdClass::class, $docs);
        return $docs;
    }

    private static function objectDoc(string $name): mixed
    {
        $docs = self::objectDocs();
        $citizen = $docs->citizen;
        self::assertInstanceOf(\stdClass::class, $citizen);
        return $citizen->{$name} ?? null;
    }

    private static function vectorText(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . "/vectors/{$rel}");
    }

    /** @param array<string,mixed>|null $issuerDoc */
    private static function resolverFor(?string $citizenDocument, ?array $issuerDoc = null): callable
    {
        $docs = self::docs();
        $issuer = $issuerDoc ?? $docs['issuer'];
        $issuerDid = Wire::str($docs['issuer']['id']);
        return static fn (string $did): ?array => $did === $issuerDid
            ? $issuer
            : ($citizenDocument === null ? null : ($docs['citizen'][$citizenDocument] ?? null));
    }

    /** @return iterable<string,array{0:array<string,mixed>}> */
    public static function goldenCases(): iterable
    {
        /** @var array{cases: list<array<string,mixed>>} $ck */
        $ck = self::vector('vectors.json')['citizenKey'];
        foreach ($ck['cases'] as $case) {
            yield Wire::str($case['name']) => [$case];
        }
    }

    /**
     * The bundle as its JSON text and the documents as json_decode() objects: the doors that keep a JSON object apart
     * from a list (x01, x02, x03).
     *
     * @param array<string,mixed> $case
     */
    #[DataProvider('goldenCases')]
    public function testTheCitizenKeyRuleMatchesTheGoldenVectorByteForByte(array $case): void
    {
        $doc = $case['citizenDocument'];
        $docs = self::objectDocs();
        $issuer = $docs->issuer;
        self::assertInstanceOf(\stdClass::class, $issuer);
        $citizen = is_string($doc) ? self::objectDoc($doc) : null;
        $result = Agreely::verifyReceipt(self::vectorText(Wire::str($case['file'])), [
            'resolver' => static fn (string $did): mixed => $did === $issuer->id ? $issuer : $citizen,
        ]);
        $canon = new Canonicalizer();
        $this->assertSame($canon->encode($case['expect']), $canon->encode($result->toArray()));
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function unconfirmed(): iterable
    {
        yield 'd02 not under authentication' => ['d02-notauth', 'failed'];
        yield 'd03 another identity' => ['d03-idother', 'unavailable'];
        yield 'd06 window closes at grantedAt' => ['d06-until-eq', 'failed'];
        yield 'd07 window opens after grantedAt' => ['d07-from-after', 'failed'];
        yield 'd10 method of another DID' => ['d10-otherbase', 'failed'];
        yield 'd16 no validFrom' => ['d16-nofrom', 'failed'];
        yield 'd18 retired before grantedAt' => ['d18-rotated-before', 'failed'];
    }

    #[DataProvider('unconfirmed')]
    public function testAKeyTheDocumentDoesNotConfirmIsNeverVerified(string $doc, string $overall): void
    {
        $result = Agreely::verifyReceipt(self::vector('receipts/ck-thz.bundle.json'), ['resolver' => self::resolverFor($doc)]);
        $this->assertSame($overall, $result->overall);
        $this->assertSame($overall === 'failed' ? 'fail' : 'unavailable', $result->citizenAssertion);
        $this->assertDoesNotMatchRegularExpression('/verified and bound|lists that key under authentication/', implode("\n", $result->notes));
    }

    public function testTheSameBundleVerifiesOnceTheDocumentListsTheKeyInItsWindow(): void
    {
        $result = Agreely::verifyReceipt(self::vector('receipts/ck-thz.bundle.json'), ['resolver' => self::resolverFor('d01-positive')]);
        $this->assertSame('verified', $result->overall);
        $this->assertStringContainsString('lists that key under authentication, valid at grantedAt', implode("\n", $result->notes));
    }

    public function testAGrantedAtThePasskeySignedButThatIsNotADateNeverConfirmsTheKey(): void
    {
        $result = Agreely::verifyReceipt(self::vector('receipts/ck-w7-badgranted.bundle.json'), ['resolver' => self::resolverFor('w7-badgranted')]);
        $this->assertSame('unavailable', $result->citizenAssertion);
        $this->assertSame('failed', $result->overall);
        $this->assertStringContainsString('Receipt integrity FAILED (offer-window-unreadable)', implode("\n", $result->notes));

        $result = self::withOffer(['createdAt' => null], 'receipts/ck-w7-badgranted.bundle.json', 'w7-badgranted');
        $this->assertSame('fail', $result->citizenAssertion);
        $this->assertStringContainsString('grantedAt is not a readable date, so no validity window', implode("\n", $result->notes));
    }

    /** @return iterable<string,array{0:string,1:string,2:string}> */
    public static function documentsServedAsText(): iterable
    {
        yield 'x01 authentication keyed "0"' => ['x01-auth-objmap', 'failed', 'fail'];
        yield 'x02 verificationMethod keyed "0"' => ['x02-vm-objmap', 'unavailable', 'unavailable'];
        yield 'x03 verificationMethod {}' => ['x03-vm-emptyobj', 'unavailable', 'unavailable'];
    }

    #[DataProvider('documentsServedAsText')]
    public function testACitizenDocumentFetchedAsTextKeepsAJsonObjectApartFromAList(string $doc, string $overall, string $citizen): void
    {
        $issuerText = (string) json_encode(self::objectDocs()->issuer);
        $citizenText = (string) json_encode(self::objectDoc($doc));
        $result = Agreely::verifyReceipt(self::vector('receipts/ck-thz.bundle.json'), [
            'httpGet' => static fn (string $url): ?string => match (true) {
                $url === 'https://app.agreely.ca/c/demo-co-7d9d75/did.json' => $issuerText,
                str_starts_with($url, 'https://my.agreely.ca/did/') => $citizenText,
                default => null,
            },
        ]);
        $this->assertSame('pass', $result->companySignature);
        $this->assertSame($citizen, $result->citizenAssertion);
        $this->assertSame($overall, $result->overall);
        $this->assertDoesNotMatchRegularExpression('/verified and bound|lists that key under authentication/', implode("\n", $result->notes));
    }

    /** @return iterable<string,array{0:callable(\stdClass):void,1:string,2:string}> */
    public static function bundlesWithAnObjectForAList(): iterable
    {
        yield 'receipt.proof keyed "0", "1"' => [
            static function (\stdClass $b): void {
                self::assertInstanceOf(\stdClass::class, $b->receipt);
                self::assertIsArray($b->receipt->proof);
                $b->receipt->proof = (object) $b->receipt->proof;
            },
            'companySignature',
            'fail',
        ];
        yield 'consent.items keyed "0"' => [
            static function (\stdClass $b): void {
                self::assertInstanceOf(\stdClass::class, $b->receipt);
                self::assertInstanceOf(\stdClass::class, $b->receipt->credentialSubject);
                $consent = $b->receipt->credentialSubject->consent;
                self::assertInstanceOf(\stdClass::class, $consent);
                self::assertIsArray($consent->items);
                $consent->items = (object) $consent->items;
            },
            'receiptIntegrity',
            'fail',
        ];
    }

    /** @param callable(\stdClass):void $mutate */
    #[DataProvider('bundlesWithAnObjectForAList')]
    public function testABundleWhoseListIsAJsonObjectFailsAsTextAndAsDecodedObjects(callable $mutate, string $check, string $status): void
    {
        $bundle = json_decode(self::vectorText('receipts/ck-thz.bundle.json'));
        self::assertInstanceOf(\stdClass::class, $bundle);
        $mutate($bundle);
        $text = (string) json_encode($bundle);
        foreach ([$text, json_decode($text)] as $input) {
            $result = Agreely::verifyReceipt($input, ['resolver' => self::resolverFor('d01-positive')]);
            $this->assertSame($status, $result->toArray()[$check]);
            $this->assertSame('failed', $result->overall);
        }
    }

    public function testABackdatedReceiptLeavesThePersonsKeyNotEvaluatedAndSaysOneThingOfItsIntegrity(): void
    {
        $result = Agreely::verifyReceipt(self::vector('receipts/ck-b1-backdate.bundle.json'), ['resolver' => self::resolverFor('b1-backdate')]);
        $this->assertSame('pass', $result->companySignature);
        $this->assertSame('fail', $result->receiptIntegrity);
        $this->assertSame('unavailable', $result->citizenAssertion);
        $this->assertSame('failed', $result->overall);
        $notes = implode("\n", $result->notes);
        $this->assertStringContainsString("so the person's key is not evaluated at that date", $notes);
        $this->assertDoesNotMatchRegularExpression('/verified and bound|lists that key under authentication/', $notes);
        $this->assertCount(1, array_filter($result->notes, static fn (string $n): bool => str_starts_with($n, 'Receipt integrity')));
    }

    /** @return iterable<string,array{0:string}> */
    public static function dotOnlySubjects(): iterable
    {
        yield 'two dots' => ['did:agreely:citizen:..'];
        yield 'one dot' => ['did:agreely:citizen:.'];
    }

    #[DataProvider('dotOnlySubjects')]
    public function testASubjectWhoseSuffixIsOnlyDotsIsNotLookedUp(string $subject): void
    {
        $bundle = self::vector('receipts/ck-thz.bundle.json');
        /** @var array<string,mixed> $receipt */
        $receipt = $bundle['receipt'];
        /** @var array<string,mixed> $subjectBlock */
        $subjectBlock = $receipt['credentialSubject'];
        $subjectBlock['id'] = $subject;
        $receipt['credentialSubject'] = $subjectBlock;
        $bundle['receipt'] = $receipt;
        $asked = [];
        $resolve = self::resolverFor('d01-positive');
        $result = Agreely::verifyReceipt($bundle, ['resolver' => static function (string $did) use (&$asked, $resolve): mixed {
            $asked[] = $did;
            return $resolve($did);
        }]);
        $this->assertSame('unavailable', $result->citizenAssertion);
        $this->assertNotContains($subject, $asked);
        $this->assertStringContainsString('is not an Agreely citizen DID', implode("\n", $result->notes));
    }

    /**
     * grantedAt of the crafted bundle is 2026-10-25T15:30:41Z. The offer is re-signed by a throwaway issuer under the
     * same DID, which leaves the citizen challenge (it does not cover the offer's dates) intact.
     *
     * @param array<string,string|null> $dates
     */
    private static function withOffer(array $dates, string $file = 'receipts/ck-thz.bundle.json', string $doc = 'd01-positive'): ReceiptVerification
    {
        $bundle = self::vector($file);
        /** @var array<string,mixed> $offer */
        $offer = $bundle['signedOffer'];
        foreach ($dates as $k => $v) {
            if ($v === null) {
                unset($offer[$k]);
            } else {
                $offer[$k] = $v;
            }
        }
        $company = new TestIssuer(Wire::str(self::docs()['issuer']['id']));
        /** @var array<string,mixed> $receipt */
        $receipt = $bundle['receipt'];
        /** @var list<mixed> $proof */
        $proof = $receipt['proof'];
        $receipt['proof'] = [$company->proofOver($offer), $proof[1]];
        $bundle['receipt'] = $receipt;
        $bundle['signedOffer'] = $offer;
        return Agreely::verifyReceipt($bundle, ['resolver' => self::resolverFor($doc, $company->didDoc)]);
    }

    /** @return iterable<string,array{0:array<string,string|null>}> */
    public static function offersInWindow(): iterable
    {
        yield 'created at grantedAt' => [['createdAt' => '2026-10-25T15:30:41Z']];
        yield 'created within the same second' => [['createdAt' => '2026-10-25T15:30:41.900Z']];
        yield 'expiring at grantedAt' => [['expiresAt' => '2026-10-25T15:30:41Z']];
        yield 'expiring in the same second' => [['expiresAt' => '2026-10-25T15:30:41.100Z']];
        yield 'dated in another zone' => [['createdAt' => '2026-10-25T11:30:41-04:00']];
        yield 'without createdAt' => [['createdAt' => null]];
        yield 'without expiresAt' => [['expiresAt' => null]];
    }

    /** @param array<string,string|null> $dates */
    #[DataProvider('offersInWindow')]
    public function testAnOfferWhoseWindowHoldsGrantedAtVerifies(array $dates): void
    {
        $result = self::withOffer($dates);
        $this->assertSame('pass', $result->receiptIntegrity);
        $this->assertSame('verified', $result->overall);
    }

    /** @return iterable<string,array{0:array<string,string|null>,1:string}> */
    public static function offersOutOfWindow(): iterable
    {
        yield 'created one second after grantedAt' => [['createdAt' => '2026-10-25T15:30:42Z'], 'granted-outside-offer-window'];
        yield 'expired one second before grantedAt' => [['expiresAt' => '2026-10-25T15:30:40Z'], 'granted-outside-offer-window'];
        yield 'unreadable createdAt' => [['createdAt' => 'yesterday'], 'offer-window-unreadable'];
        yield 'impossible expiresAt' => [['expiresAt' => '2026-02-30T00:00:00Z'], 'offer-window-unreadable'];
    }

    /** @param array<string,string|null> $dates */
    #[DataProvider('offersOutOfWindow')]
    public function testAnOfferWhoseWindowMissesGrantedAtFails(array $dates, string $code): void
    {
        $result = self::withOffer($dates);
        $this->assertSame('fail', $result->receiptIntegrity);
        $this->assertSame('pass', $result->cellLabelBinding);
        $this->assertSame('unavailable', $result->citizenAssertion);
        $this->assertSame('failed', $result->overall);
        $this->assertStringContainsString("Receipt integrity FAILED ({$code})", implode("\n", $result->notes));
    }

    public function testTheWindowIsNotJudgedWhenTheCompanySignatureDoesNotHold(): void
    {
        $docs = self::docs();
        $issuerDid = Wire::str($docs['issuer']['id']);
        $citizen = $docs['citizen']['b1-backdate'];
        $result = Agreely::verifyReceipt(self::vector('receipts/ck-b1-backdate.bundle.json'), [
            'resolver' => static fn (string $did): ?array => $did === $issuerDid ? null : $citizen,
        ]);
        $this->assertSame('unavailable', $result->companySignature);
        $this->assertSame('pass', $result->receiptIntegrity);
        $this->assertSame('unavailable', $result->overall);
    }

    public function testAPartialConsentAnchorReadIsUnavailableNeverPass(): void
    {
        $bundle = self::vector('receipts/a1v1.bundle.json');
        $docs = self::vector('receipts/did-documents.json');
        $root = substr(Wire::str($bundle['merkleRoot']), 2);
        $word = static fn (int $n): string => str_pad(dechex($n), 64, '0', STR_PAD_LEFT);
        $calls = 0;
        $result = Agreely::verifyReceipt($bundle, [
            'resolver' => static fn (string $did): ?array => is_array($docs[$did] ?? null) ? $docs[$did] : null,
            'rpcUrl' => 'https://rpc.test',
            'httpPost' => static function (string $url, string $body) use (&$calls, $root, $word): ?string {
                /** @var array{method:string} $req */
                $req = json_decode($body, true);
                if ($req['method'] !== 'eth_call') {
                    return (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);
                }
                $calls++;
                if ($calls > 1) {
                    return null;
                }
                $record = '0x' . $root . $word(1760000000) . $word(0) . $word(1);
                return (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $record]);
            },
        ]);
        $this->assertSame(3, $calls);
        $this->assertSame('unavailable', $result->consentAnchor);
        $this->assertStringContainsString('1 active, 0 withdrawn, 0 not anchored yet, 2 unreadable', implode("\n", $result->notes));
    }

    /** @return iterable<string,array{0:int,1:string,2:string}> */
    public static function anchorReads(): iterable
    {
        yield 'one cell active, the others not anchored yet' => [1, 'unavailable', '1 active, 0 withdrawn, 2 not anchored yet, 0 unreadable'];
        yield 'every cell active' => [3, 'pass', '3 active, 0 withdrawn, 0 not anchored yet, 0 unreadable'];
    }

    #[DataProvider('anchorReads')]
    public function testTheConsentAnchorPassesOnlyOnceEveryCellIsRecordedUnderTheRoot(int $anchored, string $status, string $counts): void
    {
        $bundle = self::vector('receipts/a1v1.bundle.json');
        $docs = self::vector('receipts/did-documents.json');
        $root = substr(Wire::str($bundle['merkleRoot']), 2);
        $word = static fn (int $n): string => str_pad(dechex($n), 64, '0', STR_PAD_LEFT);
        $calls = 0;
        $result = Agreely::verifyReceipt($bundle, [
            'resolver' => static fn (string $did): ?array => is_array($docs[$did] ?? null) ? $docs[$did] : null,
            'rpcUrl' => 'https://rpc.test',
            'httpPost' => static function (string $url, string $body) use (&$calls, $anchored, $root, $word): string {
                /** @var array{method:string} $req */
                $req = json_decode($body, true);
                if ($req['method'] !== 'eth_call') {
                    return (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);
                }
                $calls++;
                $record = $calls <= $anchored
                    ? '0x' . $root . $word(1760000000) . $word(0) . $word(1)
                    : '0x' . str_repeat('0', 64) . $word(0) . $word(0) . $word(0);
                return (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $record]);
            },
        ]);
        $this->assertSame($status, $result->consentAnchor);
        $this->assertStringContainsString($counts, implode("\n", $result->notes));
    }
}
