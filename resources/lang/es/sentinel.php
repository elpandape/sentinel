<?php

declare(strict_types=1);

return [

    'integrity' => [
        'hash_mismatch' => 'El asiento :id ya no reproduce su propio hash en la secuencia :sequence del stream :stream.',
        'link_mismatch' => 'El asiento :id no enlaza con el anterior en la secuencia :sequence del stream :stream.',
        'sequence_gap' => 'Al stream :stream le falta la secuencia :sequence, así que su cadena no se puede seguir más allá.',
    ],

];
