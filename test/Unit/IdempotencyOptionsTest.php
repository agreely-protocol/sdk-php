<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\Test\Support\MockHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every write that takes an Idempotency-Key reads it from a CLOSED $options array. A
 * misspelt option must never let the SDK generate a fresh key in its place: a retry under
 * a fresh key is a second request (a second email, a second hold, a second declaration).
 */
final class IdempotencyOptionsTest extends TestCase
{
    private const REF = '0x9f2c4e0a7b1d3c5e8f6a2b4c6d8e0f1a3b5c7d9e1f2a4b6c8d0e2f4a6b8c0d2e';
    private const UUID = '6f1c2d3e-4a5b-4c6d-8e7f-901234567890';
    private const SHA = '0xffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    private function client(MockHttpClient $http): Agreely
    {
        return new Agreely(['apiKey' => 'k', 'baseUrl' => 'https://api.test', 'httpClient' => $http]);
    }

    /**
     * Each keyed write, called with a valid input and the given options.
     *
     * @return array<string,callable(Agreely,array<string,mixed>):mixed>
     */
    private static function writes(): array
    {
        $answers = [['category' => 'Courriel', 'purpose' => 'Infolettre', 'answer' => 'yes']];
        return [
            'consentRequests.create' => static fn (Agreely $a, array $o) => $a->consentRequests()->create([
                'customerId' => 'c', 'recipientEmail' => 'r@example.com', 'consentDocumentId' => self::UUID,
                'validUntil' => '2031-01-01',
            ], self::asTyped($o)),
            'manualConsents.record' => static fn (Agreely $a, array $o) => $a->manualConsents()->record([
                'customerId' => 'c', 'documentVersionId' => self::UUID, 'effectiveDate' => '2026-10-01',
                'validUntil' => '2031-01-01', 'items' => [], 'evidence' => ['pdfSha256' => self::SHA],
            ], self::asTyped($o)),
            'manualConsents.createConsentSheet' => static fn (Agreely $a, array $o)
                => $a->manualConsents()->createConsentSheet('c', ['documentVersionId' => self::UUID], self::asTyped($o)),
            'verbalConsents.record' => static fn (Agreely $a, array $o) => $a->verbalConsents()->record([
                'customerId' => 'c', 'documentVersionId' => self::UUID, 'answers' => $answers,
                'obtainedAt' => new \DateTimeImmutable('now'), 'obtainedBy' => 'Julie', 'scriptVersion' => 'v1',
                'respondent' => ['consentedBy' => 'self'], 'validUntil' => '2027-10-01',
            ], self::asTyped($o)),
            'verbalConsents.confirmWithPaper' => static fn (Agreely $a, array $o) => $a->verbalConsents()->confirmWithPaper(
                self::UUID,
                ['signedAt' => new \DateTimeImmutable('now'), 'answers' => $answers, 'evidence' => ['pdfSha256' => self::SHA]],
                self::asTyped($o),
            ),
            'withdrawals.record' => static fn (Agreely $a, array $o)
                => $a->withdrawals()->record('c', self::REF, ['channel' => 'phone', 'operator' => 'agent-1'], self::asTyped($o)),
            'retention.placeHold' => static fn (Agreely $a, array $o)
                => $a->retention()->placeHold('c', ['ground' => 'rights_request'], self::asTyped($o)),
            'retention.releaseHold' => static fn (Agreely $a, array $o)
                => $a->retention()->releaseHold('c', self::UUID, ['reason' => 'Recours épuisés.'], self::asTyped($o)),
            'retention.declarePurge' => static fn (Agreely $a, array $o) => $a->retention()->declarePurge(self::UUID, [
                'ranAt' => '2026-09-25T03:00:00-04:00', 'recordsAffected' => 12, 'method' => 'destroyed',
                'coveredFrom' => '2024-01-01', 'coveredUntil' => '2024-06-30', 'hostSystem' => 'billing',
                'hostCategory' => 'invoices',
            ], self::asTyped($o)),
            'retention.declareSweep' => static fn (Agreely $a, array $o) => $a->retention()->declareSweep(
                self::UUID,
                ['sweptAt' => new \DateTimeImmutable('now'), 'hostSystem' => 'billing'],
                self::asTyped($o),
            ),
        ];
    }

