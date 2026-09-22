<script setup>
import { computed, onMounted, ref } from 'vue'
import {
  CalendarClock,
  Cat,
  CircleCheck,
  CircleHelp,
  Dog,
  PawPrint,
  TriangleAlert,
  UserRoundCheck,
} from '@lucide/vue'
import TutorShell from '@/components/tutor/TutorShell.vue'
import AnimalCard from '@/components/tutor/AnimalCard.vue'
import StatusPill from '@/components/base/StatusPill.vue'
import EmailVerificationBanner from '@/components/tutor/EmailVerificationBanner.vue'
import EmptyState from '@/components/base/EmptyState.vue'
import { apiGet } from '@/lib/api.js'
import { enumerarNomes } from '@/lib/animais.js'
import { useSessaoStore } from '@/stores/sessao.js'

/**
 * T01 — painel do tutor (RF50). A tela responde, na primeira dobra, à única
 * pergunta que traz Helena ao sistema: o que está pendente para os meus
 * animais? Daí a ordem dos blocos, que é fixa e não negociável — pendência,
 * animais, retornos —, e daí também não haver aqui nenhuma ação destrutiva.
 * Autorizar clínica não mora aqui: é a seção Compartilhamento inteira, e um
 * atalho no painel duplicaria a porta de entrada dela.
 */
const sessao = useSessaoStore()

const painel = ref(null)
const carregando = ref(true)
const erro = ref('')

const primeiroNome = computed(() => {
  const nome = painel.value?.tutor.nome ?? sessao.usuario?.nome ?? ''

  return nome.trim().split(/\s+/)[0] ?? ''
})

const animais = computed(() => painel.value?.animais ?? [])
const pendencias = computed(() => painel.value?.pendencias ?? [])
const retornos = computed(() => painel.value?.retornos ?? [])

const nomesDosAnimais = computed(() => enumerarNomes(animais.value.map((animal) => animal.nome)))
const verboDeEstado = computed(() => (animais.value.length > 1 ? 'estão' : 'está'))

