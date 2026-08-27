<?php

declare(strict_types=1);

return [

    'discarded' => [
        'unspecified' => 'Stage :stage discarded the :event entry for :type :id before it reached the ledger.',
    ],

    'integrity' => [
        'hash_mismatch' => 'Audit :id no longer reproduces its own hash at sequence :sequence of stream :stream.',
        'link_mismatch' => 'Audit :id does not link to the entry before it at sequence :sequence of stream :stream.',
        'sequence_gap' => 'Stream :stream is missing sequence :sequence, so its chain cannot be followed past it.',
    ],

];
