<?php

/*
 * Em produção o disco do contêiner é efêmero — o Render o apaga a cada deploy
 * e a cada hibernação —, e fotos, anexos e PDFs emitidos não podem sumir com
 * ele. Com ARQUIVOS_NA_NUVEM ligado, os dois discos que a aplicação usa passam
 * a gravar no mesmo bucket S3 (Backblaze B2), cada um sob o seu prefixo, e o
 * código segue pedindo `local` e `public` como sempre.
 *
 * O bucket é privado nos dois casos: a foto do animal é dado de identificação
 * (RN20) e sai por endereço assinado e temporário — ver Animal::fotoUrl().
 */
$naNuvem = fn (string $prefixo): array => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    'root' => $prefixo,
    'visibility' => 'private',
    // O SDK da AWS passou a mandar somas de verificação em toda escrita;
    // provedores compatíveis com S3 nem sempre as aceitam. Só quando exigido.
    'request_checksum_calculation' => 'when_required',
    'response_checksum_validation' => 'when_required',
    'throw' => false,
    // Sem isto, a falha de gravação no bucket volta só como `false`, e o log
    // de produção não diria por quê.
    'report' => true,
];

$arquivosNaNuvem = (bool) env('ARQUIVOS_NA_NUVEM', false);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => $arquivosNaNuvem ? $naNuvem('privado') : [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => $arquivosNaNuvem ? $naNuvem('publico') : [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
