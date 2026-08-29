<?php

declare(strict_types=1);

return [

    'commands' => [
        'flush' => [
            'description' => 'Settle everything the audit buffer is holding',
            'settled' => 'Settled :count entries from the buffer.',
            'not_buffered' => 'Sentinel is writing in :mode mode, so nothing is waiting in a buffer. Set the mode to buffered before flushing one.',
            'failed' => 'The buffer could not be settled: :reason. Nothing was lost: what did not settle is back in the buffer.',
        ],
    ],

    'discarded' => [
        'cancelled' => 'A listener cancelled the :event entry for :type :id before it reached the ledger.',
        'policy' => 'A policy discarded the :event entry for :type :id before it reached the ledger.',
        'unchanged' => 'The :event to :type :id changed nothing that is audited, so no entry was written.',
        'unspecified' => 'Stage :stage discarded the :event entry for :type :id before it reached the ledger.',
    ],

    'ledger' => [
        'destination_failed' => 'Destination :destination did not take audit :id at sequence :sequence of stream :stream: :reason',
        'write_failed' => 'The deferred write of the :event entry for :type :id did not complete: :reason',
    ],

    'presenter' => [
        'entry' => ':actor :event :subject',
        'impersonated' => ':impersonator acting as :actor :event :subject',
        'reference' => ':type #:id',
        'someone' => 'Someone',
        'something' => 'something',
        'field' => ':ordinal. v:version  :value',
        'relation' => ':line · :relation',
        'attached' => '  + :related',
        'detached' => '  - :related',
        'repivoted' => '  ~ :related',
        'timeline' => ':time  :line',
        'transition' => ':line · :from → :to',
        'nothing' => 'nothing',
        'yes' => 'yes',
        'no' => 'no',
        'structure' => 'a structure',
    ],

    'events' => [
        'created' => 'created',
        'updated' => 'changed',
        'deleted' => 'deleted',
        'restored' => 'restored',
        'force_deleted' => 'permanently deleted',
        'attached' => 'attached',
        'detached' => 'detached',
        'synced' => 'synced',
        'transition' => 'moved',
        'restore' => 'restored the state of',
        'rekeyed' => 're-keyed',
        'custom' => 'recorded',
        'login' => 'signed in',
        'logout' => 'signed out',
        'failed' => 'was refused',
        'lockout' => 'was locked out',
        'password_reset' => 'reset the password of',
    ],

    'transitions' => [
        'illegal' => ':subject cannot move its :attribute from :from to :to.',
    ],

    'restore' => [
        'subject_missing' => 'The record this entry is about no longer exists, so there is nothing to restore.',
        'entry_redacted' => 'This entry has been redacted: its contents were destroyed on purpose and cannot put anything back.',
        'entry_tampered' => 'This entry no longer reproduces its own hash, so nothing it holds may be written back.',
        'entry_stateless' => 'This entry holds no earlier state to put back.',
        'cancelled' => 'A listener cancelled the restoration.',
        'unknown_field' => 'The record no longer has a :key.',
        'unrecorded_field' => 'This entry does not record the :key.',
        'identity_field' => 'The :key identifies the record rather than describing its state.',
        'redacted_field' => 'The :key was stored masked, and the original is gone.',
        'hashed_field' => 'The :key was stored as a digest, which cannot be reversed.',
        'key_unavailable' => 'The key that encrypted the :key is not on the keyring.',
        'related_missing' => 'The related record :key no longer exists.',
        'unchanged' => 'The :key already holds the value this entry would put back.',
    ],

    'integrity' => [
        'hash_mismatch' => 'Audit :id no longer reproduces its own hash at sequence :sequence of stream :stream.',
        'link_mismatch' => 'Audit :id does not link to the entry before it at sequence :sequence of stream :stream.',
        'sequence_gap' => 'Stream :stream is missing sequence :sequence, so its chain cannot be followed past it.',
    ],

];
