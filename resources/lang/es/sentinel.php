<?php

declare(strict_types=1);

return [

    'discarded' => [
        'policy' => 'Una policy descartó el asiento :event de :type :id antes de que llegara al ledger.',
        'unchanged' => 'El :event de :type :id no cambió nada de lo que se audita, así que no se escribió ningún asiento.',
        'unspecified' => 'La etapa :stage descartó el asiento :event de :type :id antes de que llegara al ledger.',
    ],

    'ledger' => [
        'destination_failed' => 'El destino :destination no aceptó el asiento :id en la secuencia :sequence del stream :stream: :reason',
    ],

    'integrity' => [
        'hash_mismatch' => 'El asiento :id ya no reproduce su propio hash en la secuencia :sequence del stream :stream.',
        'link_mismatch' => 'El asiento :id no enlaza con el anterior en la secuencia :sequence del stream :stream.',
        'sequence_gap' => 'Al stream :stream le falta la secuencia :sequence, así que su cadena no se puede seguir más allá.',
    ],

];
