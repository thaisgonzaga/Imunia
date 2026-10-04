<script setup>
import { onMounted, ref } from 'vue'
import {
  Cat,
  CircleCheck,
  Dog,
  Mail,
  TriangleAlert,
} from '@lucide/vue'
import VetShell from '@/components/vet/VetShell.vue'
import AppInput from '@/components/base/AppInput.vue'
import AppButton from '@/components/base/AppButton.vue'
import StatusPill from '@/components/base/StatusPill.vue'
import { ApiError, apiGet, apiPost } from '@/lib/api.js'
import { descreverAnimal } from '@/lib/animais.js'

/**
 * V04 — cadastrar tutor (RF12, RF13, RF14).
 *
 * Nome e e-mail, num formulário só. O e-mail é a chave do cadastro — um
 * endereço, uma conta (RN05) — e o destino do convite; o CPF não é pedido. Se o
 * e-mail já é de um tutor, o servidor não cria segundo cadastro (RF12b): devolve
 * o que existe, e a tela mostra o tutor e os animais dele e segue para o animal,
 * sem esperar nada do tutor.
 */
const contexto = ref(null)
const carregando = ref(true)
const erroDeContexto = ref('')

/** formulario → sucesso | formulario → existente */
const etapa = ref('formulario')

const formulario = ref({ nome: '', email: '' })
const errosDeCampo = ref({})
const erroDeEnvio = ref('')
const enviando = ref(false)

/** O tutor que a resposta devolveu — criado agora ou já existente. */
const tutor = ref(null)

/** Os animais do tutor já cadastrado, quando o e-mail o achou. */
const animaisDoTutor = ref([])

function iconeDaEspecie(especie) {
  return especie === 'gato' ? Cat : Dog
}

function primeiroErro(campo) {
  return errosDeCampo.value[campo]?.[0] ?? ''
}

/** O tutor viaja para V05 pelo estado do histórico, e nunca pelo endereço. */
function paraOAnimal() {
  return { path: '/clinica/animais/novo', state: { email: tutor.value?.email } }
}

async function carregar() {
  carregando.value = true
  erroDeContexto.value = ''

  try {
    // Sem termo, a busca devolve só o contexto clínico — prestador ativo e
    // vínculos —, que é o que a moldura precisa. Nada é consultado nem
    // registrado.
    contexto.value = await apiGet('/api/clinica/buscar')
  } catch (excecao) {
    erroDeContexto.value = excecao.message
  } finally {
    carregando.value = false
  }
}

async function cadastrar() {
  if (enviando.value) return

  enviando.value = true
  errosDeCampo.value = {}
  erroDeEnvio.value = ''

  try {
    const busca = new URLSearchParams()
    if (contexto.value?.prestador) busca.set('prestador', contexto.value.prestador.id)

    const resposta = await apiPost(`/api/clinica/tutores?${busca}`, {
      nome: formulario.value.nome.trim(),
      email: formulario.value.email.trim(),
    })

    tutor.value = resposta.tutor

    // RF12b — o e-mail já é de um tutor: o cadastro que existe vale, e o
    // atendimento segue para o animal.
    if (resposta.situacao === 'tutor_existente') {
      animaisDoTutor.value = resposta.animais ?? []
      etapa.value = 'existente'
      return
    }

    etapa.value = 'sucesso'
  } catch (excecao) {
    if (excecao instanceof ApiError && excecao.status === 422) {
      errosDeCampo.value = excecao.errors
    } else {
      erroDeEnvio.value = excecao.message
    }
  } finally {
    enviando.value = false
  }
}

/** Volta ao formulário, mantendo o que foi digitado. */
function voltarAoFormulario() {
  etapa.value = 'formulario'
  tutor.value = null
  animaisDoTutor.value = []
  erroDeEnvio.value = ''
  errosDeCampo.value = {}
}

function cadastrarOutro() {
  formulario.value = { nome: '', email: '' }
  voltarAoFormulario()
}

function trocarPrestador(id) {
  const vinculo = (contexto.value?.vinculos ?? []).find((candidato) => candidato.id === id)
  if (!vinculo) return

  contexto.value = { ...contexto.value, prestador: vinculo }
}

onMounted(carregar)
</script>

