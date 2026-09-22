<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  Cat,
  ChevronLeft,
  Dog,
  Eye,
  KeyRound,
  Stethoscope,
  TriangleAlert,
} from '@lucide/vue'
import VetShell from '@/components/vet/VetShell.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppCheckbox from '@/components/base/AppCheckbox.vue'
import AppInput from '@/components/base/AppInput.vue'
import AppSelect from '@/components/base/AppSelect.vue'
import { ApiError, apiGet, apiPost } from '@/lib/api.js'
import { descreverEspecie } from '@/lib/animais.js'
import {
  cpfValido,
  formatarCpf,
  formatarDataAproximada,
  formatarDataCompleta,
  somenteDigitos,
} from '@/lib/masks.js'

/**
 * V05 — cadastrar animal no atendimento (RF16, RF19, RF20).
 *
 * Uma tela, duas portas. Por `/clinica/animais/novo`, o cadastro inteiro:
 * identificação e caracterização de uma vez, com o tutor resolvido por CPF —
 * a chave do balcão, verificada pela busca de V03 como em V04. Por
 * `/clinica/animais/:codigo/caracterizar` — o endereço que a tarja de V06
 * promete —, o modo de consolidação (RF20b): a identificação já existe e é do
 * tutor; o que se preenche é só a metade privativa.
 *
 * A divisão de responsabilidade que sustenta o diferencial do sistema fica
 * explícita nos dois cabeçalhos do formulário: identificação, que o tutor
 * também pode preencher; caracterização, privativa do médico-veterinário
 * (RN18). É desenho, não decoração — é a tela dizendo quem responde pelo quê.
 */
const route = useRoute()
const router = useRouter()

const modo = computed(() => (route.params.codigo ? 'consolidar' : 'novo'))

const contexto = ref(null)
const carregando = ref(true)
const erroDeContexto = ref('')

/** O 403 do modo consolidação: animal fora do âmbito de autorização (RN48). */
const semAutorizacao = ref('')

/** O animal do modo consolidação, como o servidor o conhece. */
const animal = ref(null)

// Etapa do tutor (modo novo) ------------------------------------------------

/** cpf → confirmado | sem-cadastro — a barreira de V04, pela mesma razão. */
const etapaDoTutor = ref('cpf')
const cpf = ref('')
const erroDoCpf = ref('')
const verificando = ref(false)

/** Nome do tutor quando o âmbito já o mostra; vazio fora dele (RN12). */
const tutorConhecido = ref('')

// Formulário -----------------------------------------------------------------

const form = ref({
  nome: '',
  especie: '',
  sexo: '',
  nascimento: '',
  nascimento_exato: false,
  raca: '',
  pelagem: '',
  situacao_reprodutiva: '',
  microchip: '',
})

const errosDeCampo = ref({})
const erroDeEnvio = ref('')
const enviando = ref(false)

/** A resposta 409 de RF20a — bloqueante até escolha explícita. */
const duplicado = ref(null)

const prestador = computed(() => contexto.value?.prestador?.nome ?? 'este prestador')
const prestadorId = computed(() => contexto.value?.prestador?.id ?? null)

const iconeDaEspecie = computed(() => ((animal.value?.especie ?? form.value.especie) === 'gato' ? Cat : Dog))

const podeEnviar = computed(() => (modo.value === 'consolidar'
  ? true
  : form.value.nome.trim() !== '' && form.value.especie !== ''))

function primeiroErro(campo) {
  return errosDeCampo.value[campo]?.[0] ?? ''
}

function preenchidoOuNulo(valor) {
  const limpo = String(valor ?? '').trim()

  return limpo === '' ? null : limpo
}

// A máscara acompanha a afirmação: exata pede o dia, estimada o recusa.
function aoDigitarNascimento(valor) {
  form.value.nascimento = form.value.nascimento_exato
    ? formatarDataCompleta(valor)
    : formatarDataAproximada(valor)
}

