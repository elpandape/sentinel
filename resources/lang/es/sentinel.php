<?php

declare(strict_types=1);

return [

    'commands' => [
        'flush' => [
            'description' => 'Asienta todo lo que el buffer de auditoría está reteniendo',
            'settled' => 'Se asentaron :count entradas del buffer.',
            'not_buffered' => 'Sentinel está escribiendo en modo :mode, así que no hay nada esperando en un buffer. Pon el modo en buffered antes de vaciar uno.',
            'failed' => 'El buffer no se pudo asentar: :reason. No se perdió nada: lo que no se asentó volvió al buffer.',
        ],
    ],

    'discarded' => [
        'cancelled' => 'Un listener canceló el asiento :event de :type :id antes de que llegara al ledger.',
        'policy' => 'Una policy descartó el asiento :event de :type :id antes de que llegara al ledger.',
        'unchanged' => 'El :event de :type :id no cambió nada de lo que se audita, así que no se escribió ningún asiento.',
        'unspecified' => 'La etapa :stage descartó el asiento :event de :type :id antes de que llegara al ledger.',
    ],

    'ledger' => [
        'destination_failed' => 'El destino :destination no aceptó el asiento :id en la secuencia :sequence del stream :stream: :reason',
        'write_failed' => 'La escritura diferida del asiento :event de :type :id no se completó: :reason',
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
        'mass' => ':count registros de :type',
        'timeline' => ':time  :line',
        'transition' => ':line · :from → :to',
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
        'upserted' => 'insertó o actualizó',
        'transition' => 'movió',
        'restore' => 'restauró el estado de',
        'rekeyed' => 'recifró',
        'custom' => 'registró',
        'login' => 'inició sesión',
        'logout' => 'cerró sesión',
        'failed' => 'fue rechazado',
        'lockout' => 'fue bloqueado',
        'password_reset' => 'restableció la contraseña de',
    ],

    'transitions' => [
        'illegal' => ':subject no puede mover su :attribute de :from a :to.',
    ],

    'restore' => [
        'subject_missing' => 'El registro del que habla este asiento ya no existe, así que no hay nada que restaurar.',
        'entry_redacted' => 'Este asiento está redactado: su contenido se destruyó a propósito y no puede devolver nada.',
        'entry_tampered' => 'Este asiento ya no reproduce su propio hash, así que nada de lo que guarda puede escribirse de vuelta.',
        'entry_stateless' => 'Este asiento no guarda ningún estado anterior que devolver.',
        'cancelled' => 'Un listener canceló la restauración.',
        'unknown_field' => 'El registro ya no tiene :key.',
        'unrecorded_field' => 'Este asiento no registra el campo :key.',
        'identity_field' => 'El campo :key identifica al registro en vez de describir su estado.',
        'redacted_field' => 'El campo :key se guardó enmascarado, y el original ya no está.',
        'hashed_field' => 'El campo :key se guardó como digest, y un digest no se revierte.',
        'key_unavailable' => 'La clave que cifró :key no está en el llavero.',
        'related_missing' => 'El registro relacionado :key ya no existe.',
        'unchanged' => 'El campo :key ya tiene el valor que este asiento devolvería.',
    ],

    'integrity' => [
        'hash_mismatch' => 'El asiento :id ya no reproduce su propio hash en la secuencia :sequence del stream :stream.',
        'link_mismatch' => 'El asiento :id no enlaza con el anterior en la secuencia :sequence del stream :stream.',
        'sequence_gap' => 'Al stream :stream le falta la secuencia :sequence, así que su cadena no se puede seguir más allá.',
    ],

];
