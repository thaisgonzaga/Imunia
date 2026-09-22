<script setup>
import { computed } from 'vue'
import { Cat, Dog, KeyRound, Lock } from '@lucide/vue'
import { emNumeros } from '@/lib/datas.js'

/**
 * O cartão de um pedido de acesso em T13 (RF38).
 *
 * Três variantes, e a diferença entre elas é a mesma do `AuthorizationCard`:
 * não é de cor, é do que resta fazer. O pendente pergunta e traz as duas
 * respostas possíveis; o caducado e o recusado não oferecem ação alguma, e
 * continuam na tela porque saber quem pediu acesso aos seus animais é
 * informação do titular, não histórico descartável.
 *
 * Só o pendente vem no índigo de consentimento. O que já não decide nada volta
 * ao branco das demais telas, como a autorização encerrada de T12.
 */
const props = defineProps({
  solicitacao: { type: Object, required: true },
})

defineEmits(['recusar'])

const pendente = computed(() => props.solicitacao.situacao === 'pendente')

const prestador = computed(() => props.solicitacao.prestador)
const animal = computed(() => props.solicitacao.animal)

const iconeDaEspecie = computed(() => (animal.value.especie === 'gato' ? Cat : Dog))

/**
 * O prazo em palavras. "caduca em 1 dias" é o tipo de frase que denuncia que
 * ninguém leu a tela, e aqui ela apareceria justamente no cartão mais urgente.
 */
const caducidade = computed(() => {
  const dias = props.solicitacao.dias_restantes

  if (dias <= 0) return 'o pedido caduca hoje'
  if (dias === 1) return 'o pedido caduca amanhã'

  return `o pedido caduca em ${dias} dias`
})

const etiqueta = computed(() => ({
  pendente: 'Pedido aguardando você',
  expirada: 'Pedido caducado',
  recusada: 'Recusado por você',
}[props.solicitacao.situacao]))

/**
 * O desfecho de quem já não espera resposta, com o animal à frente: nas
 * situações encerradas o cartão é uma linha de histórico, e a pergunta que ele
 * responde é "de qual animal se tratava".
 */
const desfecho = computed(() => {
  const pedido = `pedido em ${emNumeros(props.solicitacao.solicitada_em)}`

  const fim = props.solicitacao.situacao === 'recusada'
    ? `recusado em ${emNumeros(props.solicitacao.recusada_em)}`
    : `caducou em ${emNumeros(props.solicitacao.expira_em)}`

  return props.solicitacao.situacao === 'recusada'
    ? `${animal.value.nome} · ${fim}`
    : `${animal.value.nome} · ${pedido}, ${fim}`
})

/**
 * "Autorizar" não autoriza: leva ao passo 3 de T11, com o prestador e o animal
 * já resolvidos, e é lá que o código enviado ao e-mail do tutor conclui o ato
 * (RF37). Manter a concessão num caminho só é o que impede este botão de virar
 * um segundo modo de consentir, sem confirmação.
 */
const destinoDaAutorizacao = computed(() => ({
  path: '/autorizacoes/nova',
  query: { prestador: prestador.value.id, animal: animal.value.codigo },
}))
</script>