function aoMarcarExata(valor) {
  form.value.nascimento_exato = valor
  form.value.nascimento = valor
    ? formatarDataCompleta(form.value.nascimento)
    : formatarDataAproximada(form.value.nascimento)
}

/**
 * O declarado como o campo o exibe: exata em `dd/mm/aaaa`, estimada em
 * `mm/aaaa`. A estimativa por ano perde a forma original ("2024" reaparece
 * como "01/2024"), e a perda é aceitável: o veterinário está aqui exatamente
 * para confirmar ou corrigir o que está vendo (RF19b).
 */
function nascimentoParaCampo(iso, exato) {
  if (!iso) return ''

  const [ano, mes, dia] = iso.split('-')

  return exato ? `${dia}/${mes}/${ano}` : `${mes}/${ano}`
}

async function carregar() {
  carregando.value = true
  erroDeContexto.value = ''
  semAutorizacao.value = ''

  try {
    if (modo.value === 'novo') {
      // Sem termo, a busca devolve só o contexto clínico — prestador ativo e
      // vínculos. Nada é consultado nem registrado.
      contexto.value = await apiGet('/api/clinica/buscar')

      return
    }

    const busca = new URLSearchParams()
    if (route.query.prestador) busca.set('prestador', route.query.prestador)

    const resposta = await apiGet(`/api/clinica/animais/${route.params.codigo}/caracterizar?${busca}`)

    contexto.value = { prestador: resposta.prestador, vinculos: resposta.vinculos }
    animal.value = resposta.animal

    form.value = {
      ...form.value,
      sexo: resposta.animal.sexo ?? '',
      nascimento: nascimentoParaCampo(resposta.animal.nascimento_em, resposta.animal.nascimento_exato),
      nascimento_exato: Boolean(resposta.animal.nascimento_exato),
      raca: resposta.animal.raca ?? '',
      pelagem: resposta.animal.pelagem ?? '',
      situacao_reprodutiva: resposta.animal.situacao_reprodutiva ?? '',
      microchip: resposta.animal.microchip ?? '',
    }
  } catch (excecao) {
    if (excecao instanceof ApiError && excecao.status === 403) {
      semAutorizacao.value = excecao.message
    } else {
      erroDeContexto.value = excecao.message
    }
  } finally {
    carregando.value = false
  }
}

/**
 * A barreira do CPF — a mesma verificação de V04, pela busca de V03: revela só
 * a existência (RN12) e registra a consulta (RF18b). O formulário só abre com
 * um titular resolvido, porque animal sem tutor não existe no sistema.
 */
async function verificar() {
  erroDoCpf.value = ''

  if (!cpfValido(cpf.value)) {
    erroDoCpf.value =
      'Este CPF não é válido: o dígito verificador não confere. Confira o número com o tutor.'

    return
  }

  verificando.value = true

  try {
    const busca = new URLSearchParams({ termo: somenteDigitos(cpf.value) })
    if (prestadorId.value) busca.set('prestador', prestadorId.value)

    const consulta = await apiGet(`/api/clinica/buscar?${busca}`)

    if (consulta.autorizados?.length) {
      tutorConhecido.value = consulta.autorizados[0].tutor
      etapaDoTutor.value = 'confirmado'
    } else if (consulta.existencia?.tipo === 'tutor') {
      tutorConhecido.value = ''
      etapaDoTutor.value = 'confirmado'
    } else {
      etapaDoTutor.value = 'sem-cadastro'
    }
  } catch (excecao) {
    erroDoCpf.value = excecao.message
  } finally {
    verificando.value = false
  }
}

function trocarCpf() {
  etapaDoTutor.value = 'cpf'
  tutorConhecido.value = ''
  duplicado.value = null
  erroDeEnvio.value = ''
  errosDeCampo.value = {}
}

