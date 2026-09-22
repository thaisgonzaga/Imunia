<script setup>
import { computed, ref } from 'vue'
import { CircleHelp } from '@lucide/vue'
import ProvenanceChip from '@/components/tutor/ProvenanceChip.vue'

/**
 * Uma seção nomeada do prontuário (T08): rótulo, texto do veterinário e, quando
 * o rótulo é jargão, a nota que o explica em linguagem simples.
 *
 * A nota fica fechada até ser pedida. O prontuário é do animal e foi escrito
 * por um profissional: abrir explicações por cima dele sem que ninguém tenha
 * perguntado trataria o tutor como quem não consegue ler o próprio documento.
 *
 * `vinculada` é a outra versão do mesmo campo, quando o registro está
 * encadeado (RF33): lendo o original, é o texto corrigido; lendo a retificação,
 * é o que estava escrito antes. Nos dois casos as duas versões aparecem juntas,
 * porque é comparando que se entende o que mudou.
 */
const props = defineProps({
  rotulo: { type: String, required: true },
  texto: { type: String, required: true },
  nota: { type: String, default: null },
  vinculada: { type: Object, default: null },
})

const notaAberta = ref(false)

// Liga o botão à nota que ele abre, para quem navega por leitor de tela saber
// o que apareceu.
const idDaNota = computed(() => `nota-${props.rotulo.toLowerCase().replace(/\W+/g, '-')}`)
</script>

<template>
  <section class="secao">
    <div class="secao__conteudo">
      <div class="secao__cabecalho">
        <h2 class="secao__rotulo">{{ rotulo }}</h2>
        <button
          v-if="nota"
          type="button"
          class="secao__ajuda"
          :aria-expanded="notaAberta"
          :aria-controls="idDaNota"
          @click="notaAberta = !notaAberta"
        >
          <CircleHelp :size="20" :stroke-width="1.75" />
          <span class="visually-hidden">O que quer dizer "{{ rotulo }}"?</span>
        </button>
      </div>

      <p v-if="nota && notaAberta" :id="idDaNota" class="secao__nota">
        <CircleHelp :size="20" :stroke-width="1.75" class="secao__nota-icone" />
        <span>{{ nota }}</span>
      </p>

      <p class="secao__texto">{{ texto }}</p>
    </div>

    <!-- O fio âmbar à esquerda é o que liga as duas versões: sem ele, seriam
         dois textos soltos sobre o mesmo assunto. -->
    <div v-if="vinculada" class="versao">
      <span class="versao__fio" aria-hidden="true" />
      <div class="versao__conteudo">
        <h3 class="versao__rotulo">{{ vinculada.rotulo }}</h3>
        <p class="versao__texto">{{ vinculada.texto }}</p>
        <p v-if="vinculada.complemento" class="versao__complemento">{{ vinculada.complemento }}</p>
        <ProvenanceChip
          v-if="vinculada.chip"
          :variante="vinculada.chip.variante"
          :texto="vinculada.chip.texto"
          class="versao__chip"
        />
      </div>
    </div>
  </section>
</template>

<style scoped>
.secao:not(:first-child) {
  border-top: 1px solid var(--border-hairline);
}

.secao__conteudo {
  padding: var(--space-4);
}

.secao__cabecalho {
  display: flex;
  align-items: center;
  gap: var(--space-1);
}

.secao__rotulo {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

/* Alvo de 44 px sem esticar a linha do rótulo: a margem negativa devolve ao
   cabeçalho a altura que o botão acrescentaria. */
.secao__ajuda {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 44px;
  height: 44px;
  margin: -12px 0;
  background: transparent;
  border: none;
  border-radius: var(--radius-sm);
  color: var(--brand-bright);
  cursor: pointer;
}

.secao__ajuda:hover {
  background: var(--surface-sunken);
}

.secao__nota {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: var(--space-2) 0 0;
  padding: var(--space-3);
  background: var(--surface-sunken);
  border-radius: var(--radius-sm);
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.secao__nota-icone {
  flex: none;
  margin-top: 2px;
  color: var(--ink-muted);
}

.secao__texto {
  margin: var(--space-2) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
  text-wrap: pretty;
}

/* Versão vinculada — RF33 ------------------------------------------------- */

.versao {
  display: flex;
  border-top: 1px solid var(--border-hairline);
}

.versao__fio {
  flex: none;
  width: 2px;
  margin: var(--space-4) 11px;
  background: var(--status-due);
}

.versao__conteudo {
  flex: 1;
  min-width: 0;
  padding: var(--space-4) var(--space-4) var(--space-4) 0;
}

.versao__rotulo {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--status-due-text);
}

.versao__texto {
  margin: var(--space-2) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
  text-wrap: pretty;
}

.versao__complemento {
  margin: var(--space-2) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.versao__chip {
  margin: var(--space-3) 0 0;
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
  .secao__conteudo {
    padding: var(--space-4) var(--space-5, 20px);
  }

  .versao__conteudo {
    padding: var(--space-4) var(--space-5, 20px) var(--space-4) 0;
  }
}
</style>
