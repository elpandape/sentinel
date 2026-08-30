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
        'checkpoint' => [
            'description' => 'Ancla todas las ventanas completas que los streams tengan pendientes',
            'columns' => [
                'stream' => 'Stream',
                'from' => 'Desde',
                'to' => 'Hasta',
                'root' => 'Raíz',
            ],
            'none' => 'No queda nada que anclar: ningún stream tiene una ventana completa que las anclas no cubran ya.',
            'anchored' => 'Se anclaron :count rangos.',
            'failed' => 'No se ancló nada: :reason',
        ],
        'prune' => [
            'description' => 'Aplica las políticas de retención y reporta lo que se fue',
            'columns' => [
                'stream' => 'Stream',
                'ranges' => 'Rangos',
                'entries' => 'Asientos',
                'rate' => 'Ritmo',
                'note' => 'Nota',
            ],
            'per_second' => ':rate/s',
            'nothing' => 'No se retiró nada. La nota de cada stream dice qué lo está reteniendo.',
            'removed' => 'Se retiraron :entries asientos en :windows rangos de :streams streams.',
            'planned' => 'Se retirarían :entries asientos en :windows rangos de :streams streams. No se tocó nada.',
            'unknown_action' => 'No existe la acción :action. Pasa una de: :accepted.',
            'failed' => 'No se retiró nada: :reason',
        ],
        'verify' => [
            'description' => 'Recorre la cadena y reporta lo que encuentra',
            'columns' => [
                'stream' => 'Stream',
                'entries' => 'Asientos',
                'chain' => 'Cadena',
                'anchors' => 'Anclas',
                'signatures' => 'Firmas',
            ],
            'states' => [
                'signed' => 'firmados',
                'unsigned' => 'sin firmar',
                'invalid' => 'FIRMA INVÁLIDA',
                'unknown_key' => 'clave desconocida',
            ],
            'anchor_states' => [
                'anchored' => 'ancladas',
                'archived' => 'retiradas',
                'absent' => 'ninguna',
            ],
            'covering' => 'que cubren :covered asientos que nadie leyó',
            'retired_entries' => '(+:archived retirados)',
            'sound' => 'íntegra',
            'broken' => 'ROTA',
            'intact' => 'Verificados :entries asientos en :streams streams. La cadena está íntegra.',
            'anchored' => 'Se leyeron :entries asientos y se dieron por buenos :covered por la palabra de sus anclas, en :streams streams. No salió nada mal, que no es lo mismo que haber leído todos los asientos.',
            'retired' => 'Se leyeron :entries asientos, se dieron por buenos :covered por la palabra de sus anclas y se pasó por encima de :archived que ya no están, en :streams streams. No salió nada mal. Tampoco se leyó nada de los asientos que faltan: lo que responde por ellos es el ancla que los cubre.',
            'unscoped_range' => 'Un rango de secuencias es una pregunta sobre un solo stream: los mismos números significan asientos distintos en cada uno. Pasa --stream junto a --from y --to.',
            'unscoped_depth' => 'Un rango de secuencias es lo que responde la profundidad entries. Los recorridos más someros cubren lo que cubran las anclas, así que no aceptan --from ni --to.',
            'unknown_depth' => 'No existe la profundidad :depth. Pasa una de: :accepted.',
            'failed' => 'La cadena no se pudo verificar: :reason. No se comprobó nada, que no es lo mismo que que no haya nada mal.',
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

    'retention' => [
        'undeclared' => 'No hay ninguna política de retención declarada, así que :stream lo conserva todo.',
        'unanchored' => 'El stream :stream no tiene anclas. Un rango solo se retira mientras un ancla siga respondiendo por él, así que ancla el historial antes de purgarlo.',
        'tail' => 'Todos los rangos anclados de :stream contienen el asiento con el que enlaza la escritura siguiente. Retirar uno reiniciaría la cadena bajo el mismo nombre, así que no se ofrece ninguno.',
        'retained' => 'La retención todavía conserva :held en la secuencia :sequence de :stream. Un rango se retira entero o no se retira, así que el rango que rodea a ese asiento se queda.',
    ],

    'integrity' => [
        'hash_mismatch' => 'El asiento :id ya no reproduce su propio hash en la secuencia :sequence del stream :stream.',
        'link_mismatch' => 'El asiento :id no enlaza con el anterior en la secuencia :sequence del stream :stream.',
        'sequence_gap' => 'Al stream :stream le falta la secuencia :sequence, así que su cadena no se puede seguir más allá.',
        'signature_mismatch' => 'El asiento :id lleva una firma que su propia clave no valida, en la secuencia :sequence del stream :stream.',
        'projection_mismatch' => 'Las relaciones indexadas del asiento :id ya no coinciden con las líneas que selló, en la secuencia :sequence del stream :stream. La cadena está intacta: la proyección no forma parte de ella.',
        'checkpoint_mismatch' => 'El ancla :id ya no pliega la raíz que registró, sobre el rango del stream :stream que empieza en la secuencia :sequence.',
    ],

];
