<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Errors\AgreelyAuthError;
use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\Errors\AgreelyConflictError;
use Agreely\Sdk\Errors\AgreelyNotFoundError;
use Agreely\Sdk\Errors\AgreelyRateLimitError;
use Agreely\Sdk\Errors\AgreelyValidationError;
use Agreely\Sdk\Errors\AgreelyVerbalDailyCapError;
use Agreely\Sdk\Test\Support\MockHttpClient;
use Agreely\Sdk\Types\RepresentativeCapacity;
use Agreely\Sdk\Types\Scope;
use Agreely\Sdk\Types\VerbalConsentHistory;
use Agreely\Sdk\Types\VerbalPurpose;
use PHPUnit\Framework\TestCase;

/** The verbal (telephone) consent resource: record, the paper rise, the history. */
final class VerbalConsentsTest extends TestCase
{
    private const ID = '3f2b8c1e-4d5a-4b6c-8d7e-9f0a1b2c3d4e';
    private const YES = '0x' . 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    private const ACK = '0x' . 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';
    private const SHA = '0x' . 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    /** @param array<string,mixed> $extra */
    private function client(MockHttpClient $http, array $extra = []): Agreely
    {
        return new Agreely(['apiKey' => 'k', 'baseUrl' => 'https://api.test', 'httpClient' => $http, ...$extra]);
    }

    /**
     * @return array{customerId:string,documentVersionId:string,answers:list<array{category:string,purpose:string,answer:string}>,obtainedAt:string|\DateTimeInterface,obtainedBy:string,scriptVersion:string,respondent:array{consentedBy:string,representativeCapacity?:string,name?:string},validUntil:string,sensitiveExpressAttested?:bool,isMinor?:bool,paperExpected?:bool}
     */
    private function input(): array
    {
        return [
            'customerId' => 'cust_8812',
            'documentVersionId' => self::ID,
            'answers' => [
                ['category' => 'Courriel', 'purpose' => 'Infolettre', 'answer' => 'yes'],
                ['category' => 'Courriel', 'purpose' => 'Sondages', 'answer' => 'no'],
            ],
            'obtainedAt' => '2026-09-29T10:05:00-04:00',
            'obtainedBy' => 'Julie, service client',
            'scriptVersion' => 'script-2026.09',
            'respondent' => ['consentedBy' => 'self'],
            'validUntil' => '2027-09-29',
        ];
    }

    private static function recorded(): MockHttpClient
    {
        return new MockHttpClient([
            MockHttpClient::json(201, [
                'consentId' => self::ID,
                'merkleRoot' => '0x' . str_repeat('1', 64),
                'consentRefs' => [self::YES, self::ACK],
                'tier' => 'verbal',
                'assurance' => 'company_documented',
                'anchored' => false,
                'paperExpected' => true,
                'acknowledged' => [['category' => 'Coordonnees', 'purpose' => 'Dossier', 'consentRef' => self::ACK]],
                'asksDeclined' => false,
            ]),
        ]);
    }

    public function testRecordPostsTheCallAndReturnsTheVerbalTier(): void
    {
        $http = self::recorded();
        $input = $this->input();
        $input['paperExpected'] = true;
        $r = $this->client($http)->verbalConsents()->record($input);

        $this->assertSame('verbal', $r->tier);
        $this->assertSame('company_documented', $r->assurance);
        $this->assertTrue($r->paperExpected);
        $this->assertFalse($r->asksDeclined);
        $this->assertSame(self::ACK, $r->acknowledged[0]->consentRef);
        $this->assertSame([self::YES, self::ACK], $r->consentRefs);

        $call = $http->calls[0];
        $this->assertSame('POST', $call->method);
        $this->assertSame('/v1/verbal-consents', $call->path());
        $this->assertStringStartsWith('idem_', (string) $call->header('Idempotency-Key'));
        $this->assertNotNull($call->body);
        $this->assertSame('2026-09-29T10:05:00-04:00', $call->body['obtainedAt']);
        $this->assertTrue($call->body['paperExpected']);
        $this->assertArrayNotHasKey('isMinor', $call->body, 'a false flag is not sent');
        $this->assertArrayNotHasKey('sensitiveExpressAttested', $call->body);
    }

    public function testRecordSendsADateTimeInUtcAndTheMinorRespondent(): void
    {
        $http = self::recorded();
        $input = $this->input();
        $input['obtainedAt'] = new \DateTimeImmutable('2026-09-29T10:05:00-04:00');
        $input['isMinor'] = true;
        $input['sensitiveExpressAttested'] = true;
        $input['respondent'] = [
            'consentedBy' => 'representative',
            'representativeCapacity' => RepresentativeCapacity::TITULAIRE_AUTORITE_PARENTALE,
            'name' => 'Marie Tremblay',
        ];
        $this->client($http)->verbalConsents()->record($input);
        $body = $http->calls[0]->body;
        $this->assertNotNull($body);
        $this->assertSame('2026-09-29T14:05:00.000Z', $body['obtainedAt']);
        $this->assertTrue($body['isMinor']);
        $this->assertTrue($body['sensitiveExpressAttested']);
        $this->assertSame([
            'consentedBy' => 'representative',
            'representativeCapacity' => 'titulaire_autorite_parentale',
            'name' => 'Marie Tremblay',
        ], $body['respondent']);
    }