<template>
  <VetShell
    titulo="Cadastrar tutor"
    :prestador="contexto?.prestador"
    :vinculos="contexto?.vinculos ?? []"
    @trocar-prestador="trocarPrestador"
  >
    <div v-if="carregando" class="tela" aria-busy="true" aria-live="polite">
      <span class="visually-hidden">Abrindo o cadastro.</span>
      <div class="esqueleto esqueleto--titulo" />
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

    <div v-else class="tela">
      <!-- Sucesso: o convite saiu, e a tela diz o que ele ainda não é (RF14). -->
      <section v-if="etapa === 'sucesso'" class="sucesso" role="status">
        <CircleCheck :size="32" :stroke-width="1.75" class="sucesso__icone" />
        <h1 class="sucesso__titulo">Tutor cadastrado</h1>
        <p class="sucesso__texto">
          {{ tutor?.nome }} já está no Imunia. Agora cadastre o animal: o convite
          para acompanhar as informações sai para <strong>{{ tutor?.email }}</strong>
          junto com ele.
        </p>
        <p class="sucesso__ressalva">
          O atendimento não depende do tutor: tudo o que você registrar vale desde já.
        </p>
        <div class="sucesso__acoes">
          <RouterLink :to="paraOAnimal()" class="botao botao--primario">
            Cadastrar animal
          </RouterLink>
          <button type="button" class="botao botao--secundario" @click="cadastrarOutro">
            Cadastrar outro tutor
          </button>
        </div>
        <RouterLink to="/clinica/buscar" class="sucesso__voltar">Voltar à busca</RouterLink>
      </section>

      <template v-else>
        <header class="cabecalho">
          <p class="cabecalho__sobrelinha">Cadastro</p>
          <h1 class="cabecalho__titulo">Cadastrar tutor</h1>
          <p class="cabecalho__texto">
            Basta o nome e o e-mail. O cadastro é único na plataforma: se o
            e-mail já é de um tutor do Imunia, você segue direto para o animal —
            nunca um segundo cadastro.
          </p>
        </header>

        <!-- Já cadastrado: não há o que cadastrar, e o atendimento segue para o
             animal sem esperar nada do tutor. -->
        <section v-if="etapa === 'existente'" class="secao-vinculado">
          <div class="tutor-existente">
            <div>
              <p class="tutor-existente__nome">{{ tutor?.nome }}</p>
              <p class="tutor-existente__email">{{ tutor?.email }}</p>
            </div>
            <button type="button" class="tutor-existente__trocar" @click="voltarAoFormulario">
              Trocar e-mail
            </button>
          </div>

          <p class="secao-vinculado__texto">
            Este e-mail já é de um tutor cadastrado no Imunia.
            <template v-if="animaisDoTutor.length">
              Abra a ficha de um animal abaixo ou cadastre um novo.
            </template>
            <template v-else>Cadastre o primeiro animal dele.</template>
          </p>

          <div class="secao-vinculado__acoes">
            <RouterLink :to="paraOAnimal()" class="botao botao--primario">
              Cadastrar animal
            </RouterLink>
          </div>

          <div v-if="animaisDoTutor.length" class="cartoes">
            <RouterLink
              v-for="animal in animaisDoTutor"
              :key="animal.codigo"
              :to="`/clinica/animais/${animal.codigo}`"
              class="cartao-animal"
            >
              <span class="cartao-animal__icone">
                <component :is="iconeDaEspecie(animal.especie)" :size="20" :stroke-width="1.75" />
              </span>
              <span class="cartao-animal__texto">
                <span class="cartao-animal__nome">{{ animal.nome }}</span>
                <span class="cartao-animal__meta">{{ descreverAnimal(animal) }}</span>
                <span class="cartao-animal__codigo">{{ animal.codigo }}</span>
              </span>
              <StatusPill
                v-if="animal.situacao"
                :tipo="animal.situacao.tipo"
                :texto="animal.situacao.texto_curto"
              />
              <StatusPill v-else tipo="nao-verificada" texto="sem dados" />
            </RouterLink>
          </div>
        </section>

        <form v-else class="formulario" novalidate @submit.prevent="cadastrar">
          <AppInput
            id="v04-nome"
            v-model="formulario.nome"
            label="Nome completo"
            autocomplete="off"
            :error="primeiroErro('nome')"
          />

          <AppInput
            id="v04-email"
            v-model="formulario.email"
            label="E-mail do tutor"
            type="email"
            autocomplete="off"
            :error="primeiroErro('email')"
            hint="É para este endereço que vai o convite de acesso."
          />

          <!-- O bloco de convite: o que acontece ao concluir, dito antes (RF14). -->
          <div class="bloco-convite">
            <Mail :size="20" :stroke-width="1.75" class="bloco-convite__icone" />
            <p class="bloco-convite__texto">
              Quando você cadastrar o primeiro animal, o tutor recebe por e-mail
              um convite para acompanhar as informações. O atendimento não depende
              disso — tudo o que você registrar vale desde já.
            </p>
          </div>

          <div v-if="erroDeEnvio" class="aviso aviso--erro" role="alert">
            <TriangleAlert :size="20" :stroke-width="1.75" class="aviso__icone" />
            <div>
              <p class="aviso__titulo">Não conseguimos concluir o cadastro.</p>
              <p class="aviso__texto aviso__texto--solto">{{ erroDeEnvio }}</p>
            </div>
          </div>

          <div class="barra">
            <AppButton type="submit" :loading="enviando">Cadastrar tutor</AppButton>
          </div>
        </form>
      </template>
    </div>

  </VetShell>
