<script setup>
import { computed } from 'vue'
import { ClockAlert, History, KeyRound } from '@lucide/vue'
import { emNumeros } from '@/lib/datas.js'

/**
 * `AuthorizationCard` (§5.2 do briefing) — o cartão de uma autorização em T12.
 *
 * Quatro variantes, e a diferença entre elas não é de cor: é do que se pode
 * fazer. A vigente se revoga; a que está a expirar se renova em um toque
 * (RF40c); a expirada só se refaz pelo fluxo inteiro de T11, porque o
 * consentimento acabou; a revogada não oferece ação alguma — foi ato do tutor,
 * e desfazê-lo é conceder de novo, não "desrevogar".
 *
 * A linha de dados traz sempre a data de concessão ao lado da data que
 * importa, como pede RF41: sem ela, o cartão diria quando o acesso termina e
 * calaria desde quando ele existe.
 */
const props = defineProps({
  autorizacao: { type: Object, required: true },
  animal: { type: Object, required: true },
})

defineEmits(['revogar', 'renovar'])

const vigente = computed(() =>
  ['vigente', 'a_expirar'].includes(props.autorizacao.situacao),
)

const aExpirar = computed(() => props.autorizacao.situacao === 'a_expirar')

/**
 * O prazo em palavras. O plural muda, e "hoje" e "amanhã" não são números:
 * "expira em 1 dias" é o tipo de frase que denuncia que ninguém leu a tela.
 */
function emDias(dias, { prefixo, restante = false }) {
  if (dias <= 0) return restante ? 'Expira hoje' : `${prefixo} hoje`
  if (dias === 1) return restante ? 'Resta 1 dia' : `${prefixo} amanhã`

  return restante ? `Restam ${dias} dias` : `${prefixo} em ${dias} dias`
}

const etiqueta = computed(() => {
  const dias = props.autorizacao.dias_restantes

  return {
    vigente: 'Autorização vigente',
    a_expirar: emDias(dias, { prefixo: 'Expira' }),
    expirada: 'Expirada',
    revogada: 'Revogada por você',
  }[props.autorizacao.situacao]
})

const restante = computed(() => emDias(props.autorizacao.dias_restantes, { restante: true }))

/**
 * Concessão e desfecho na mesma linha. O verbo diz a situação por escrito, e
 * não só pela cor da borda — a mesma exigência que vale para a etiqueta de
 * situação de uma dose (§5.2).
 */
const datas = computed(() => {
  const concedida = `Concedida em ${emNumeros(props.autorizacao.concedida_em)}`

  const desfecho = {
    vigente: `expira em ${emNumeros(props.autorizacao.expira_em)}`,
    a_expirar: `expira em ${emNumeros(props.autorizacao.expira_em)}`,
    expirada: `expirou em ${emNumeros(props.autorizacao.expira_em)}`,
    revogada: `revogada em ${emNumeros(props.autorizacao.revogada_em)}`,
  }[props.autorizacao.situacao]

  return `${concedida} · ${desfecho}`
})

const larguraDaBarra = computed(
  () => `${Math.round(props.autorizacao.proporcao_restante * 100)}%`,
)

const prestador = computed(() => props.autorizacao.prestador)

/**
 * A ação nomeia o que alcança. Quem navega por leitor de tela ouve os botões
 * fora do contexto do cartão, e "Revogar acesso" sozinho, numa tela com quatro
 * autorizações, não diz qual delas termina.
 */
const alcance = computed(() => `de ${prestador.value.nome} ao histórico de ${props.animal.nome}`)

// T14 — a auditoria de quem consultou o quê (RF53), com o recorte já feito.
// A tela ainda é fatia própria; o destino fica no lugar desenhado, como já
// acontece com os demais destinos de `TutorShell` que ainda não existem.
const destinoDosAcessos = computed(() => ({
  path: '/acessos',
  query: { prestador: prestador.value.id, animal: props.animal.codigo },
}))

const destinoDeNovaAutorizacao = computed(() => ({
  path: '/autorizacoes/nova',
  query: { prestador: prestador.value.id },
}))
</script>

