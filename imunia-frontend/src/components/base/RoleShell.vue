<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PublicFooter from '@/components/public/PublicFooter.vue'
import PublicHeader from '@/components/public/PublicHeader.vue'
import AdminShell from '@/components/prestador/AdminShell.vue'
import PlataformaShell from '@/components/plataforma/PlataformaShell.vue'
import TutorShell from '@/components/tutor/TutorShell.vue'
import VetShell from '@/components/vet/VetShell.vue'
import { molduraDe } from '@/lib/areas.js'
import { useSessaoStore } from '@/stores/sessao.js'

/**
 * A moldura de quem está do outro lado da tela.
 *
 * Existe para as telas de exceção (§8.1: "centrado na área de conteúdo,
 * preservando a navegação do papel"). Errar uma rota, esbarrar numa área alheia
 * ou topar com uma falha do servidor não pode custar a barra lateral, as abas e
 * a faixa de contexto: quem perde a navegação junto com a página fica sem saída
 * que não seja o botão de voltar do navegador.
 *
 * As molduras são as mesmas dos ambientes, sem variação — reproduzi-las aqui em
 * versão simplificada faria a tela de exceção parecer outro sistema.
 */
const props = defineProps({
  /** Cabeçalho de celular das molduras que o exibem. */
  titulo: { type: String, default: '' },
  /**
   * Dispensa o limite de leitura da moldura, para a tela que traz a própria
   * largura e a quer centrada. O limite de 880 px serve a texto corrido e é
   * alinhado à esquerda; uma coluna de 640 px centrada dentro dele fica fora do
   * centro da área de conteúdo, e a diferença cresce com a tela.
   */
  amplo: { type: Boolean, default: false },
})

const MOLDURAS = {
  clinica: { componente: VetShell, aceitaTitulo: true },
  prestador: { componente: AdminShell, aceitaTitulo: true },
  plataforma: { componente: PlataformaShell, aceitaTitulo: true },
  // A moldura do tutor não tem título de cabeçalho: o lugar dela é a marca. É
  // também a única com limite de leitura próprio, e por isso a única que
  // precisa ser avisada de que esta tela não o quer.
  tutor: { componente: TutorShell, aceitaTitulo: false, aceitaAmplo: true },
}

const route = useRoute()
const router = useRouter()
const sessao = useSessaoStore()

/**
 * A tela de exceção é alcançável sem passar pela guarda de rota — o endereço
 * inexistente e a falha do SPA não pedem autenticação —, e por isso a sessão
 * pode ainda não ter sido consultada quando esta moldura monta. Enquanto a
 * resposta não chega, nada é desenhado: mostrar a moldura pública e trocá-la um
 * instante depois pela do papel seria pior do que esperar.
 */
const pronta = ref(false)

onMounted(async () => {
  await sessao.carregar()
  pronta.value = true
})

const moldura = computed(() => {
  const area = molduraDe(sessao.usuario?.papeis ?? [], route.path)

  return area ? MOLDURAS[area.nome] : null
})

/**
 * Sessão aberta e nenhum ambiente a que pertencer: é o caso de quem teve o
 * vínculo com a clínica encerrado (RF10) e não é tutor de animal algum. A
 * moldura pública não serve — ela convida a entrar e a criar conta quem já fez
 * as duas coisas —, e das quatro do sistema nenhuma lhe abre. Resta a marca e a
 * única ação que ainda lhe pertence.
 */
const semAmbiente = computed(() => sessao.autenticado && moldura.value === null)

async function sair() {
  await sessao.encerrar()
  router.push({ name: 'login' })
}

const atributos = computed(() => ({
  ...(moldura.value?.aceitaTitulo && props.titulo ? { titulo: props.titulo } : {}),
  ...(moldura.value?.aceitaAmplo && props.amplo ? { amplo: true } : {}),
}))
</script>

<template>
  <component :is="moldura.componente" v-if="pronta && moldura" v-bind="atributos">
    <slot />
  </component>

  <div v-else-if="pronta && semAmbiente" class="role-shell">
    <header class="role-shell__cabecalho">
      <span class="role-shell__marca">Imunia</span>
      <button type="button" class="role-shell__sair" @click="sair">Sair da conta</button>
    </header>
    <main class="role-shell__conteudo">
      <slot />
    </main>
  </div>

  <!-- Sem sessão, a navegação do papel é a das telas públicas: quem chegou a
       um endereço que não existe sem estar autenticado precisa de "Entrar" e de
       "Verificar documento" à mão, que é tudo o que o sistema lhe oferece. -->
  <div v-else-if="pronta" class="role-shell">
    <PublicHeader />
    <main class="role-shell__conteudo">
      <slot />
    </main>
    <PublicFooter />
  </div>
</template>

<style scoped>
.role-shell {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  background: var(--surface-page);
}

.role-shell__conteudo {
  display: flex;
  flex: 1;
  align-items: center;
  justify-content: center;
}

.role-shell__cabecalho {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4);
  height: 56px;
  padding: 0 var(--space-4);
  background: var(--surface-card);
  border-bottom: 1px solid var(--border-hairline);
}

.role-shell__marca {
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--brand);
}

.role-shell__sair {
  height: 44px;
  padding: 0 var(--space-3);
  background: none;
  border: 0;
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 14px;
  font-weight: 600;
  color: var(--ink-muted);
  cursor: pointer;
}

.role-shell__sair:hover {
  background: var(--surface-sunken);
  color: var(--ink);
}
</style>
