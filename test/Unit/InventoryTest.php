<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\Errors\AgreelyNotFoundError;
use Agreely\Sdk\Resources\Inventory;
use Agreely\Sdk\Test\Support\MockHttpClient;
use Agreely\Sdk\Types\InventoryDeclaration;
use Agreely\Sdk\Types\InventoryDecision;
use Agreely\Sdk\Types\InventoryRecordSet;
use PHPUnit\Framework\TestCase;

/**
 * The host inventory surface. Two things carry the weight here:
 *
 *   1. replaceCategories takes a COMPLETE LIST and withdraws what it omits, so an empty
 *      list is refused rather than read as « withdraw everything ».
 *   2. Fields are NAMES, never values, so the body is reduced to the documented members
 *      and anything else is refused instead of forwarded.
 */
final class InventoryTest extends TestCase
{
    private function client(MockHttpClient $http): Agreely
    {
        return new Agreely(['apiKey' => 'k', 'baseUrl' => 'https://api.test', 'httpClient' => $http]);
    }

    /** @return array<string,mixed> */
    private function setWire(string $state = InventoryDecision::STATE_DECIDED): array
    {
        return [
            'key' => 'beneficiaires',
            'hostSystem' => 'crm',
            'label' => 'Bénéficiaires',
            'labelEn' => 'Beneficiaries',
            'fields' => [
                ['key' => 'nom', 'label' => 'Nom complet', 'labelEn' => null],
                ['key' => 'naissance', 'label' => 'Date de naissance', 'labelEn' => 'Date of birth'],
            ],
            'status' => 'declared',
            'firstDeclaredAt' => '2026-09-01T00:00:00.000000Z',
            'lastDeclaredAt' => '2026-09-25T00:00:00.000000Z',
            'withdrawnAt' => null,
            'decision' => [
                'state' => $state,
                'cellKey' => $state === InventoryDecision::STATE_NO_CELL ? null : 'cell-1',
                'ruleKey' => $state === InventoryDecision::STATE_DECIDED ? 'rule-1' : null,
            ],
            'retentionStatement' => $state === InventoryDecision::STATE_DECIDED ? [
                'key' => 'st-1',
                'revision' => 2,
                'frozenAt' => '2026-09-25T00:00:00.000000Z',
                'decided' => true,
                'duration' => ['value' => 3, 'unit' => 'months'],
                'trigger' => 'collection',
                'action' => 'destroy',
                'text' => ['fr' => 'Conservé 3 mois.', 'en' => 'Kept for 3 months.'],
            ] : null,
        ];
    }

    /**
     * A valid declaration, as a loose array so each test can break one thing about it.
     *
     * @return array{hostSystem:string,categories:array<int,array<string,mixed>>}
     */
    private function declaration(): array
    {
        return [
            'hostSystem' => 'crm',
            'categories' => [[
                'key' => 'beneficiaires',
                'label' => 'Bénéficiaires',
                'fields' => [['key' => 'nom', 'label' => 'Nom complet']],
            ]],
        ];
    }

    /**
     * Send a declaration built from {@see InventoryTest::declaration()}. The shape check
     * belongs to the resource, which is exactly what these tests exercise, so the input
     * stays loose here and the one cast lives in one place.
     *
     * @param array<string,mixed> $input
     */
    private function replace(MockHttpClient $http, array $input): InventoryDeclaration
    {
        /** @phpstan-ignore argument.type */
        return $this->client($http)->inventory()->replaceCategories($input);
    }

    // -----------------------------------------------------------------
    // replaceCategories
    // -----------------------------------------------------------------