    public function testRecordRefusesAnythingButAnExplicitYesOrNo(): void
    {
        foreach ([true, 1, 'informed', 'Yes', null] as $bad) {
            $http = self::recorded();
            $input = $this->input();
            $input['answers'][0]['answer'] = $bad;
            try {
                /** @phpstan-ignore argument.type */
                $this->client($http)->verbalConsents()->record($input);
                $this->fail('expected AgreelyConfigError for ' . json_encode($bad));
            } catch (AgreelyConfigError $e) {
                $this->assertStringContainsString('"yes" or "no"', $e->getMessage());
            }
            $this->assertCount(0, $http->calls);
        }
    }

    public function testRecordRefusesAnEmptyAnswersListANaiveInstantAndAnIdempotencyKeyInTheBody(): void
    {
        $cases = [
            'answers' => static function (array $i): array {
                $i['answers'] = [];
                return $i;
            },
            'obtainedAt' => static function (array $i): array {
                $i['obtainedAt'] = '2026-09-29T10:05:00';
                return $i;
            },
            'Idempotency-Key' => static function (array $i): array {
                $i['idempotencyKey'] = 'abc';
                return $i;
            },
        ];
        foreach ($cases as $needle => $mutate) {
            $http = self::recorded();
            try {
                /** @phpstan-ignore argument.type */
                $this->client($http)->verbalConsents()->record($mutate($this->input()));
                $this->fail("expected AgreelyConfigError ({$needle})");
            } catch (AgreelyConfigError $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
            $this->assertCount(0, $http->calls);
        }
    }

    public function testRecordRefusesAMalformedIdempotencyKeyOverride(): void
    {
        $http = self::recorded();
        $this->expectException(AgreelyConfigError::class);
        $this->client($http)->verbalConsents()->record($this->input(), ['idempotencyKey' => 'not ok']);
    }

    public function testTheDailyCapIsNeverAutoRetried(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(429, ['error' => ['code' => 'verbal_daily_cap', 'message' => 'Daily limit reached.']]),
        ]);
        try {
            $this->client($http, ['maxRetries' => 3])->verbalConsents()->record($this->input());
            $this->fail('expected AgreelyVerbalDailyCapError');
        } catch (AgreelyVerbalDailyCapError $e) {
            $this->assertInstanceOf(AgreelyRateLimitError::class, $e);
            $this->assertSame('verbal_daily_cap', $e->code);
        }
        $this->assertCount(1, $http->calls);
    }

    public function testCoveredPurposesAreAStateConflict(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(409, ['error' => ['code' => 'conflict', 'message' => 'Infolettre is held by an active paper consent.']]),
        ]);
        try {
            $this->client($http)->verbalConsents()->record($this->input());
            $this->fail('expected AgreelyConflictError');
        } catch (AgreelyConflictError $e) {
            $this->assertTrue($e->isStateConflict());
        }
    }

    public function testAnAnswerOnAnInformedLineIsAValidationError(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(422, ['error' => ['code' => 'invalid_request', 'message' => 'Dossier is given for information.']]),
        ]);
        $this->expectException(AgreelyValidationError::class);
        $this->client($http)->verbalConsents()->record($this->input());
    }

    public function testConfirmWithPaperPostsToThePaperRoute(): void
    {
        $manual = '0x' . str_repeat('c', 64);
        $http = new MockHttpClient([
            MockHttpClient::json(201, [
                'verbalConsentId' => self::ID,
                'manualConsentId' => '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d',
                'merkleRoot' => '0x' . str_repeat('2', 64),
                'tier' => 'manual',
                'confirmed' => [['category' => 'Courriel', 'purpose' => 'Infolettre', 'verbalConsentRef' => self::YES, 'consentRef' => $manual]],
                'withdrawn' => [],
            ]),
        ]);
        $r = $this->client($http)->verbalConsents()->confirmWithPaper(self::ID, [
            'signedAt' => '2026-10-02T09:00:00-04:00',
            'answers' => [['category' => 'Courriel', 'purpose' => 'Infolettre', 'answer' => 'yes']],
            'evidence' => ['pdfSha256' => self::SHA, 'pdf' => 'JVBERi0='],
        ], ['idempotencyKey' => 'paper-8812']);

        $this->assertSame('manual', $r->tier);
        $this->assertSame($manual, $r->confirmed[0]['consentRef']);
        $this->assertSame(self::YES, $r->confirmed[0]['verbalConsentRef']);
        $call = $http->calls[0];
        $this->assertSame('/v1/verbal-consents/' . self::ID . '/paper', $call->path());
        $this->assertSame('paper-8812', $call->header('Idempotency-Key'));
        $this->assertNotNull($call->body);
        $this->assertSame(['pdfSha256' => self::SHA, 'pdf' => 'JVBERi0='], $call->body['evidence']);
    }

    public function testAnAllUntickedPaperHasNoManualConsent(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(201, [
                'verbalConsentId' => self::ID, 'manualConsentId' => null, 'merkleRoot' => null, 'tier' => null,
                'confirmed' => [],
                'withdrawn' => [['category' => 'Courriel', 'purpose' => 'Infolettre', 'consentRef' => self::YES]],
            ]),
        ]);
        $r = $this->client($http)->verbalConsents()->confirmWithPaper(self::ID, [
            'signedAt' => new \DateTimeImmutable('2026-10-02T13:00:00Z'),
            'answers' => [['category' => 'Courriel', 'purpose' => 'Infolettre', 'answer' => 'no']],
            'evidence' => ['pdfSha256' => self::SHA],
        ]);
        $this->assertNull($r->manualConsentId);
        $this->assertNull($r->tier);
        $this->assertSame(self::YES, $r->withdrawn[0]['consentRef']);
    }

    public function testAVerbalOnlyKeyCannotRecordThePaper(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(403, ['error' => ['code' => 'forbidden', 'message' => "This API key does not carry the 'attest' scope."]]),
        ]);
        $this->expectException(AgreelyAuthError::class);
        $this->client($http)->verbalConsents()->confirmWithPaper(self::ID, [
            'signedAt' => '2026-10-02T09:00:00Z',
            'answers' => [['category' => 'Courriel', 'purpose' => 'Infolettre', 'answer' => 'yes']],
            'evidence' => ['pdfSha256' => self::SHA],
        ]);
    }

    public function testGetReadsTheHistoryIncludingInformedLines(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, [
                'consentId' => self::ID, 'tier' => 'verbal', 'assurance' => 'company_documented',
                'merkleRoot' => '0x' . str_repeat('1', 64), 'anchored' => true,
                'obtainedAt' => '2026-09-29T14:05:00Z', 'recordedAt' => '2026-09-29T14:07:12Z',
                'obtainedBy' => 'Julie', 'scriptVersion' => 'script-2026.09', 'documentVersionId' => self::ID,
                'validUntil' => '2027-09-30T03:59:59Z', 'paperExpected' => true, 'state' => 'paper_received',
                'purposes' => [
                    [
                        'category' => 'Courriel', 'purpose' => 'Infolettre', 'answer' => 'yes', 'consentRef' => self::YES,
                        'status' => 'active', 'paper' => 'confirmed_on_paper',
                        'confirmedBy' => ['consentRef' => '0xmanual', 'status' => 'active'],
                    ],
                    [
                        'category' => 'Courriel', 'purpose' => 'Sondages', 'answer' => 'no', 'consentRef' => null,
                        'status' => null, 'paper' => null, 'confirmedBy' => null,
                    ],
                    [
                        'category' => 'Coordonnees', 'purpose' => 'Dossier', 'answer' => 'informed', 'consentRef' => self::ACK,
                        'status' => 'active', 'paper' => null, 'confirmedBy' => null,
                    ],
                ],
                'paper' => [
                    'signedAt' => '2026-10-02T13:00:00Z', 'recordedAt' => '2026-10-02T13:01:00Z', 'pdfSha256' => self::SHA,
                    'manualConsentId' => '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d', 'manualMerkleRoot' => '0x22', 'tier' => 'manual',
                ],
            ]),
        ]);
        $h = $this->client($http)->verbalConsents()->get(self::ID);

        $this->assertSame('/v1/verbal-consents/' . self::ID, $http->calls[0]->path());
        $this->assertSame('GET', $http->calls[0]->method);
        $this->assertSame(VerbalConsentHistory::STATE_PAPER_RECEIVED, $h->state);
        $this->assertSame('verbal', $h->tier, 'a verbal consent keeps tier verbal forever');
        $this->assertCount(3, $h->purposes);
        $this->assertSame(VerbalPurpose::ANSWER_INFORMED, $h->purposes[2]->answer);
        $this->assertNull($h->purposes[1]->consentRef);
        $this->assertSame(['consentRef' => '0xmanual', 'status' => 'active'], $h->purposes[0]->confirmedBy);
        $this->assertNotNull($h->paper);
        $this->assertSame('manual', $h->paper['tier']);
    }

    public function testGetOfAnUnknownIdIsNotFound(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(404, ['error' => ['code' => 'not_found', 'message' => 'No verbal consent with that id.']]),
        ]);
        $this->expectException(AgreelyNotFoundError::class);
        $this->client($http)->verbalConsents()->get(self::ID);
    }

    public function testTheVocabulariesMatchTheSpec(): void
    {
        $this->assertContains('attest_verbal', Scope::ALL);
        $this->assertSame([
            'tutelle', 'mandat_protection_homologue', 'representation_temporaire', 'curatelle', 'other', 'undeclared',
            'titulaire_autorite_parentale', 'tuteur_mineur',
        ], RepresentativeCapacity::ALL);
    }
}
