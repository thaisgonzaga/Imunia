<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Building2, ClockAlert, Lock, TriangleAlert, UserRoundCheck } from '@lucide/vue'
import ContaShell from '@/components/prestador/ContaShell.vue'
import { apiGet, apiPost } from '@/lib/api.js'
import { formatarCnpj, formatarTelefone } from '@/lib/masks.js'

/**
 * A01 — painel administrativo do prestador (RF07, RF08, RF09).
 *
 * Não há indicador clínico algum nesta tela, e a ausência é o argumento: o
 * perfil administra a conta, não o dado (RN08). O bloco cinza ao pé da coluna
 * lateral existe para dizer isso em voz alta — sem ele, um painel sem números
 * de atendimento passaria por painel incompleto.
 */
const router = useRouter()

const ICONES = {
  'clock-alert': ClockAlert,
  'building-2': Building2,
  'user-round-check': UserRoundCheck,
}

const painel = ref(null)
const carregando = ref(true)
const erro = ref('')
const reenviando = ref(null)
const avisoDeReenvio = ref('')

const prestador = computed(() => painel.value?.prestador ?? null)
const podeAdministrar = computed(() => painel.value?.pode_administrar ?? false)

/**
 * "A administração desta conta é de Dra. Camila Martins Oliveira." — a frase
 * termina no nome de quem procurar, e não numa regra abstrata. Sem nome algum
 * (conta cujo administrador ainda não aceitou o convite), diz o que é verdade
 * sem prometer um interlocutor que a tela não conhece.
 */
const quemAdministra = computed(() => {
  const nomes = painel.value?.administrada_por ?? []

  if (nomes.length === 0) return 'A administração desta conta é de quem a criou.'

  return `A administração desta conta é de ${nomes.join(', ')}.`
})
const cnpj = computed(() => formatarCnpj(prestador.value?.cnpj ?? ''))
const telefone = computed(() => formatarTelefone(prestador.value?.telefone ?? ''))

async function carregar(prestadorId) {
  carregando.value = true
  erro.value = ''

  try {
    const consulta = prestadorId ? `?prestador=${prestadorId}` : ''
    painel.value = await apiGet(`/api/prestador/painel${consulta}`)
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    carregando.value = false
  }
}

/**
 * A pendência de convite a expirar resolve-se onde é anunciada: obrigar o
 * administrador a abrir A03 para clicar num botão que já está à vista faria da
 * lista de pendências um índice, e não um lugar de agir.
 */
async function reenviar(vinculo) {
  reenviando.value = vinculo
  avisoDeReenvio.value = ''
  erro.value = ''

  try {
    const resposta = await apiPost(`/api/prestador/equipe/${vinculo}/reenviar`, {})
    avisoDeReenvio.value = resposta.message
    await carregar(prestador.value?.id)
  } catch (excecao) {
    erro.value = excecao.message
  } finally {
    reenviando.value = null
  }
}

function agir(acao) {
  if (acao.tipo === 'reenviar_convite') return reenviar(acao.vinculo)
  if (acao.tipo === 'ir_para_equipe') return router.push('/prestador/equipe')
  if (acao.tipo === 'convidar_veterinario') return router.push('/prestador/equipe?convidar=1')

  return router.push('/prestador/dados')
}

carregar()
</script>