    public function testReplaceCategoriesPutsTheCompleteListAndReportsWhatItWithdrew(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [
            'hostSystem' => 'crm',
            'withdrawn' => 2,
            'categories' => [$this->setWire()],
        ])]);
        $out = $this->replace($http, $this->declaration());

        $call = $http->calls[0];
        // PUT, because it REPLACES: the verb says what the method name says.
        $this->assertSame('PUT', $call->method);
        $this->assertSame('/v1/inventory/categories', $call->path());
        $this->assertSame(2, $out->withdrawn);
        $this->assertSame('crm', $out->hostSystem);
        $this->assertCount(1, $out->categories);
        $this->assertSame('Bénéficiaires', $out->categories[0]->label);
        $this->assertCount(2, $out->categories[0]->fields);
        $this->assertSame('Date of birth', $out->categories[0]->fields[1]->labelEn);
        $this->assertTrue($out->categories[0]->decision->isDecided());
        $this->assertNotNull($out->categories[0]->retentionStatement);
        $this->assertSame(3, $out->categories[0]->retentionStatement->duration?->value);
        $this->assertSame('Conservé 3 mois.', $out->categories[0]->retentionStatement->text('fr'));
    }

    public function testTheBodyCarriesOnlyTheDocumentedMembers(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['hostSystem' => 'crm', 'withdrawn' => 0, 'categories' => []])]);
        $input = $this->declaration();
        $input['categories'][0]['labelEn'] = 'Beneficiaries';
        $this->replace($http, $input);

        $body = $http->calls[0]->body;
        $this->assertNotNull($body);
        $this->assertSame(['hostSystem', 'categories'], array_keys($body));
        $this->assertIsArray($body['categories']);
        $set = $body['categories'][0];
        $this->assertIsArray($set);
        $this->assertSame(['key', 'label', 'fields', 'labelEn'], array_keys($set));
        $fields = $set['fields'];
        $this->assertIsArray($fields);
        $this->assertIsArray($fields[0]);
        $this->assertSame(['key', 'label'], array_keys($fields[0]));
    }

    /** 🔴 A serialisation bug must never retire a whole inventory in one call. */
    public function testAnEmptyCategoryListIsRefusedBeforeAnyWireCall(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [])]);
        try {
            $this->client($http)->inventory()->replaceCategories(['hostSystem' => 'crm', 'categories' => []]);
            $this->fail('expected AgreelyConfigError');
        } catch (AgreelyConfigError $e) {
            $this->assertStringContainsString('at least one record set', $e->getMessage());
            $this->assertStringContainsString('withdraw everything', $e->getMessage());
            $this->assertCount(0, $http->calls);
        }
    }

    public function testExactlyTwoHundredRecordSetsAreSent(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['hostSystem' => 'crm', 'withdrawn' => 0, 'categories' => []])]);
        $input = ['hostSystem' => 'crm', 'categories' => []];
        for ($i = 0; $i < Inventory::MAX_SETS; $i++) {
            $input['categories'][] = ['key' => 'set-' . $i, 'label' => 'Set ' . $i, 'fields' => [['key' => 'f', 'label' => 'Field']]];
        }
        $this->replace($http, $input);
        $this->assertCount(1, $http->calls);
        $body = $http->calls[0]->body;
        $this->assertNotNull($body);
        $this->assertIsArray($body['categories']);
        $this->assertCount(200, $body['categories']);
    }

    public function testMoreThanTwoHundredRecordSetsIsRefused(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [])]);
        $input = $this->declaration();
        for ($i = 0; $i < Inventory::MAX_SETS; $i++) {
            $input['categories'][] = [
                'key' => 'set-' . $i,
                'label' => 'Set ' . $i,
                'fields' => [['key' => 'f', 'label' => 'Field']],
            ];
        }
        try {
            $this->replace($http, $input);
            $this->fail('expected AgreelyConfigError');
        } catch (AgreelyConfigError $e) {
            $this->assertSame(200, Inventory::MAX_SETS);
            $this->assertStringContainsString('at most 200 record sets', $e->getMessage());
            $this->assertCount(0, $http->calls);
        }
    }

    public function testASetWithNoFieldsOrTooManyIsRefused(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [])]);
        $empty = $this->declaration();
        $empty['categories'][0]['fields'] = [];
        try {
            $this->replace($http, $empty);
            $this->fail('expected AgreelyConfigError for an empty field list');
        } catch (AgreelyConfigError $e) {
            $this->assertStringContainsString('1 to 60 fields', $e->getMessage());
        }

        $overCap = [];
        for ($i = 0; $i <= Inventory::MAX_FIELDS; $i++) {
            $overCap[] = ['key' => 'f' . $i, 'label' => 'Field ' . $i];
        }
        $tooMany = $this->declaration();
        $tooMany['categories'][0]['fields'] = $overCap;
        try {
            $this->replace($http, $tooMany);
            $this->fail('expected AgreelyConfigError for too many fields');
        } catch (AgreelyConfigError $e) {
            $this->assertStringContainsString('1 to 60 fields', $e->getMessage());
        }
        $this->assertCount(0, $http->calls);
    }

    /** 🔴 A value smuggled in as an extra member never leaves the process. */
    public function testAValueBearingMemberIsRefusedRatherThanForwarded(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [])]);
        $input = $this->declaration();
        // A field object carrying the value itself: refused, and never echoed back.
        $input['categories'][0]['fields'] = [
            ['key' => 'naissance', 'label' => 'Date de naissance', 'value' => '1975-03-02'],
        ];
        try {
            $this->replace($http, $input);
            $this->fail('expected AgreelyConfigError');
        } catch (AgreelyConfigError $e) {
            $this->assertStringContainsString('not a member of this input', $e->getMessage());
            $this->assertStringNotContainsString('1975-03-02', $e->getMessage());
            $this->assertCount(0, $http->calls);
        }
    }

    public function testAnIdShapedKeyIsRefused(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [])]);
        $input = $this->declaration();
        $input['categories'][0]['key'] = 'beneficiaire-00017';
        try {
            $this->replace($http, $input);
            $this->fail('expected AgreelyConfigError');
        } catch (AgreelyConfigError $e) {
            $this->assertStringContainsString('stable slug', $e->getMessage());
            $this->assertCount(0, $http->calls);
        }
    }

    public function testABlankLabelIsRefused(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [])]);
        $input = $this->declaration();
        $input['categories'][0]['label'] = '   ';
        try {
            $this->replace($http, $input);
            $this->fail('expected AgreelyConfigError');
        } catch (AgreelyConfigError $e) {
            $this->assertStringContainsString('non-empty string', $e->getMessage());
            $this->assertCount(0, $http->calls);
        }
    }

    public function testReplaceCategoriesIsNeverAutoRetried(): void
    {
        $http = new MockHttpClient([MockHttpClient::network()]);
        $client = new Agreely([
            'apiKey' => 'k',
            'baseUrl' => 'https://api.test',
            'httpClient' => $http,
            'maxRetries' => 2,
        ]);
        try {
            /** @phpstan-ignore argument.type */
            $client->inventory()->replaceCategories($this->declaration());
            $this->fail('expected the outage to surface');
        } catch (\Agreely\Sdk\Errors\AgreelyUnavailableError) {
            $this->assertCount(1, $http->calls);
        }
    }

    // -----------------------------------------------------------------
    // listCategories
    // -----------------------------------------------------------------

    public function testListCategoriesReturnsTheSetsAndNarrowsByHostSystem(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['categories' => [$this->setWire()]])]);
        $sets = $this->client($http)->inventory()->listCategories(['hostSystem' => 'crm']);

        $this->assertSame('/v1/inventory/categories', $http->calls[0]->path());
        $this->assertSame('hostSystem=crm', $http->calls[0]->query());
        $this->assertCount(1, $sets);
        $this->assertInstanceOf(InventoryRecordSet::class, $sets[0]);
        $this->assertFalse($sets[0]->isWithdrawn());
    }

    public function testListCategoriesWithoutAFilterSendsNoQuery(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['categories' => []])]);
        $this->assertSame([], $this->client($http)->inventory()->listCategories());
        $this->assertSame('', $http->calls[0]->query());
    }

    public function testListCategoriesRefusesAnIdShapedFilter(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['categories' => []])]);
        try {
            $this->client($http)->inventory()->listCategories(['hostSystem' => 'crm-7f9c8d6b5']);
            $this->fail('expected AgreelyConfigError');
        } catch (AgreelyConfigError $e) {
            $this->assertStringContainsString('stable slug', $e->getMessage());
            $this->assertCount(0, $http->calls);
        }
    }

    /** 🔴 A gap carries no duration, and the host abstains rather than picking one. */
    public function testAGapSetCarriesNoStatementAndNamesWhyTheHostAbstains(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [
            'categories' => [$this->setWire(InventoryDecision::STATE_NO_RULE)],
        ])]);
        $sets = $this->client($http)->inventory()->listCategories();

        $this->assertFalse($sets[0]->decision->isDecided());
        $this->assertSame(InventoryDecision::STATE_NO_RULE, $sets[0]->decision->state);
        $this->assertNull($sets[0]->decision->ruleKey);
        $this->assertNull($sets[0]->retentionStatement);
    }

    // -----------------------------------------------------------------
    // getStatement
    // -----------------------------------------------------------------

    public function testGetStatementResolvesASupersededStatementWithItsFrozenTerms(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [
            'categoryKey' => 'beneficiaires',
            'hostSystem' => 'crm',
            'current' => false,
            'supersededAt' => '2026-09-20T00:00:00.000000Z',
            'key' => 'st-1',
            'revision' => 1,
            'frozenAt' => '2026-06-01T00:00:00.000000Z',
            'decided' => true,
            'duration' => ['value' => 3, 'unit' => 'months'],
            'trigger' => 'collection',
            'action' => 'destroy',
            'text' => ['fr' => 'Conservé 3 mois.', 'en' => 'Kept for 3 months.'],
        ])]);
        $resolved = $this->client($http)->inventory()->getStatement('st-1');

        $this->assertSame('/v1/inventory/statements/st-1', $http->calls[0]->path());
        // Superseded and still resolving with the terms it was frozen under: that is the point.
        $this->assertFalse($resolved->current);
        $this->assertSame('2026-09-20T00:00:00.000000Z', $resolved->supersededAt);
        $this->assertSame(3, $resolved->statement->duration?->value);
        $this->assertSame('Kept for 3 months.', $resolved->statement->text('en'));
    }

    public function testGetStatementRefusesABlankKeyBeforeAnyCall(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [])]);
        try {
            $this->client($http)->inventory()->getStatement('');
            $this->fail('expected AgreelyConfigError');
        } catch (AgreelyConfigError $e) {
            $this->assertStringContainsString('statementKey', $e->getMessage());
            $this->assertCount(0, $http->calls);
        }
    }

    public function testAnUnknownStatementIsTheSameNotFound(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(
            404,
            ['error' => ['code' => 'not_found', 'message' => 'No retention statement with that key.']],
        )]);
        $this->expectException(AgreelyNotFoundError::class);
        $this->client($http)->inventory()->getStatement('nope');
    }
}
