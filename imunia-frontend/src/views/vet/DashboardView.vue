<script setup>
import { computed, onMounted, ref } from 'vue'
import {
  CalendarClock,
  Cat,
  ChevronLeft,
  ChevronRight,
  Dog,
  KeyRound,
  TriangleAlert,
} from '@lucide/vue'
import VetShell from '@/components/vet/VetShell.vue'
import AvisoOutroContexto from '@/components/vet/AvisoOutroContexto.vue'
import StatusPill from '@/components/base/StatusPill.vue'
import { apiGet } from '@/lib/api.js'

/**
 * V01 — painel do veterinário (RF48). Quatro números e duas listas. Nenhum
 * gráfico: nenhum entra sem responder a uma pergunta declarada, e os indicadores
 * respondem melhor como número clicável que leva à lista filtrada.
 *
 * O que a tela mostra tem dois âmbitos, e os dois são visíveis na própria
 * interface: o prestador ativo, anunciado na faixa de contexto (RF48a), e as
 * autorizações vigentes, explicadas quando não há nenhuma (RN48).
 */
const painel = ref(null)
const carregando = ref(true)
const erro = ref('')

const dias = ref(30)
const pagina = ref(1)

const indicadores = computed(() => painel.value?.indicadores ?? {})
const animais = computed(() => painel.value?.animais_atendidos.itens ?? [])
const paginacao = computed(() => painel.value?.animais_atendidos.paginacao ?? null)
const pendencias = computed(() => painel.value?.pendencias ?? [])
const retornos = computed(() => painel.value?.retornos ?? [])

/**
 * Cada indicador leva à lista que o detalha. Doses vencidas e retornos vão para
 * a mesma tela — V02 responde pelos dois (RF34b) —, com o recorte na consulta.
 */
const CARTOES = computed(() => [
  {
    chave: 'atendimentos',
    rotulo: 'Atendimentos',
    valor: indicadores.value.atendimentos,
    apoio: 'registrados por você no período',
    destino: '/clinica/registros',
  },
  {
    chave: 'vacinas',
    rotulo: 'Vacinas aplicadas',
    valor: indicadores.value.vacinas_aplicadas,
    apoio: 'doses com lote e validade',
    destino: '/clinica/registros?tipo=vacinacao',
  },
  {
    chave: 'vencidas',
    rotulo: 'Doses vencidas',
    valor: indicadores.value.doses_vencidas?.animais,
    apoio: `animais com dose atrasada · ${indicadores.value.doses_vencidas?.a_vencer ?? 0} a vencer em ${dias.value} dias`,
    destino: '/clinica/pendencias',
    alerta: true,
  },
  {
    chave: 'retornos',
    rotulo: 'Retornos previstos',
    valor: indicadores.value.retornos_previstos,
    apoio: `nos próximos ${dias.value} dias`,
    destino: '/clinica/pendencias?tipo=retorno',
  },
])

