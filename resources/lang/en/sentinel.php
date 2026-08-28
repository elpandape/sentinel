<?php

declare(strict_types=1);

return [

    'discarded' => [
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

    'integrity' => [
        'hash_mismatch' => 'Audit :id no longer reproduces its own hash at sequence :sequence of stream :stream.',
        'link_mismatch' => 'Audit :id does not link to the entry before it at sequence :sequence of stream :stream.',
        'sequence_gap' => 'Stream :stream is missing sequence :sequence, so its chain cannot be followed past it.',
    ],

];
