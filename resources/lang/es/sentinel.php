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
        'write_failed' => 'El asiento :event de :type :id se capturó pero nunca llegó a asentarse: :reason',
    ],

    'presenter' => [
        'entry' => ':actor :event :subject',
        'impersonated' => ':impersonator en nombre de :actor :event :subject',
        'reference' => ':type #:id',
        'someone' => 'Alguien',
        'something' => 'algo',
        'field' => ':ordinal. v:version  :value',
        'relation' => ':line · :relation',
        'attached' => '  + :related',
        'detached' => '  - :related',
        'repivoted' => '  ~ :related',
        'timeline' => ':time  :line',
        'nothing' => 'nada',
        'yes' => 'sí',
        'no' => 'no',
        'structure' => 'una estructura',
    ],

    'events' => [
        'created' => 'creó',
        'updated' => 'cambió',
        'deleted' => 'borró',
        'restored' => 'restauró',
        'force_deleted' => 'borró definitivamente',
        'attached' => 'vinculó',
        'detached' => 'desvinculó',
        'synced' => 'sincronizó',
        'transition' => 'movió',
        'rekeyed' => 'recifró',
        'custom' => 'registró',
    ],

    'integrity' => [
        'hash_mismatch' => 'El asiento :id ya no reproduce su propio hash en la secuencia :sequence del stream :stream.',
        'link_mismatch' => 'El asiento :id no enlaza con el anterior en la secuencia :sequence del stream :stream.',
        'sequence_gap' => 'Al stream :stream le falta la secuencia :sequence, así que su cadena no se puede seguir más allá.',
    ],

];