async function carregar({ prestador } = {}) {
  carregando.value = true
  erro.value = ''

  const parametros = new URLSearchParams({ dias: dias.value, pagina: pagina.value })
  if (prestador) parametros.set('prestador', prestador)

  try {
    painel.value = await apiGet(`/api/clinica/painel?${parametros}`)
  } catch (excecao) {
    // §6.1 — o estado de erro traz o que fazer, nunca o código do que falhou.
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

onMounted(carregar)

/** RF48b — trocar o intervalo recomeça a paginação: outra pergunta, outra lista. */
function ajustarIntervalo(valor) {
  dias.value = valor
  pagina.value = 1
  carregar({ prestador: painel.value?.prestador.id })
}

function irParaPagina(destino) {
  pagina.value = destino
  carregar({ prestador: painel.value?.prestador.id })
}

function trocarPrestador(id) {
  pagina.value = 1
  carregar({ prestador: id })
}

function iconeDaEspecie(especie) {
  return especie === 'gato' ? Cat : Dog
}

/** "02/08/2026" — data que já passou, em números (§6.2). */
function emNumeros(iso) {
  return new Intl.DateTimeFormat('pt-BR').format(new Date(`${iso}T00:00:00`))
}

/** "07/08" — data próxima, no bloco estreito dos retornos. */
function diaEMes(iso) {
  return new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit' })
    .format(new Date(`${iso}T00:00:00`))
}
</script>

<template>
  <VetShell
    :prestador="painel?.prestador"
    :vinculos="painel?.vinculos ?? []"
    :pendencias="indicadores.doses_vencidas?.animais ?? 0"
    @trocar-prestador="trocarPrestador"
  >
    <!-- Carregando: esqueleto na forma exata do conteúdo que substitui — quatro
         indicadores e seis linhas —, jamais indicador circular (§5.1). -->
    <div v-if="carregando" class="painel" aria-busy="true" aria-live="polite">
      <span class="visually-hidden">Carregando o painel.</span>
      <div class="esqueleto esqueleto--titulo" />
      <div class="painel__indicadores">
        <div v-for="cartao in 4" :key="cartao" class="cartao">
          <div class="esqueleto esqueleto--rotulo" />
          <div class="esqueleto esqueleto--numero" />
        </div>
      </div>
      <div class="cartao cartao--liso">
        <div v-for="linha in 6" :key="linha" class="esqueleto-linha">
          <div class="esqueleto esqueleto--celula" />
          <div class="esqueleto esqueleto--celula esqueleto--curta" />
          <div class="esqueleto esqueleto--pilula" />
        </div>
      </div>
    </div>

    <div v-else-if="erro" class="painel">
      <div class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar o painel.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar()">
            Tentar novamente
          </button>
        </div>
      </div>
    </div>

    <!-- Primeiro acesso: o painel sem números não é um painel vazio, é o
         começo do trabalho — e os três passos são a ordem em que ele acontece. -->
    <div v-else-if="painel.estado === 'primeiro_acesso'" class="painel painel--estreito">
      <div>
        <p class="painel__sobrelinha">Primeiro acesso</p>
        <!-- "Boas-vindas" e não "Bem-vindo": o sistema não sabe o gênero de quem
             entra, e a Dra. Larissa do cenário também abre esta tela. -->
        <h1 class="painel__titulo">Boas-vindas, {{ painel.profissional.nome }}</h1>
        <p class="painel__apoio">
          Seu painel ainda não tem números porque nenhum registro foi feito por você
          em {{ painel.prestador.nome }}. Três passos para começar:
        </p>
      </div>

      <div class="passos">
        <div class="cartao passo">
          <p class="passo__ordem">Passo 1</p>
          <p class="passo__titulo">Cadastrar o tutor</p>
          <p class="passo__texto">
            O CPF é a chave. Se o tutor já existe no Imunia, você não vê os dados dele:
            pede a autorização.
          </p>
          <RouterLink to="/clinica/tutores/novo" class="botao botao--primario">
            Cadastrar tutor
          </RouterLink>
        </div>

        <div class="cartao passo">
          <p class="passo__ordem">Passo 2</p>
          <p class="passo__titulo">Cadastrar o animal</p>
          <p class="passo__texto">
            Identificação e caracterização. Os campos de caracterização são privativos
            do médico-veterinário.
          </p>
          <RouterLink to="/clinica/animais/novo" class="botao botao--secundario">
            Cadastrar animal
          </RouterLink>
        </div>

        <div class="cartao passo">
          <p class="passo__ordem">Passo 3</p>
          <p class="passo__titulo">Registrar o atendimento</p>
          <p class="passo__texto">
            Vacinação ou consulta. Depois de confirmado, o registro não pode ser
            alterado nem excluído.
          </p>
          <RouterLink to="/clinica/buscar?registrar=atendimento" class="botao botao--secundario">
            Registrar
          </RouterLink>
        </div>
      </div>
    </div>

    <!-- RN48 — o painel vazio por falta de autorização precisa dizer que está
         funcionando. Sem esta explicação, a regra parece defeito. -->
    <div v-else-if="painel.estado === 'sem_autorizacoes'" class="painel painel--estreito">
      <div class="consentimento">
        <div class="consentimento__topo">
          <KeyRound :size="24" :stroke-width="1.75" class="consentimento__icone" />
          <h1 class="consentimento__titulo">Nenhuma autorização vigente neste prestador</h1>
        </div>
        <p class="consentimento__texto">
          O painel abrange somente animais sob autorização vigente para
          {{ painel.prestador.nome }}. Sem nenhuma, não há indicadores, lista de
          atendidos nem pendências a exibir — e isso não é uma falha do sistema.
        </p>
        <p class="consentimento__texto consentimento__texto--secundario">
          Os registros que você já produziu continuam no prontuário da clínica. O que
          depende de autorização é ver o histórico completo do animal, incluindo o que
          outros prestadores registraram.
        </p>
        <div class="consentimento__acoes">
          <RouterLink to="/clinica/buscar" class="botao botao--consentimento">
            <KeyRound :size="16" :stroke-width="1.75" />
            Buscar e solicitar autorização
          </RouterLink>
          <RouterLink to="/clinica/tutores/novo" class="botao botao--secundario">
            Cadastrar tutor
          </RouterLink>
        </div>
      </div>

      <AvisoOutroContexto
        :prestador="painel.prestador"
        :vinculos="painel.vinculos ?? []"
        @trocar="trocarPrestador"
      />
    </div>

    <div v-else class="painel">
      <AvisoOutroContexto
        :prestador="painel.prestador"
        :vinculos="painel.vinculos ?? []"
        @trocar="trocarPrestador"
      />

      <div class="painel__cabecalho">
        <div>
          <p class="painel__sobrelinha">Painel</p>
          <h1 class="painel__titulo">{{ painel.profissional.nome }}</h1>
          <!-- O nome do prestador vem depois de um ponto médio, e não regido por
               preposição: "na Clínica Vet Amigo" viraria "na Hospital Bicho Bom"
               no outro vínculo, e o sistema não sabe o gênero de um nome próprio. -->
          <p class="painel__meta">
            <span v-if="painel.profissional.crmv" class="painel__crmv">
              {{ painel.profissional.crmv }}
            </span>
            <span v-if="painel.profissional.crmv"> · </span>
            últimos {{ painel.intervalo.dias }} dias · {{ painel.prestador.nome }}
          </p>
        </div>

        <div class="intervalo" role="group" aria-label="Intervalo do painel">
          <button
            v-for="opcao in painel.intervalo.opcoes"
            :key="opcao"
            type="button"
            class="intervalo__opcao"
            :class="{ 'intervalo__opcao--ativa': opcao === painel.intervalo.dias }"
            :aria-pressed="opcao === painel.intervalo.dias"
            @click="ajustarIntervalo(opcao)"
          >
            {{ opcao }} dias
          </button>
        </div>
      </div>

      <div class="painel__indicadores">
        <RouterLink
          v-for="cartao in CARTOES"
          :key="cartao.chave"
          :to="cartao.destino"
          class="cartao indicador"
          :class="{ 'indicador--alerta': cartao.alerta && cartao.valor > 0 }"
        >
          <span class="indicador__rotulo">{{ cartao.rotulo }}</span>
          <span class="indicador__valor">{{ cartao.valor }}</span>
          <span class="indicador__apoio">{{ cartao.apoio }}</span>
        </RouterLink>
      </div>

      <div class="painel__colunas">
        <section class="cartao cartao--liso">
          <div class="bloco__cabecalho">
            <h2 class="bloco__titulo">Animais atendidos recentemente</h2>
            <RouterLink to="/clinica/buscar" class="bloco__ligacao">Buscar animal</RouterLink>
          </div>

          <p v-if="animais.length === 0" class="bloco__vazio">
            Nenhum animal foi atendido nos últimos {{ painel.intervalo.dias }} dias.
          </p>

          <table v-else class="tabela">
            <thead>
              <tr>
                <th scope="col">Animal</th>
                <th scope="col">Tutor</th>
                <th scope="col">Último registro</th>
                <th scope="col">Situação vacinal</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="animal in animais" :key="animal.codigo">
                <td data-rotulo="Animal">
                  <RouterLink :to="`/clinica/animais/${animal.codigo}`" class="tabela__ligacao">
                    <component
                      :is="iconeDaEspecie(animal.especie)"
                      :size="16"
                      :stroke-width="1.75"
                      class="tabela__icone"
                    />
                    {{ animal.nome }} · {{ animal.especie === 'gato' ? 'gato' : 'cão' }}
                  </RouterLink>
                </td>
                <td data-rotulo="Tutor" class="tabela__discreto">{{ animal.tutor }}</td>
                <td data-rotulo="Último registro" class="tabela__discreto tabela__numeros">
                  {{ emNumeros(animal.ultimo_registro.em) }} · {{ animal.ultimo_registro.tipo }}
                </td>
                <td data-rotulo="Situação vacinal">
                  <StatusPill
                    v-if="animal.situacao"
                    :tipo="animal.situacao.tipo"
                    :texto="animal.situacao.texto_curto"
                  />
                  <span v-else class="tabela__discreto">sem registro de vacina</span>
                </td>
              </tr>
            </tbody>
          </table>

          <div v-if="paginacao && paginacao.total > 0" class="paginacao">
            <p class="paginacao__contagem">
              Exibindo {{ paginacao.de }}–{{ paginacao.ate }} de {{ paginacao.total }}
            </p>
            <div class="paginacao__controles">
              <button
                type="button"
                class="paginacao__botao"
                aria-label="Página anterior"
                :disabled="paginacao.pagina === 1"
                @click="irParaPagina(paginacao.pagina - 1)"
              >
                <ChevronLeft :size="16" :stroke-width="1.75" />
              </button>
              <span class="paginacao__posicao">{{ paginacao.pagina }} / {{ paginacao.paginas }}</span>
              <button
                type="button"
                class="paginacao__botao"
                aria-label="Próxima página"
                :disabled="paginacao.pagina === paginacao.paginas"
                @click="irParaPagina(paginacao.pagina + 1)"
              >
                <ChevronRight :size="16" :stroke-width="1.75" />
              </button>
            </div>
          </div>
        </section>

        <div class="painel__lado">
          <section class="cartao cartao--liso">
            <div class="bloco__cabecalho">
              <h2 class="bloco__titulo">Pendências</h2>
              <RouterLink to="/clinica/pendencias" class="bloco__ligacao">Ver todas</RouterLink>
            </div>
            <!-- O bloco mostra o que já venceu e o que vence dentro do intervalo
                 escolhido — dizer "desta semana" com o painel em trinta dias
                 seria nomear um recorte que a lista não faz. -->
            <p class="bloco__nota">
              Vencidas e a vencer em {{ painel.intervalo.dias }} dias
            </p>

            <p v-if="pendencias.length === 0" class="bloco__vazio">
              Nenhuma dose vencida ou próxima do vencimento no período.
            </p>

            <RouterLink
              v-for="pendencia in pendencias"
              :key="`${pendencia.animal.codigo}-${pendencia.imunobiologico}`"
              :to="`/clinica/animais/${pendencia.animal.codigo}`"
              class="pendencia"
              :class="{ 'pendencia--atrasada': pendencia.situacao.tipo === 'atrasada' }"
            >
              <span class="pendencia__nome">
                {{ pendencia.animal.nome }} · {{ pendencia.imunobiologico }}
              </span>
              <span class="pendencia__prazo">{{ pendencia.situacao.texto_curto }}</span>
            </RouterLink>
          </section>

          <section class="cartao cartao--liso">
            <div class="bloco__cabecalho">
              <h2 class="bloco__titulo">Retornos previstos</h2>
            </div>

            <p v-if="retornos.length === 0" class="bloco__vazio">
              Nenhum retorno programado para os próximos {{ painel.intervalo.dias }} dias.
            </p>

            <div v-for="retorno in retornos" :key="`${retorno.animal.codigo}-${retorno.prevista_para}`" class="retorno">
              <CalendarClock :size="16" :stroke-width="1.75" class="retorno__icone" />
              <span class="retorno__texto">{{ retorno.animal.nome }} · {{ retorno.finalidade }}</span>
              <span class="retorno__data">{{ diaEMes(retorno.prevista_para) }}</span>
            </div>
          </section>
        </div>
      </div>
    </div>
  </VetShell>
</template>

<style scoped>
/* Como em T01, o painel traz a própria largura e se centra na área de
   conteúdo: 1280 px — o teto das telas largas da clínica (busca, ficha) —
   para o conjunto, e 880 px, a largura de leitura de T01, para os estados
   de coluna única. */
.painel {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 1280px;
  margin: 0 auto;
}

.painel--estreito {
  max-width: 880px;
}

.painel__cabecalho {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: var(--space-6);
  flex-wrap: wrap;
}

.painel__sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand);
}