    /**
     * The options exactly as a caller typed them, misspellings included: the point of these
     * tests is a shape the method's type forbids, so it is passed through as the type says.
     *
     * @param array<mixed> $options
     * @return array{idempotencyKey:string}
     */
    private static function asTyped(array $options): array
    {
        /** @var array{idempotencyKey:string} $options */
        return $options;
    }

    /** @return array<string,array{string}> one case per keyed write */
    public static function keyedWrites(): array
    {
        return array_map(static fn (string $name): array => [$name], array_combine(array_keys(self::writes()), array_keys(self::writes())));
    }

    #[DataProvider('keyedWrites')]
    public function testAMisspeltOptionIsRefusedBeforeTheCall(string $name): void
    {
        $write = self::writes()[$name];
        foreach ([['idempotency_key' => 'order-4471'], ['idempotencyKey' => 'order-4471', 'idempotencyKy' => 'x']] as $options) {
            $http = new MockHttpClient([MockHttpClient::json(201, [])]);
            try {
                $write($this->client($http), $options);
                $this->fail("{$name} accepted " . json_encode($options));
            } catch (AgreelyConfigError) {
                $this->assertCount(0, $http->calls, "{$name} sent nothing");
            }
        }
    }

    #[DataProvider('keyedWrites')]
    public function testTheCorrectlySpeltKeyIsSentAsTheHeader(string $name): void
    {
        $http = new MockHttpClient([MockHttpClient::json(201, [])]);
        self::writes()[$name]($this->client($http), ['idempotencyKey' => 'order-4471']);
        $this->assertSame('order-4471', $http->calls[0]->header('Idempotency-Key'), $name);
    }

    public function testAConsentRequestKeyIsCheckedBeforeTheEmail(): void
    {
        $writes = self::writes();
        foreach (['', 'has space', "k\r\nX-Injected: 1", str_repeat('k', 256), 'caf' . "\u{e9}", 4471] as $bad) {
            $http = new MockHttpClient([MockHttpClient::json(201, [])]);
            try {
                $writes['consentRequests.create']($this->client($http), ['idempotencyKey' => $bad]);
                $this->fail('expected AgreelyConfigError for ' . json_encode($bad));
            } catch (AgreelyConfigError $e) {
                $this->assertStringContainsString('printable ASCII', $e->getMessage());
                $this->assertCount(0, $http->calls);
            }
        }
    }

    public function testAConsentRequestInputIsClosedExceptTheLegacyItemsList(): void
    {
        $base = [
            'customerId' => 'c', 'recipientEmail' => 'r@example.com', 'consentDocumentId' => self::UUID,
            'validUntil' => '2031-01-01',
        ];
        foreach (['recipientName' => 'Marie', 'consentDocumentID' => self::UUID, 'idempotencyKey' => 'k'] as $member => $value) {
            $http = new MockHttpClient([MockHttpClient::json(201, [])]);
            try {
                $this->client($http)->consentRequests()->create($base + [$member => $value]);
                $this->fail("expected AgreelyConfigError for {$member}");
            } catch (AgreelyConfigError) {
                $this->assertCount(0, $http->calls, "{$member} sent nothing");
            }
        }

        // The golden vectors pin a legacy items list as accepted and DROPPED, never sent.
        $http = new MockHttpClient([MockHttpClient::json(201, [])]);
        $this->client($http)->consentRequests()->create($base + ['items' => ['0xcat']]);
        $this->assertNotNull($http->calls[0]->body);
        $this->assertArrayNotHasKey('items', $http->calls[0]->body);
    }
}
