<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Contract;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Errors\AgreelyConflictError;
use Agreely\Sdk\Errors\AgreelyNotFoundError;
use Agreely\Sdk\Errors\ErrorCode;
use Agreely\Sdk\Errors\ErrorReason;
use Agreely\Sdk\Types\ConsentWithdrawal;
use Agreely\Sdk\Types\HoldsSync;
use Agreely\Sdk\Types\RetentionHoldFeedItem;
use PHPUnit\Framework\TestCase;

/**
 * The live contract suite for the 0.5.0 surface, against the running /v1 API: the check
 * dates, the consent documents and their PDF, the registry upsert, the consent sheet,
 * the withdrawal, and a hold placed, read back through the feed and released.
 *
 * Every customer reference here carries a "/" and a "%41", so each route proves the
 * reference travels as ONE encoded segment and is decoded exactly once.
 *
 * Gated like LiveContractTest (AGREELY_LIVE=1 and a seeded fixture). A test whose key
 * the fixture does not carry skips ({@see Fixture::keyOrNull()}).
 */
final class LiveSurfaceContractTest extends TestCase
{
    private const INSTANT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    private Fixture $fixture;

    protected function setUp(): void
    {
        if (getenv('AGREELY_LIVE') !== '1') {
            $this->markTestSkipped('Live contract suite is gated behind AGREELY_LIVE=1 (default run stays offline).');
        }
        if (!Fixture::exists()) {
            $this->markTestSkipped('No fixture. Seed one from the live api (scripts/sdk-contract-seed.php).');
        }
        $this->fixture = Fixture::load();
    }

    private function client(string $scope): Agreely
    {
        $key = $this->fixture->keyOrNull($scope);
        if ($key === null) {
            $this->markTestSkipped("The fixture carries no '{$scope}' key.");
        }
        return new Agreely(['apiKey' => $key, 'baseUrl' => $this->fixture->baseUrl(), 'timeout' => 8000]);
    }

    /** A reference with a slash and a percent sign, unique per run. */
    private static function awkwardRef(string $tag): string
    {
        return 'sdk-php/' . $tag . '-' . bin2hex(random_bytes(3)) . '/%41';
    }

    /** Register $ref, so the routes that need a known customer accept it. */
    private function register(string $ref): void
    {
        $created = $this->client('registry')->customers()->upsert($ref, ['legalBasis' => 'contract']);
        $this->assertTrue($created->created);
        $this->assertSame($ref, $created->customerRef, 'the reference reached the server exactly as sent');
    }

    public function testARecordBackedAnswerCarriesItsEndAndANoRecordAnswerNone(): void
    {
        $check = $this->client('check');
        $active = $check->checkDetailed($this->fixture->subject(), 'Email Address', 'Marketing Outreach');
        $this->assertSame('active', $active->status);
        if ($active->validUntil !== null) {
            $this->assertMatchesRegularExpression(self::INSTANT, $active->validUntil);
        }
        $this->assertNull($active->revokedAt);

        $none = $check->checkDetailed($this->fixture->absent(), 'Email Address', 'Marketing Outreach');
        $this->assertSame('none', $none->status);
        $this->assertNull($none->validUntil);
        $this->assertNull($none->revokedAt);
    }

    public function testConsentDocumentsResolveByCodeAndServeTheInformationPdf(): void
    {
        $issue = $this->fixture->issue();
        $documents = $this->client('check')->consentDocuments();

        $listed = array_values(array_filter($documents->list(), static fn ($d): bool => $d->code === $issue['documentCode']));
        $this->assertCount(1, $listed);
        $this->assertSame($issue['documentId'], $listed[0]->documentVersionId);

        $detail = $documents->get($issue['documentCode']);
        $this->assertSame($issue['documentId'], $detail->documentVersionId);
        $this->assertNotNull($detail->disclosure->purpose->text('fr'));

        $pdf = $documents->getInformationPdf($issue['documentId']);
        $this->assertStringStartsWith('%PDF-', $pdf->pdf);
        $this->assertStringContainsString('application/pdf', $pdf->contentType);

        $catalog = $this->client('check')->catalog()->forDocument($issue['documentCode']);
        $this->assertSame($issue['documentId'], $catalog->document['documentVersionId']);
        $this->assertNotEmpty($catalog->catalog);

        try {
            $documents->getInformationPdf('00000000-0000-4000-8000-000000000000');
            $this->fail('expected AgreelyNotFoundError');
        } catch (AgreelyNotFoundError $e) {
            $this->assertSame(404, $e->status);
        }
    }

    public function testUpsertCreatesThenMergesAReferenceWithASlashAndAPercent(): void
    {
        $ref = self::awkwardRef('upsert');
        $this->register($ref);

        $registry = $this->client('registry')->customers();
        $read = $registry->get($ref);
        $this->assertSame($ref, $read->customerRef);
        $this->assertTrue($read->registered);
        $this->assertSame('contract', $read->legalBasis);

        $merged = $registry->upsert($ref, ['noticeLocale' => 'fr']);
        $this->assertFalse($merged->created, 'the second call merged into the record the first created');
        $this->assertSame('contract', $merged->legalBasis, 'an absent field is left untouched');
        $this->assertSame('fr', $merged->noticeLocale);
    }

