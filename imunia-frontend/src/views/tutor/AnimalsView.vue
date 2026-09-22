<script setup>
import { computed, onMounted, ref } from 'vue'
import { Cat, Dog, PawPrint, TriangleAlert } from '@lucide/vue'
import TutorShell from '@/components/tutor/TutorShell.vue'
import AnimalCard from '@/components/tutor/AnimalCard.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import { apiGet } from '@/lib/api.js'

/**
 * T02 — relação de animais do tutor (RF16). Objetivo único: listar quem está
 * sob a titularidade de Helena e abrir caminho para o cadastro de um novo
 * (T03). Nenhum deslize de exclusão mora aqui — o tutor não exclui animal.
 */
const animais = ref([])
const carregando = ref(true)
const erro = ref('')

// §5.1 do briefing — filtro de espécie só ganha sentido, e só aparece na
// tela, quando a lista já é grande o bastante para precisar dele.
const filtroEspecie = ref('todas')

async function carregar() {
  carregando.value = true
  erro.value = ''

  try {
    const resposta = await apiGet('/api/animais')
    animais.value = resposta.animais
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

onMounted(carregar)

const mostrarFiltro = computed(() => animais.value.length > 4)

const animaisFiltrados = computed(() => {
  if (!mostrarFiltro.value || filtroEspecie.value === 'todas') return animais.value

  return animais.value.filter((animal) => animal.especie === filtroEspecie.value)
})
</script>

<template>
  <!--
    Como em T01, `amplo` para que a coluna se centre na área de conteúdo
    inteira: a tela traz a própria largura de leitura, e o limite de 880 px da
    moldura, alinhado à esquerda, a deixaria fora do centro em telas largas.
  -->
  <TutorShell amplo>
    <div class="animais">
      <div class="animais__cabecalho">
        <div>
          <p class="animais__sobrelinha">Titularidade</p>
          <h1 class="animais__titulo">Meus animais</h1>
          <p v-if="!carregando && !erro" class="animais__contagem">
            {{ animais.length }} {{ animais.length === 1 ? 'animal' : 'animais' }} sob sua titularidade.
          </p>
        </div>

        <RouterLink to="/animais/novo" class="botao botao--primario">Cadastrar animal</RouterLink>
      </div>

      <!-- Carregando: esqueleto na forma exata da grade que substitui. -->
      <div v-if="carregando" class="animais__grade" aria-busy="true" aria-live="polite">
        <span class="visually-hidden">Carregando seus animais.</span>
        <div v-for="linha in 2" :key="linha" class="esqueleto-cartao">
          <div class="esqueleto esqueleto--foto" />
          <div class="esqueleto-cartao__texto">
            <div class="esqueleto esqueleto--nome" />
            <div class="esqueleto esqueleto--meta" />
          </div>
        </div>
      </div>

      <div v-else-if="erro" class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar seus animais.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">
            Tentar novamente
          </button>
        </div>
      </div>

      <EmptyState
        v-else-if="animais.length === 0"
        :icone="PawPrint"
        titulo="Você ainda não cadastrou nenhum animal"
        descricao="Cadastre seu cão ou gato para acompanhar as vacinas e receber lembretes das próximas doses."
      >
        <RouterLink to="/animais/novo" class="botao botao--primario">Cadastrar animal</RouterLink>
      </EmptyState>

      <template v-else>
        <div v-if="mostrarFiltro" class="animais__filtro" role="group" aria-label="Filtrar por espécie">
          <button
            type="button"
            class="filtro"
            :class="{ 'filtro--ativo': filtroEspecie === 'todas' }"
            @click="filtroEspecie = 'todas'"
          >
            Todas
          </button>
          <button
            type="button"
            class="filtro"
            :class="{ 'filtro--ativo': filtroEspecie === 'cao' }"
            @click="filtroEspecie = 'cao'"
          >
            <Dog :size="16" :stroke-width="1.75" /> Cães
          </button>
          <button
            type="button"
            class="filtro"
            :class="{ 'filtro--ativo': filtroEspecie === 'gato' }"
            @click="filtroEspecie = 'gato'"
          >
            <Cat :size="16" :stroke-width="1.75" /> Gatos
          </button>
        </div>

        <div class="animais__grade">
          <AnimalCard v-for="animal in animaisFiltrados" :key="animal.codigo" :animal="animal" />
        </div>
      </template>
    </div>
  </TutorShell>
</template>

<style scoped>
.animais {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 880px;
  margin: 0 auto;
}

.animais__cabecalho {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: var(--space-4);
}

.animais__sobrelinha {
  display: none;
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand-bright);
}

.animais__titulo {
  margin: 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

.animais__contagem {
  margin: var(--space-1) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.animais__cabecalho .botao {
  width: 100%;
}

.animais__filtro {
  display: flex;
  gap: var(--space-2);
}

.filtro {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  height: 36px;
  padding: 0 var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-pill);
  color: var(--ink-muted);
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}

.filtro--ativo {
  background: var(--brand-wash);
  border-color: var(--brand);
  color: var(--brand);
}

.animais__grade {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

/* Avisos e botões — mesmo vocabulário do painel (T01). */

.aviso {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-4);
  border-radius: var(--radius-md);
}

.aviso__icone {
  flex: none;
  margin-top: 2px;
}

.aviso--erro {
  background: var(--status-late-wash);
  border: 1px solid var(--status-late);
}

.aviso--erro .aviso__icone {
  color: var(--status-late);
}

.aviso__titulo {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.aviso__texto {
  margin: var(--space-1) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.aviso--erro .botao {
  margin: var(--space-4) 0 0;
  width: auto;
}

.botao {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 48px;
  padding: 0 var(--space-6);
  border-radius: var(--radius-sm);
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
}

.botao--primario {
  background: var(--brand);
  border: 1px solid var(--brand);
  color: var(--surface-card);
}

.botao--primario:hover {
  background: var(--brand-hover);
  border-color: var(--brand-hover);
  color: var(--surface-card);
}

.botao--secundario {
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  color: var(--ink);
}

.botao--secundario:hover {
  background: var(--surface-sunken);
}

/* Esqueleto de carregamento ----------------------------------------------- */

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: pulsar 1.2s ease-in-out infinite;
}

.esqueleto--foto {
  flex: none;
  width: 56px;
  height: 56px;
  border-radius: var(--radius-pill);
}

.esqueleto--nome {
  height: 24px;
  width: 60%;
}

.esqueleto--meta {
  height: 20px;
  width: 45%;
  margin: var(--space-2) 0 0;
}

.esqueleto-cartao {
  display: flex;
  align-items: center;
  gap: var(--space-4);
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.esqueleto-cartao__texto {
  flex: 1;
}

@keyframes pulsar {
  0% { opacity: .55; }
  50% { opacity: 1; }
  100% { opacity: .55; }
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}

/* Larguras derivadas ------------------------------------------------------ */

@media (min-width: 768px) {
  .animais__cabecalho {
    flex-direction: row;
    align-items: flex-end;
    justify-content: space-between;
  }

  .animais__cabecalho .botao {
    width: auto;
  }

  .animais__grade {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-4);
  }
}

@media (min-width: 1024px) {
  .animais__sobrelinha {
    display: block;
  }

  .animais__titulo {
    margin: var(--space-1) 0 0;
    font-size: 36px;
    line-height: 40px;
    letter-spacing: -.02em;
  }

  /* Mesma grade do painel (T01): duas colunas, para que o cartão do animal
     tenha ali e aqui a mesma largura e a mesma respiração. */
  .animais__grade {
    gap: var(--space-6);
  }
}
</style>
