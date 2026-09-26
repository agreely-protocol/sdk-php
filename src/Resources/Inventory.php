<?php

declare(strict_types=1);

namespace Agreely\Sdk\Resources;

use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\HostInput;
use Agreely\Sdk\Http\RequestSpec;
use Agreely\Sdk\Http\Transport;
use Agreely\Sdk\Types\InventoryDeclaration;
use Agreely\Sdk\Types\InventoryRecordSet;
use Agreely\Sdk\Types\ResolvedRetentionStatement;
use Agreely\Sdk\Types\Wire;

/**
 * The host INVENTORY resource. A host system declares the record sets it holds and the
 * NAMES of their fields, never a value; Agreely links each set to a catalogue cell and
 * returns the retention statement the host stamps on a record at collection.
 *
 * ⚠️ `declared`, never `verified`: what a set says it holds is what the host DECLARED.
 *
 * THE SCOPES DIFFER PER METHOD, and it is deliberate:
 *   replaceCategories 'inventory' only. Declaring an inventory SHAPES the register the
 *                     responsable reviews, so a purge cron holding 'retention' must not
 *                     be able to rewrite what the organisation says it holds.
 *   listCategories    'inventory' or 'retention'. A purge job needs the list; the field
 *                     labels stay off a 'check' key.
 *   getStatement      'inventory', 'retention' or 'check', so a public collection form
 *                     can resolve the sentence it stamps without holding a key that writes.
 *
 * 🔴 SOURCE NOTE. Unlike the retention surface, /v1/inventory/* is NOT in the committed
 * openapi.yaml as of 2026-09-25. This resource is built from the shipped
 * InventoryController and its InventoryInput parser, so its shapes are asserted against
 * an implementation rather than against a ratified contract. Treat a divergence here as
 * a question for the API, not as a bug to work around silently.
 */
final class Inventory
{
    /** At most this many record sets per host system, mirrored from the server. */
    public const MAX_SETS = 50;

    /** At most this many fields per record set, mirrored from the server. */
    public const MAX_FIELDS = 60;

    /** The members a declaration accepts, and no others. */
    private const DECLARATION_MEMBERS = ['hostSystem', 'categories'];

    /** The members one record set accepts, and no others. */
    private const SET_MEMBERS = ['key', 'label', 'labelEn', 'fields'];

