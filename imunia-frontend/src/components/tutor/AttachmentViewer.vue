<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { TriangleAlert, X } from '@lucide/vue'

/**
 * Visualizador de anexo (T08). Abre sobre a tela, em tela cheia no celular, e
 * carrega o arquivo pela rota autorizada — a mesma que a lista recebeu, e a
 * única que existe: o caminho no armazenamento nunca chega ao navegador
 * (RF32c).
 */
defineProps({
  anexo: { type: Object, required: true },
})

const emit = defineEmits(['fechar'])

const falhou = ref(false)
const fechar = ref(null)

function aoTeclar(evento) {
  if (evento.key === 'Escape') emit('fechar')
}

onMounted(() => {
  document.addEventListener('keydown', aoTeclar)
  // O foco entra no visualizador junto com ele; sem isto, o teclado
  // continuaria na lista atrás da cortina.
  fechar.value?.focus()
})

onBeforeUnmount(() => document.removeEventListener('keydown', aoTeclar))
</script>

<template>
  <div class="visualizador">
    <div class="visualizador__cortina" @click="emit('fechar')" />

    <div class="visualizador__janela" role="dialog" aria-modal="true" :aria-label="anexo.descricao">
      <header class="visualizador__cabecalho">
        <h2 class="visualizador__titulo">{{ anexo.descricao }}</h2>
        <button ref="fechar" type="button" class="visualizador__fechar" @click="emit('fechar')">
          <X :size="20" :stroke-width="1.75" />
          <span class="visually-hidden">Fechar o anexo</span>
        </button>
      </header>

      <div class="visualizador__corpo">
        <div v-if="falhou" class="visualizador__falha" role="alert">
          <TriangleAlert :size="20" :stroke-width="1.75" class="visualizador__falha-icone" />
          <p class="visualizador__falha-texto">
            Não conseguimos carregar este anexo agora. O restante do atendimento continua disponível.
          </p>
        </div>

        <img
          v-else-if="anexo.tipo === 'imagem'"
          :src="anexo.url"
          :alt="anexo.descricao"
          class="visualizador__imagem"
          @error="falhou = true"
        />

        <!-- Documento: o navegador tem visualizador de PDF próprio, e é ele que
             o tutor já sabe usar. A ligação abaixo cobre quem não tem. -->
        <iframe
          v-else
          :src="anexo.url"
          :title="anexo.descricao"
          class="visualizador__documento"
        />
      </div>

      <footer class="visualizador__rodape">
        <a :href="anexo.url" target="_blank" rel="noopener" class="visualizador__externo">
          Abrir em nova aba
        </a>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.visualizador {
  position: fixed;
  inset: 0;
  z-index: 30;
  display: flex;
  align-items: stretch;
  justify-content: center;
}

.visualizador__cortina {
  position: absolute;
  inset: 0;
  background: rgb(20 35 31 / .48);
}

.visualizador__janela {
  position: relative;
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
  background: var(--surface-card);
}

.visualizador__cabecalho {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  flex: none;
  height: 56px;
  padding: 0 var(--space-4);
  border-bottom: 1px solid var(--border-hairline);
}

.visualizador__titulo {
  flex: 1;
  min-width: 0;
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.visualizador__fechar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 44px;
  height: 44px;
  margin-right: calc(var(--space-3) * -1);
  background: none;
  border: none;
  color: var(--ink-muted);
  cursor: pointer;
}

.visualizador__fechar:hover {
  color: var(--ink);
}

.visualizador__corpo {
  flex: 1;
  min-height: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--space-4);
  background: var(--surface-sunken);
}

.visualizador__imagem {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}

.visualizador__documento {
  width: 100%;
  height: 100%;
  border: none;
  background: var(--surface-card);
}

.visualizador__falha {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  max-width: 480px;
  padding: var(--space-4);
  background: var(--status-late-wash);
  border: 1px solid var(--status-late);
  border-radius: var(--radius-md);
}

.visualizador__falha-icone {
  flex: none;
  margin-top: 2px;
  color: var(--status-late);
}

.visualizador__falha-texto {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.visualizador__rodape {
  flex: none;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  padding: 0 var(--space-4);
  border-top: 1px solid var(--border-hairline);
}

.visualizador__externo {
  display: inline-flex;
  align-items: center;
  height: 48px;
  font-size: 16px;
  font-weight: 600;
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}

/* A partir de md o anexo deixa de ocupar a tela inteira: o prontuário atrás
   continua visível, e fechar o visualizador é voltar ao lugar de onde se saiu. */
@media (min-width: 768px) {
  .visualizador {
    align-items: center;
    padding: var(--space-8);
  }

  .visualizador__janela {
    flex: none;
    width: min(880px, 100%);
    height: min(720px, 100%);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-modal);
    overflow: hidden;
  }
}
</style>
