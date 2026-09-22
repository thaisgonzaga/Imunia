import { emNumeros } from '@/lib/datas.js'

/**
 * A procedência de um registro clínico, no formato que `ProvenanceChip` espera
 * (§5.2 do briefing: nenhum registro é renderizado sem declarar de onde veio).
 *
 * Uma fonte só, de propósito: o selo de lote (T05, T06) e a entrada da linha do
 * tempo (T07) escrevem a mesma frase sobre o mesmo registro. Duas frases
 * ligeiramente diferentes para a mesma aplicação seriam, num prontuário, duas
 * versões da verdade.
 *
 * @param {{origem: string, aplicador?: object, lancado_por?: object}} registro
 * @returns {{variante: string, texto: string}}
 */
export function procedenciaDe(registro) {
  if (registro.origem === 'pregresso') {
    const nome = registro.lancado_por?.nome ?? 'tutor'
    const em = emNumeros(registro.lancado_por?.em)

    return {
      variante: 'unverified',
      texto: `Histórico pregresso — não verificado · lançado por ${nome}${em ? ` em ${em}` : ''}`,
    }
  }

  const { prestador, nome, crmv } = registro.aplicador ?? {}

  return {
    variante: 'professional',
    texto: [prestador, nome, crmv].filter(Boolean).join(' · '),
  }
}

/**
 * A procedência vista do ambiente clínico (V06): a mesma frase, mais o aviso de
 * que aquela leitura está sendo gravada, quando o registro é de outro prestador
 * (RF52, RN49). É a variante `other-provider` do briefing, a única do sistema
 * que fala sobre quem lê, e não sobre quem escreveu.
 *
 * O pregresso nunca entra aqui: não tem prestador, e chamá-lo de "outro
 * prestador" emprestaria a ele responsabilidade técnica que ninguém assumiu
 * (RN24).
 *
 * @param {{origem: string, aplicador?: object, lancado_por?: object}} registro
 * @param {string|null} prestadorAtivo nome do prestador em cujo contexto se lê
 * @returns {{variante: string, texto: string}}
 */
export function procedenciaNoAmbienteClinico(registro, prestadorAtivo) {
  const procedencia = procedenciaDe(registro)

  if (procedencia.variante !== 'professional') return procedencia

  const deOutro = Boolean(prestadorAtivo) && registro.aplicador?.prestador !== prestadorAtivo

  return deOutro ? { ...procedencia, variante: 'other-provider' } : procedencia
}

/**
 * A mesma frase de `procedenciaDe`, no futuro do pretérito: é o que a
 * pré-visualização de T09 exibe antes de o registro existir. O tempo verbal é
 * a única diferença, e é o que impede que a pré-visualização seja lida como um
 * registro já lançado.
 *
 * @param {{nome?: string, em?: string}} lancadoPor
 * @returns {{variante: string, texto: string}}
 */
export function procedenciaPrevista(lancadoPor) {
  const nome = lancadoPor?.nome ?? 'você'
  const em = emNumeros(lancadoPor?.em)

  return {
    variante: 'unverified',
    texto: `Histórico pregresso — não verificado · será lançado por ${nome}${em ? ` em ${em}` : ''}`,
  }
}

/**
 * A procedência de uma retificação (RF33): quem corrigiu o registro e quando.
 * Variante própria — nem verde de aplicação profissional, nem cinza de não
 * verificado — porque a retificação é um terceiro tipo de fato: registro
 * clínico legítimo que existe por causa de outro.
 *
 * @param {{responsavel?: object, em?: string}} retificacao
 * @returns {{variante: string, texto: string}}
 */
export function procedenciaDaRetificacao(retificacao) {
  const { nome, crmv } = retificacao.responsavel ?? {}
  const em = emNumeros(retificacao.em)

  return {
    variante: 'rectified',
    texto: `Retificado por ${[nome, crmv].filter(Boolean).join(' · ')}${em ? ` · ${em}` : ''}`,
  }
}