function caracterizacaoDoFormulario() {
  return {
    sexo: preenchidoOuNulo(form.value.sexo),
    nascimento: preenchidoOuNulo(form.value.nascimento),
    nascimento_exato: form.value.nascimento_exato,
    raca: preenchidoOuNulo(form.value.raca),
    pelagem: preenchidoOuNulo(form.value.pelagem),
    situacao_reprodutiva: preenchidoOuNulo(form.value.situacao_reprodutiva),
    microchip: preenchidoOuNulo(somenteDigitos(form.value.microchip)),
  }
}

async function enviar({ apesarDaDuplicidade = false } = {}) {
  if (enviando.value) return

  enviando.value = true
  erroDeEnvio.value = ''
  errosDeCampo.value = {}

  try {
    if (modo.value === 'consolidar') {
      await apiPost(`/api/clinica/animais/${route.params.codigo}/caracterizar`, {
        ...caracterizacaoDoFormulario(),
        ...(prestadorId.value ? { prestador: prestadorId.value } : {}),
      })

      irParaFicha(route.params.codigo)

      return
    }

    const resposta = await apiPost('/api/clinica/animais', {
      cpf: somenteDigitos(cpf.value),
      nome: form.value.nome.trim(),
      especie: form.value.especie,
      ...caracterizacaoDoFormulario(),
      ...(apesarDaDuplicidade ? { confirmar_duplicidade: true } : {}),
      ...(prestadorId.value ? { prestador: prestadorId.value } : {}),
    })

    duplicado.value = null
    irParaFicha(resposta.animal.codigo)
  } catch (excecao) {
    tratarFalha(excecao)
  } finally {
    enviando.value = false
  }
}

/**
 * O sucesso conduz à ficha (V06), como o briefing manda. O cadastro novo chega
 * lá no estado sem autorização — e é ali que está o pedido de V10, fechando o
 * caminho do balcão: tutor, animal, solicitação.
 */
function irParaFicha(codigo) {
  const destino = `/clinica/animais/${codigo}`

  router.push(prestadorId.value ? `${destino}?prestador=${prestadorId.value}` : destino)
}

function tratarFalha(excecao) {
  if (excecao instanceof ApiError && excecao.status === 409 && excecao.data?.duplicado) {
    duplicado.value = excecao.data.duplicado

    return
  }

  if (excecao instanceof ApiError && excecao.status === 422) {
    errosDeCampo.value = excecao.errors

    // O CPF é validado no envio também; o erro dele volta para a barreira.
    if (excecao.errors.cpf) {
      erroDoCpf.value = excecao.errors.cpf[0]
      etapaDoTutor.value = 'cpf'
    }

    return
  }

  erroDeEnvio.value = excecao.message
}

function trocarPrestador(id) {
  contexto.value = { ...contexto.value, prestador: { ...contexto.value.prestador, id } }

  // O âmbito muda com o prestador: o tutor confirmado numa clínica pode estar
  // fora do âmbito na outra, e a verificação precisa ser refeita.
  if (modo.value === 'novo' && etapaDoTutor.value !== 'cpf') verificar()
  if (modo.value === 'consolidar') carregar()
}

const OPCOES_DE_SEXO = [
  { value: 'macho', label: 'Macho' },
  { value: 'femea', label: 'Fêmea' },
]

const OPCOES_REPRODUTIVAS = [
  { value: 'inteiro', label: 'Inteiro' },
  { value: 'castrado', label: 'Castrado' },
]

onMounted(carregar)
</script>