<template>
  <article class="autorizacao" :class="`autorizacao--${autorizacao.situacao}`">
    <p class="autorizacao__etiqueta">
      <KeyRound v-if="autorizacao.situacao === 'vigente'" :size="16" :stroke-width="1.75" />
      <ClockAlert v-else-if="aExpirar" :size="16" :stroke-width="1.75" />
      {{ etiqueta }}
    </p>

    <h3 class="autorizacao__prestador">{{ prestador.nome }}</h3>
    <p class="autorizacao__datas">{{ datas }}</p>

    <template v-if="vigente">
      <!-- A barra mede o que resta, e encolhe junto com o número ao lado dela.
           Decorativa: o prazo já está escrito em duas formas acima e abaixo. -->
      <div class="autorizacao__barra" aria-hidden="true">
        <span class="autorizacao__barra-prazo" :style="{ width: larguraDaBarra }" />
      </div>

      <div class="autorizacao__rodape">
        <!-- Na que está a expirar o prazo já está dito na etiqueta, em âmbar e
             no topo do cartão: repeti-lo aqui só empurraria as ações para
             baixo. -->
        <p v-if="!aExpirar" class="autorizacao__restante">{{ restante }}</p>

        <div class="autorizacao__acoes">
          <button
            v-if="aExpirar"
            type="button"
            class="acao acao--renovar"
            :aria-label="`Renovar por 90 dias o acesso ${alcance}`"
            @click="$emit('renovar', autorizacao)"
          >
            Renovar por 90 dias
          </button>

          <!-- O nome no lugar de "ela": o prestador tanto pode ser uma clínica
               quanto um hospital ou um profissional, e o pronome acertaria só
               um dos três. Na coluna estreita do desenho de 1440 px a ação
               encurta, e o nome fica no rótulo acessível. -->
          <RouterLink
            v-else
            :to="destinoDosAcessos"
            class="acao acao--neutra"
            :aria-label="`Ver o que ${prestador.nome} acessou do histórico de ${animal.nome}`"
          >
            <History :size="20" :stroke-width="1.75" class="acao__icone" />
            <span class="acao__longa">Ver o que {{ prestador.nome }} acessou</span>
            <span class="acao__curta" aria-hidden="true">Ver acessos</span>
          </RouterLink>

          <button
            type="button"
            class="acao acao--revogar"
            :aria-label="`Revogar o acesso ${alcance}`"
            @click="$emit('revogar', autorizacao)"
          >
            Revogar acesso
          </button>
        </div>
      </div>

      <!-- RF40c em uma frase: a renovação não repete o fluxo, e dizê-lo aqui
           evita que o tutor adie o toque esperando um código que não vem. -->
      <p v-if="aExpirar" class="autorizacao__nota">
        Renovar não pede código novamente: você já confirmou esta autorização uma vez.
      </p>
    </template>

    <!-- Autorizar de novo é concessão nova, com o código de RF37: o
         consentimento anterior terminou com o prazo. -->
    <RouterLink
      v-else-if="autorizacao.situacao === 'expirada'"
      :to="destinoDeNovaAutorizacao"
      class="acao acao--neutra autorizacao__refazer"
    >
      Autorizar {{ prestador.nome }} de novo
    </RouterLink>
  </article>
</template>

<style scoped>
.autorizacao {
  padding: var(--space-4);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
  background: var(--surface-card);
}

/* O índigo de consentimento é o eixo do bloco: o que ainda dá acesso vem nele,
   o que já não dá volta ao branco das demais telas. */
.autorizacao--vigente {
  background: var(--consent-wash);
  border-color: var(--consent);
}

.autorizacao--a_expirar {
  background: var(--consent-wash);
  border-color: var(--status-due);
}

.autorizacao--expirada {
  border-color: var(--border-strong);
}

/* Esmaecida: a revogada é a única sem ação alguma, e a opacidade é o que
   sinaliza isso antes da leitura. */
.autorizacao--revogada {
  opacity: .55;
}

.autorizacao__etiqueta {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0;
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
  color: var(--ink-muted);
}

.autorizacao--vigente .autorizacao__etiqueta {
  color: var(--consent);
}

/* --status-due não passa em contraste como cor de texto; a palavra usa a
   variante escura prevista nos tokens, como em `StatusPill`. */
.autorizacao--a_expirar .autorizacao__etiqueta {
  color: var(--status-due-text);
}

.autorizacao__prestador {
  margin: var(--space-2) 0 0;
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.autorizacao--expirada .autorizacao__prestador,
.autorizacao--revogada .autorizacao__prestador {
  margin-top: var(--space-1);
}

.autorizacao__datas {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.autorizacao__barra {
  height: 6px;
  margin: var(--space-3) 0 0;
  overflow: hidden;
  background: var(--surface-card);
  border-radius: var(--radius-pill);
}

.autorizacao__barra-prazo {
  display: block;
  height: 100%;
  background: var(--consent);
}

.autorizacao--a_expirar .autorizacao__barra-prazo {
  background: var(--status-due);
}

.autorizacao__restante {
  margin: var(--space-2) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.autorizacao__acoes {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin: var(--space-3) 0 0;
}

.autorizacao__nota {
  margin: var(--space-3) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.autorizacao__refazer {
  width: 100%;
  margin: var(--space-3) 0 0;
}

.acao {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  height: 48px;
  padding: 0 var(--space-4);
  border: 1px solid transparent;
  border-radius: var(--radius-sm);
  font-family: var(--font-body);
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
  text-align: center;
}

.acao--neutra {
  background: var(--surface-card);
  border-color: var(--border-strong);
  color: var(--ink);
}

.acao--neutra:hover {
  background: var(--surface-sunken);
  color: var(--ink);
}

.acao__icone {
  flex: none;
  color: var(--consent);
}

.acao__curta {
  display: none;
}

.acao--renovar {
  background: var(--brand);
  border-color: var(--brand);
  color: var(--surface-card);
}

.acao--renovar:hover {
  background: var(--brand-hover);
  border-color: var(--brand-hover);
}

/* Revogar não é a ação em destaque do cartão: fica em contorno, com a cor que
   o sistema reserva ao que encerra. */
.acao--revogar {
  background: var(--surface-card);
  border-color: var(--status-late);
  color: var(--status-late);
}

.acao--revogar:hover {
  background: var(--status-late-wash);
}

/* Larguras derivadas — a partir de md os cartões dividem a coluna em dois, e
   as ações cabem numa linha só ao lado do prazo, como no desenho de 1440 px. */
@media (min-width: 768px) {
  .autorizacao__rodape {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: var(--space-3);
  }

  .autorizacao__acoes {
    flex-direction: row;
    margin-top: var(--space-2);
  }

  .acao {
    height: 32px;
    padding: 0 var(--space-3);
    font-size: 14px;
  }

  .acao__icone,
  .acao__longa {
    display: none;
  }

  .acao__curta {
    display: inline;
  }

  .autorizacao__refazer {
    width: auto;
  }
}
</style>
