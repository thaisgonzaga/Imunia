<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { BookOpen } from '@lucide/vue'
import { emHoras, emNumeros } from '@/lib/datas.js'
import { iconeDaSituacao } from '@/lib/notificacoes.js'

/**
 * O detalhe de uma notificação enviada (T17, RF45) — "item → detalhe em folha
 * inferior", como o briefing pede.
 *
 * A linha da tabela já diz quando, o quê, para quem e se chegou. A folha existe
 * para as duas coisas que não cabem numa célula: de que dose o aviso tratava, e
 * o que a situação de envio quer dizer para quem está procurando a mensagem e
 * não a encontrou.
 *
 * Folha inferior no celular e caixa centralizada a partir de `md`, com a mesma
 * mecânica de foco do `ConfirmDialog`: não é decisão, mas é modal, e o teclado
 * não pode escapar para a página de trás.
 */
const props = defineProps({
  aberto: { type: Boolean, default: false },
  notificacao: { type: Object, default: null },
})

const emit = defineEmits(['fechar'])

const folha = ref(null)
let focoAnterior = null

const icone = computed(() => iconeDaSituacao(props.notificacao?.situacao))

function aoTeclar(evento) {
  if (evento.key === 'Escape') {
    emit('fechar')
    return
  }

  if (evento.key !== 'Tab' || !folha.value) return

  const focaveis = folha.value.querySelectorAll('a[href], button:not([disabled])')
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
    folha.value?.querySelector('.folha__fechar')?.focus()
    return
  }

  document.removeEventListener('keydown', aoTeclar)
  // De volta à linha que abriu a folha: quem percorre a tabela pelo teclado
  // continua de onde parou.
  focoAnterior?.focus?.()
  focoAnterior = null
})

onBeforeUnmount(() => document.removeEventListener('keydown', aoTeclar))
</script>

<template>
  <Teleport to="body">
    <Transition name="folha">
      <div v-if="aberto && notificacao" class="folha__fundo" @click.self="$emit('fechar')">
        <div
          ref="folha"
          class="folha"
          role="dialog"
          aria-modal="true"
          aria-labelledby="notificacao-titulo"
        >
          <div class="folha__alca" aria-hidden="true" />

          <div class="folha__cabecalho">
            <span class="situacao" :class="`situacao--${notificacao.situacao}`">
              <component :is="icone" :size="14" :stroke-width="1.75" />
              {{ notificacao.situacao_rotulo }}
            </span>
            <h2 id="notificacao-titulo" class="folha__titulo">{{ notificacao.tipo_rotulo }}</h2>
          </div>

          <dl class="dados">
            <div class="dados__item">
              <dt>Animal</dt>
              <dd>{{ notificacao.animal.nome }}</dd>
            </div>
            <div class="dados__item">
              <dt>Vacina</dt>
              <dd :class="{ 'dados__ausente': !notificacao.vacina }">
                {{ notificacao.vacina ?? 'Vacina não identificada' }}
              </dd>
            </div>
            <div class="dados__item">
              <dt>Dose prevista para</dt>
              <dd class="dados__numeros">{{ emNumeros(notificacao.referente_a) }}</dd>
            </div>
            <div class="dados__item">
              <dt>Enviada em</dt>
              <dd class="dados__numeros">
                {{ emNumeros(notificacao.data) }} às {{ emHoras(notificacao.hora) }}
              </dd>
            </div>
            <div class="dados__item">
              <dt>Para</dt>
              <dd class="dados__numeros">{{ notificacao.destinatario }}</dd>
            </div>
          </dl>

          <div class="explicacao" :class="`explicacao--${notificacao.situacao}`">
            <p class="explicacao__texto">{{ notificacao.explicacao }}</p>

            <!-- RF06 — a mensagem foi para um endereço que a conta já não usa.
                 Dito aqui porque é o que tranquiliza diante de uma falha: os
                 próximos lembretes vão para outro lugar. -->
            <p v-if="notificacao.endereco_anterior" class="explicacao__texto">
              Este não é mais o e-mail da sua conta: os próximos lembretes vão para o endereço atual.
            </p>

            <RouterLink
              v-else-if="notificacao.situacao === 'falhou'"
              to="/conta"
              class="explicacao__ligacao"
            >
              Conferir meu e-mail em Minha conta
            </RouterLink>
          </div>

          <div class="folha__acoes">
            <RouterLink
              v-if="notificacao.animal.do_tutor"
              :to="`/animais/${notificacao.animal.codigo}/carteira`"
              class="botao botao--secundario"
            >
              <BookOpen :size="20" :stroke-width="1.75" />
              Ver a carteira de {{ notificacao.animal.nome }}
            </RouterLink>
            <button type="button" class="botao botao--primario folha__fechar" @click="$emit('fechar')">
              Fechar
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.folha__fundo {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  background: rgba(20, 35, 31, .32);
}