async function carregar() {
  carregando.value = true
  erro.value = ''

  try {
    painel.value = await apiGet('/api/tutor/painel')
  } catch (excecao) {
    // §6.1 — o estado de erro traz o que fazer, nunca o código do que falhou.
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

onMounted(carregar)

function iconeDaEspecie(especie) {
  return especie === 'gato' ? Cat : Dog
}

/** "sáb, 26 de dezembro de 2026" — data futura relevante, por extenso (§6.2). */
function porExtenso(iso) {
  return new Intl.DateTimeFormat('pt-BR', {
    weekday: 'short',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(new Date(`${iso}T00:00:00`))
}

/** "23/06/2026" — data que já passou, em números. */
function emNumeros(iso) {
  return new Intl.DateTimeFormat('pt-BR').format(new Date(`${iso}T00:00:00`))
}
</script>

<template>
  <!--
    Como em T18, `amplo` para que a coluna se centre na área de conteúdo
    inteira: o painel traz a própria largura de leitura, e o limite de 880 px da
    moldura, alinhado à esquerda, o deixaria fora do centro em telas largas.
  -->
  <TutorShell amplo>
    <template #aviso>
      <!-- RF05 — some sozinha assim que o endereço é confirmado. -->
      <EmailVerificationBanner
        v-if="painel && !painel.tutor.email_verificado"
        :email="painel.tutor.email"
      />
    </template>

    <!-- Carregando: esqueleto na forma exata do conteúdo que substitui, jamais
         indicador circular centralizado (§5.1). -->
    <div v-if="carregando" class="painel" aria-busy="true" aria-live="polite">
      <span class="visually-hidden">Carregando o painel.</span>
      <div class="esqueleto esqueleto--titulo" />
      <div v-for="linha in 3" :key="linha" class="esqueleto-cartao">
        <div class="esqueleto esqueleto--foto" />
        <div class="esqueleto-cartao__texto">
          <div class="esqueleto esqueleto--nome" />
          <div class="esqueleto esqueleto--meta" />
        </div>
      </div>
    </div>

    <div v-else-if="erro" class="painel">
      <h1 class="painel__saudacao">Olá<template v-if="primeiroNome">, {{ primeiroNome }}</template></h1>

      <div class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar seu painel.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">
            Tentar novamente
          </button>
        </div>
      </div>
    </div>

    <div v-else class="painel">
      <div>
        <p class="painel__sobrelinha">Início</p>
        <h1 class="painel__saudacao">Olá<template v-if="primeiroNome">, {{ primeiroNome }}</template></h1>
      </div>

      <EmptyState
        v-if="animais.length === 0"
        :icone="PawPrint"
        titulo="Cadastre seu primeiro animal"
        descricao="Assim que ele estiver aqui, você acompanha as vacinas e recebe lembretes antes de cada dose."
      >
        <RouterLink to="/animais/novo" class="botao botao--primario">Cadastrar animal</RouterLink>
      </EmptyState>

      <template v-else>
        <section>
          <h2 class="painel__rotulo">Precisa de atenção</h2>
          <p class="painel__nota">Calculado pelo Imunia a partir dos registros da carteira</p>

          <ul v-if="pendencias.length" class="painel__pendencias">
            <li
              v-for="pendencia in pendencias"
              :key="`${pendencia.animal.codigo}-${pendencia.imunobiologico}`"
              class="atencao"
              :class="`atencao--${pendencia.situacao.tipo}`"
            >
              <div class="atencao__topo">
                <span class="atencao__foto">
                  <img
                    v-if="pendencia.animal.foto_url"
                    :src="pendencia.animal.foto_url"
                    :alt="`Foto de ${pendencia.animal.nome}`"
                  />
                  <component
                    :is="iconeDaEspecie(pendencia.animal.especie)"
                    v-else
                    :size="24"
                    :stroke-width="1.75"
                  />
                </span>

                <div class="atencao__dados">
                  <p class="atencao__titulo">
                    {{ pendencia.animal.nome }} · {{ pendencia.imunobiologico }}
                  </p>
                  <p class="atencao__data">era para {{ emNumeros(pendencia.prevista_para) }}</p>
                  <StatusPill
                    :tipo="pendencia.situacao.tipo"
                    :texto="pendencia.situacao.texto"
                    class="atencao__situacao"
                  />
                </div>
              </div>

              <RouterLink
                :to="`/animais/${pendencia.animal.codigo}/carteira`"
                class="botao botao--primario"
              >
                Ver carteira de {{ pendencia.animal.nome }}
              </RouterLink>
            </li>
          </ul>

          <div v-else-if="painel.situacao_geral === 'em_dia'" class="aviso aviso--positivo">
            <CircleCheck :size="20" :stroke-width="1.75" class="aviso__icone" />
            <p class="aviso__titulo">
              {{ nomesDosAnimais }} {{ verboDeEstado }} com as vacinas em dia.
            </p>
          </div>

          <!-- Sem vacinação registrada, o painel não diz que está tudo em dia:
               diz que ainda não sabe, e diz quem pode fazê-lo saber. -->
          <div v-else class="aviso aviso--neutro">
            <CircleHelp :size="20" :stroke-width="1.75" class="aviso__icone" />
            <div>
              <p class="aviso__titulo">Ainda não há vacinas registradas.</p>
              <p class="aviso__texto">
                Quando um veterinário registrar as aplicações, as próximas doses de
                {{ nomesDosAnimais }} aparecem aqui.
              </p>
            </div>
          </div>
        </section>

        <section>
          <h2 class="painel__rotulo">Meus animais</h2>
          <div class="painel__animais">
            <AnimalCard v-for="animal in animais" :key="animal.codigo" :animal="animal" />
          </div>
        </section>

        <section v-if="retornos.length" class="cartao">
          <h2 class="painel__rotulo">Retornos programados</h2>
          <div v-for="retorno in retornos" :key="retorno.id" class="retorno">
            <CalendarClock :size="20" :stroke-width="1.75" class="retorno__icone" />
            <div>
              <p class="retorno__titulo">{{ retorno.animal.nome }} · {{ retorno.motivo }}</p>
              <p class="retorno__data">{{ porExtenso(retorno.prevista_para) }}</p>
              <!-- Nenhum registro clínico aparece sem a origem visível. -->
              <p class="procedencia">
                <UserRoundCheck :size="16" :stroke-width="1.75" class="procedencia__icone" />
                <span>{{ retorno.prestador }} · {{ retorno.profissional }}</span>
              </p>
            </div>
          </div>
        </section>
      </template>
    </div>
  </TutorShell>
</template>

<style scoped>
.painel {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 880px;
  margin: 0 auto;
}

.painel__sobrelinha {
  display: none;
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand-bright);
}

.painel__saudacao {
  margin: 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

.painel__rotulo {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.painel__nota {
  margin: var(--space-1) 0 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-muted);
}

.painel__pendencias {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  margin: var(--space-3) 0 0;
  padding: 0;
  list-style: none;
}

.painel__animais {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin: var(--space-3) 0 0;
}

/* Bloco de pendência ------------------------------------------------------ */

.atencao {
  padding: var(--space-4);
  border-radius: var(--radius-md);
}

.atencao--atrasada {
  background: var(--status-late-wash);
  border: 1px solid var(--status-late);
}

.atencao--proxima {
  background: var(--surface-card);
  border: 1px solid var(--status-due);
}

.atencao__topo {
  display: flex;
  align-items: center;
  gap: var(--space-4);
}

.atencao__foto {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 56px;
  height: 56px;
  overflow: hidden;
  border-radius: var(--radius-pill);
  background: var(--surface-card);
  color: var(--ink-muted);
}

.atencao__foto img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.atencao__dados {
  flex: 1;
  min-width: 0;
}

.atencao__titulo {
  margin: 0;
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.atencao__data {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.atencao__situacao {
  margin: var(--space-2) 0 0;
}

.atencao .botao {
  margin: var(--space-4) 0 0;
}

/* Avisos ------------------------------------------------------------------ */

.aviso {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: var(--space-3) 0 0;
  padding: var(--space-4);
  border-radius: var(--radius-md);
}

.aviso__icone {
  flex: none;
  margin-top: 2px;
}

.aviso--positivo {
  background: var(--brand-wash);
  border: 1px solid var(--brand);
}

.aviso--positivo .aviso__icone {
  color: var(--brand);
}

.aviso--neutro {
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
}

.aviso--neutro .aviso__icone {
  color: var(--ink-muted);
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
}

/* Retornos ---------------------------------------------------------------- */

.cartao {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.retorno {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: var(--space-3) 0 0;
}

.retorno__icone {
  flex: none;
  margin-top: 2px;
  color: var(--ink-muted);
}

.retorno__titulo {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.retorno__data {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.procedencia {
  display: inline-flex;
  align-items: flex-start;
  gap: var(--space-2);
  margin: var(--space-2) 0 0;
  padding: 6px 10px;
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-xs);
  font-size: 12px;
  line-height: 16px;
  color: var(--ink);
}

.procedencia__icone {
  flex: none;
  color: var(--brand);
}

/* Botões em ligação — o AppButton é <button>; aqui a ação é navegação. ----- */

.botao {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 48px;
  padding: 0 var(--space-6);
  border-radius: var(--radius-sm);
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
}

/* No painel o botão ocupa a linha inteira porque mora dentro de um bloco de
   pendência, onde ele é a continuação da frase. No estado vazio não: ali ele é
   um convite isolado, e fica curto e centrado sob o texto, como em T02. */
.empty-state__acao .botao {
  width: auto;
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

.esqueleto--titulo {
  height: 34px;
  width: 50%;
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
  .painel {
    gap: var(--space-6);
  }

  /* Os blocos do painel seguem em largura total: o que divide a linha são os
     cartões de animal, dois a dois. */
  .painel__animais {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-4);
  }

  .atencao .botao,
  .aviso--erro .botao {
    width: auto;
  }
}

@media (min-width: 1024px) {
  .painel__sobrelinha {
    display: block;
  }

  .painel__saudacao {
    margin: var(--space-1) 0 0;
    font-size: 36px;
    line-height: 40px;
    letter-spacing: -.02em;
  }

  .painel__animais {
    gap: var(--space-6);
  }
}
</style>
