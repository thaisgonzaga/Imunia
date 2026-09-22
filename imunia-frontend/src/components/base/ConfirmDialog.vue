<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import AppButton from '@/components/base/AppButton.vue'

/**
 * `ConfirmDialog` (§5.2 do briefing) — específico para ações irreversíveis.
 * A estrutura é obrigatória e não é decorativa: título que nomeia a ação, um
 * bloco **O que acontece** e um bloco **O que não acontece**, ambos em lista.
 *
 * O segundo bloco é o que cumpre P3. Um diálogo que só enumera consequências
 * assusta sem informar; é dizer o que *não* vai acontecer que permite decidir
 * — e é justamente o que falta nos avisos que as pessoas aprenderam a fechar
 * sem ler.
 *
 * Folha inferior no celular e caixa centralizada a partir de `md`, como no
 * desenho: a mão que decide está no polegar, e o polegar alcança o rodapé.
 */
const props = defineProps({
  aberto: { type: Boolean, default: false },
  titulo: { type: String, required: true },
  acontece: { type: Array, required: true },
  naoAcontece: { type: Array, required: true },
  rotuloConfirmar: { type: String, required: true },
  rotuloCancelar: { type: String, default: 'Voltar e revisar' },
  // Nem toda ação irreversível é destrutiva: lançar histórico pregresso (T09)
  // cria um registro, revogar uma autorização (T12) encerra um acesso. A cor
  // acompanha o que a ação faz, e não o fato de ela não ter volta.
  varianteConfirmar: { type: String, default: 'primary' },
  carregando: { type: Boolean, default: false },
})

const emit = defineEmits(['confirmar', 'cancelar'])

const folha = ref(null)
let focoAnterior = null

function fechar() {
  if (props.carregando) return
  emit('cancelar')
}

/**
 * O foco não pode escapar para a página de trás enquanto a decisão está
 * pendente: quem navega por teclado precisa poder percorrer o diálogo inteiro
 * — inclusive a lista do que não acontece — sem sair dele por engano.
 */
function aoTeclar(evento) {
  if (evento.key === 'Escape') {
    fechar()
    return
  }

  if (evento.key !== 'Tab' || !folha.value) return

  const focaveis = folha.value.querySelectorAll('button:not([disabled])')
  if (focaveis.length === 0) return

  const primeiro = focaveis[0]
  const ultimo = focaveis[focaveis.length - 1]

  if (evento.shiftKey && document.activeElement === primeiro) {
    evento.preventDefault()
    ultimo.focus()
  } else if (!evento.shiftKey && document.activeElement === ultimo) {
    evento.preventDefault()
    primeiro.focus()
  }
}

watch(() => props.aberto, async (aberto) => {
  if (aberto) {
    focoAnterior = document.activeElement
    document.addEventListener('keydown', aoTeclar)
    await nextTick()
    folha.value?.querySelector('button')?.focus()
    return
  }

  document.removeEventListener('keydown', aoTeclar)
  // De volta ao botão que abriu o diálogo: desistir tem de devolver a pessoa
  // exatamente onde ela estava.
  focoAnterior?.focus?.()
  focoAnterior = null
})

onBeforeUnmount(() => document.removeEventListener('keydown', aoTeclar))
</script>

<template>
  <Teleport to="body">
    <Transition name="confirm">
      <div
        v-if="aberto"
        class="confirm__fundo"
        @click.self="fechar"
      >
        <div
          ref="folha"
          class="confirm__folha"
          role="dialog"
          aria-modal="true"
          :aria-label="titulo"
        >
          <div class="confirm__alca" aria-hidden="true" />

          <h2 class="confirm__titulo">{{ titulo }}</h2>

          <div class="confirm__corpo">
            <div class="confirm__rotulo">O que acontece</div>
            <ul class="confirm__lista">
              <li v-for="item in acontece" :key="item">{{ item }}</li>
            </ul>

            <div class="confirm__rotulo">O que não acontece</div>
            <ul class="confirm__lista confirm__lista--ultima">
              <li v-for="item in naoAcontece" :key="item">{{ item }}</li>
            </ul>
          </div>

          <div class="confirm__acoes">
            <AppButton :variant="varianteConfirmar" :loading="carregando" @click="$emit('confirmar')">
              {{ rotuloConfirmar }}
            </AppButton>
            <AppButton variant="secondary" :disabled="carregando" @click="fechar">
              {{ rotuloCancelar }}
            </AppButton>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.confirm__fundo {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  background: rgba(20, 35, 31, .32);
}

.confirm__folha {
  max-height: 90vh;
  overflow-y: auto;
  background: var(--surface-card);
  border-radius: var(--radius-lg) var(--radius-lg) 0 0;
  box-shadow: var(--shadow-modal);
}

.confirm__alca {
  width: 40px;
  height: 4px;
  margin: var(--space-2) auto 0;
  background: var(--border-strong);
  border-radius: var(--radius-pill);
}

.confirm__titulo {
  margin: 0;
  padding: var(--space-4) var(--space-4) var(--space-2);
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--ink);
}

.confirm__corpo {
  padding: 0 var(--space-4) var(--space-4);
}

.confirm__rotulo {
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink);
}

.confirm__lista {
  margin: var(--space-2) 0 var(--space-4);
  padding: 0 0 0 20px;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.confirm__lista--ultima {
  margin-bottom: 0;
}

.confirm__lista li + li {
  margin-top: var(--space-2);
}

.confirm__acoes {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  padding: var(--space-4);
  border-top: 1px solid var(--border-hairline);
}

.confirm__acoes :deep(.app-button) {
  width: 100%;
}

.confirm-enter-active,
.confirm-leave-active {
  transition: opacity 160ms cubic-bezier(.2, 0, 0, 1);
}

.confirm-enter-active .confirm__folha,
.confirm-leave-active .confirm__folha {
  transition: transform 160ms cubic-bezier(.2, 0, 0, 1);
}

.confirm-enter-from,
.confirm-leave-to {
  opacity: 0;
}

.confirm-enter-from .confirm__folha,
.confirm-leave-to .confirm__folha {
  transform: translateY(16px);
}

/* A partir de `md` a folha vira caixa centralizada: o polegar deixa de ser o
   que decide, e a decisão passa a merecer o centro da tela. */
@media (min-width: 768px) {
  .confirm__fundo {
    justify-content: center;
    align-items: center;
    padding: var(--space-6);
  }

  .confirm__folha {
    width: 100%;
    max-width: 480px;
    border-radius: var(--radius-lg);
  }

  .confirm__alca {
    display: none;
  }

  .confirm__titulo {
    padding-top: var(--space-6);
  }

  .confirm-enter-from .confirm__folha,
  .confirm-leave-to .confirm__folha {
    transform: scale(.98);
  }
}

@media (prefers-reduced-motion: reduce) {
  .confirm-enter-active,
  .confirm-leave-active,
  .confirm-enter-active .confirm__folha,
  .confirm-leave-active .confirm__folha {
    transition-duration: 1ms;
  }
}
</style>