.folha {
  max-height: 90vh;
  overflow-y: auto;
  background: var(--surface-card);
  border-radius: var(--radius-lg) var(--radius-lg) 0 0;
  box-shadow: var(--shadow-modal);
}

.folha__alca {
  width: 40px;
  height: 4px;
  margin: var(--space-2) auto 0;
  background: var(--border-strong);
  border-radius: var(--radius-pill);
}

.folha__cabecalho {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: var(--space-2);
  padding: var(--space-4) var(--space-4) var(--space-2);
}

.folha__titulo {
  margin: 0;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--ink);
}

/* Situação — ícone e texto sempre juntos: a cor reforça, não carrega sozinha
   o significado (§4.1 dos tokens). Mesmo desenho da célula da tabela. */
.situacao {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 22px;
  padding: 0 10px;
  white-space: nowrap;
  border-radius: var(--radius-pill);
  background: var(--surface-card);
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
}

.situacao--entregue {
  border: 1px solid var(--status-ok);
  color: var(--status-ok);
}

.situacao--sem_confirmacao {
  border: 1px solid var(--border-strong);
  color: var(--ink-muted);
}

.situacao--falhou {
  border: 1px solid var(--status-late);
  color: var(--status-late);
}

/* Dados ------------------------------------------------------------------- */

.dados {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  margin: 0;
  padding: var(--space-2) var(--space-4) var(--space-4);
}

.dados__item {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.dados dt {
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.dados dd {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.dados__numeros {
  font-variant-numeric: tabular-nums;
}

/* Ausência é informação: `--ink-muted`, que passa no contraste (§9.1). */
.dados dd.dados__ausente {
  color: var(--ink-muted);
}

/* Explicação --------------------------------------------------------------- */

.explicacao {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin: 0 var(--space-4) var(--space-4);
  padding: var(--space-3) var(--space-4);
  border-radius: var(--radius-sm);
  background: var(--surface-sunken);
  border: 1px solid var(--border-hairline);
}

.explicacao--falhou {
  background: var(--status-late-wash);
  border-color: var(--status-late);
}

.explicacao__texto {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.explicacao__ligacao {
  align-self: flex-start;
  display: inline-flex;
  align-items: center;
  min-height: 44px;
  font-size: 16px;
  font-weight: 600;
  color: var(--ink);
  text-decoration: underline;
}

/* Ações ------------------------------------------------------------------- */

.folha__acoes {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  padding: var(--space-4);
  border-top: 1px solid var(--border-hairline);
}

.botao {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  width: 100%;
  height: 48px;
  padding: 0 var(--space-6);
  border: 1px solid transparent;
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 16px;
  font-weight: 600;
  text-decoration: none;
  cursor: pointer;
}

.botao--primario {
  background: var(--brand);
  border-color: var(--brand);
  color: var(--surface-card);
}

.botao--primario:hover {
  background: var(--brand-hover);
  border-color: var(--brand-hover);
}

.botao--secundario {
  background: var(--surface-card);
  border-color: var(--border-strong);
  color: var(--ink);
}

.botao--secundario:hover {
  background: var(--surface-sunken);
}

/* Transição ---------------------------------------------------------------- */

.folha-enter-active,
.folha-leave-active {
  transition: opacity 160ms cubic-bezier(.2, 0, 0, 1);
}

.folha-enter-active .folha,
.folha-leave-active .folha {
  transition: transform 160ms cubic-bezier(.2, 0, 0, 1);
}

.folha-enter-from,
.folha-leave-to {
  opacity: 0;
}

.folha-enter-from .folha,
.folha-leave-to .folha {
  transform: translateY(16px);
}

@media (min-width: 768px) {
  .folha__fundo {
    justify-content: center;
    align-items: center;
    padding: var(--space-6);
  }

  .folha {
    width: 100%;
    max-width: 480px;
    border-radius: var(--radius-lg);
  }

  .folha__alca {
    display: none;
  }

  .folha__cabecalho {
    padding-top: var(--space-6);
  }

  .folha-enter-from .folha,
  .folha-leave-to .folha {
    transform: scale(.98);
  }
}

@media (prefers-reduced-motion: reduce) {
  .folha-enter-active,
  .folha-leave-active,
  .folha-enter-active .folha,
  .folha-leave-active .folha {
    transition-duration: 1ms;
  }
}
</style>
