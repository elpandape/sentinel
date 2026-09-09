<?php

declare(strict_types=1);

use function ElPandaPe\Sentinel\Tests\internalMarks;

/**
 * The boundary between what 1.0 covers and what it does not, held as data so that CI is what
 * enforces it. A declaration added later is either classified here or the suite goes red, which is
 * the only version of this gate that survives contact with a package that keeps growing.
 *
 * The two lists say the same thing twice over: a namespace whose every declaration is internal, and
 * the ones that are internal inside a namespace that is not. Each is keyed by why, because an
 * exception with no reason written next to it is a hole, and the reason is what the next person
 * needs in order to move a line from one side to the other on purpose.
 */
$internalNamespaces = [
    'the configuration picks the implementation by a fixed string, so no caller ever names the class' => [
        'Buffer', 'Dispatch', 'Ledger', 'Mass',
    ],
    'a command drives it, and the published surface is the command — its name, its options and its exit codes' => [
        'Compliance', 'Console', 'Import', 'Partitions', 'Retention',
    ],
    'reached only from inside another internal, and from nowhere else at all' => [
        'Jobs', 'Snapshot', 'Telemetry/OpenTelemetry',
    ],
];

$internalDeclarations = [
    'concrete implementations behind a contract the configuration resolves' => [
        'Integrity/HmacSigner', 'Integrity/JsonCanonicalizer', 'Integrity/NullSigner',
        'Integrity/OpenSslSigner', 'Integrity/Stream',
    ],
    'the machinery of the chain, reached only through the three reports the facade returns' => [
        'Integrity/AnchorTail', 'Integrity/Checkpoint', 'Integrity/CheckpointGate',
        'Integrity/Checkpoints', 'Integrity/Content', 'Integrity/Fold', 'Integrity/Hasher',
        'Integrity/Projections', 'Integrity/Signers', 'Integrity/Verifier',
    ],
    'the capture path: an Eloquent event reaches it, a caller never does' => [
        'Capture/AuthenticationSubscriber', 'Capture/ModelCapture', 'Capture/ModelObserver',
        'Capture/ParentCapture', 'Capture/Recorder', 'Capture/RelationCapture',
        'Capture/Relations/RecordsRelationChanges', 'Capture/WriteFailure',
    ],
    'how a batch is written and read, behind the two classes the readme resolves' => [
        'Archive/ArchiveBatch', 'Archive/Batch', 'Archive/BatchPath', 'Archive/BatchReader',
        'Archive/BatchWriter', 'Archive/Claim', 'Archive/Line', 'Archive/Manifest',
    ],
    'how a restoration is planned, behind the result it hands back' => [
        'Restore/Columns', 'Restore/Plan', 'Restore/Planner', 'Restore/RelationPlanner',
        'Restore/Restorer', 'Restore/Subject',
    ],
    'how a value is masked, hashed or re-keyed, behind the contract and the command' => [
        'Security/Digester', 'Security/Fields', 'Security/Keyring', 'Security/Maskers',
        'Security/PartialMasker',
    ],
    'plumbing for migrations, schema and policy' => [
        'Support/AuditPolicy', 'Support/AuditSchema', 'Support/DerivedIdentity',
        'Support/PackageMigrations', 'Support/PartitionedTable', 'Support/Policies',
        'Support/PolicyRegistry', 'Support/PublishedMigration', 'Support/Reference',
    ],
    'the seams the package uses to talk to itself, not the points somebody extends' => [
        'Contracts/Buffer', 'Contracts/Canonicalizer', 'Contracts/DispatchStrategy',
        'Contracts/MassStrategy',
    ],
    'how a diff is computed, behind the two shapes it produces' => [
        'Diff/Comparator', 'Diff/Normalizer', 'Diff/Pointer',
    ],
    'values only an internal writes and only an internal reads' => [
        'Enums/BatchLine', 'Enums/CheckpointState', 'Enums/PruneAction', 'Enums/RetentionHold',
    ],
    'how a trace is carried, behind the context the facade returns' => [
        'Telemetry/Envelope', 'Telemetry/NullSpanContextProvider', 'Telemetry/TraceParent',
        'Telemetry/Tracer',
    ],
    'the state machine behind a transition, which is not the transition' => [
        'Transitions/Machine', 'Transitions/State',
    ],
    'resolved from the container and named by nobody' => [
        'Context/Identity', 'SentinelServiceProvider',
    ],
    'what a capture states about its own context, passed hand to hand inside the package' => [
        'Context/Attribution', 'Context/Attributions',
    ],
];

$expected = static function () use ($internalNamespaces, $internalDeclarations): Closure {
    $namespaces = array_merge(...array_values($internalNamespaces));
    $declarations = array_merge(...array_values($internalDeclarations));

    return static fn (string $name): bool => in_array($name, $declarations, true)
        || array_any($namespaces, static fn (string $namespace): bool => str_starts_with($name, $namespace.'/'));
};

it('marks every declaration the freeze does not cover, and only those', function () use ($expected): void {
    $wanted = $expected();

    $wrong = ['unmarked but internal' => [], 'marked but public' => []];

    foreach (internalMarks() as $name => $marked) {
        if ($wanted($name) !== $marked) {
            $wrong[$marked ? 'marked but public' : 'unmarked but internal'][] = $name;
        }
    }

    expect($wrong['unmarked but internal'])->toBeEmpty(implode(', ', $wrong['unmarked but internal']))
        ->and($wrong['marked but public'])->toBeEmpty(implode(', ', $wrong['marked but public']));
});

it('names every internal namespace and declaration with the reason it is one', function () use ($internalNamespaces, $internalDeclarations): void {
    $reasons = [...array_keys($internalNamespaces), ...array_keys($internalDeclarations)];

    expect($reasons)->each->toMatch('/^[a-z].{30,}/')
        ->and($reasons)->toHaveSameSize(array_unique($reasons));
});

it('leaves the surface a reader can reach standing, whatever else moves', function () use ($expected): void {
    $wanted = $expected();

    $published = [
        'Facades/Sentinel', 'Sentinel', 'Concerns/Auditable', 'Data/AuditData', 'Data/RelationLine',
        'Models/Audit', 'Query/AuditQuery', 'Query/AuditPage', 'Testing/LedgerContractTestCase',
        'Presentation/AuditPresenter', 'Restore/RestoreResult', 'Support/Config',
        'Support/AuditCollection', 'Archive/Rehydrator', 'Archive/Rehydration', 'Security/Rekeyer',
        'Capture/PendingEvent', 'Capture/Relations/AuditedBelongsToMany',
        'Integrity/VerificationResult', 'Integrity/CanonicalPayload', 'Telemetry/TraceContext',
        'Transitions/TransitionQuery', 'Transitions/IllegalTransition', 'Diff/Diff', 'Diff/Change',
    ];

    expect(array_values(array_filter($published, $wanted)))->toBeEmpty()
        ->and(array_diff($published, array_keys(internalMarks())))->toBeEmpty();
});