.painel__titulo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

.painel__meta {
  margin: var(--space-1) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.painel__crmv {
  font-family: var(--font-mono);
  font-size: 13px;
  font-weight: 500;
}

.painel__apoio {
  margin: var(--space-2) 0 0;
  max-width: 75ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Seletor de intervalo ----------------------------------------------------- */

.intervalo {
  display: flex;
  gap: var(--space-2);
}

.intervalo__opcao {
  height: 32px;
  padding: 0 var(--space-3);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-pill);
  font-size: 14px;
  font-weight: 600;
  color: var(--ink-muted);
  cursor: pointer;
}

.intervalo__opcao:hover {
  border-color: var(--brand-bright);
  color: var(--ink);
}

.intervalo__opcao--ativa,
.intervalo__opcao--ativa:hover {
  background: var(--brand-wash);
  border-color: var(--brand);
  color: var(--brand);
}

/* Cartões ------------------------------------------------------------------ */

.cartao {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.cartao--liso {
  padding: 0;
  overflow: hidden;
}

.painel__indicadores {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-3);
}

.indicador {
  display: block;
  color: var(--ink);
}

.indicador:hover {
  border-color: var(--brand-bright);
  color: var(--ink);
}

.indicador__rotulo {
  display: block;
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.indicador__valor {
  display: block;
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.indicador__apoio {
  display: none;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* O número que interrompe a rotina do dia é o único com borda própria. */
.indicador--alerta {
  border-color: var(--status-late);
}

.indicador--alerta .indicador__rotulo,
.indicador--alerta .indicador__valor {
  color: var(--status-late);
}

/* Blocos ------------------------------------------------------------------- */

.painel__colunas {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

/* Empilhado, o bloco de pendências vem antes da lista: quem abre o painel no
   celular, em pé na recepção, precisa do que está vencido antes do que já foi
   feito (§8.3 do briefing). Ao lado, na largura cheia, a ordem volta a ser a
   da leitura. */
.painel__lado {
  order: -1;
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.bloco__cabecalho {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-4) var(--space-4) var(--space-3);
}

.bloco__titulo {
  margin: 0;
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.bloco__ligacao {
  font-size: 14px;
  font-weight: 600;
}

.bloco__nota {
  margin: calc(var(--space-3) * -1) 0 0;
  padding: 0 var(--space-4) var(--space-3);
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

.bloco__vazio {
  margin: 0;
  padding: 0 var(--space-4) var(--space-4);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Tabela — vira lista de cartões abaixo de 768 px (§5.1). ------------------- */

.tabela {
  width: 100%;
  border-collapse: collapse;
}

.tabela thead {
  display: none;
}

.tabela tr {
  position: relative;
  display: grid;
  gap: var(--space-1);
  padding: var(--space-3) var(--space-4);
  border-top: 1px solid var(--border-hairline);
}

.tabela td {
  display: flex;
  gap: var(--space-2);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.tabela td::before {
  content: attr(data-rotulo) ':';
  flex: none;
  min-width: 116px;
  color: var(--ink-faint);
}

.tabela__ligacao {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  font-weight: 600;
  color: var(--ink);
}

/* A linha inteira é o alvo, sem que a semântica da tabela se perca. */
.tabela__ligacao::after {
  content: '';
  position: absolute;
  inset: 0;
}

.tabela tr:hover {
  background: var(--surface-sunken);
}

.tabela__icone {
  flex: none;
  color: var(--ink-muted);
}

.tabela__discreto {
  color: var(--ink-muted);
}

.tabela__numeros {
  font-variant-numeric: tabular-nums;
}

.paginacao {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4);
  padding: var(--space-3) var(--space-4);
  border-top: 1px solid var(--border-hairline);
}

.paginacao__contagem {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.paginacao__controles {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

.paginacao__botao {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  color: var(--ink-muted);
  cursor: pointer;
}

.paginacao__botao:hover:not(:disabled) {
  border-color: var(--brand-bright);
  color: var(--ink);
}

.paginacao__botao:disabled {
  opacity: .45;
  cursor: not-allowed;
}

.paginacao__posicao {
  font-family: var(--font-mono);
  font-size: 13px;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
}

/* Pendências e retornos ---------------------------------------------------- */

.pendencia,
.retorno {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  min-height: 48px;
  padding: var(--space-2) var(--space-4);
  border-top: 1px solid var(--border-hairline);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.pendencia:hover {
  background: var(--surface-sunken);
  color: var(--ink);
}

.pendencia--atrasada {
  background: var(--status-late-wash);
}

.pendencia__nome,
.retorno__texto {
  flex: 1;
  min-width: 0;
}

.pendencia__prazo {
  flex: none;
  font-weight: 600;
  color: var(--status-due-text);
  font-variant-numeric: tabular-nums;
}

.pendencia--atrasada .pendencia__prazo {
  color: var(--status-late);
}

.retorno__icone {
  flex: none;
  color: var(--ink-muted);
}

.retorno__data {
  flex: none;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

/* Primeiro acesso ---------------------------------------------------------- */

.passos {
  display: grid;
  gap: var(--space-4);
}

.passo__ordem {
  margin: 0;
  font-family: var(--font-mono);
  font-size: 13px;
  line-height: 18px;
  font-weight: 500;
  color: var(--brand);
}

.passo__titulo {
  margin: var(--space-2) 0 0;
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.passo__texto {
  margin: var(--space-2) 0 var(--space-4);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Sem autorização vigente -------------------------------------------------- */

.consentimento {
  padding: var(--space-6);
  background: var(--surface-card);
  border: 1px solid var(--consent);
  border-radius: var(--radius-md);
}

.consentimento__topo {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.consentimento__icone {
  flex: none;
  color: var(--consent);
}

.consentimento__titulo {
  margin: 0;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--ink);
}

.consentimento__texto {
  margin: var(--space-3) 0 0;
  max-width: 75ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.consentimento__texto--secundario {
  margin-top: var(--space-2);
  color: var(--ink-muted);
}

.consentimento__acoes {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-3);
  margin: var(--space-4) 0 0;
}

/* Botões em ligação — aqui a ação é navegação, não submissão. --------------- */

.botao {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  height: 40px;
  padding: 0 var(--space-4);
  border-radius: var(--radius-sm);
  font-size: 14px;
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
  color: var(--ink);
}

.botao--consentimento {
  background: var(--consent);
  border: 1px solid var(--consent);
  color: var(--surface-card);
}

.botao--consentimento:hover {
  background: #32427A;
  color: var(--surface-card);
}

/* Avisos ------------------------------------------------------------------- */

.aviso {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-4);
  border-radius: var(--radius-md);
}

.aviso--erro {
  background: var(--status-late-wash);
  border: 1px solid var(--status-late);
}

.aviso__icone {
  flex: none;
  margin-top: 2px;
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
  margin: var(--space-1) 0 var(--space-4);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Esqueleto ---------------------------------------------------------------- */

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: pulsar 1.2s ease-in-out infinite;
}

.esqueleto--titulo {
  height: 34px;
  width: 40%;
}

.esqueleto--rotulo {
  height: 16px;
  width: 70%;
}

.esqueleto--numero {
  height: 34px;
  width: 50%;
  margin: var(--space-2) 0 0;
}

.esqueleto-linha {
  display: flex;
  align-items: center;
  gap: var(--space-4);
  height: 48px;
  padding: 0 var(--space-4);
  border-top: 1px solid var(--border-hairline);
}

.esqueleto-linha:first-child {
  border-top: 0;
}

.esqueleto--celula {
  height: 16px;
  width: 26%;
}

.esqueleto--curta {
  width: 18%;
}

.esqueleto--pilula {
  height: 22px;
  width: 16%;
  margin-left: auto;
  border-radius: var(--radius-pill);
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

/* Larguras derivadas ------------------------------------------------------- */

@media (min-width: 768px) {
  .painel {
    gap: var(--space-6);
  }

  .painel__indicadores {
    gap: var(--space-4);
  }

  .indicador__apoio {
    display: block;
  }

  .painel__colunas {
    gap: var(--space-6);
  }

  .passos {
    grid-template-columns: repeat(3, 1fr);
  }

  /* A tabela vira tabela: cabeçalho de volta, rótulos por célula fora. */
  .tabela thead {
    display: table-header-group;
  }

  .tabela th {
    padding: var(--space-3) 0;
    font-size: 12px;
    line-height: 16px;
    font-weight: 600;
    letter-spacing: .06em;
    text-transform: uppercase;
    text-align: left;
    color: var(--ink-muted);
  }

  /* Cabeçalho e linhas repetem a mesma grade e o mesmo recuo lateral: qualquer
     diferença entre os dois desalinha a coluna do seu próprio título. */
  /* "14/08/2026 · atendimento" em algarismos tabulares é a célula mais larga da
     tabela, e a coluna dela tem piso em pixels em vez de só uma fração: com
     fração pura, a mesma linha mede 48 px numa largura e 59 px na seguinte, e a
     lista fica com o passo irregular. O piso não pode ser `max-content` porque
     cabeçalho e corpo são grades separadas, e cada uma resolveria o seu. */
  .tabela thead tr,
  .tabela tbody tr {
    grid-template-columns: 1.3fr 1fr minmax(192px, 1.3fr) 1fr;
    gap: var(--space-4);
    padding: 0 var(--space-4);
  }

  .tabela__numeros {
    white-space: nowrap;
  }

  .tabela thead tr {
    display: grid;
    background: var(--surface-sunken);
    border-top: 0;
  }

  .tabela tbody tr {
    align-items: center;
    min-height: 48px;
    padding-top: var(--space-2);
    padding-bottom: var(--space-2);
  }

  .tabela td::before {
    display: none;
  }
}

@media (min-width: 1024px) {
  .painel__titulo {
    font-size: 36px;
    line-height: 40px;
    letter-spacing: -.02em;
  }

  .indicador__valor {
    font-size: 36px;
    line-height: 40px;
  }
}

@media (min-width: 1280px) {
  /* Só aqui os quatro indicadores cabem lado a lado; abaixo disso são duas
     linhas de dois, e nunca quatro colunas espremidas. */
  .painel__indicadores {
    grid-template-columns: repeat(4, 1fr);
  }

  .painel__colunas {
    display: grid;
    grid-template-columns: 2fr 1fr;
    align-items: start;
  }

  .painel__lado {
    order: revert;
  }
}
</style>
