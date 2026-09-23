<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { CircleCheck, TriangleAlert, UserRoundPlus } from '@lucide/vue'
import AppButton from '@/components/base/AppButton.vue'
import AppCheckbox from '@/components/base/AppCheckbox.vue'
import AppInput from '@/components/base/AppInput.vue'
import RoleShell from '@/components/base/RoleShell.vue'
import { ApiError, apiPost } from '@/lib/api.js'
import { cpfValido, formatarCpf, somenteDigitos } from '@/lib/masks.js'
import { useSessaoStore } from '@/stores/sessao.js'

/**
 * Criar o cadastro de tutor de uma conta que já existe (RF12, RN05).
 *
 * A terceira origem do cadastro de tutor, ao lado do autocadastro de P03 e do
 * cadastro pelo prestador de V04 — e a que faltava: o veterinário que também
 * tem um cão em casa precisava, até aqui, inventar um segundo endereço de
 * correio, e dois endereços são duas pessoas para o resto do sistema, do livro
 * de acessos à autoria dos registros.
 *
 * O formulário é curto porque quase tudo já existe: endereço, senha e nome são
 * os da conta. Falta o que só o tutor tem — o CPF, único na plataforma (RF12a)
 * — e o aceite dos termos, que ninguém pratica por outro.
 */
const router = useRouter()
const sessao = useSessaoStore()

const form = reactive({ cpf: '', nome: '', aceite_termos: false })
const erros = reactive({})
const enviando = ref(false)
const erroDeEnvio = ref('')
const contaJaExiste = ref(false)
const cpfTocado = ref(false)

// Só quem foi convidado para uma equipe e nunca chegou a definir o nome. Para
// todos os demais o nome é o da conta, e redigitá-lo aqui só poderia fazer a
// mesma pessoa aparecer com dois nomes conforme a tela.
const precisaDoNome = computed(() => (sessao.usuario?.nome ?? '').trim() === '')

const cpfCompleto = computed(() => somenteDigitos(form.cpf).length === 11)
const cpfEhValido = computed(() => cpfCompleto.value && cpfValido(form.cpf))

const podeEnviar = computed(
  () => form.cpf.trim() !== '' && form.aceite_termos && (!precisaDoNome.value || form.nome.trim() !== ''),
)

onMounted(async () => {
  // A tela é alcançável direto pelo endereço, e não só vinda da entrada por
  // papel: sem a
  // sessão carregada não se sabe sequer se o nome precisa ser pedido.
  await sessao.carregar()

  // Quem já é tutor não tem o que fazer aqui — o cadastro é um só por conta.
  if ((sessao.usuario?.papeis ?? []).includes('tutor')) {
    router.replace('/inicio')
  }
})

function onCpfInput(valor) {
  form.cpf = formatarCpf(valor)
  delete erros.cpf
}

function validarCpf() {
  cpfTocado.value = true
  if (cpfCompleto.value && !cpfEhValido.value) {
    erros.cpf = 'Este CPF não é válido: o dígito verificador não confere.'
  }
}

async function enviar() {
  validarCpf()
  if (Object.keys(erros).length > 0) return

  enviando.value = true
  erroDeEnvio.value = ''
  contaJaExiste.value = false

  try {
    const resposta = await apiPost('/api/conta/tutor', {
      cpf: form.cpf,
      ...(precisaDoNome.value ? { nome: form.nome } : {}),
      aceite_termos: form.aceite_termos,
    })

    // O papel novo abre um ambiente que não existia um instante atrás: sem
    // atualizar a sessão, a guarda da rota de destino recusaria a entrada.
    sessao.registrar(resposta.usuario)

    router.push(resposta.usuario.rota_inicial)
  } catch (excecao) {
    if (excecao instanceof ApiError && excecao.status === 422) {
      // A recusa por cadastro existente vem numa chave própria, e não em
      // `cpf`: qual dado coincidiu é justamente o que não se conta (RF12b).
      if (excecao.errors.conta) {
        contaJaExiste.value = true
      } else {
        Object.entries(excecao.errors).forEach(([campo, mensagens]) => {
          erros[campo] = mensagens[0]
        })
        erroDeEnvio.value = 'Há campos que precisam ser corrigidos. Revise as informações abaixo.'
      }
    } else if (excecao instanceof ApiError && excecao.status === 409) {
      // Duas abas, o mesmo cadastro: a segunda chega depois da primeira.
      router.replace('/inicio')
    } else {
      erroDeEnvio.value = excecao.message
    }
  } finally {
    enviando.value = false
  }
}

function revisarDados() {
  contaJaExiste.value = false
}
</script>