<template>
  <VetShell
    :titulo="modo === 'consolidar' ? 'Completar caracterização' : 'Cadastrar animal'"
    :prestador="contexto?.prestador"
    :vinculos="contexto?.vinculos ?? []"
    @trocar-prestador="trocarPrestador"
  >
    <div v-if="carregando" class="tela" aria-busy="true" aria-live="polite">
      <span class="visually-hidden">Abrindo o cadastro.</span>
      <div class="esqueleto esqueleto--titulo" />
      <div class="esqueleto esqueleto--campo" />
      <div class="esqueleto esqueleto--campo" />
    </div>

    <div v-else-if="erroDeContexto" class="tela">
      <div class="aviso aviso--erro" role="alert">
        <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
        <div>
          <p class="aviso__titulo">Não conseguimos abrir o cadastro.</p>
          <p class="aviso__texto">{{ erroDeContexto }}</p>
          <button type="button" class="botao botao--secundario" @click="carregar">
            Tentar novamente
          </button>
        </div>
      </div>
    </div>

    <!-- P2 do modo consolidação: caracterizar é escrever sobre o cadastro, e
         isso exige autorização vigente (RN48). O caminho é a ficha, que já
         sabe pedi-la (V10). -->
    <div v-else-if="semAutorizacao" class="tela">
      <section class="sem-autorizacao">
        <KeyRound :size="24" :stroke-width="1.75" class="sem-autorizacao__icone" />
        <div>
          <h1 class="sem-autorizacao__titulo">Sem autorização para caracterizar</h1>
          <p class="sem-autorizacao__texto">{{ semAutorizacao }}</p>
          <RouterLink :to="`/clinica/animais/${route.params.codigo}`" class="botao botao--consentimento">
            Abrir a ficha do animal
          </RouterLink>
        </div>
      </section>
    </div>

    <div v-else class="tela">
      <RouterLink
        :to="modo === 'consolidar' ? `/clinica/animais/${route.params.codigo}` : '/clinica/buscar'"
        class="voltar"
      >
        <ChevronLeft :size="16" :stroke-width="1.75" />
        {{ modo === 'consolidar' ? 'Voltar à ficha' : 'Voltar à busca' }}
      </RouterLink>

      <h1 class="tela__titulo">
        {{ modo === 'consolidar' ? 'Completar caracterização' : 'Cadastrar animal' }}
      </h1>

      <!-- Modo consolidação: a identificação já existe, é do tutor, e a tela a
           mostra como fato — não como formulário (RF20b, RN19). -->
      <section v-if="modo === 'consolidar'" class="cartao identificado">
        <component :is="iconeDaEspecie" :size="28" :stroke-width="1.75" class="identificado__icone" />
        <div class="identificado__texto">
          <p class="identificado__nome">{{ animal.nome }}</p>
          <p class="identificado__meta">
            {{ descreverEspecie(animal.especie) }} · {{ animal.tutor }}
          </p>
          <p class="identificado__codigo">{{ animal.codigo }}</p>
        </div>
        <span v-if="animal.preliminar" class="identificado__tarja">Cadastro preliminar</span>
      </section>

      <!-- Etapa do tutor (modo novo): o CPF é a chave e a barreira, como em
           V04 — animal sem titular resolvido não existe no sistema. -->
      <template v-if="modo === 'novo'">
        <section v-if="etapaDoTutor === 'cpf'" class="cartao etapa-cpf">
          <h2 class="secao__rotulo">Tutor do animal</h2>
          <form class="etapa-cpf__linha" novalidate @submit.prevent="verificar">
            <AppInput
              id="v05-cpf"
              :model-value="cpf"
              label="CPF do tutor"
              inputmode="numeric"
              autocomplete="off"
              mono
              :error="erroDoCpf"
              hint="A verificação diz apenas se o CPF já tem cadastro — nada mais."
              @update:model-value="cpf = formatarCpf($event)"
            />
            <AppButton type="submit" :loading="verificando" class="etapa-cpf__botao">
              Verificar
            </AppButton>
          </form>

          <div class="aviso-de-registro">
            <Eye :size="20" :stroke-width="1.75" class="aviso-de-registro__icone" />
            <p class="aviso-de-registro__texto">
              Se o CPF tiver cadastro fora da sua carteira de autorizações, a consulta fica
              registrada e visível ao tutor.
            </p>
          </div>
        </section>

        <section v-else-if="etapaDoTutor === 'sem-cadastro'" class="cartao etapa-cpf">
          <h2 class="secao__rotulo">Tutor do animal</h2>
          <p class="etapa-cpf__vazio">
            Nenhum cadastro corresponde a {{ formatarCpf(cpf) }}. O animal precisa de um titular:
            cadastre o tutor primeiro — o animal vem em seguida.
          </p>
          <div class="etapa-cpf__acoes">
            <RouterLink to="/clinica/tutores/novo" class="botao botao--primario">
              Cadastrar tutor
            </RouterLink>
            <button type="button" class="botao botao--secundario" @click="trocarCpf">
              Trocar CPF
            </button>
          </div>
        </section>

        <section v-else class="cartao cpf-verificado">
          <div>
            <p class="cpf-verificado__rotulo">Tutor do animal</p>
            <p class="cpf-verificado__numero">{{ formatarCpf(cpf) }}</p>
            <p v-if="tutorConhecido" class="cpf-verificado__nome">{{ tutorConhecido }}</p>
            <p v-else class="cpf-verificado__nota">
              Cadastro localizado. Nome e animais do tutor aparecem depois que ele autorizar
              {{ prestador }} — o pedido pode ser feito na ficha, logo após o cadastro.
            </p>
          </div>
          <button type="button" class="cpf-verificado__trocar" @click="trocarCpf">
            Trocar CPF
          </button>
        </section>
      </template>

      <!-- O formulário, nas duas metades que dão nome às colunas. -->
      <form
        v-if="modo === 'consolidar' || etapaDoTutor === 'confirmado'"
        novalidate
        @submit.prevent="enviar()"
      >
        <div class="metades">
          <section v-if="modo === 'novo'" class="metade">
            <header class="metade__cabecalho">
              <h2 class="secao__rotulo">Identificação</h2>
              <p class="metade__nota">O tutor também pode preencher e manter estes dados.</p>
            </header>

            <AppInput
              id="v05-nome"
              v-model="form.nome"
              label="Nome do animal"
              autocomplete="off"
              :error="primeiroErro('nome')"
            />

            <fieldset class="especie">
              <legend class="especie__rotulo">Espécie</legend>
              <div class="especie__cartoes">
                <button
                  v-for="opcao in [
                    { valor: 'cao', rotulo: 'Cão', icone: Dog },
                    { valor: 'gato', rotulo: 'Gato', icone: Cat },
                  ]"
                  :key="opcao.valor"
                  type="button"
                  class="especie-cartao"
                  :class="{ 'especie-cartao--ativo': form.especie === opcao.valor }"
                  :aria-pressed="form.especie === opcao.valor"
                  @click="form.especie = opcao.valor"
                >
                  <component :is="opcao.icone" :size="32" :stroke-width="1.5" />
                  {{ opcao.rotulo }}
                </button>
              </div>
              <p v-if="primeiroErro('especie')" class="erro-solto" role="alert">
                <TriangleAlert :size="16" :stroke-width="1.75" />
                <span>{{ primeiroErro('especie') }}</span>
              </p>
            </fieldset>
          </section>

          <section class="metade">
            <header class="metade__cabecalho">
              <h2 class="secao__rotulo secao__rotulo--clinico">
                <Stethoscope :size="16" :stroke-width="1.75" />
                Caracterização
              </h2>
              <p class="metade__nota">
                Privativa do médico-veterinário (RN18) — é o que encerra o cadastro preliminar.
                <template v-if="modo === 'consolidar'">
                  O que o tutor declarou aparece preenchido: confirme ou corrija.
                </template>
              </p>
            </header>

            <div class="metade__dupla">
              <AppSelect
                id="v05-sexo"
                v-model="form.sexo"
                label="Sexo"
                placeholder="Não informado"
                :options="OPCOES_DE_SEXO"
                :error="primeiroErro('sexo')"
              />
              <AppSelect
                id="v05-reprodutiva"
                v-model="form.situacao_reprodutiva"
                label="Situação reprodutiva"
                placeholder="Não informada"
                :options="OPCOES_REPRODUTIVAS"
                :error="primeiroErro('situacao_reprodutiva')"
              />
            </div>

            <div class="nascimento">
              <AppInput
                id="v05-nascimento"
                :model-value="form.nascimento"
                :label="form.nascimento_exato ? 'Data de nascimento' : 'Nascimento estimado'"
                :placeholder="form.nascimento_exato ? '04/06/2024' : '2024 ou 06/2024'"
                inputmode="numeric"
                autocomplete="off"
                mono
                :error="primeiroErro('nascimento') || primeiroErro('nascimento_exato')"
                @update:model-value="aoDigitarNascimento"
              />
              <!-- RN14 — só o veterinário afirma exatidão, e a afirmação muda
                   o que o campo exige: com o dia, ou sem ele. -->
              <AppCheckbox
                id="v05-nascimento-exato"
                :model-value="form.nascimento_exato"
                @update:model-value="aoMarcarExata"
              >
                Data exata, confirmada por documento ou aferição
              </AppCheckbox>
            </div>

            <div class="metade__dupla">
              <AppInput
                id="v05-raca"
                v-model="form.raca"
                label="Raça"
                placeholder="SRD, quando sem raça definida"
                autocomplete="off"
                :error="primeiroErro('raca')"
              />
              <AppInput
                id="v05-pelagem"
                v-model="form.pelagem"
                label="Pelagem"
                autocomplete="off"
                :error="primeiroErro('pelagem')"
              />
            </div>

            <AppInput
              id="v05-microchip"
              :model-value="form.microchip"
              label="Micro-chip"
              placeholder="15 dígitos, quando houver"
              inputmode="numeric"
              autocomplete="off"
              mono
              :error="primeiroErro('microchip')"
              @update:model-value="form.microchip = somenteDigitos($event).slice(0, 15)"
            />
          </section>
        </div>

        <!-- RF20a — bloqueante até escolha explícita: o par de botões substitui
             o envio enquanto a pergunta não for respondida. -->
        <section v-if="duplicado" class="duplicidade" role="alert">
          <div class="duplicidade__topo">
            <TriangleAlert :size="20" :stroke-width="1.75" class="duplicidade__icone" />
            <p class="duplicidade__titulo">Este tutor já tem um cadastro parecido com este.</p>
          </div>

          <p class="duplicidade__animal">
            <component :is="duplicado.especie === 'gato' ? Cat : Dog" :size="18" :stroke-width="1.75" />
            <strong>{{ duplicado.nome }}</strong> · {{ descreverEspecie(duplicado.especie) }}
            <span v-if="duplicado.codigo" class="duplicidade__codigo">{{ duplicado.codigo }}</span>
          </p>

          <p v-if="duplicado.ambito === 'fora_do_ambito'" class="duplicidade__nota">
            É tudo o que podemos mostrar sem autorização do tutor — e esta revelação ficou
            registrada, visível a ele. Se for o mesmo animal, use o código com o tutor ou peça a
            autorização; cadastrar de novo criaria uma duplicidade que depois não se desfaz.
          </p>
          <p v-else class="duplicidade__nota">
            Se for o mesmo animal, abra a ficha dele em vez de criar um segundo cadastro — a
            fusão posterior não é oferecida.
          </p>

          <div class="duplicidade__acoes">
            <RouterLink
              v-if="duplicado.ambito === 'autorizado'"
              :to="`/clinica/animais/${duplicado.codigo}`"
              class="botao botao--primario"
            >
              Abrir a ficha de {{ duplicado.nome }}
            </RouterLink>
            <button
              type="button"
              class="botao botao--secundario"
              :disabled="enviando"
              @click="enviar({ apesarDaDuplicidade: true })"
            >
              É outro animal — cadastrar mesmo assim
            </button>
            <button type="button" class="botao botao--secundario" @click="duplicado = null">
              Voltar e revisar
            </button>
          </div>
        </section>

        <div v-if="erroDeEnvio" class="aviso aviso--erro" role="alert">
          <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
          <div>
            <p class="aviso__titulo">Não conseguimos gravar agora.</p>
            <p class="aviso__texto aviso__texto--solto">{{ erroDeEnvio }}</p>
          </div>
        </div>

        <div v-if="!duplicado" class="barra">
          <AppButton type="submit" :loading="enviando" :disabled="!podeEnviar">
            {{ modo === 'consolidar' ? 'Registrar caracterização' : 'Cadastrar animal' }}
          </AppButton>
        </div>
      </form>
    </div>
  </VetShell>