<template>
  <ContaShell
    titulo="Administração"
    :prestador="painel?.prestador ?? null"
    :vinculos="painel?.vinculos ?? []"
    :convites-pendentes="painel?.equipe?.convites_pendentes ?? 0"
    :contexto-clinico="painel?.contexto_clinico ?? null"
    :atende-aqui="painel?.atende_aqui ?? false"
    :vinculos-clinicos="painel?.vinculos_clinicos ?? []"
    @trocar-prestador="carregar"
  >
    <div class="painel">
      <div v-if="carregando" class="painel__esqueleto" aria-busy="true" aria-live="polite">
        <span class="visually-hidden">Carregando o painel administrativo.</span>
        <div class="cartao">
          <div class="esqueleto esqueleto--titulo" />
          <div class="painel__identificacao">
            <div v-for="n in 6" :key="n" class="esqueleto esqueleto--campo" />
          </div>
        </div>
        <div class="cartao">
          <div class="esqueleto esqueleto--titulo" />
          <div class="painel__numeros">
            <div v-for="n in 3" :key="n" class="esqueleto esqueleto--numero" />
          </div>
        </div>
      </div>

      <div v-else-if="erro" class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" />
        <div>
          <p class="aviso__titulo">Não conseguimos carregar o painel.</p>
          <p class="aviso__texto">{{ erro }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar()">Tentar novamente</button>
        </div>
      </div>

      <template v-else>
        <div class="painel__cabecalho">
          <p class="painel__sobrelinha">{{ podeAdministrar ? 'Painel administrativo' : 'Onde você atende' }}</p>
          <h1 class="painel__titulo">{{ prestador.nome }}</h1>
        </div>

        <!--
          A quem pedir. Quem só atende aqui vê a conta e não a move, e a frase
          evita a leitura de que a tela veio incompleta — o que não está à mão
          está com alguém, e o nome dessa pessoa é a informação útil.
        -->
        <p v-if="!podeAdministrar" class="painel__leitura">
          <Lock :size="16" :stroke-width="1.75" />
          <span>
            Você atende aqui. {{ quemAdministra }}
          </span>
        </p>

        <!--
          RF07c — bloqueante, e por isso acima de tudo: sem responsável técnico a
          clínica não registra informação clínica alguma, o que é diferente em
          natureza das demais pendências, que só valem a pena resolver.
        -->
        <section v-if="painel.bloqueio" class="bloqueio" role="alert">
          <div class="bloqueio__cabecalho">
            <TriangleAlert :size="24" :stroke-width="1.75" />
            <h2 class="bloqueio__titulo">{{ painel.bloqueio.titulo }}</h2>
          </div>
          <p class="bloqueio__texto">{{ painel.bloqueio.descricao }}</p>
          <p class="bloqueio__texto bloqueio__texto--fraco">{{ painel.bloqueio.complemento }}</p>
          <!--
            O bloqueio aparece para os dois — sem responsável técnico ninguém
            registra nada aqui —, mas a ação é de quem pode cumpri-la.
          -->
          <RouterLink
            v-if="podeAdministrar"
            to="/prestador/dados#responsavel-tecnico"
            class="botao botao--primario"
          >
            {{ painel.bloqueio.acao.rotulo }}
          </RouterLink>
        </section>

        <p v-if="avisoDeReenvio" class="aviso aviso--sucesso" role="status">{{ avisoDeReenvio }}</p>

        <div class="painel__grade">
          <div class="painel__coluna">
            <section class="cartao">
              <div class="cartao__cabecalho">
                <h2 class="cartao__rotulo">Identificação</h2>
                <RouterLink to="/prestador/dados" class="ligacao">
                  {{ podeAdministrar ? 'Editar dados' : 'Ver dados' }}
                </RouterLink>
              </div>
              <dl class="painel__identificacao">
                <div>
                  <dt>Tipo</dt>
                  <dd>{{ prestador.tipo_rotulo }}</dd>
                </div>
                <div>
                  <dt>Município</dt>
                  <dd>{{ prestador.municipio }}, {{ prestador.uf }}</dd>
                </div>
                <div>
                  <dt>CNPJ</dt>
                  <dd class="mono">{{ cnpj }}</dd>
                </div>
                <div>
                  <dt>Responsável técnico</dt>
                  <dd>{{ prestador.responsavel_tecnico_nome ?? 'não informado' }}</dd>
                </div>
                <div>
                  <dt>CRMV</dt>
                  <dd class="mono">{{ prestador.responsavel_tecnico_crmv ?? '—' }}</dd>
                </div>
                <div>
                  <dt>Contato público</dt>
                  <dd class="mono">{{ telefone }}</dd>
                </div>
              </dl>
            </section>

            <section class="cartao cartao--sem-espaco">
              <div class="cartao__cabecalho cartao__cabecalho--fio">
                <h2 class="cartao__titulo">Equipe</h2>
                <RouterLink to="/prestador/equipe" class="ligacao">
                  {{ podeAdministrar ? 'Gerenciar equipe' : 'Ver equipe' }}
                </RouterLink>
              </div>
              <!--
                Convite pendente e vínculo encerrado são contagens de gestão, e
                em leitura viriam zeradas por não terem sido contadas — número
                que não foi apurado não se exibe como se fosse zero.
              -->
              <dl class="painel__numeros">
                <div>
                  <dt>Veterinários ativos</dt>
                  <dd>{{ painel.equipe.ativos }}</dd>
                </div>
                <template v-if="podeAdministrar">
                  <div class="painel__numero--consentimento">
                    <dt>Convites pendentes</dt>
                    <dd>{{ painel.equipe.convites_pendentes }}</dd>
                  </div>
                  <div>
                    <dt>Vínculos encerrados</dt>
                    <dd>{{ painel.equipe.encerrados }}</dd>
                  </div>
                </template>
              </dl>
            </section>
          </div>

          <div class="painel__coluna">
            <h2 v-if="painel.pendencias.length" class="painel__rotulo-lateral">Pendências de configuração</h2>

            <article
              v-for="pendencia in painel.pendencias"
              :key="`${pendencia.chave}-${pendencia.acao.vinculo ?? 0}`"
              class="pendencia"
            >
              <p class="pendencia__tipo">
                <component :is="ICONES[pendencia.icone] ?? ClockAlert" :size="16" :stroke-width="1.75" />
                {{ pendencia.titulo }}
              </p>
              <p class="pendencia__texto">{{ pendencia.descricao }}</p>
              <button
                type="button"
                class="botao botao--secundario"
                :disabled="reenviando === pendencia.acao.vinculo"
                @click="agir(pendencia.acao)"
              >
                {{ reenviando === pendencia.acao.vinculo ? 'Reenviando…' : pendencia.acao.rotulo }}
              </button>
            </article>

            <!--
              §8.4 do briefing — a ausência de indicador clínico é intencional, e
              precisa ser explicada na interface, não apenas produzida por ela.
            -->
            <aside class="limitacao">
              <Lock :size="20" :stroke-width="1.75" />
              <p>
                Não há indicador de atendimentos, vacinas ou animais neste painel: essas informações
                pertencem ao ambiente clínico, e o perfil administrativo não tem acesso a elas.
              </p>
            </aside>
          </div>
        </div>
      </template>
    </div>
  </ContaShell>
</template>

<style scoped>
.painel {
  width: 100%;
  max-width: 1280px;
  margin: 0 auto;
}

.painel__cabecalho {
  margin: 0 0 var(--space-6);
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
  letter-spacing: -.02em;
  color: var(--ink);
}

.painel__grade {
  display: flex;
  flex-direction: column;
  gap: var(--space-6);
}

.painel__coluna {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  min-width: 0;
}

.cartao {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.cartao--sem-espaco {
  padding: 0;
  overflow: hidden;
}

.cartao__cabecalho {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4);
}

.cartao__cabecalho--fio {
  padding: var(--space-4) var(--space-4);
  border-bottom: 1px solid var(--border-hairline);
}

.cartao__rotulo {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.cartao__titulo {
  margin: 0;
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.ligacao {
  font-size: 14px;
  font-weight: 600;
}

.painel__identificacao,
.painel__numeros {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-4);
  margin: var(--space-4) 0 0;
}

.painel__numeros {
  margin: 0;
  padding: var(--space-4);
}

.painel__identificacao dt {
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

.painel__identificacao dd {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.painel__numeros dt {
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.painel__numeros dd {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
}

.painel__numero--consentimento dt,
.painel__numero--consentimento dd {
  color: var(--consent);
}

.mono {
  font-family: var(--font-mono);
  font-size: 13px;
  line-height: 18px;
  font-variant-numeric: tabular-nums;
}

.painel__rotulo-lateral {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

.pendencia {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--status-due);
  border-radius: var(--radius-md);
}

.pendencia__tipo {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0;
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
  color: var(--status-due-text);
}

.pendencia__texto {
  margin: var(--space-2) 0 var(--space-2);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.bloqueio {
  margin: 0 0 var(--space-6);
  padding: var(--space-6);
  background: var(--status-late-wash);
  border: 1px solid var(--status-late);
  border-radius: var(--radius-md);
}

.bloqueio__cabecalho {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  color: var(--status-late);
}

.bloqueio__titulo {
  margin: 0;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--ink);
}

.bloqueio__texto {
  margin: var(--space-3) 0 0;
  max-width: 75ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.bloqueio__texto--fraco {
  margin-top: var(--space-2);
  color: var(--ink-muted);
}

.bloqueio .botao {
  margin-top: var(--space-4);
}

/* Uma linha, e não um cartão: a informação é de enquadramento — diz de quem é
   a conta que se está vendo — e não uma advertência a ser encarada. */
.painel__leitura {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: var(--space-4) 0 0;
  padding: var(--space-2) var(--space-3);
  background: var(--surface-sunken);
  border-radius: var(--radius-sm);
  font-size: 13px;
  line-height: 18px;
  color: var(--ink-muted);
}

.painel__leitura svg {
  flex: none;
}

.limitacao {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-4);
  background: var(--surface-sunken);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
}

.limitacao svg {
  flex: none;
  margin-top: 2px;
  color: var(--ink-muted);
}

.limitacao p {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.botao {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 44px;
  padding: 0 var(--space-4);
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}

.botao--primario {
  background: var(--brand);
  border: 1px solid var(--brand);
  color: #fff;
}

.botao--primario:hover {
  background: var(--brand-hover);
}

.botao--secundario {
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  color: var(--ink);
}

.botao--secundario:hover {
  background: var(--surface-sunken);
}

.botao:disabled {
  opacity: .6;
  cursor: progress;
}

.aviso {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: 0 0 var(--space-4);
  padding: var(--space-4);
  border-radius: var(--radius-sm);
}

.aviso--erro {
  background: var(--status-late-wash);
  border: 1px solid var(--status-late);
  color: var(--status-late);
}

.aviso--sucesso {
  display: block;
  background: var(--brand-wash);
  border: 1px solid var(--brand);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink);
}

.aviso__titulo {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.aviso__texto {
  margin: var(--space-1) 0 var(--space-3);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.painel__esqueleto {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: esqueleto 1.2s ease-in-out infinite;
}

.esqueleto--titulo {
  height: 16px;
  width: 28%;
}

.esqueleto--campo {
  height: 40px;
}

.esqueleto--numero {
  height: 52px;
}

@keyframes esqueleto {
  0% { opacity: .55; }
  50% { opacity: 1; }
  100% { opacity: .55; }
}

@media (prefers-reduced-motion: reduce) {
  .esqueleto {
    animation: none;
  }
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  margin: -1px;
  padding: 0;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
  border: 0;
}

@media (min-width: 768px) {
  .painel__identificacao,
  .painel__numeros {
    grid-template-columns: repeat(3, 1fr);
  }

  .botao {
    min-height: 40px;
  }
}

@media (min-width: 1024px) {
  .painel__titulo {
    font-size: 36px;
    line-height: 40px;
  }

  .painel__grade {
    display: grid;
    grid-template-columns: 2fr 1fr;
    align-items: flex-start;
  }
}
</style>