</template>

<style scoped>
/* Coluna única (§V04): a tela é uma conversa de balcão, não um painel. */
.tela {
  width: 640px;
  max-width: 100%;
  margin: 0 auto;
  padding: var(--space-4) 0 var(--space-12);
}

.cabecalho__sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand);
}

.cabecalho__titulo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  letter-spacing: -.02em;
  color: var(--ink);
}

.cabecalho__texto {
  margin: var(--space-2) 0 0;
  max-width: 65ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* O tutor já cadastrado ----------------------------------------------------- */

.tutor-existente {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  margin: 0 0 var(--space-4);
  padding: var(--space-3) var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.tutor-existente__nome {
  margin: 0;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.tutor-existente__email {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
  overflow-wrap: anywhere;
}

.tutor-existente__trocar {
  flex: none;
  padding: var(--space-2) var(--space-3);
  background: none;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  font-family: inherit;
  font-size: 14px;
  font-weight: 600;
  color: var(--ink);
  cursor: pointer;
}

.tutor-existente__trocar:hover {
  background: var(--surface-sunken);
}

/* Já cadastrado ------------------------------------------------------------ */

.secao-vinculado {
  margin: var(--space-6) 0 0;
}

.secao-vinculado__acoes {
  display: flex;
  margin: 0 0 var(--space-4);
}

.secao-vinculado__texto {
  margin: 0 0 var(--space-3);
  max-width: 65ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.cartoes {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-3);
}

.cartao-animal {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
  color: var(--ink);
}

.cartao-animal:hover {
  border-color: var(--brand-bright);
  color: var(--ink);
}

.cartao-animal__icone {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-pill);
  background: var(--surface-sunken);
  color: var(--ink-muted);
}

.cartao-animal__texto {
  flex: 1;
  min-width: 0;
}

.cartao-animal__nome {
  display: block;
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
}

.cartao-animal__meta {
  display: block;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.cartao-animal__codigo {
  display: block;
  font-family: var(--font-mono);
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

/* Formulário ---------------------------------------------------------------- */

.formulario {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  margin: var(--space-6) 0 0;
}

.bloco-convite {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-sm);
}

.bloco-convite__icone {
  flex: none;
  color: var(--ink-muted);
}

.bloco-convite__texto {
  margin: 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

/* §V04 — barra de ação fixa no rodapé em celular. `sticky`, como em V07: fixa
   enquanto o formulário passa, sem cobrir a barra de navegação da moldura. */
.barra {
  position: sticky;
  bottom: 0;
  display: flex;
  flex-direction: column;
  padding: var(--space-3) 0;
  background: var(--surface-page);
}

/* Sucesso -------------------------------------------------------------------- */

.sucesso {
  padding: var(--space-12) 0;
  text-align: center;
}

.sucesso__icone {
  color: var(--brand);
}

.sucesso__titulo {
  margin: var(--space-3) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  letter-spacing: -.02em;
  color: var(--ink);
}

.sucesso__texto {
  margin: var(--space-3) auto 0;
  max-width: 55ch;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink);
}

/* RF14b — a ressalva é parte do sucesso, não nota de rodapé: o profissional
   precisa dizê-la ao tutor que está à sua frente. */
.sucesso__ressalva {
  margin: var(--space-3) auto 0;
  max-width: 55ch;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.sucesso__acoes {
  display: flex;
  justify-content: center;
  gap: var(--space-3);
  flex-wrap: wrap;
  margin: var(--space-6) 0 0;
}

.sucesso__voltar {
  display: inline-block;
  margin: var(--space-4) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
  text-decoration: underline;
}

.sucesso__voltar:hover {
  color: var(--ink);
}

/* Botões e avisos — o vocabulário de V03 ------------------------------------ */

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

.botao--secundario:hover {
  background: var(--surface-sunken);
  color: var(--ink);
}

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

.aviso__texto--solto {
  margin-bottom: 0;
}

/* §4.3 — alvo mínimo de toque abaixo de 1024 px. Os 40 px vêm do desenho de
   1440 px e valem só lá; na largura de toque, todo acionável desta tela sobe
   para 44. */
@media (max-width: 1023px) {
  .botao,
  .tutor-existente__trocar {
    min-height: 44px;
  }
}

/* Esqueleto ----------------------------------------------------------------- */

.esqueleto {
  border-radius: var(--radius-xs);
  background: var(--surface-sunken);
  animation: pulsar 1.2s ease-in-out infinite;
}

.esqueleto--titulo {
  height: 34px;
  width: 40%;
}

.esqueleto--campo {
  height: 48px;
  margin: var(--space-6) 0 0;
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
</style>