</template>

<style scoped>
/* Como no painel (V01), a tela traz a própria largura e se centra na área
   de conteúdo. */
.tela {
  width: 100%;
  max-width: 880px;
  margin: 0 auto;
}

.tela__titulo {
  margin: var(--space-2) 0 var(--space-4);
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  letter-spacing: -.02em;
  color: var(--ink);
}

.voltar {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.voltar:hover {
  color: var(--ink);
}

.cartao {
  margin: 0 0 var(--space-4);
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.secao__rotulo {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--ink-muted);
}

/* O verde clínico marca a metade que é ato profissional, como em V07/V08. */
.secao__rotulo--clinico {
  color: var(--brand);
}

/* Etapa do tutor ------------------------------------------------------------ */

.etapa-cpf__linha {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: var(--space-3) 0 0;
}

.etapa-cpf__linha > :first-child {
  flex: 1;
}

.etapa-cpf__botao {
  margin-top: 24px;
}

.etapa-cpf__vazio {
  margin: var(--space-3) 0 0;
  max-width: 65ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.etapa-cpf__acoes {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin: var(--space-4) 0 0;
}

.aviso-de-registro {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: var(--space-4) 0 0;
  padding: var(--space-3);
  background: var(--surface-sunken);
  border: 1px dashed var(--consent);
  border-radius: var(--radius-sm);
}

.aviso-de-registro__icone {
  flex: none;
  color: var(--consent);
}

.aviso-de-registro__texto {
  margin: 0;
  font-size: 13px;
  line-height: 18px;
  color: var(--ink);
}

.cpf-verificado {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-4);
}

.cpf-verificado__rotulo {
  margin: 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

.cpf-verificado__numero {
  margin: var(--space-1) 0 0;
  font-family: var(--font-mono);
  font-size: 16px;
  line-height: 24px;
  font-weight: 500;
  font-variant-numeric: tabular-nums;
  color: var(--ink);
}

.cpf-verificado__nome {
  margin: var(--space-1) 0 0;
  font-size: 14px;
  line-height: 20px;
  font-weight: 600;
  color: var(--ink);
}

.cpf-verificado__nota {
  margin: var(--space-1) 0 0;
  max-width: 60ch;
  font-size: 13px;
  line-height: 18px;
  color: var(--ink-muted);
}

.cpf-verificado__trocar {
  flex: none;
  background: none;
  border: 0;
  font-family: inherit;
  font-size: 14px;
  font-weight: 600;
  color: var(--brand);
  cursor: pointer;
}

/* Identificação do modo consolidação ---------------------------------------- */

.identificado {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.identificado__icone {
  flex: none;
  color: var(--ink-muted);
}

.identificado__texto {
  flex: 1;
  min-width: 0;
}

.identificado__nome {
  margin: 0;
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.identificado__meta {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.identificado__codigo {
  margin: 0;
  font-family: var(--font-mono);
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

.identificado__tarja {
  flex: none;
  padding: var(--space-1) var(--space-2);
  background: var(--consent-wash);
  border-radius: var(--radius-pill);
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
  color: var(--consent);
}

/* As duas metades ------------------------------------------------------------ */

.metades {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-4);
}

.metade {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.metade__cabecalho {
  margin: 0 0 var(--space-4);
  padding: 0 0 var(--space-3);
  border-bottom: 1px solid var(--border-hairline);
}

.metade__nota {
  margin: var(--space-1) 0 0;
  font-size: 13px;
  line-height: 18px;
  color: var(--ink-muted);
}

.metade__dupla {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-3);
}

.metade :deep(.app-field) {
  margin-bottom: var(--space-3);
}

.nascimento {
  margin: 0 0 var(--space-3);
}

.especie {
  margin: 0;
  padding: 0;
  border: 0;
}

.especie__rotulo {
  margin: 0 0 var(--space-2);
  font-size: 14px;
  line-height: 20px;
  font-weight: 500;
  color: var(--ink);
}

.especie__cartoes {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: var(--space-3);
}

.especie-cartao {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  font-family: inherit;
  font-size: 16px;
  font-weight: 600;
  color: var(--ink-muted);
  cursor: pointer;
}

.especie-cartao--ativo {
  border-color: var(--brand);
  outline: 2px solid var(--brand);
  outline-offset: -1px;
  color: var(--brand);
}

.erro-solto {
  display: flex;
  align-items: flex-start;
  gap: var(--space-1);
  margin: var(--space-2) 0 0;
  font-size: 12px;
  line-height: 16px;
  color: var(--status-late);
}

/* Duplicidade (RF20a) -------------------------------------------------------- */

.duplicidade {
  margin: var(--space-4) 0 0;
  padding: var(--space-4);
  background: var(--surface-sunken);
  border: 1px dashed var(--consent);
  border-radius: var(--radius-md);
}

.duplicidade__topo {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
}

.duplicidade__icone {
  flex: none;
  color: var(--consent);
}

.duplicidade__titulo {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.duplicidade__animal {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: var(--space-3) 0 0;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

.duplicidade__codigo {
  font-family: var(--font-mono);
  font-size: 13px;
  color: var(--ink-faint);
}

.duplicidade__nota {
  margin: var(--space-2) 0 0;
  max-width: 70ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.duplicidade__acoes {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin: var(--space-4) 0 0;
}

/* Sem autorização (modo consolidação) ---------------------------------------- */

.sem-autorizacao {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  max-width: 640px;
  padding: var(--space-6);
  background: var(--surface-sunken);
  border: 1px dashed var(--consent);
  border-radius: var(--radius-md);
}

.sem-autorizacao__icone {
  flex: none;
  color: var(--consent);
}

.sem-autorizacao__titulo {
  margin: 0;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--ink);
}

.sem-autorizacao__texto {
  margin: var(--space-2) 0 var(--space-4);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* Barra e avisos -------------------------------------------------------------- */

.barra {
  display: flex;
  justify-content: flex-end;
  margin: var(--space-4) 0 0;
}

.botao {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  height: 40px;
  padding: 0 var(--space-4);
  border-radius: var(--radius-sm);
  font-family: inherit;
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

.botao--secundario:hover:not(:disabled) {
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

.aviso {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: var(--space-4) 0 0;
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

.aviso__texto--solto {
  margin-bottom: 0;
}

/* Esqueleto -------------------------------------------------------------------- */

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: pulsar 1.2s ease-in-out infinite;
}

.esqueleto--titulo {
  height: 34px;
  width: 40%;
  margin: 0 0 var(--space-4);
}

.esqueleto--campo {
  height: 88px;
  margin: 0 0 var(--space-4);
}

@keyframes pulsar {
  0%, 100% { opacity: .55; }
  50% { opacity: 1; }
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}

/* Larguras derivadas ----------------------------------------------------------- */

@media (min-width: 768px) {
  .metade__dupla {
    grid-template-columns: repeat(2, 1fr);
  }
}

/* §V05 — duas colunas em xl: identificação à esquerda, caracterização à
   direita, cada uma sob o cabeçalho que nomeia a responsabilidade. */
@media (min-width: 1440px) {
  .tela {
    max-width: 1120px;
  }

  .metades {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: start;
  }
}
</style>
