<?php

declare(strict_types=1);

return [

    'integrity' => [
        'hash_mismatch' => 'Audit :id no longer reproduces its own hash at sequence :sequence of stream :stream.',
        'link_mismatch' => 'Audit :id does not link to the entry before it at sequence :sequence of stream :stream.',
        'sequence_gap' => 'Stream :stream is missing sequence :sequence, so its chain cannot be followed past it.',
    ],

];
