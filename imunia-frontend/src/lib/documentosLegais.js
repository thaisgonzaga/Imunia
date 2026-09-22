/**
 * A versão vigente dos Termos de Uso e da Política de Privacidade, exibida no
 * cabeçalho dos dois documentos e ao lado da caixa de aceite.
 *
 * Quem grava a versão no aceite é o servidor — `App\Support\DocumentosLegais`
 * no backend, que é a fonte da verdade. Esta constante existe só para exibir,
 * e precisa ser mudada junto com a de lá ao publicar uma versão nova. Se as
 * duas divergirem, o que fica registrado é a do servidor, e a tela mente.
 */
export const VERSAO = '1.0'

/** Data em que esta versão entrou em vigor, em ISO. */
export const VIGENTE_DESDE = '2026-09-08'

/** A mesma data por extenso, como o briefing pede para data visível (§6.2). */
export const VIGENTE_DESDE_POR_EXTENSO = '8 de setembro de 2026'