    public function testAConsentSheetIsMintedOnceAndItsKeyIsALatch(): void
    {
        $ref = self::awkwardRef('sheet');
        $this->register($ref);
        $issue = $this->fixture->issue();
        $key = 'php-contract-sheet-' . bin2hex(random_bytes(6));

        $sheet = $this->client('attest')->manualConsents()->createConsentSheet(
            $ref,
            ['documentVersionId' => $issue['documentId']],
            ['idempotencyKey' => $key],
        );
        $this->assertStringStartsWith('%PDF-', $sheet->signatureSheet->bytes());
        $this->assertSame('fr', $sheet->signatureSheet->locale);
        $this->assertNotSame('', $sheet->printedReference);
        $this->assertNotSame('', $sheet->claim->claimUrl);

        try {
            $this->client('attest')->manualConsents()->createConsentSheet(
                $ref,
                ['documentVersionId' => $issue['documentId']],
                ['idempotencyKey' => $key],
            );
            $this->fail('expected AgreelyConflictError already_minted');
        } catch (AgreelyConflictError $e) {
            $this->assertSame(ErrorCode::ALREADY_MINTED, $e->code);
            $this->assertSame(ErrorReason::ALREADY_MINTED, $e->reason);
        }
    }

    public function testAWithdrawalRecordedOnBehalfDeniesAtOnce(): void
    {
        $ref = self::awkwardRef('withdraw');
        $issue = $this->fixture->issue();

        $paper = $this->client('attest')->manualConsents()->record([
            'customerId' => $ref,
            'documentVersionId' => $issue['documentId'],
            'effectiveDate' => gmdate('Y-m-d'),
            'validUntil' => gmdate('Y-m-d', strtotime('+1 year')),
            'items' => [['category' => $issue['category'], 'purpose' => $issue['purpose']]],
            'evidence' => ['pdfSha256' => Agreely::hashPdf(random_bytes(64))],
            'versionAttested' => true,
        ]);
        $this->assertNotEmpty($paper->consentRefs);
        $check = $this->client('check');
        $this->assertTrue($check->check($ref, $issue['category'], $issue['purpose']));

        $withdrawn = $this->client('withdraw')->withdrawals()->record($ref, $paper->consentRefs[0], [
            'channel' => 'phone',
            'operator' => 'sdk-php-contract',
            'reason' => 'Contract suite.',
        ]);
        $this->assertTrue($withdrawn->withdrawn);
        $this->assertTrue($withdrawn->recordedOnBehalf);
        $this->assertSame('company_attested', $withdrawn->assurance);
        $this->assertSame(ConsentWithdrawal::GATE_DENIED, $withdrawn->gate);

        $after = $check->checkDetailed($ref, $issue['category'], $issue['purpose']);
        $this->assertSame('deny', $after->decision);
        $this->assertSame('revoked', $after->status);
        $this->assertNotNull($after->revokedAt);
        $this->assertMatchesRegularExpression(self::INSTANT, $after->revokedAt);

        try {
            $this->client('withdraw')->withdrawals()->record($ref, '0x' . str_repeat('0', 64), [
                'channel' => 'phone',
                'operator' => 'sdk-php-contract',
            ]);
            $this->fail('expected AgreelyNotFoundError');
        } catch (AgreelyNotFoundError $e) {
            $this->assertSame(ErrorReason::UNKNOWN_CONSENT, $e->reason);
        }
    }

    public function testAHoldIsPlacedReadThroughTheFeedAndReleased(): void
    {
        $ref = self::awkwardRef('hold');
        $this->register($ref);
        $retention = $this->client('registry')->retention();
        $feed = $this->client('holds')->retention();

        $placed = $retention->placeHold($ref, ['ground' => 'other_law', 'provision' => 'Contract suite, art. 1']);
        $this->assertSame($ref, $placed->customerRef);
        $this->assertTrue($placed->isActive());
        $this->assertSame('api', $placed->placedBy);
        $this->assertTrue($placed->scope->all, 'no scope sent: the server holds all the information');

        $snapshot = $feed->syncHolds();
        $this->assertSame(HoldsSync::MODE_SNAPSHOT, $snapshot->mode);
        $this->assertNotSame('', $snapshot->cursor);
        $mine = array_values(array_filter($snapshot->holds, static fn (RetentionHoldFeedItem $h): bool => $h->id === $placed->id));
        $this->assertCount(1, $mine, 'the snapshot carries the hold just placed');
        $this->assertSame($ref, $mine[0]->customerRef);
        $this->assertTrue($mine[0]->isActive());

        $pages = iterator_to_array($feed->holdPages(), false);
        $this->assertNotEmpty($pages);
        $this->assertTrue($pages[count($pages) - 1]->isLast());

        $posture = $retention->getCustomerRetention($ref);
        $this->assertCount(1, $posture->activeHolds());

        $released = $retention->releaseHold($ref, $placed->id, ['reason' => 'Contract suite: released.']);
        $this->assertFalse($released->isActive());
        $this->assertSame($ref, $released->customerRef);

        try {
            $retention->releaseHold($ref, $placed->id, ['reason' => 'Twice.']);
            $this->fail('expected AgreelyConflictError already_released');
        } catch (AgreelyConflictError $e) {
            $this->assertSame(ErrorCode::ALREADY_RELEASED, $e->code);
        }

        $delta = $feed->syncHolds(['changedSince' => $snapshot->cursor]);
        $this->assertSame(HoldsSync::MODE_DELTA, $delta->mode);
        $after = array_values(array_filter($delta->holds, static fn (RetentionHoldFeedItem $h): bool => $h->id === $placed->id));
        $this->assertNotEmpty($after, 'the delta carries the release');
        $this->assertFalse($after[count($after) - 1]->isActive());
    }
}
