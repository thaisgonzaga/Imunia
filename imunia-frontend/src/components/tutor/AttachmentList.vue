<script setup>
import { Paperclip, TriangleAlert } from '@lucide/vue'
import { emNumeros } from '@/lib/datas.js'

/**
 * Os exames e documentos do atendimento (RF32). Cada item diz o que é, de
 * quando é e abre pelo visualizador — nunca por endereço direto do arquivo, que
 * a API sequer devolve (RF32c).
 *
 * O anexo que não responde vira um aviso no lugar dele, e só ali: um exame que
 * falhou não pode levar o prontuário inteiro junto, porque o que está escrito
 * no registro continua íntegro e continua sendo o que o tutor veio ler.
 */
const props = defineProps({
  anexos: { type: Array, required: true },
  // Serve de data do anexo que não tem data de exame própria — uma foto da
  // consulta é do dia da consulta, e afirmar isso é melhor do que calar.
  dataDoRegistro: { type: String, required: true },
})

defineEmits(['abrir', 'recarregar'])

/**
 * "exame de 15/11/2025 · documento" quando o anexo tem data própria — que
 * quase nunca é a da consulta, porque o laudo chega depois dela.
 */
function descreverOrigem(anexo) {
  const data = anexo.exame_em
    ? `exame de ${emNumeros(anexo.exame_em)}`
    : emNumeros(props.dataDoRegistro)

  return `${data} · ${anexo.tipo}`
}
</script>

<template>
  <section class="anexos">
    <div class="anexos__cabecalho">
      <h2 class="anexos__rotulo">Anexos</h2>
      <span v-if="anexos.length" class="anexos__contador">{{ anexos.length }}</span>
    </div>

    <div v-if="anexos.length === 0" class="anexos__vazio">
      <Paperclip :size="32" :stroke-width="1.75" class="anexos__vazio-icone" />
      <p class="anexos__vazio-titulo">Nenhum anexo neste atendimento</p>
      <p class="anexos__vazio-texto">
        Exames e imagens, quando existirem, são anexados pelo veterinário. Você pode pedir à clínica
        que anexe um exame feito fora dela.
      </p>
      <slot name="acao-vazio" />
    </div>

    <template v-else>
      <template v-for="anexo in anexos" :key="anexo.id">
        <div v-if="anexo.disponivel" class="anexo">
          <Paperclip :size="20" :stroke-width="1.75" class="anexo__icone" />
          <div class="anexo__dados">
            <span class="anexo__descricao">{{ anexo.descricao }}</span>
            <span class="anexo__origem">{{ descreverOrigem(anexo) }}</span>
          </div>
          <button type="button" class="anexo__abrir" @click="$emit('abrir', anexo)">
            Abrir
            <span class="visually-hidden">{{ anexo.descricao }}</span>
          </button>
        </div>

        <div v-else class="anexo anexo--indisponivel">
          <TriangleAlert :size="20" :stroke-width="1.75" class="anexo__icone anexo__icone--alerta" />
          <div class="anexo__dados">
            <span class="anexo__descricao">{{ anexo.descricao }}</span>
            <p class="anexo__falha">
              Não conseguimos carregar este anexo agora. O restante do atendimento continua disponível.
            </p>
            <button type="button" class="botao botao--secundario" @click="$emit('recarregar')">
              Tentar novamente
            </button>
          </div>
        </div>
      </template>
    </template>
  </section>
</template>

<style scoped>
.anexos {
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
  overflow: hidden;
}

.anexos__cabecalho {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-4);
}

.anexos__rotulo {
  flex: 1;
  min-width: 0;
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.anexos__contador {
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

/* Vazio ------------------------------------------------------------------- */

.anexos__vazio {
  padding: var(--space-6) var(--space-4) var(--space-8);
  border-top: 1px solid var(--border-hairline);
  text-align: center;
}

.anexos__vazio-icone {
  color: var(--ink-faint);
}

.anexos__vazio-titulo {
  margin: var(--space-3) 0 0;
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.anexos__vazio-texto {
  margin: var(--space-2) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
  text-wrap: pretty;
}

/* Item -------------------------------------------------------------------- */

.anexo {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  border-top: 1px solid var(--border-hairline);
}

.anexo--indisponivel {
  align-items: flex-start;
  background: var(--status-late-wash);
}

.anexo__icone {
  flex: none;
  color: var(--ink-muted);
}

.anexo__icone--alerta {
  margin-top: 2px;
  color: var(--status-late);
}

.anexo__dados {
  flex: 1;
  min-width: 0;
}

.anexo__descricao {
  display: block;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.anexo__origem {
  display: block;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.anexo__falha {
  margin: var(--space-1) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
  text-wrap: pretty;
}

.anexo__abrir {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: none;
  height: 48px;
  padding: 0 var(--space-3);
  background: none;
  border: none;
  font-family: var(--font-body);
  font-size: 16px;
  font-weight: 600;
  color: var(--brand-bright);
  cursor: pointer;
}

.anexo__abrir:hover {
  color: var(--brand-hover);
}

.botao {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 48px;
  margin: var(--space-2) 0 0;
  padding: 0 var(--space-4);
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
}

.botao--secundario {
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  color: var(--ink);
}

.botao--secundario:hover {
  background: var(--surface-sunken);
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}

@media (min-width: 768px) {
  .anexos__cabecalho,
  .anexo {
    padding-left: var(--space-5, 20px);
    padding-right: var(--space-5, 20px);
  }
}
</style>