    /** The members one field accepts, and no others: its NAME, never a value. */
    private const FIELD_MEMBERS = ['key', 'label', 'labelEn'];

    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * REPLACE a host system's inventory with the COMPLETE LIST given
     * (PUT /v1/inventory/categories).
     *
     * 🔴 THIS IS NOT AN APPEND, AND THE NAME SAYS SO. Every set this system declared
     * before that is missing from `categories` is WITHDRAWN (it stays listed, marked
     * withdrawn, and the response's `withdrawn` counts it). Always send the whole list,
     * from one source of truth, per hostSystem. Check
     * {@see InventoryDeclaration::$withdrawn} on a run that meant to change nothing.
     *
     * REFUSED CLIENT-SIDE, before any wire call (AgreelyConfigError): an EMPTY category
     * list (it would read as "withdraw everything", which a serialisation bug must never
     * do in one call), more than 50 sets, a set with no fields or more than 60, a
     * malformed hostSystem or key, and any member outside the documented ones. The body
     * carries only key / label / labelEn / fields, so nothing else on the input leaves
     * the process. NEVER auto-retried.
     *
     * ⚠️ `hostSystem` NEW VALUES ARE RATIONED: at most 10 distinct ones per organisation
     * in any rolling 30 days, so a pod name or a deployment slot locks you out within
     * days. See {@see HostInput::hostToken()}.
     *
     * @param array{hostSystem:string,categories:list<array{key:string,label:string,labelEn?:string|null,fields:list<array{key:string,label:string,labelEn?:string|null}>}>} $input
     */
    public function replaceCategories(array $input): InventoryDeclaration
    {
        $label = 'inventory.replaceCategories';
        HostInput::closed($input, self::DECLARATION_MEMBERS, $label);
        $hostSystem = HostInput::hostToken($input['hostSystem'] ?? null, "{$label}: hostSystem");

        $categories = $input['categories'] ?? null;
        if (!is_array($categories) || !array_is_list($categories) || $categories === []) {
            throw new AgreelyConfigError(
                "{$label}: categories must declare at least one record set. To stop declaring a set, send the "
                . 'COMPLETE list without it; an empty list is refused rather than read as "withdraw everything".',
            );
        }
        if (count($categories) > self::MAX_SETS) {
            throw new AgreelyConfigError(
                "{$label}: at most " . self::MAX_SETS . ' record sets per host system. A record set names a KIND of '
                . 'records, never a record.',
            );
        }

        $body = ['hostSystem' => $hostSystem, 'categories' => []];
        foreach ($categories as $i => $set) {
            $body['categories'][] = $this->set($set, "{$label}: categories[{$i}]");
        }

        $wire = $this->transport->request(new RequestSpec(
            method: 'PUT',
            path: '/v1/inventory/categories',
            body: $body,
            idempotentRetry: false,
        ));
        return InventoryDeclaration::fromWire($wire);
    }

    /**
     * The declared record sets (WITHDRAWN ones included), each with the cell and rule
     * Agreely linked it to and its current retention statement.
     *
     * 🔴 A set whose decision is not "decided" has NO duration: the host abstains and
     * never picks one of its own.
     *
     * @param array{hostSystem?:string} $input narrow to one host system
     * @return list<InventoryRecordSet>
     */
    public function listCategories(array $input = []): array
    {
        $hostSystem = $input['hostSystem'] ?? null;
        $wire = $this->transport->request(new RequestSpec(
            method: 'GET',
            path: '/v1/inventory/categories',
            query: [
                'hostSystem' => $hostSystem === null
                    ? null
                    : HostInput::hostToken($hostSystem, 'inventory.listCategories: hostSystem'),
            ],
            idempotentRetry: true,
        ));
        return InventoryRecordSet::listFromWire(Wire::objects($wire, 'categories'));
    }

    /**
     * One FROZEN retention statement, by the key a host stamped on a record at
     * collection.
     *
     * It resolves for as long as the set exists, whatever became of the rule since:
     * `current` is false once a later statement superseded it, and the terms are STILL
     * the ones frozen. An unknown, foreign or malformed key is the same
     * AgreelyNotFoundError with the same body.
     */
    public function getStatement(string $statementKey): ResolvedRetentionStatement
    {
        $key = HostInput::pathKey($statementKey, 'inventory.getStatement', 'statementKey');
        $wire = $this->transport->request(new RequestSpec(
            method: 'GET',
            path: '/v1/inventory/statements/' . rawurlencode($key),
            idempotentRetry: true,
        ));
        return ResolvedRetentionStatement::fromWire($wire);
    }

    /**
     * One record set, reduced to the documented members.
     *
     * @return array<string,mixed>
     */
    private function set(mixed $set, string $label): array
    {
        if (!is_array($set)) {
            throw new AgreelyConfigError("{$label} must be an array describing one record set.");
        }
        HostInput::closed($set, self::SET_MEMBERS, $label);
        $fields = $set['fields'] ?? null;
        if (!is_array($fields) || !array_is_list($fields) || $fields === []) {
            throw new AgreelyConfigError("{$label}.fields must list 1 to " . self::MAX_FIELDS . ' fields.');
        }
        if (count($fields) > self::MAX_FIELDS) {
            throw new AgreelyConfigError("{$label}.fields must list 1 to " . self::MAX_FIELDS . ' fields.');
        }

        $out = [
            'key' => HostInput::hostToken($set['key'] ?? null, "{$label}.key"),
            'label' => $this->labelText($set['label'] ?? null, "{$label}.label"),
            'fields' => [],
        ];
        if (($set['labelEn'] ?? null) !== null) {
            $out['labelEn'] = $this->labelText($set['labelEn'], "{$label}.labelEn");
        }
        foreach ($fields as $j => $field) {
            $out['fields'][] = $this->field($field, "{$label}.fields[{$j}]");
        }
        return $out;
    }

    /**
     * One field: its NAME, never a value.
     *
     * @return array<string,mixed>
     */
    private function field(mixed $field, string $label): array
    {
        if (!is_array($field)) {
            throw new AgreelyConfigError("{$label} must be an array describing one field.");
        }
        HostInput::closed($field, self::FIELD_MEMBERS, $label);
        $out = [
            'key' => HostInput::hostToken($field['key'] ?? null, "{$label}.key"),
            'label' => $this->labelText($field['label'] ?? null, "{$label}.label"),
        ];
        if (($field['labelEn'] ?? null) !== null) {
            $out['labelEn'] = $this->labelText($field['labelEn'], "{$label}.labelEn");
        }
        return $out;
    }

    /**
     * A label is a NAME, so it is required to be a non-empty string here. The server
     * holds the rest of the rule (NFC, 1 to 120 characters, no control character, no
     * leading spreadsheet formula character, and no value-shaped text: an at sign, a run
     * of five digits, a calendar date). Those are deliberately NOT re-implemented
     * client-side: the refusal names a PATH and never echoes what was sent, and
     * duplicating the value-shape heuristic here would drift from the register's own.
     */
    private function labelText(mixed $value, string $label): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new AgreelyConfigError("{$label} must be a non-empty string naming a kind of record or a field.");
        }
        return $value;
    }
}
