<?php

namespace App\Support;

/**
 * A versão vigente dos Termos de Uso e da Política de Privacidade.
 *
 * Existe porque `tutores.termos_aceitos_em` sozinho não prova coisa alguma: a
 * data diz *quando* alguém aceitou, não *o que* aceitou. Quando o texto muda, o
 * aceite antigo passa a apontar para um documento que ninguém leu, e o ônus da
 * prova do art. 8º, § 2º da LGPD — que é do controlador — fica sem lastro.
 *
 * A versão é gravada pelo servidor, nunca aceita do cliente. Quem envia o
 * formulário declara que aceita; qual documento estava vigente naquele instante
 * é fato do sistema, não afirmação de quem se cadastra.
 *
 * O texto correspondente está em `docs/imunia-termos-de-uso.md` e
 * `docs/imunia-politica-de-privacidade.md`, e é renderizado em `/termos` e
 * `/privacidade`. Ao publicar uma versão nova, mude os três lugares: os dois
 * documentos, esta constante e `imunia-frontend/src/lib/documentosLegais.js`.
 */
final class DocumentosLegais
{
    /**
     * Versão semântica curta. Muda quando o texto muda de forma que afete o
     * que a pessoa aceitou — correção de vírgula não é versão nova.
     */
    public const VERSAO = '1.0';

    public const VIGENTE_DESDE = '2026-09-08';
}