<template>
  <article class="pedido" :class="`pedido--${solicitacao.situacao}`">
    <p class="pedido__etiqueta">
      <KeyRound v-if="pendente" :size="16" :stroke-width="1.75" />
      {{ etiqueta }}
    </p>

    <h3 class="pedido__prestador">{{ prestador.nome }}</h3>

    <p v-if="solicitacao.situacao !== 'recusada'" class="pedido__local">
      {{ prestador.tipo_rotulo }} · {{ prestador.municipio }}, {{ prestador.uf }}
    </p>

    <template v-if="pendente">
      <p class="pedido__animal">
        <component :is="iconeDaEspecie" :size="20" :stroke-width="1.75" class="pedido__especie" />
        <span>Quer ver o histórico de <strong>{{ animal.nome }}</strong></span>
      </p>

      <p class="pedido__datas">
        Pedido em {{ emNumeros(solicitacao.solicitada_em) }} · {{ caducidade }}
      </p>

      <!-- V10 — a linha de contexto que o prestador anexou. Citada e atribuída
           a ele: é o que ajuda a reconhecer de onde o pedido veio, e não texto
           da plataforma. -->
      <figure v-if="solicitacao.mensagem" class="pedido__mensagem">
        <blockquote class="pedido__mensagem-texto">“{{ solicitacao.mensagem }}”</blockquote>
        <figcaption class="pedido__mensagem-autor">mensagem de {{ prestador.nome }}</figcaption>
      </figure>

      <!-- RF38a em uma frase, e no lugar onde ela decide alguma coisa: antes
           dos botões. O tutor que não sabe que o pedido nada revelou tende a
           autorizar por resignação, supondo que a clínica já viu o histórico. -->
      <div class="pedido__garantia">
        <Lock :size="20" :stroke-width="1.75" class="pedido__cadeado" />
        <p>
          Enquanto você não autorizar, {{ prestador.nome }} não vê nada sobre
          {{ animal.nome }}. O pedido em si não revelou nome de vacina, atendimento ou anexo.
        </p>
      </div>

      <div class="pedido__acoes">
        <RouterLink
          :to="destinoDaAutorizacao"
          class="acao acao--autorizar"
          :aria-label="`Autorizar ${prestador.nome} a ver o histórico de ${animal.nome}`"
        >
          <KeyRound :size="20" :stroke-width="1.75" />
          Autorizar
        </RouterLink>

        <button
          type="button"
          class="acao acao--neutra"
          :aria-label="`Recusar o pedido de ${prestador.nome} sobre ${animal.nome}`"
          @click="$emit('recusar', solicitacao)"
        >
          Recusar
        </button>
      </div>

      <p class="pedido__nota">
        Autorizar leva direto à confirmação por código. Recusar não exige explicação, e a
        clínica só fica sabendo que o pedido não foi aceito.
      </p>
    </template>

    <template v-else>
      <p class="pedido__datas">{{ desfecho }}</p>

      <p v-if="solicitacao.situacao === 'recusada'" class="pedido__nota">
        Se mudar de ideia, você pode autorizar esta clínica pelo diretório.
      </p>
    </template>
  </article>
</template>

<style scoped>
.pedido {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
}

/* O índigo de consentimento é o eixo do bloco, e aqui ele marca o único cartão
   que ainda decide alguma coisa. */
.pedido--pendente {
  background: var(--consent-wash);
  border-color: var(--consent);
}

/* Esmaecido: o pedido que caducou não pede nada, e a opacidade diz isso antes
   da leitura. O recusado fica legível, porque foi ato do tutor e ele pode
   querer conferir a data. */
.pedido--expirada {
  opacity: .55;
}

.pedido__etiqueta {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0;
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
  color: var(--ink-muted);
}

.pedido--pendente .pedido__etiqueta {
  color: var(--consent);
}

.pedido__prestador {
  margin: var(--space-2) 0 0;
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.pedido--expirada .pedido__prestador,
.pedido--recusada .pedido__prestador {
  margin-top: var(--space-1);
}

.pedido__local,
.pedido__datas {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.pedido__datas {
  font-variant-numeric: tabular-nums;
}

.pedido__animal {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: var(--space-2) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.pedido__animal strong {
  font-weight: 600;
}

.pedido__especie {
  flex: none;
  color: var(--ink-muted);
}

.pedido__mensagem {
  margin: var(--space-3) 0 0;
  padding: var(--space-2) var(--space-3);
  border-left: 2px solid var(--consent);
}

.pedido__mensagem-texto {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.pedido__mensagem-autor {
  margin: var(--space-1) 0 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-muted);
}

.pedido__garantia {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: var(--space-3) 0 0;
  padding: var(--space-3);
  background: var(--surface-card);
  border-radius: var(--radius-xs);
}

.pedido__garantia p {
  margin: 0;
  max-width: 75ch;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.pedido__cadeado {
  flex: none;
  margin-top: 2px;
  color: var(--consent);
}

.pedido__acoes {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin: var(--space-3) 0 0;
}

.pedido__nota {
  margin: var(--space-3) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
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

.acao--autorizar {
  background: var(--consent);
  border-color: var(--consent);
  color: var(--surface-card);
}

.acao--autorizar:hover {
  background: #32447C;
  border-color: #32447C;
  color: var(--surface-card);
}

.acao--neutra {
  background: var(--surface-card);
  border-color: var(--border-strong);
  color: var(--ink);
}

.acao--neutra:hover {
  background: var(--surface-sunken);
}

/* Larguras derivadas — a partir de md as duas respostas cabem lado a lado, e
   "Autorizar" continua à frente por ser a que o pedido pede. */
@media (min-width: 768px) {
  .pedido__acoes {
    flex-direction: row;
  }

  .acao {
    height: 40px;
    font-size: 14px;
  }
}
</style>
