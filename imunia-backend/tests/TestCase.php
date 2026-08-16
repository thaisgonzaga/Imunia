<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * O Sanctum só trata a requisição como "de mesma origem" — e portanto só
     * abre sessão — quando reconhece a origem declarada pelo navegador. Sem
     * este cabeçalho, todo teste de autenticação bateria numa requisição sem
     * sessão, cenário que o SPA nunca produz.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', (string) config('app.url'));
    }
}