<template>
  <RoleShell titulo="Cadastro de tutor" amplo>
    <div class="pagina">
      <header class="cabecalho">
        <p class="sobrelinha">Minha conta</p>
        <h1 class="titulo">Criar meu cadastro de tutor</h1>
        <p class="cabecalho__detalhe">
          Você continua com a mesma conta, o mesmo e-mail e a mesma senha. O cadastro de tutor
          acrescenta o ambiente onde ficam os seus animais — o de trabalho permanece como está.
        </p>
      </header>

      <!-- Cadastro existente: informa a existência e nada além dela (RF12b). -->
      <div v-if="contaJaExiste" class="cartao">
        <div class="aviso aviso--atencao" role="alert">
          <TriangleAlert :size="20" :stroke-width="1.75" />
          <div>
            <p class="aviso__titulo">Já existe um cadastro de tutor com estes dados.</p>
            <p class="aviso__texto">
              Se o cadastro for seu, ele está em outra conta: entre com ela para chegar aos seus animais.
            </p>
          </div>
        </div>

        <div class="acoes">
          <AppButton variant="secondary" @click="revisarDados">Revisar o CPF</AppButton>
          <RouterLink to="/entrar/tutor" class="ligacao-botao">Entrar na outra conta</RouterLink>
        </div>

        <p class="nota">
          É tudo o que mostramos: nem de quem é o cadastro, nem desde quando existe.
        </p>
      </div>

      <form v-else class="cartao" novalidate @submit.prevent="enviar">
        <p class="conta-atual">
          <UserRoundPlus :size="18" :stroke-width="1.75" />
          <span>
            Cadastrando em <strong>{{ sessao.usuario?.email }}</strong>
            <template v-if="!precisaDoNome"> · {{ sessao.usuario?.nome }}</template>
          </span>
        </p>

        <div class="campos">
          <AppInput
            v-if="precisaDoNome"
            id="tutor-nome"
            v-model="form.nome"
            label="Nome completo"
            autocomplete="name"
            :error="erros.nome"
            hint="É o nome que encabeça a carteira de vacinação dos seus animais."
          />

          <AppInput
            id="tutor-cpf"
            label="CPF"
            mono
            inputmode="numeric"
            :model-value="form.cpf"
            :error="erros.cpf"
            hint="O CPF identifica você na plataforma e não pode ser alterado depois."
            @update:model-value="onCpfInput"
            @blur="validarCpf"
          >
            <template v-if="cpfTocado && cpfEhValido && !erros.cpf" #feedback>
              <span class="valido">
                <CircleCheck :size="16" />
                CPF válido
              </span>
            </template>
          </AppInput>
        </div>

        <!-- As ligações abrem em nova aba para não descartar o formulário meio
             preenchido, e param a propagação para não marcarem a caixa. -->
        <div class="aceite">
          <AppCheckbox id="aceite-tutor" v-model="form.aceite_termos">
            Li e aceito os
            <a href="/termos" target="_blank" rel="noopener" @click.stop>termos de uso</a>
            e a
            <a href="/privacidade" target="_blank" rel="noopener" @click.stop>política de privacidade</a>.
          </AppCheckbox>
          <p v-if="erros.aceite_termos" class="erro-de-campo" role="alert">
            <TriangleAlert :size="16" />
            <span>{{ erros.aceite_termos }}</span>
          </p>
        </div>

        <div v-if="erroDeEnvio" class="aviso aviso--erro" role="alert">
          <TriangleAlert :size="20" :stroke-width="1.75" />
          <div>
            <p class="aviso__titulo">Não conseguimos criar o cadastro.</p>
            <p class="aviso__texto">{{ erroDeEnvio }}</p>
          </div>
        </div>

        <div class="acoes">
          <RouterLink to="/conta" class="ligacao">Agora não</RouterLink>
          <AppButton type="submit" :disabled="!podeEnviar" :loading="enviando">
            {{ enviando ? 'Criando…' : 'Criar cadastro de tutor' }}
          </AppButton>
        </div>
      </form>
    </div>
  </RoleShell>
</template>

<style scoped>
.pagina {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  width: 100%;
  max-width: 640px;
  margin: 0 auto;
  padding: var(--space-4);
}

.cabecalho {
  padding: var(--space-2) 0 0;
}

.sobrelinha {
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--brand);
}

.titulo {
  margin: var(--space-1) 0 0;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  color: var(--ink);
}

.cabecalho__detalhe {
  margin: var(--space-2) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.cartao {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.conta-atual {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  margin: 0;
  padding: var(--space-3);
  background: var(--surface-sunken);
  border-radius: var(--radius-sm);
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.conta-atual svg {
  flex: none;
  color: var(--brand);
}

.campos {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  margin: var(--space-4) 0 0;
}

.aceite {
  margin: var(--space-4) 0 0;
}

.valido {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  color: var(--status-ok);
  font-size: 13px;
  line-height: 16px;
}

.erro-de-campo {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: var(--space-2) 0 0;
  font-size: 13px;
  line-height: 16px;
  color: var(--status-late);
}

.aviso {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: var(--space-4) 0 0;
  padding: var(--space-3);
  border-radius: var(--radius-sm);
  background: var(--surface-sunken);
  font-size: 14px;
  line-height: 20px;
}

.aviso:first-child {
  margin-top: 0;
}

.aviso svg {
  flex: none;
  margin-top: 2px;
  color: var(--status-due-text);
}

.aviso__titulo {
  margin: 0;
  font-weight: 600;
  color: var(--ink);
}

.aviso__texto {
  margin: var(--space-1) 0 0;
  color: var(--ink-muted);
}

.acoes {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: var(--space-3);
  margin: var(--space-6) 0 0;
  padding: var(--space-4) 0 0;
  border-top: 1px solid var(--border-hairline);
}

/* Navegação, não envio — mas com a altura de alvo de §4.3: abaixo de 1024 px
   um vínculo de 20 px na fileira de ações é intocável no celular. */
.ligacao,
.ligacao-botao {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 44px;
  padding: 0 var(--space-4);
  font-size: 14px;
  line-height: 20px;
  font-weight: 600;
  color: var(--brand);
  text-decoration: none;
}

.ligacao:hover,
.ligacao-botao:hover {
  text-decoration: underline;
}

.nota {
  margin: var(--space-4) 0 0;
  font-size: 13px;
  line-height: 18px;
  color: var(--ink-muted);
}
</style>
