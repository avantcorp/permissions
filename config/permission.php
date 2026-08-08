<?php

declare(strict_types=1);

return [
    'policy_path' => app_path(),

    'models_namespace'   => '\\App\\Models',
    'policies_namespace' => '\\App\\Policies',

    'modules' => [
        'namespace'          => '\\App\\Modules',
        'models_namespace'   => 'Models',
        'policies_namespace' => 'Policies',
    ],
];
